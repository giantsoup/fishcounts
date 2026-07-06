<?php

namespace Database\Seeders;

use App\Enums\BookingProvider;
use App\Models\Landing;
use App\Models\Region;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class LandingSeeder extends Seeder
{
    public function run(): void
    {
        $region = Region::query()->where('slug', 'san-diego')->firstOrFail();

        $landings = [
            [
                'name' => "Fisherman's Landing",
                'website_url' => 'https://www.fishermanslanding.com',
                'booking_provider' => BookingProvider::FishingReservations,
                'booking_base_url' => 'https://fishermanslanding.fishingreservations.net/resos/',
            ],
            [
                'name' => 'Seaforth Sportfishing',
                'website_url' => 'https://www.seaforthlanding.com',
                'booking_provider' => BookingProvider::FishingReservations,
                'booking_base_url' => 'https://seaforth.fishingreservations.net/sales/',
            ],
            [
                'name' => 'H&M Landing',
                'website_url' => 'https://www.hmlanding.com',
                'booking_provider' => BookingProvider::HmLanding,
                'booking_base_url' => 'https://www.hmlanding.com',
            ],
            [
                'name' => 'Point Loma Sportfishing',
                'website_url' => 'https://www.pointlomasportfishing.com',
                'booking_provider' => BookingProvider::FishingReservations,
                'booking_base_url' => 'https://pointloma.fishingreservations.net/sales/',
            ],
        ];

        foreach ($landings as $landing) {
            Landing::query()->updateOrCreate(
                ['slug' => Str::slug($landing['name'])],
                [
                    'region_id' => $region->id,
                    'name' => $landing['name'],
                    'website_url' => $landing['website_url'],
                    'booking_provider' => $landing['booking_provider'],
                    'booking_base_url' => $landing['booking_base_url'],
                    'is_active' => true,
                ],
            );
        }
    }
}
