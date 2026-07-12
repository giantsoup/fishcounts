<?php

namespace App\Console\Commands;

use App\Enums\BookingProvider;
use App\Models\Landing;
use App\Services\Booking\FishingReservationsBoatIdentifierResolver;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('booking:sync-provider-identifiers')]
#[Description('Sync external booking provider boat identifiers.')]
class SyncBookingProviderIdentifiersCommand extends Command
{
    public function __construct(
        private readonly FishingReservationsBoatIdentifierResolver $identifierResolver,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $updated = 0;

        Landing::query()
            ->where('booking_provider', BookingProvider::FishingReservations->value)
            ->whereNotNull('booking_base_url')
            ->with('boats')
            ->orderBy('name')
            ->get()
            ->each(function (Landing $landing) use (&$updated): void {
                $updated += $this->syncLanding($landing);
            });

        $this->info("Booking provider identifiers synced. {$updated} boats updated.");

        return self::SUCCESS;
    }

    private function syncLanding(Landing $landing): int
    {
        $providerBoats = $this->providerBoats($landing);
        $updated = 0;

        foreach ($landing->boats as $boat) {
            $providerIdentifier = $this->identifierResolver->identifierForBoatName($providerBoats, $boat->name);

            if ($providerIdentifier === null || $boat->booking_provider_identifier === $providerIdentifier) {
                continue;
            }

            $boat->update(['booking_provider_identifier' => $providerIdentifier]);
            $updated++;
        }

        return $updated;
    }

    /** @return array<string, string> */
    private function providerBoats(Landing $landing): array
    {
        try {
            return $this->identifierResolver->identifiersForLanding($landing);
        } catch (Throwable $throwable) {
            $this->warn("Unable to sync {$landing->name}: {$throwable->getMessage()}");

            return [];
        }
    }
}
