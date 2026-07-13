<?php

namespace App\Services\IssueTracking;

use App\DTOs\ParserBugIssueCandidateData;
use App\Enums\ParserDiagnosticReviewActionType;
use App\Enums\ParserDiagnosticReviewClassification;
use App\Enums\ParserDiagnosticReviewStatus;
use App\Models\ParserDiagnosticReview;
use Illuminate\Validation\ValidationException;

final class ParserBugIssueCandidateFactory
{
    public function __construct(
        private readonly ParserBugSignatureFactory $signatureFactory,
        private readonly ParserBugIssueRenderer $renderer,
    ) {}

    public function make(ParserDiagnosticReview $review): ParserBugIssueCandidateData
    {
        $review->loadMissing(['parserError.scrapeSource', 'rawScrapePayload']);
        $parserError = $review->parserError;

        if ($review->status !== ParserDiagnosticReviewStatus::Succeeded
            || $review->confidence === null
            || (float) $review->confidence < (float) config('fish.github_issues.minimum_confidence')
            || ! in_array($review->classification?->value, config('fish.github_issues.eligible_classifications'), true)) {
            throw ValidationException::withMessages(['review' => 'This AI review is not eligible for a parser-bug issue.']);
        }

        if ($parserError === null
            || $parserError->diagnostic_fingerprint !== $review->diagnostic_fingerprint
            || $parserError->resolved_at !== null
            || ! is_array($review->validated_result)
            || $this->hasBlockingHumanAction($review)) {
            throw ValidationException::withMessages(['review' => 'This AI review is stale or no longer has validated parser facts.']);
        }

        $this->validateIssueFacts($review, $parserError->context ?? []);

        $sourceSlug = $this->sourceSlug((string) data_get($parserError->context, 'source', $parserError->scrapeSource?->slug));
        $signature = $this->signatureFactory->make($review, $parserError, $sourceSlug);
        $labels = array_keys(config('fish.github_issues.required_labels'));

        return new ParserBugIssueCandidateData(
            signature: $signature,
            sourceSlug: $sourceSlug,
            title: $this->renderer->title($review, $sourceSlug, $parserError->raw_field ?? 'report'),
            body: $this->renderer->body($review, $parserError, $signature, $sourceSlug),
            labels: $labels,
        );
    }

    private function sourceSlug(string $source): string
    {
        $slug = str($source)->lower()->replaceMatches('/[^a-z0-9_-]+/', '-')->trim('-')->toString();

        if ($slug === '') {
            throw ValidationException::withMessages(['review' => 'The parser-bug candidate has no valid source slug.']);
        }

        return str($slug)->limit(50, '')->toString();
    }

    private function hasBlockingHumanAction(ParserDiagnosticReview $review): bool
    {
        return $review->humanActions()
            ->where('review_attempt', $review->attempts)
            ->whereIn('action', [
                ParserDiagnosticReviewActionType::Accepted->value,
                ParserDiagnosticReviewActionType::Rejected->value,
                ParserDiagnosticReviewActionType::Dismissed->value,
            ])
            ->exists();
    }

    /** @param array<string, mixed> $context */
    private function validateIssueFacts(ParserDiagnosticReview $review, array $context): void
    {
        $url = $context['url'] ?? null;
        $scheme = is_string($url) ? parse_url($url, PHP_URL_SCHEME) : null;
        $corrections = $review->validated_result['corrections'] ?? null;
        $resultClassification = $review->validated_result['classification'] ?? null;
        $resultConfidence = $review->validated_result['confidence'] ?? null;
        $allowsApplicationOwnedExpectation = in_array($review->classification, [
            ParserDiagnosticReviewClassification::NewEntityCandidate,
            ParserDiagnosticReviewClassification::MissingReport,
        ], true);

        if (! filled($context['sanitized_paragraph'] ?? null)
            || ! filled($context['parser_version'] ?? $review->rawScrapePayload?->parser_version)
            || ! is_string($url)
            || filter_var($url, FILTER_VALIDATE_URL) === false
            || ! in_array($scheme, ['http', 'https'], true)
            || ! array_key_exists('extracted_fields', $context)
            || ! is_array($context['extracted_fields'])
            || ! is_array($context['evidence'] ?? null)
            || $context['evidence'] === []
            || ! is_array($corrections)
            || $resultClassification !== $review->classification?->value
            || ! is_numeric($resultConfidence)
            || abs((float) $resultConfidence - (float) $review->confidence) > 0.0001
            || ($corrections === [] && ! $allowsApplicationOwnedExpectation)) {
            throw ValidationException::withMessages([
                'review' => 'This parser-bug candidate does not contain the validated reproduction, parse, expectation, and evidence required for an issue.',
            ]);
        }
    }
}
