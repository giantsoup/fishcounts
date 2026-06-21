<?php

namespace App\Http\Controllers;

use App\Http\Requests\CountsIndexRequest;
use App\Models\Boat;
use App\Models\Landing;
use App\Models\Species;
use App\Models\SpeciesCount;
use App\Models\TripType;
use Illuminate\Contracts\View\View;

class CountsController extends Controller
{
    public function __invoke(CountsIndexRequest $request): View
    {
        $filters = $request->filters();

        return view('counts.index', [
            'counts' => SpeciesCount::query()
                ->with(['species', 'tripReport.region', 'tripReport.landing', 'tripReport.boat', 'tripReport.tripType', 'tripReport.source'])
                ->whereHas('tripReport', function ($query) use ($filters): void {
                    $query
                        ->where('is_deduped_primary', true)
                        ->whereBetween('trip_date', [$filters['from'], $filters['to']])
                        ->when($filters['trip_type_id'], fn ($query, int $tripTypeId) => $query->where('trip_type_id', $tripTypeId))
                        ->when($filters['landing_id'], fn ($query, int $landingId) => $query->where('landing_id', $landingId))
                        ->when($filters['boat_id'], fn ($query, int $boatId) => $query->where('boat_id', $boatId));
                })
                ->when($filters['species_id'], fn ($query, int $speciesId) => $query->where('species_id', $speciesId))
                ->join('trip_reports', 'trip_reports.id', '=', 'species_counts.trip_report_id')
                ->orderByDesc('trip_reports.trip_date')
                ->orderBy('trip_reports.raw_landing_name')
                ->orderBy('trip_reports.raw_boat_name')
                ->orderBy('species_counts.id')
                ->select('species_counts.*')
                ->paginate(50)
                ->withQueryString(),
            'filters' => $filters,
            'species' => Species::query()->where('is_active', true)->orderBy('name')->get(),
            'tripTypes' => TripType::query()->where('is_active', true)->orderedForDisplay()->get(),
            'landings' => Landing::query()->where('is_active', true)->orderBy('name')->get(),
            'boats' => Boat::query()->where('is_active', true)->orderBy('name')->get(),
        ]);
    }
}
