<?php

namespace App\Actions\Parsing;

use App\DTOs\StartParserReparseRunResult;
use App\Enums\ParserReparseRunStatus;
use App\Jobs\DispatchParserReparseRunJob;
use App\Models\ParserReparseRun;
use App\Models\User;
use App\Services\Parsing\ParserReparsePlanner;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StartParserReparseRun
{
    public const LOCK_KEY = 'parser-reparse-run:start';

    public function __construct(private readonly ParserReparsePlanner $planner) {}

    public function handle(User $requester, ?int $sourceId = null, ?string $from = null, ?string $to = null, ?string $fingerprint = null): StartParserReparseRunResult
    {
        $result = Cache::lock(self::LOCK_KEY, 300)->block(15, function () use ($requester, $sourceId, $from, $to, $fingerprint): StartParserReparseRunResult {
            return DB::transaction(function () use ($requester, $sourceId, $from, $to, $fingerprint): StartParserReparseRunResult {
                $activeRun = ParserReparseRun::query()
                    ->whereIn('status', [ParserReparseRunStatus::Pending, ParserReparseRunStatus::Running])
                    ->lockForUpdate()
                    ->latest('id')
                    ->first();

                if ($activeRun !== null) {
                    return new StartParserReparseRunResult($activeRun, false);
                }

                $plan = $this->planner->preview($sourceId, $from, $to);
                if ($fingerprint !== null && ! hash_equals($plan['fingerprint'], $fingerprint)) {
                    throw ValidationException::withMessages(['fingerprint' => 'The affected data changed. Preview the selection again before starting.']);
                }
                $initialOpenErrors = $plan['open_errors'];
                $initialAliasErrors = $plan['alias_errors'];

                $run = ParserReparseRun::query()->create([
                    'requested_by_user_id' => $requester->getKey(),
                    'initial_open_errors' => $initialOpenErrors,
                    'initial_alias_errors' => $initialAliasErrors,
                    'initial_structural_errors' => $initialOpenErrors - $initialAliasErrors,
                    'initial_payloads' => $plan['payloads'],
                    'affected_dates' => $plan['dates'],
                ]);

                $run->items()->createMany($plan['items']->all());
                $totalItems = $run->items()->count();

                $run->update([
                    'status' => $totalItems === 0 ? ParserReparseRunStatus::Succeeded : ParserReparseRunStatus::Pending,
                    'total_items' => $totalItems,
                    'finished_at' => $totalItems === 0 ? now() : null,
                    'remaining_open_errors' => $totalItems === 0 ? $initialOpenErrors : null,
                    'remaining_alias_errors' => $totalItems === 0 ? $initialAliasErrors : null,
                    'remaining_structural_errors' => $totalItems === 0 ? $initialOpenErrors - $initialAliasErrors : null,
                ]);

                return new StartParserReparseRunResult($run->fresh(), true);
            }, attempts: 3);
        });

        if ($result->created && $result->run->status->isActive()) {
            DispatchParserReparseRunJob::dispatch($result->run->id)->afterCommit();
        }

        return $result;
    }
}
