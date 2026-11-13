{{-- Abilities and expiry, shared by the create form and the rotate form. --}}
<fieldset>
    <legend class="block font-medium text-sm text-gray-700">Abilities</legend>

    <div class="mt-2 space-y-2">
        @foreach ($abilityOptions as $ability)
            <div>
                <x-form.checkbox
                    name="abilities[]"
                    value="{{ $ability->value }}"
                    :checked="in_array($ability->value, old('abilities', $selectedAbilities), true)"
                >
                    <span class="font-medium">{{ $ability->value }}</span>
                    <span class="text-gray-500">{{ $ability->description() }}</span>
                </x-form.checkbox>
            </div>
        @endforeach
    </div>

    <x-input-error :messages="$errors->get('abilities')" class="mt-2" />
    <x-input-error :messages="$errors->get('abilities.*')" class="mt-2" />
</fieldset>

<div>
    <x-input-label for="expires_in_days" value="Expires in" />
    <x-form.select id="expires_in_days" name="expires_in_days">
        @foreach ($expiryOptions as $days)
            <option value="{{ $days }}" @selected((int) old('expires_in_days', $defaultExpiryDays) === $days)>{{ $days }} days</option>
        @endforeach
    </x-form.select>
    <x-input-error :messages="$errors->get('expires_in_days')" class="mt-2" />
</div>
