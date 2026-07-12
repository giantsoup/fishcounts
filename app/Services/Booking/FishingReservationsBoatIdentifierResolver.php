<?php

namespace App\Services\Booking;

use App\Models\Boat;
use App\Models\Landing;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class FishingReservationsBoatIdentifierResolver
{
    /** @var array<string, array<string, string>> */
    private array $identifiersByUrl = [];

    /** @var array<string, string> */
    private array $failureByUrl = [];

    public function resolve(Boat $boat, Landing $landing): ?string
    {
        try {
            $identifiers = $this->identifiersForLanding($landing);

            if ($identifiers === []) {
                throw new RuntimeException('No boat identifiers were found on the provider page.');
            }
        } catch (Throwable $throwable) {
            Log::warning('FishingReservations boat identifier lookup failed; using the saved identifier.', [
                'boat_id' => $boat->getKey(),
                'landing_id' => $landing->getKey(),
                'error' => $throwable->getMessage(),
            ]);

            return filled($boat->booking_provider_identifier)
                ? (string) $boat->booking_provider_identifier
                : null;
        }

        $providerIdentifier = $this->identifierForBoatName($identifiers, $boat->name);

        if ($providerIdentifier !== null
            && $boat->exists
            && $boat->booking_provider_identifier !== $providerIdentifier) {
            $boat->update(['booking_provider_identifier' => $providerIdentifier]);
        }

        return $providerIdentifier;
    }

    /** @return array<string, string> */
    public function identifiersForLanding(Landing $landing): array
    {
        $url = (string) $landing->booking_base_url;

        if ($url === '') {
            return [];
        }

        if (isset($this->identifiersByUrl[$url])) {
            return $this->identifiersByUrl[$url];
        }

        if (isset($this->failureByUrl[$url])) {
            throw new RuntimeException($this->failureByUrl[$url]);
        }

        try {
            $response = Http::timeout(10)
                ->connectTimeout(5)
                ->retry([250, 750], throw: false)
                ->get($url);

            $response->throw();
        } catch (Throwable $throwable) {
            $this->failureByUrl[$url] = $throwable->getMessage();

            throw $throwable;
        }

        return $this->identifiersByUrl[$url] = $this->parseIdentifiers($response->body());
    }

    /** @return array<string, string> */
    public function parseIdentifiers(string $html): array
    {
        $previousErrors = libxml_use_internal_errors(true);
        $document = new DOMDocument;
        $document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrors);

        $xpath = new DOMXPath($document);
        $identifiers = [];
        $labelsByFor = [];

        foreach ($xpath->query('//label[@for]') as $label) {
            if ($label instanceof DOMElement) {
                $labelsByFor[$label->getAttribute('for')] = Str::of($label->textContent)->squish()->toString();
            }
        }

        foreach ($xpath->query('//select[@name="boat_filter[]"]/option[@value]') as $option) {
            if ($option instanceof DOMElement) {
                $this->addIdentifier($identifiers, $option->textContent, $option->getAttribute('value'));
            }
        }

        foreach ($xpath->query('//input[@name="boat_filter[]" and @value]') as $input) {
            if (! $input instanceof DOMElement) {
                continue;
            }

            $this->addIdentifier(
                $identifiers,
                $labelsByFor[$input->getAttribute('id')] ?? '',
                $input->getAttribute('value'),
            );
        }

        return $identifiers;
    }

    /**
     * @param  array<string, string>  $identifiers
     */
    public function identifierForBoatName(array $identifiers, string $boatName): ?string
    {
        return $identifiers[$this->normalizeBoatName($boatName)] ?? null;
    }

    /**
     * @param  array<string, string>  $identifiers
     */
    private function addIdentifier(array &$identifiers, string $boatName, string $providerIdentifier): void
    {
        $boatName = Str::of($boatName)->squish()->toString();

        if ($providerIdentifier === '' || $providerIdentifier === '0' || $boatName === '') {
            return;
        }

        $identifiers[$this->normalizeBoatName($boatName)] = $providerIdentifier;
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
