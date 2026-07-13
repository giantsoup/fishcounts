<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ParserErrorResolutionType;
use App\Http\Controllers\Controller;
use App\Http\Requests\DismissParserErrorRequest;
use App\Models\Boat;
use App\Models\ParserError;
use App\Models\Species;
use App\Models\TripType;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class ParserErrorController extends Controller
{
    public function __invoke(Request $request): View
    {
        $showAll = $request->string('status')->toString() === 'all';
        $humanReviewEnabled = (bool) config('fish.ai_review.human_review_enabled');
        $errors = ParserError::query()
            ->with([
                'rawScrapePayload',
                'resolver',
                'scrapeSource',
                ...($humanReviewEnabled ? ['latestDiagnosticReview.humanActions.actor'] : []),
            ])
            ->when(! $showAll, fn ($query) => $query->whereNull('resolved_at'))
            ->latest()
            ->paginate(25)
            ->withQueryString();

        return view('admin.parser-errors.index', [
            'errors' => $errors,
            'boats' => Boat::query()->where('is_active', true)->orderBy('name')->get(),
            'species' => Species::query()->where('is_active', true)->orderBy('name')->get(),
            'tripTypes' => TripType::query()->where('is_active', true)->orderedForDisplay()->get(),
            'showAll' => $showAll,
            'humanReviewEnabled' => $humanReviewEnabled,
            'reviewTargets' => $humanReviewEnabled ? $this->reviewTargets($errors->getCollection()) : collect(),
        ]);
    }

    public function dismiss(DismissParserErrorRequest $request, ParserError $parserError): RedirectResponse
    {
        $wasDismissed = ParserError::query()
            ->whereKey($parserError->getKey())
            ->whereNull('resolved_at')
            ->update([
                'resolved_at' => now(),
                'resolved_by_user_id' => $request->user()->getKey(),
                'resolution_type' => ParserErrorResolutionType::Dismissed->value,
            ]) === 1;

        return back()->with('status', $wasDismissed
            ? 'Parser error dismissed without creating an alias.'
            : 'Parser error was already resolved.');
    }

    /** @param Collection<int, ParserError> $parserErrors */
    private function reviewTargets(Collection $parserErrors): Collection
    {
        $reviews = $parserErrors->pluck('latestDiagnosticReview')->filter();
        $references = $reviews->mapWithKeys(function ($review): array {
            $target = $review->recommendedCanonicalTarget();

            return $target === null ? [] : [$review->id => $target];
        });

        $targets = collect([
            'boat' => Boat::query()->whereKey($references->where('type', 'boat')->pluck('id'))->get()->keyBy('id'),
            'species' => Species::query()->whereKey($references->where('type', 'species')->pluck('id'))->get()->keyBy('id'),
            'trip_type' => TripType::query()->whereKey($references->where('type', 'trip_type')->pluck('id'))->get()->keyBy('id'),
        ]);

        return $references->map(fn (array $reference) => $targets->get($reference['type'])?->get($reference['id']));
    }
}
