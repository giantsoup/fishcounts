<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $landings = [
            'fishermans-landing' => [
                'website_url' => 'https://www.fishermanslanding.com',
                'booking_provider' => 'fishing_reservations',
                'booking_base_url' => 'https://fishermanslanding.fishingreservations.net/resos/',
            ],
            'seaforth-sportfishing' => [
                'website_url' => 'https://www.seaforthlanding.com',
                'booking_provider' => 'fishing_reservations',
                'booking_base_url' => 'https://seaforth.fishingreservations.net/sales/',
            ],
            'hm-landing' => [
                'website_url' => 'https://www.hmlanding.com',
                'booking_provider' => 'hm_landing',
                'booking_base_url' => 'https://www.hmlanding.com',
            ],
            'point-loma-sportfishing' => [
                'website_url' => 'https://www.pointlomasportfishing.com',
                'booking_provider' => 'fishing_reservations',
                'booking_base_url' => 'https://pointloma.fishingreservations.net/sales/',
            ],
        ];

        foreach ($landings as $slug => $metadata) {
            DB::table('landings')->where('slug', $slug)->update($metadata);
        }

        $providerIdentifiers = [
            'fishermans-landing' => [
                'dolphin' => '70',
                'pacific-queen' => '201',
            ],
            'point-loma-sportfishing' => [
                'daily-double' => '64',
                'mission-belle' => '214',
                'new-lo-an' => '181',
            ],
            'seaforth-sportfishing' => [
                'san-diego' => '248',
            ],
        ];

        foreach ($providerIdentifiers as $landingSlug => $boats) {
            $landingId = DB::table('landings')->where('slug', $landingSlug)->value('id');

            if ($landingId === null) {
                continue;
            }

            foreach ($boats as $boatSlug => $providerIdentifier) {
                DB::table('boats')
                    ->where('landing_id', $landingId)
                    ->where('slug', $boatSlug)
                    ->update(['booking_provider_identifier' => $providerIdentifier]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $providerIdentifiers = [
            'fishermans-landing' => ['dolphin' => '70', 'pacific-queen' => '201'],
            'point-loma-sportfishing' => ['daily-double' => '64', 'mission-belle' => '214', 'new-lo-an' => '181'],
            'seaforth-sportfishing' => ['san-diego' => '248'],
        ];

        foreach ($providerIdentifiers as $landingSlug => $boats) {
            $landingId = DB::table('landings')->where('slug', $landingSlug)->value('id');

            if ($landingId === null) {
                continue;
            }

            foreach ($boats as $boatSlug => $providerIdentifier) {
                DB::table('boats')
                    ->where('landing_id', $landingId)
                    ->where('slug', $boatSlug)
                    ->where('booking_provider_identifier', $providerIdentifier)
                    ->update(['booking_provider_identifier' => null]);
            }
        }

        $landings = [
            'fishermans-landing' => ['https://www.fishermanslanding.com', 'fishing_reservations', 'https://fishermanslanding.fishingreservations.net/resos/'],
            'seaforth-sportfishing' => ['https://www.seaforthlanding.com', 'fishing_reservations', 'https://seaforth.fishingreservations.net/sales/'],
            'hm-landing' => ['https://www.hmlanding.com', 'hm_landing', 'https://www.hmlanding.com'],
            'point-loma-sportfishing' => ['https://www.pointlomasportfishing.com', 'fishing_reservations', 'https://pointloma.fishingreservations.net/sales/'],
        ];

        foreach ($landings as $slug => [$websiteUrl, $provider, $baseUrl]) {
            DB::table('landings')
                ->where('slug', $slug)
                ->where('website_url', $websiteUrl)
                ->where('booking_provider', $provider)
                ->where('booking_base_url', $baseUrl)
                ->update([
                    'website_url' => null,
                    'booking_provider' => null,
                    'booking_base_url' => null,
                ]);
        }
    }
};
