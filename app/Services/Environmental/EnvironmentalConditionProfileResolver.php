<?php

namespace App\Services\Environmental;

use App\Models\AlertRule;

class EnvironmentalConditionProfileResolver
{
    public function resolve(AlertRule $rule): string
    {
        $rule->loadMissing('species:id,environmental_location_profile');
        $speciesProfile = $rule->species?->environmental_location_profile;

        if (is_string($speciesProfile) && $this->profileExists($speciesProfile)) {
            return $speciesProfile;
        }

        $defaultProfile = (string) config('fish.conditions.location_profile', 'san_diego_bight');

        if ($this->profileExists($defaultProfile)) {
            return $defaultProfile;
        }

        return (string) (array_key_first(config('fish.conditions.profiles', [])) ?? $defaultProfile);
    }

    private function profileExists(string $profile): bool
    {
        return array_key_exists($profile, config('fish.conditions.profiles', []));
    }
}
