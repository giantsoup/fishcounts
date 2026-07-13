<?php

namespace App\Services\IssueTracking;

use App\Models\ParserDiagnosticReview;
use App\Models\ParserError;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use JsonException;

final class ParserBugSignatureFactory
{
    /** @throws JsonException */
    public function make(ParserDiagnosticReview $review, ParserError $parserError, string $sourceSlug): string
    {
        $correctionShapes = collect($review->validated_result['corrections'] ?? [])
            ->map(fn (array $correction): array => Arr::only($correction, [
                'operation', 'field', 'canonical_type',
            ]))
            ->sortBy(fn (array $correction): string => implode('|', $correction))
            ->values()
            ->all();

        return hash('sha256', json_encode([
            'source' => $this->normalize($sourceSlug),
            'diagnostic_type' => $parserError->error_type,
            'field' => $this->normalize($parserError->raw_field ?? 'report'),
            'raw_value_pattern' => $this->pattern($parserError->raw_value ?? ''),
            'evidence_shape' => $this->shape(data_get($parserError->context, 'evidence', [])),
            'correction_shapes' => $correctionShapes,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function normalize(string $value): string
    {
        return Str::of($value)->lower()->squish()->toString();
    }

    private function pattern(string $value): string
    {
        return Str::of($this->normalize($value))
            ->replaceMatches('/\b\d+(?:[.\/-]\d+)*\b/', '{number}')
            ->toString();
    }

    private function shape(mixed $value): mixed
    {
        if (! is_array($value)) {
            return get_debug_type($value);
        }

        ksort($value);

        return collect($value)
            ->map(fn (mixed $item): mixed => $this->shape($item))
            ->all();
    }
}
