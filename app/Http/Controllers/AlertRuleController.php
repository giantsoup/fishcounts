<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAlertRuleRequest;
use App\Http\Requests\UpdateAlertRuleRequest;
use App\Models\AlertRule;
use App\Models\Boat;
use App\Models\Landing;
use App\Models\Region;
use App\Models\Species;
use App\Models\TripType;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class AlertRuleController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', AlertRule::class);

        return view('alert-rules.index', [
            'rules' => AlertRule::query()
                ->with(['species', 'regions', 'tripTypes'])
                ->whereBelongsTo($request->user())
                ->latest()
                ->paginate(20),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', AlertRule::class);

        return view('alert-rules.form', $this->formData());
    }

    public function store(StoreAlertRuleRequest $request): RedirectResponse
    {
        $rule = DB::transaction(function () use ($request): AlertRule {
            $rule = $request->user()->alertRules()->create($this->ruleAttributes($request->validated()));
            $this->syncFilters($rule, $request->validated());

            return $rule;
        });

        return redirect()->route('alert-rules.edit', $rule)->with('status', 'Alert rule created.');
    }

    public function edit(AlertRule $alertRule): View
    {
        $this->authorize('update', $alertRule);

        return view('alert-rules.form', [
            ...$this->formData(),
            'rule' => $alertRule->load(['regions', 'tripTypes', 'landings', 'boats']),
        ]);
    }

    public function update(UpdateAlertRuleRequest $request, AlertRule $alertRule): RedirectResponse
    {
        DB::transaction(function () use ($request, $alertRule): void {
            $alertRule->update($this->ruleAttributes($request->validated()));
            $this->syncFilters($alertRule, $request->validated());
        });

        return redirect()->route('alert-rules.edit', $alertRule)->with('status', 'Alert rule updated.');
    }

    public function destroy(AlertRule $alertRule): RedirectResponse
    {
        $this->authorize('delete', $alertRule);
        $alertRule->delete();

        return redirect()->route('alert-rules.index')->with('status', 'Alert rule deleted.');
    }

    /** @return array<string, mixed> */
    private function formData(): array
    {
        return [
            'rule' => new AlertRule,
            'species' => Species::query()->where('is_active', true)->orderBy('name')->get(),
            'regions' => Region::query()->where('is_active', true)->orderBy('name')->get(),
            'tripTypes' => TripType::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(),
            'landings' => Landing::query()->where('is_active', true)->orderBy('name')->get(),
            'boats' => Boat::query()->where('is_active', true)->orderBy('name')->get(),
        ];
    }

    /** @param array<string, mixed> $validated */
    private function ruleAttributes(array $validated): array
    {
        return Arr::only($validated, [
            'name',
            'species_id',
            'is_enabled',
            'minimum_score',
            'minimum_total_count',
            'minimum_count_per_angler',
            'trend_window_days',
            'baseline_window_days',
            'email_enabled',
            'discord_enabled',
            'include_in_weekly_digest',
        ]);
    }

    /** @param array<string, mixed> $validated */
    private function syncFilters(AlertRule $rule, array $validated): void
    {
        $rule->regions()->sync($validated['region_ids']);
        $rule->tripTypes()->sync($validated['trip_type_ids']);
        $rule->landings()->sync($validated['landing_ids'] ?? []);
        $rule->boats()->sync($validated['boat_ids'] ?? []);
    }
}
