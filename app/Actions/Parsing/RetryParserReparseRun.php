<?php

namespace App\Actions\Parsing;

use App\Enums\ParserReparseItemStatus;
use App\Enums\ParserReparseRunStatus;
use App\Jobs\DispatchParserReparseRunJob;
use App\Models\ParserReparseRun;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class RetryParserReparseRun
{
    public function handle(ParserReparseRun $run): ParserReparseRun
    {
        $run = Cache::lock(StartParserReparseRun::LOCK_KEY, 300)->block(15, function () use ($run): ParserReparseRun {
            return DB::transaction(function () use ($run): ParserReparseRun {
                $run = ParserReparseRun::query()->lockForUpdate()->findOrFail($run->id);

                if ($run->status->isActive() || $run->failed_items === 0) {
                    return $run;
                }

                $anotherActiveRun = ParserReparseRun::query()
                    ->whereKeyNot($run->id)
                    ->whereIn('status', [ParserReparseRunStatus::Pending, ParserReparseRunStatus::Running])
                    ->lockForUpdate()
                    ->first();

                if ($anotherActiveRun !== null) {
                    return $run;
                }

                $run->items()
                    ->where('status', ParserReparseItemStatus::Failed)
                    ->update([
                        'status' => ParserReparseItemStatus::Pending,
                        'finished_at' => null,
                        'date_deduplicated_at' => null,
                        'error_message' => null,
                    ]);
                $run->update([
                    'status' => ParserReparseRunStatus::Pending,
                    'queued_items' => $run->total_items,
                    'failed_items' => 0,
                    'finished_at' => null,
                    'error_message' => null,
                ]);

                return $run->fresh();
            }, attempts: 3);
        });

        if ($run->status === ParserReparseRunStatus::Pending) {
            DispatchParserReparseRunJob::dispatch($run->id)->afterCommit();
        }

        return $run;
    }
}
