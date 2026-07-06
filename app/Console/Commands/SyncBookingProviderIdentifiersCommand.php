<?php

namespace App\Console\Commands;

use App\Enums\BookingProvider;
use App\Models\Landing;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

#[Signature('booking:sync-provider-identifiers')]
#[Description('Sync external booking provider boat identifiers.')]
class SyncBookingProviderIdentifiersCommand extends Command
{
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
            $providerIdentifier = $providerBoats[$this->normalizeBoatName($boat->name)] ?? null;

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
            $html = Http::timeout(10)
                ->connectTimeout(5)
                ->retry(2, 250)
                ->get($landing->booking_base_url)
                ->throw()
                ->body();
        } catch (Throwable $throwable) {
            $this->warn("Unable to sync {$landing->name}: {$throwable->getMessage()}");

            return [];
        }

        return $this->boatOptions($html);
    }

    /** @return array<string, string> */
    private function boatOptions(string $html): array
    {
        $previousErrors = libxml_use_internal_errors(true);

        $document = new \DOMDocument;
        $document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrors);

        $xpath = new \DOMXPath($document);
        $options = [];
        $labelsByFor = [];

        /** @var \DOMElement $label */
        foreach ($xpath->query('//label[@for]') as $label) {
            $labelsByFor[$label->getAttribute('for')] = Str::of($label->textContent)->squish()->toString();
        }

        /** @var \DOMElement $option */
        foreach ($xpath->query('//select[@name="boat_filter[]"]/option[@value]') as $option) {
            $providerIdentifier = $option->getAttribute('value');
            $boatName = Str::of($option->textContent)->squish()->toString();

            if ($providerIdentifier === '' || $providerIdentifier === '0' || $boatName === '') {
                continue;
            }

            $options[$this->normalizeBoatName($boatName)] = $providerIdentifier;
        }

        /** @var \DOMElement $input */
        foreach ($xpath->query('//input[@name="boat_filter[]" and @value]') as $input) {
            $providerIdentifier = $input->getAttribute('value');
            $id = $input->getAttribute('id');
            $boatName = $labelsByFor[$id] ?? '';

            if ($providerIdentifier === '' || $providerIdentifier === '0' || $boatName === '') {
                continue;
            }

            $options[$this->normalizeBoatName($boatName)] = $providerIdentifier;
        }

        return $options;
    }

    private function normalizeBoatName(string $boatName): string
    {
        return Str::of($boatName)
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->squish()
            ->toString();
    }
}
