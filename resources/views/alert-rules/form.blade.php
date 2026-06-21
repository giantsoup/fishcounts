<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ $rule->exists ? 'Edit alert rule' : 'Create alert rule' }}</h2>
    </x-slot>

    @php
        $selectedRegions = old('region_ids', $rule->exists ? $rule->regions->pluck('id')->all() : []);
        $selectedTripTypes = old('trip_type_ids', $rule->exists ? $rule->tripTypes->pluck('id')->all() : []);
        $selectedLandings = old('landing_ids', $rule->exists ? $rule->landings->pluck('id')->all() : []);
        $selectedBoats = old('boat_ids', $rule->exists ? $rule->boats->pluck('id')->all() : []);
    @endphp

    <div class="py-8">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8 bg-white p-6 shadow sm:rounded-lg">
            @if (session('status'))
                <p class="mb-4 text-sm text-green-700">{{ session('status') }}</p>
            @endif

            <form method="POST" action="{{ $rule->exists ? route('alert-rules.update', $rule) : route('alert-rules.store') }}" class="space-y-6">
                @csrf
                @if ($rule->exists)
                    @method('PUT')
                @endif

                <div>
                    <x-input-label for="name" value="Name" />
                    <x-text-input id="name" name="name" class="mt-1 block w-full" :value="old('name', $rule->name)" required />
                    <x-input-error :messages="$errors->get('name')" class="mt-2" />
                </div>

                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <x-input-label for="species_id" value="Species" />
                        <x-form.select id="species_id" name="species_id">
                            @foreach ($species as $item)
                                <option value="{{ $item->id }}" @selected((int) old('species_id', $rule->species_id) === $item->id)>{{ $item->name }}</option>
                            @endforeach
                        </x-form.select>
                        <x-input-error :messages="$errors->get('species_id')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="minimum_score" value="Minimum score" />
                        <x-form.number id="minimum_score" name="minimum_score" min="0" max="100" :value="old('minimum_score', $rule->minimum_score ?? 70)" required />
                        <x-input-error :messages="$errors->get('minimum_score')" class="mt-2" />
                    </div>
                </div>

                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <x-input-label for="minimum_total_count" value="Minimum total count" />
                        <x-form.number id="minimum_total_count" name="minimum_total_count" min="0" :value="old('minimum_total_count', $rule->minimum_total_count)" />
                        <x-input-error :messages="$errors->get('minimum_total_count')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="minimum_count_per_angler" value="Minimum count per angler" />
                        <x-form.number id="minimum_count_per_angler" name="minimum_count_per_angler" step="0.01" min="0" :value="old('minimum_count_per_angler', $rule->minimum_count_per_angler)" decimal />
                        <x-input-error :messages="$errors->get('minimum_count_per_angler')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="trend_window_days" value="Trend window days" />
                        <x-form.number id="trend_window_days" name="trend_window_days" min="1" max="30" :value="old('trend_window_days', $rule->trend_window_days ?? 3)" required />
                        <x-input-error :messages="$errors->get('trend_window_days')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="baseline_window_days" value="Baseline window days" />
                        <x-form.number id="baseline_window_days" name="baseline_window_days" min="1" max="90" :value="old('baseline_window_days', $rule->baseline_window_days ?? 7)" required />
                        <x-input-error :messages="$errors->get('baseline_window_days')" class="mt-2" />
                    </div>
                </div>

                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <x-input-label value="Regions" />
                        <x-form.select name="region_ids[]" multiple placeholder="Select regions">
                            @foreach ($regions as $region)
                                <option value="{{ $region->id }}" @selected(in_array($region->id, array_map('intval', $selectedRegions), true))>{{ $region->name }}</option>
                            @endforeach
                        </x-form.select>
                        <x-input-error :messages="$errors->get('region_ids')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label value="Trip types" />
                        <x-form.select name="trip_type_ids[]" multiple placeholder="Select trip types">
                            @foreach ($tripTypes as $tripType)
                                <option value="{{ $tripType->id }}" @selected(in_array($tripType->id, array_map('intval', $selectedTripTypes), true))>{{ $tripType->name }}</option>
                            @endforeach
                        </x-form.select>
                        <x-input-error :messages="$errors->get('trip_type_ids')" class="mt-2" />
                    </div>
                </div>

                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <x-input-label value="Landings" />
                        <x-form.select name="landing_ids[]" multiple placeholder="Select landings">
                            @foreach ($landings as $landing)
                                <option value="{{ $landing->id }}" @selected(in_array($landing->id, array_map('intval', $selectedLandings), true))>{{ $landing->name }}</option>
                            @endforeach
                        </x-form.select>
                        <x-input-error :messages="$errors->get('landing_ids')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label value="Boats" />
                        <x-form.select name="boat_ids[]" multiple placeholder="Select boats">
                            @foreach ($boats as $boat)
                                <option value="{{ $boat->id }}" @selected(in_array($boat->id, array_map('intval', $selectedBoats), true))>{{ $boat->name }}</option>
                            @endforeach
                        </x-form.select>
                        <x-input-error :messages="$errors->get('boat_ids')" class="mt-2" />
                    </div>
                </div>

                <div class="flex flex-wrap gap-4 text-sm">
                    <input type="hidden" name="is_enabled" value="0">
                    <input type="hidden" name="email_enabled" value="0">
                    <input type="hidden" name="discord_enabled" value="0">
                    <input type="hidden" name="include_in_weekly_digest" value="0">
                    <x-form.checkbox name="is_enabled" value="1" :checked="old('is_enabled', $rule->is_enabled ?? true)">Enabled</x-form.checkbox>
                    <x-form.checkbox name="email_enabled" value="1" :checked="old('email_enabled', $rule->email_enabled ?? true)">Email</x-form.checkbox>
                    <x-form.checkbox name="discord_enabled" value="1" :checked="old('discord_enabled', $rule->discord_enabled ?? false)">Discord</x-form.checkbox>
                    <x-form.checkbox name="include_in_weekly_digest" value="1" :checked="old('include_in_weekly_digest', $rule->include_in_weekly_digest ?? true)">Weekly digest</x-form.checkbox>
                </div>

                <x-primary-button>Save</x-primary-button>
            </form>
        </div>
    </div>
</x-app-layout>
