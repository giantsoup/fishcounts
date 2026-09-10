<?php

namespace App\Console\Commands;

use App\Models\TripReport;
use App\Services\Parsing\TripReportNormalizer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

#[Signature('fish:deduplicate-date {date : Date to deduplicate, YYYY-MM-DD} {--apply : Refresh primary-report selection for this date}')]
#[Description('Preview or refresh primary reports for a date without reparsing, scraping, or deleting source evidence.')]
class DeduplicateDateCommand extends Command
{
    public function handle(TripReportNormalizer $normalizer): int
    {
        $date = (string) $this->argument('date');
        $validator = Validator::make(['date' => $date], ['date' => ['required', 'date_format:Y-m-d']]);

        if ($validator->fails()) {
            $this->error('Date must be a valid calendar date in YYYY-MM-DD format.');

            return self::FAILURE;
        }

        $primaryIds = $normalizer->previewPrimaryReportIds($date);
        $reports = TripReport::query()->whereDate('trip_date', $date)->with('source')->orderBy('id')->get();
        $changes = $reports->filter(fn (TripReport $report): bool => $report->is_deduped_primary !== in_array($report->id, $primaryIds, true));

        $this->info("Preview for {$date}: {$reports->count()} stored reports; ".count($primaryIds)." primary reports; {$changes->count()} change(s).");
        if ($changes->isNotEmpty()) {
            $this->table(['Report', 'Source', 'Boat', 'Trip', 'Primary after cleanup'], $changes->map(fn (TripReport $report): array => [
                $report->id, $report->source->name, $report->raw_boat_name, $report->raw_trip_type,
                in_array($report->id, $primaryIds, true) ? 'Yes' : 'No',
            ])->all());
        }

        if (! $this->option('apply')) {
            $this->info('Preview only. Run with --apply to refresh primary reports.');

            return self::SUCCESS;
        }

        $normalizer->refreshPrimaryReports($date);
        $this->info("Refreshed primary reports for {$date}. Source reports and catches were preserved.");

        return self::SUCCESS;
    }
}
