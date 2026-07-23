<?php

namespace App\Console\Commands;

use App\Models\ParserError;
use App\Models\TripReport;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('fish:reparse-corrected-parser-errors')]
#[Description('Synchronously reparse dates containing known parser errors or malformed boat names corrected by shipped rules.')]
class ReparseCorrectedParserErrorsCommand extends Command
{
    private const CORRECTED_RAW_BOAT_NAMES = [
        'AM Dolphin',
        'Aztec also returned this afternoon from a',
        'Dolphin AM',
        'Dolphin AM 22 anglers',
        'Dolphin AM trip',
        'Dolphin PM trip',
        'Dolphin Twilight trip',
        'Dolphin Twiligiht trip last night',
        'Dolphin Twlight',
        'FridayThe Dolphin',
        'Lucky B caught 10 Yellowtail for',
        "New Seaforth's AM",
        "New Seaforth's Friday evening",
        "New Seaforth's Thursday evening",
        'Pacific Voyager returned this afternoon from a',
        'PM Dolphin',
        'Polaris Supreme finished their',
        'Polaris Supreme returned this morning from their',
        'San Diego wrapped up today',
        'Sea Watch Coronado islands',
        'The Constitution is returning this morning with 110 Bluefin Tuna (up to 200 lbs.) 22 Yellowtail, and 2 Dorado for',
        'The Dolphin (AM) caught 51 Calico Bass (released 100), 26 Bonito, 32 Rockfish, 6 Sheephead, and 5 Sandbass for',
        'The Dolphin (AM) trip caught 32 Calico Bass and 50 Released, 6 Sandbass, 30 Rockfish, 5 Sheephead and 1 White Seabass for',
        'The Dolphin (PM) trip caught 37 Rockfish, 2 Sculpin, 6 Sheephead, 4 Sandbass, 2 Calico (Kelp) Bass, and 1 Halibut for',
        'The Dolphin (PM) trip had 56 Calico Bass (200 released), 39 Bonito, 1 Cabazon,4 Sheephead, and 1 Yelowtail for',
        'The Dolphin AM',
        'The Dolphin PM',
        'The Dolphin PM returned with 18 Sand Bass, 6 Sculpin, 3 Rock sole for',
        'The Pacific Voyager returned this evening from a',
        'Tomahawk just',
        'Tribute finished up their reverse',
        'Tribute returned this afternoon from a reverse',
        'Voyager on a',
        'Voyager returned this evening from a',
        'Voyager returned today from a',
        'Wednesday San Diego',
    ];

    private const CORRECTED_RAW_VALUES = [
        '2 Day Am',
        '2 Day Pm',
        '3/4 Day Local',
        '4 Day',
        '6 Hour',
        'Assorted Rockfish',
        'Baracuda',
        'Baracuda On Their Fullday Trip',
        'Bleufin Tuna',
        'Bluefin Tuna For Day',
        'Bluefin Amd',
        'Bluefin For Their',
        'Bluefin Tuna Along',
        'Bluefin Tuna On Day',
        'Bonita',
        'Bonito On Their Full Day Trip With',
        'Bontio',
        'C Alico Bass',
        'Cakico Bass',
        'Cabazon',
        'Day Private Charter',
        'Day Today With',
        'Day Trip',
        'Day Trip Finished Up With',
        'Day Trips Through Friday',
        'Dorado For Their Overnight Trip',
        'Full Day Local',
        'Full Day Trip With',
        'Halibut At',
        'Halfmoon',
        'Hooks',
        'Lb Setup With A',
        'Leopard Shark',
        'Ling Cod',
        'Lingcod Ona Full Day Trip',
        'Lings',
        'Of A',
        'Of The Bluefin Were In The Class',
        'Of Their',
        'Oz Sinker And Live Bait',
        'Pacific Dawn just',
        'Pounds',
        'Pacific Mackerel',
        'Reds',
        'Returned With',
        'Rock Sole',
        'Sculpin For Their',
        'Vermilliion Reds',
        'Yellowtail For Their',
        'Yellowtail On A Three Day Trip',
        'Yelowtail',
    ];

    public function handle(): int
    {
        $parserErrorDates = ParserError::query()
            ->whereNull('resolved_at')
            ->whereNotNull('target_date')
            ->whereIn('raw_value', self::CORRECTED_RAW_VALUES)
            ->orderBy('target_date')
            ->pluck('target_date');
        $boatNameDates = TripReport::query()
            ->whereIn('raw_boat_name', self::CORRECTED_RAW_BOAT_NAMES)
            ->orderBy('trip_date')
            ->pluck('trip_date');
        $dates = $parserErrorDates
            ->concat($boatNameDates)
            ->map(fn (string $date): string => CarbonImmutable::parse($date)->toDateString())
            ->unique()
            ->values();

        if ($dates->isEmpty()) {
            $this->info('No corrected parser errors need reparsing.');

            return self::SUCCESS;
        }

        foreach ($dates as $date) {
            $exitCode = $this->call('fish:reparse-date', [
                'date' => $date,
                '--sync' => true,
            ]);

            if ($exitCode !== self::SUCCESS) {
                $this->error("Reparsing failed for {$date}.");

                return self::FAILURE;
            }
        }

        $this->info("Reparsed {$dates->count()} date(s) containing corrected parser errors.");

        return self::SUCCESS;
    }
}
