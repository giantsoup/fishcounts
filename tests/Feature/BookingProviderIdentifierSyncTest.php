<?php

namespace Tests\Feature;

use App\Enums\BookingProvider;
use App\Models\Boat;
use App\Models\Landing;
use App\Models\Region;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BookingProviderIdentifierSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_syncs_fishing_reservations_select_provider_identifiers(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://fishermanslanding.fishingreservations.net/resos/' => Http::response(<<<'HTML'
                <form>
                    <select name="boat_filter[]" multiple="multiple">
                        <option value="70">Dolphin</option>
                        <option value="201">Pacific Queen</option>
                    </select>
                </form>
                HTML),
        ]);

        $region = Region::query()->create(['name' => 'San Diego', 'slug' => 'san-diego']);
        $landing = Landing::query()->create([
            'region_id' => $region->id,
            'name' => 'Fisherman\'s Landing',
            'slug' => 'fishermans-landing',
            'booking_provider' => BookingProvider::FishingReservations,
            'booking_base_url' => 'https://fishermanslanding.fishingreservations.net/resos/',
        ]);
        $pacificQueen = Boat::query()->create(['landing_id' => $landing->id, 'name' => 'Pacific Queen', 'slug' => 'pacific-queen']);
        $dolphin = Boat::query()->create(['landing_id' => $landing->id, 'name' => 'Dolphin', 'slug' => 'dolphin']);

        $this->artisan('booking:sync-provider-identifiers')
            ->expectsOutput('Booking provider identifiers synced. 2 boats updated.')
            ->assertSuccessful();

        $this->assertSame('201', $pacificQueen->fresh()->booking_provider_identifier);
        $this->assertSame('70', $dolphin->fresh()->booking_provider_identifier);
    }

    public function test_command_syncs_fishing_reservations_checkbox_provider_identifiers(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://pointloma.fishingreservations.net/sales/' => Http::response(<<<'HTML'
                <table>
                    <tr>
                        <td class="chk"><input id="boat-filter-64" name="boat_filter[]" type="checkbox" value="64"></td>
                        <td class="filter"><label for="boat-filter-64">Daily Double</label></td>
                    </tr>
                    <tr>
                        <td class="chk"><input id="boat-filter-181" name="boat_filter[]" type="checkbox" value="181"></td>
                        <td class="filter"><label for="boat-filter-181">New Lo-An</label></td>
                    </tr>
                </table>
                HTML),
        ]);

        $region = Region::query()->create(['name' => 'San Diego', 'slug' => 'san-diego']);
        $landing = Landing::query()->create([
            'region_id' => $region->id,
            'name' => 'Point Loma Sportfishing',
            'slug' => 'point-loma-sportfishing',
            'booking_provider' => BookingProvider::FishingReservations,
            'booking_base_url' => 'https://pointloma.fishingreservations.net/sales/',
        ]);
        $newLoAn = Boat::query()->create(['landing_id' => $landing->id, 'name' => 'New Lo-An', 'slug' => 'new-lo-an']);
        $dailyDouble = Boat::query()->create(['landing_id' => $landing->id, 'name' => 'Daily Double', 'slug' => 'daily-double']);

        $this->artisan('booking:sync-provider-identifiers')
            ->expectsOutput('Booking provider identifiers synced. 2 boats updated.')
            ->assertSuccessful();

        $this->assertSame('181', $newLoAn->fresh()->booking_provider_identifier);
        $this->assertSame('64', $dailyDouble->fresh()->booking_provider_identifier);
    }
}
