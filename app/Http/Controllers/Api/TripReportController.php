<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ListTripReportsRequest;
use App\Http\Resources\TripReportResource;
use App\Models\TripReport;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Read-only trip reports. Corrections go through the parser and reparses, so
 * there is deliberately no create, update, or delete here.
 */
class TripReportController extends Controller
{
    private const RELATIONS = ['boat', 'landing', 'tripType', 'speciesCounts.species'];

    public function index(ListTripReportsRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();

        $reports = TripReport::query()
            ->with(self::RELATIONS)
            ->when($filters['date'] ?? null, fn ($query, string $date) => $query->whereDate('trip_date', $date))
            ->when($filters['from'] ?? null, fn ($query, string $from) => $query->whereDate('trip_date', '>=', $from))
            ->when($filters['to'] ?? null, fn ($query, string $to) => $query->whereDate('trip_date', '<=', $to))
            ->when($filters['source'] ?? null, fn ($query, int|string $source) => $query->where('source_id', (int) $source))
            ->when($filters['boat'] ?? null, fn ($query, int|string $boat) => $query->where('boat_id', (int) $boat))
            ->orderByDesc('trip_date')
            ->orderByDesc('id')
            ->paginate($request->perPage())
            ->withQueryString();

        return TripReportResource::collection($reports);
    }

    public function show(TripReport $tripReport): TripReportResource
    {
        return TripReportResource::make($tripReport->load(self::RELATIONS));
    }
}
