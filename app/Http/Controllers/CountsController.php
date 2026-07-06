<?php

namespace App\Http\Controllers;

use App\Http\Requests\CountsIndexRequest;
use App\Models\Boat;
use App\Models\Landing;
use App\Models\Species;
use App\Models\SpeciesCount;
use App\Models\TripReport;
use App\Models\TripType;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;

class CountsController extends Controller
{
    public function __invoke(CountsIndexRequest $request): View
    {
        $filters = $this->resolveDateFilters($request->filters());
        $reportsQuery = $this->reportsQuery($filters);

        return view('counts.index', [
            'reports' => (clone $reportsQuery)
                ->with([
                    'landing',
                    'boat',
                    'tripType',
                    'source',
                    'speciesCounts' => fn ($query) => $query
                        ->with('species')
                        ->when($filters['species_id'], fn ($query, int $speciesId) => $query->where('species_id', $speciesId))
                        ->orderBy('id'),
                ])
                ->latest('trip_date')
                ->orderBy('raw_landing_name')
                ->orderBy('raw_boat_name')
                ->paginate(50)
                ->withQueryString(),
            'summary' => $this->summaryFor((clone $reportsQuery), $filters),
            'dateLabel' => $this->dateLabel($filters),
            'filters' => $filters,
            'species' => Species::query()->where('is_active', true)->orderBy('name')->get(),
            'tripTypes' => TripType::query()->where('is_active', true)->orderedForDisplay()->get(),
            'landings' => Landing::query()->where('is_active', true)->orderBy('name')->get(),
            'boats' => Boat::query()->where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    /** @param array{from: ?string, to: ?string, species_id: ?int, trip_type_id: ?int, landing_id: ?int, boat_id: ?int} $filters */
    private function resolveDateFilters(array $filters): array
    {
        if ($filters['from'] !== null && $filters['to'] !== null) {
            return $filters;
        }

        $latestDate = $this->baseReportsQuery($filters)
            ->latest('trip_date')
            ->value('trip_date');

        $filters['from'] = $latestDate ?? now()->toDateString();
        $filters['to'] = $filters['from'];

        return $filters;
    }

    /** @param array{from: ?string, to: ?string, species_id: ?int, trip_type_id: ?int, landing_id: ?int, boat_id: ?int} $filters */
    private function baseReportsQuery(array $filters): Builder
    {
        return TripReport::query()
            ->where('is_deduped_primary', true)
            ->when($filters['trip_type_id'], fn (Builder $query, int $tripTypeId) => $query->where('trip_type_id', $tripTypeId))
            ->when($filters['landing_id'], fn (Builder $query, int $landingId) => $query->where('landing_id', $landingId))
            ->when($filters['boat_id'], fn (Builder $query, int $boatId) => $query->where('boat_id', $boatId))
            ->when($filters['species_id'], fn (Builder $query, int $speciesId) => $query->whereHas(
                'speciesCounts',
                fn (Builder $query) => $query->where('species_id', $speciesId),
            ));
    }

    /** @param array{from: string, to: string, species_id: ?int, trip_type_id: ?int, landing_id: ?int, boat_id: ?int} $filters */
    private function reportsQuery(array $filters): Builder
    {
        return $this->baseReportsQuery($filters)
            ->where('trip_date', '>=', $filters['from'])
            ->where('trip_date', '<', CarbonImmutable::parse($filters['to'])->addDay()->toDateString());
    }

    /**
     * @param  Builder<TripReport>  $reportsQuery
     * @param  array{from: string, to: string, species_id: ?int, trip_type_id: ?int, landing_id: ?int, boat_id: ?int}  $filters
     * @return array{trips: int, boats: int, anglers: int, retained: int, released: int}
     */
    private function summaryFor(Builder $reportsQuery, array $filters): array
    {
        $reportIds = (clone $reportsQuery)->select('trip_reports.id');
        $countsQuery = SpeciesCount::query()
            ->whereIn('trip_report_id', $reportIds)
            ->when($filters['species_id'], fn (Builder $query, int $speciesId) => $query->where('species_id', $speciesId));

        $boatKeys = (clone $reportsQuery)
            ->toBase()
            ->select(['id', 'boat_id', 'raw_boat_name'])
            ->get()
            ->map(fn (object $report): string => $report->boat_id !== null
                ? 'boat:'.$report->boat_id
                : 'raw:'.($report->raw_boat_name ?? $report->id)
            );

        return [
            'trips' => (clone $reportsQuery)->count(),
            'boats' => $boatKeys->unique()->count(),
            'anglers' => (int) (clone $reportsQuery)->sum('anglers'),
            'retained' => (int) (clone $countsQuery)->sum('count'),
            'released' => (int) (clone $countsQuery)->sum('released_count'),
        ];
    }

    /** @param array{from: string, to: string, species_id: ?int, trip_type_id: ?int, landing_id: ?int, boat_id: ?int} $filters */
    private function dateLabel(array $filters): string
    {
        if ($filters['from'] === $filters['to']) {
            return CarbonImmutable::parse($filters['from'])->format('F j, Y');
        }

        return CarbonImmutable::parse($filters['from'])->format('n/j/Y').' to '.CarbonImmutable::parse($filters['to'])->format('n/j/Y');
    }
}
