<?php

namespace App\Http\Controllers\Admin;

use App\Enums\EnvironmentalLocationType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\EnvironmentalConditionIndexRequest;
use App\Models\EnvironmentalDailySummary;
use App\Models\EnvironmentalObservation;
use App\Models\EnvironmentalPayload;
use App\Models\EnvironmentalSource;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class EnvironmentalConditionController extends Controller
{
    public function __invoke(EnvironmentalConditionIndexRequest $request): View
    {
        $filters = $request->filters();

        return view('admin.environmental-conditions.index', [
            'filters' => $filters,
            'locationTypes' => EnvironmentalLocationType::cases(),
            'locationProfiles' => $this->locationProfiles(),
            'profileLabels' => $this->profileLabels(),
            'metrics' => EnvironmentalObservation::query()->distinct()->orderBy('metric')->pluck('metric'),
            'sources' => EnvironmentalSource::query()->orderBy('priority')->orderBy('name')->get(),
            'summaries' => $this->summaryQuery($filters)->paginate(25, ['*'], 'summaries_page')->withQueryString(),
            'observations' => $this->observationQuery($filters)->paginate(50, ['*'], 'observations_page')->withQueryString(),
            'payloads' => $this->payloadQuery($filters)->paginate(10, ['*'], 'payloads_page')->withQueryString(),
        ]);
    }

    /**
     * @param  array{from: string, to: string, location_profile: string, location_type: ?string, source_id: ?int, metric: ?string, status: ?string}  $filters
     * @return Builder<EnvironmentalDailySummary>
     */
    private function summaryQuery(array $filters): Builder
    {
        return EnvironmentalDailySummary::query()
            ->select('environmental_daily_summaries.*')
            ->addSelect([
                'observations_count' => EnvironmentalObservation::query()
                    ->selectRaw('count(*)')
                    ->whereColumn('environmental_observations.location_profile', 'environmental_daily_summaries.location_profile')
                    ->whereColumn('environmental_observations.observed_date', 'environmental_daily_summaries.observed_date')
                    ->when($filters['location_type'], fn (Builder $query, string $locationType) => $query->where('environmental_observations.location_type', $locationType))
                    ->when($filters['source_id'], fn (Builder $query, int $sourceId) => $query->where('environmental_observations.environmental_source_id', $sourceId))
                    ->when($filters['metric'], fn (Builder $query, string $metric) => $query->where('environmental_observations.metric', $metric)),
                'payloads_count' => EnvironmentalPayload::query()
                    ->selectRaw('count(*)')
                    ->whereColumn('environmental_payloads.location_profile', 'environmental_daily_summaries.location_profile')
                    ->whereColumn('environmental_payloads.observed_date', 'environmental_daily_summaries.observed_date')
                    ->when($filters['location_type'], fn (Builder $query, string $locationType) => $query->where('environmental_payloads.location_type', $locationType))
                    ->when($filters['source_id'], fn (Builder $query, int $sourceId) => $query->where('environmental_payloads.environmental_source_id', $sourceId))
                    ->when($filters['metric'], fn (Builder $query, string $metric) => $query->whereExists(function ($query) use ($metric): void {
                        $query->selectRaw('1')
                            ->from('environmental_observations')
                            ->whereColumn('environmental_observations.environmental_payload_id', 'environmental_payloads.id')
                            ->where('environmental_observations.metric', $metric);
                    })),
            ])
            ->where('location_profile', $filters['location_profile'])
            ->when($filters['location_type'], fn (Builder $query, string $locationType) => $query->where('location_type', $locationType))
            ->whereDate('observed_date', '>=', $filters['from'])
            ->whereDate('observed_date', '<=', $filters['to'])
            ->when($filters['status'] === 'partial', fn (Builder $query) => $query->where('is_partial', true))
            ->when($filters['status'] === 'finalized', fn (Builder $query) => $query->where('is_partial', false))
            ->when($filters['source_id'] !== null || $filters['metric'] !== null, fn (Builder $query) => $query->whereExists(function ($query) use ($filters): void {
                $query->selectRaw('1')
                    ->from('environmental_observations')
                    ->whereColumn('environmental_observations.location_profile', 'environmental_daily_summaries.location_profile')
                    ->whereColumn('environmental_observations.observed_date', 'environmental_daily_summaries.observed_date')
                    ->when($filters['location_type'], fn ($query, string $locationType) => $query->where('environmental_observations.location_type', $locationType))
                    ->when($filters['source_id'], fn ($query, int $sourceId) => $query->where('environmental_observations.environmental_source_id', $sourceId))
                    ->when($filters['metric'], fn ($query, string $metric) => $query->where('environmental_observations.metric', $metric));
            }))
            ->latest('observed_date');
    }

    /**
     * @param  array{from: string, to: string, location_profile: string, location_type: ?string, source_id: ?int, metric: ?string, status: ?string}  $filters
     * @return Builder<EnvironmentalObservation>
     */
    private function observationQuery(array $filters): Builder
    {
        return EnvironmentalObservation::query()
            ->with(['environmentalSource', 'environmentalPayload'])
            ->where('location_profile', $filters['location_profile'])
            ->when($filters['location_type'], fn (Builder $query, string $locationType) => $query->where('location_type', $locationType))
            ->whereDate('observed_date', '>=', $filters['from'])
            ->whereDate('observed_date', '<=', $filters['to'])
            ->when($filters['source_id'], fn (Builder $query, int $sourceId) => $query->where('environmental_source_id', $sourceId))
            ->when($filters['metric'], fn (Builder $query, string $metric) => $query->where('metric', $metric))
            ->when($filters['status'], fn (Builder $query, string $status) => $query->whereExists(function ($query) use ($filters, $status): void {
                $query->selectRaw('1')
                    ->from('environmental_daily_summaries')
                    ->whereColumn('environmental_daily_summaries.location_profile', 'environmental_observations.location_profile')
                    ->whereColumn('environmental_daily_summaries.observed_date', 'environmental_observations.observed_date')
                    ->when($filters['location_type'], fn ($query, string $locationType) => $query->where('environmental_daily_summaries.location_type', $locationType))
                    ->where('environmental_daily_summaries.is_partial', $status === 'partial');
            }))
            ->latest('observed_at')
            ->latest('id');
    }

    /**
     * @param  array{from: string, to: string, location_profile: string, location_type: ?string, source_id: ?int, metric: ?string, status: ?string}  $filters
     * @return Builder<EnvironmentalPayload>
     */
    private function payloadQuery(array $filters): Builder
    {
        return EnvironmentalPayload::query()
            ->with('environmentalSource')
            ->where('location_profile', $filters['location_profile'])
            ->when($filters['location_type'], fn (Builder $query, string $locationType) => $query->where('location_type', $locationType))
            ->whereDate('observed_date', '>=', $filters['from'])
            ->whereDate('observed_date', '<=', $filters['to'])
            ->when($filters['source_id'], fn (Builder $query, int $sourceId) => $query->where('environmental_source_id', $sourceId))
            ->when($filters['metric'], fn (Builder $query, string $metric) => $query->whereExists(function ($query) use ($metric): void {
                $query->selectRaw('1')
                    ->from('environmental_observations')
                    ->whereColumn('environmental_observations.environmental_payload_id', 'environmental_payloads.id')
                    ->where('environmental_observations.metric', $metric);
            }))
            ->when($filters['status'], fn (Builder $query, string $status) => $query->whereExists(function ($query) use ($filters, $status): void {
                $query->selectRaw('1')
                    ->from('environmental_daily_summaries')
                    ->whereColumn('environmental_daily_summaries.location_profile', 'environmental_payloads.location_profile')
                    ->whereColumn('environmental_daily_summaries.observed_date', 'environmental_payloads.observed_date')
                    ->when($filters['location_type'], fn ($query, string $locationType) => $query->where('environmental_daily_summaries.location_type', $locationType))
                    ->where('environmental_daily_summaries.is_partial', $status === 'partial');
            }))
            ->latest('observed_date')
            ->latest('fetched_at');
    }

    private function locationProfiles(): Collection
    {
        return EnvironmentalSource::query()
            ->distinct()
            ->orderBy('location_profile')
            ->pluck('location_profile')
            ->merge(EnvironmentalDailySummary::query()->distinct()->orderBy('location_profile')->pluck('location_profile'))
            ->filter()
            ->unique()
            ->values();
    }

    /** @return array<string, string> */
    private function profileLabels(): array
    {
        return collect(config('fish.conditions.profiles', []))
            ->mapWithKeys(fn (array $profile, string $slug): array => [
                $slug => (string) ($profile['label'] ?? str($slug)->replace('_', ' ')->headline()),
            ])
            ->all();
    }
}
