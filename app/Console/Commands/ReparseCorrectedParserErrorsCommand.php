<?php

namespace App\Console\Commands;

use App\Models\ParserError;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('fish:reparse-corrected-parser-errors')]
#[Description('Synchronously reparse dates containing parser errors corrected by shipped aliases or parser rules.')]
class ReparseCorrectedParserErrorsCommand extends Command
{
    private const CORRECTED_RAW_VALUES = [
        '3/4 Day Local',
        'Assorted Rockfish',
        'Baracuda',
        'Bleufin Tuna',
        'Bluefin Amd',
        'Bluefin For Their',
        'Bluefin Tuna Along',
        'Bluefin Tuna On Day',
        'Bonito On Their Full Day Trip With',
        'Bontio',
        'C Alico Bass',
        'Cabazon',
        'Day Private Charter',
        'Day Today With',
        'Day Trip',
        'Dorado For Their Overnight Trip',
        'Full Day Local',
        'Full Day Trip With',
        'Halibut At',
        'Lb Setup With A',
        'Leopard Shark',
        'Ling Cod',
        'Lingcod Ona Full Day Trip',
        'Lings',
        'Of Their',
        'Oz Sinker And Live Bait',
        'Pounds',
        'Reds',
        'Returned With',
        'Rock Sole',
        'Sculpin For Their',
        'Vermilliion Reds',
        'Yellowtail For Their',
        'Yelowtail',
    ];

    public function handle(): int
    {
        $dates = ParserError::query()
            ->whereNotNull('target_date')
            ->whereIn('raw_value', self::CORRECTED_RAW_VALUES)
            ->orderBy('target_date')
            ->pluck('target_date')
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
