<?php

namespace App\Http\Controllers\Admin;

use App\Enums\BackfillReparseRunStatus;
use App\Enums\BackfillRunStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBackfillRunRequest;
use App\Jobs\BackfillRunJob;
use App\Jobs\ReparseBackfillRunJob;
use App\Models\BackfillReparseRun;
use App\Models\BackfillRun;
use App\Models\ScrapeSource;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BackfillController extends Controller
{
    public function index(): View
    {
        $this->authorize('viewAny', BackfillRun::class);

        return view('admin.backfills.index', [
            'backfills' => $this->backfills(),
            'hasActiveBackfills' => $this->hasActiveBackfills(),
        ]);
    }

    public function poll(): JsonResponse
    {
        $this->authorize('viewAny', BackfillRun::class);

        $backfills = $this->backfills();

        return response()->json([
            'html' => view('admin.backfills._list', ['backfills' => $backfills])->render(),
            'has_active_backfills' => $this->hasActiveBackfills(),
            'refreshed_at' => now()->toISOString(),
        ]);
    }

    public function pollReparse(BackfillRun $backfillRun): JsonResponse
    {
        $this->authorize('view', $backfillRun);

        $data = $this->reparseViewData($backfillRun);

        return response()->json([
            'html' => view('admin.backfills._reparse', [
                'backfill' => $backfillRun,
                ...$data,
            ])->render(),
            'has_active_reparse' => $data['hasActiveReparseRun'],
            'refreshed_at' => now()->toISOString(),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', BackfillRun::class);

        return view('admin.backfills.create', [
            'sources' => ScrapeSource::query()->where('is_enabled', true)->orderBy('priority')->get(),
        ]);
    }

    public function show(BackfillRun $backfillRun): View
    {
        $this->authorize('view', $backfillRun);

        $reparseData = $this->reparseViewData($backfillRun);

        return view('admin.backfills.show', [
            'backfill' => $backfillRun->loadCount([
                'items',
                'items as pending_items_count' => fn ($query) => $query->where('status', BackfillRunStatus::Pending->value),
                'items as running_items_count' => fn ($query) => $query->where('status', BackfillRunStatus::Running->value),
                'items as succeeded_items_count' => fn ($query) => $query->where('status', BackfillRunStatus::Succeeded->value),
                'items as failed_items_count' => fn ($query) => $query->where('status', BackfillRunStatus::Failed->value),
                'items as unavailable_items_count' => fn ($query) => $query->where('status', BackfillRunStatus::Unavailable->value),
            ]),
            'items' => $backfillRun->items()
                ->with(['scrapeSource', 'scrapeRun', 'rawScrapePayload'])
                ->orderBy('target_date')
                ->orderBy('scrape_source_id')
                ->paginate(100)
                ->withPath(route('admin.backfills.show', $backfillRun)),
            ...$reparseData,
        ]);
    }

    public function store(StoreBackfillRunRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $from = CarbonImmutable::parse($validated['from_date']);
        $to = CarbonImmutable::parse($validated['to_date']);
        $totalItems = ($from->diffInDays($to) + 1) * count($validated['source_ids']);

        $backfill = DB::transaction(function () use ($request, $validated, $from, $to, $totalItems): BackfillRun {
            $backfill = BackfillRun::query()->create([
                'created_by_user_id' => $request->user()->id,
                'from_date' => $from->toDateString(),
                'to_date' => $to->toDateString(),
                'source_ids' => $validated['source_ids'],
                'batch_size_days' => $validated['batch_size_days'],
                'total_days' => $totalItems,
            ]);

            foreach ($validated['source_ids'] as $sourceId) {
                for ($date = $from; $date->lte($to); $date = $date->addDay()) {
                    $backfill->items()->create([
                        'scrape_source_id' => $sourceId,
                        'target_date' => $date->toDateString(),
                    ]);
                }
            }

            return $backfill;
        });

        BackfillRunJob::dispatch($backfill->id);

        return redirect()->route('admin.backfills.index')->with('status', "Backfill {$backfill->id} queued.");
    }

    public function reparse(Request $request, BackfillRun $backfillRun): RedirectResponse
    {
        $this->authorize('update', $backfillRun);

        $activeReparseRun = $backfillRun->reparseRuns()
            ->whereIn('status', [BackfillReparseRunStatus::Pending->value, BackfillReparseRunStatus::Running->value])
            ->latest()
            ->first();

        if ($activeReparseRun !== null) {
            return redirect()
                ->route('admin.backfills.show', $backfillRun)
                ->with('status', "Backfill reparse #{$activeReparseRun->id} is already {$activeReparseRun->status->value}.");
        }

        $payloadCount = $this->reparseablePayloadCount($backfillRun);
        $reparseRun = BackfillReparseRun::query()->create([
            'backfill_run_id' => $backfillRun->id,
            'created_by_user_id' => $request->user()?->id,
            'status' => $payloadCount > 0 ? BackfillReparseRunStatus::Pending : BackfillReparseRunStatus::Succeeded,
            'total_payloads' => $payloadCount,
            'queued_payloads' => 0,
            'started_at' => $payloadCount === 0 ? now() : null,
            'finished_at' => $payloadCount === 0 ? now() : null,
        ]);

        if ($payloadCount === 0) {
            return redirect()
                ->route('admin.backfills.show', $backfillRun)
                ->with('status', 'No saved raw payloads are available to reparse for this backfill.');
        }

        ReparseBackfillRunJob::dispatch($reparseRun->id);

        return redirect()
            ->route('admin.backfills.show', $backfillRun)
            ->with('status', "Backfill reparse #{$reparseRun->id} queued for {$payloadCount} saved payload(s).");
    }

    public function cancel(BackfillRun $backfillRun): RedirectResponse
    {
        $this->authorize('update', $backfillRun);

        $backfillRun->update([
            'status' => BackfillRunStatus::Cancelled,
            'cancel_requested_at' => now(),
        ]);

        return redirect()->route('admin.backfills.index')->with('status', 'Backfill cancellation requested.');
    }

    public function pause(BackfillRun $backfillRun): RedirectResponse
    {
        $this->authorize('update', $backfillRun);

        if (in_array($backfillRun->status, [BackfillRunStatus::Pending, BackfillRunStatus::Running], true)) {
            $backfillRun->update([
                'status' => BackfillRunStatus::Paused,
                'pause_requested_at' => now(),
            ]);
        }

        return redirect()->route('admin.backfills.index')->with('status', 'Backfill paused.');
    }

    public function resume(BackfillRun $backfillRun): RedirectResponse
    {
        $this->authorize('update', $backfillRun);

        if ($backfillRun->status === BackfillRunStatus::Paused) {
            $backfillRun->update([
                'status' => BackfillRunStatus::Running,
                'pause_requested_at' => null,
                'finished_at' => null,
                'error_message' => null,
            ]);

            BackfillRunJob::dispatch($backfillRun->id);
        }

        return redirect()->route('admin.backfills.index')->with('status', 'Backfill resumed.');
    }

    public function retryFailed(BackfillRun $backfillRun): RedirectResponse
    {
        $this->authorize('update', $backfillRun);

        $backfillRun->items()
            ->whereIn('status', [BackfillRunStatus::Failed->value, BackfillRunStatus::Unavailable->value])
            ->update([
                'status' => BackfillRunStatus::Pending,
                'scrape_run_id' => null,
                'raw_scrape_payload_id' => null,
                'started_at' => null,
                'finished_at' => null,
                'error_message' => null,
            ]);

        $backfillRun->update([
            'status' => BackfillRunStatus::Running,
            'finished_at' => null,
            'error_message' => null,
        ]);

        BackfillRunJob::dispatch($backfillRun->id);

        return redirect()->route('admin.backfills.index')->with('status', 'Failed and unavailable dates were re-queued.');
    }

    /** @return LengthAwarePaginator<int, BackfillRun> */
    private function backfills(): LengthAwarePaginator
    {
        return BackfillRun::query()
            ->withCount([
                'items',
                'items as pending_items_count' => fn ($query) => $query->where('status', BackfillRunStatus::Pending->value),
                'items as running_items_count' => fn ($query) => $query->where('status', BackfillRunStatus::Running->value),
                'items as succeeded_items_count' => fn ($query) => $query->where('status', BackfillRunStatus::Succeeded->value),
                'items as failed_items_count' => fn ($query) => $query->where('status', BackfillRunStatus::Failed->value),
                'items as unavailable_items_count' => fn ($query) => $query->where('status', BackfillRunStatus::Unavailable->value),
            ])
            ->with(['items' => fn ($query) => $query->with('scrapeSource')->whereIn('status', [BackfillRunStatus::Failed->value, BackfillRunStatus::Unavailable->value])->latest('target_date')])
            ->latest()
            ->paginate(25)
            ->withPath(route('admin.backfills.index'));
    }

    private function hasActiveBackfills(): bool
    {
        return BackfillRun::query()
            ->whereIn('status', [BackfillRunStatus::Pending->value, BackfillRunStatus::Running->value])
            ->exists();
    }

    private function reparseablePayloadCount(BackfillRun $backfillRun): int
    {
        return $backfillRun->items()
            ->whereNotNull('raw_scrape_payload_id')
            ->distinct()
            ->count('raw_scrape_payload_id');
    }

    /** @return array{latestReparseRun: BackfillReparseRun|null, reparseablePayloadCount: int, hasActiveReparseRun: bool} */
    private function reparseViewData(BackfillRun $backfillRun): array
    {
        $latestReparseRun = $backfillRun->reparseRuns()->latest()->first();

        return [
            'latestReparseRun' => $latestReparseRun,
            'reparseablePayloadCount' => $this->reparseablePayloadCount($backfillRun),
            'hasActiveReparseRun' => $latestReparseRun !== null && in_array($latestReparseRun->status, [BackfillReparseRunStatus::Pending, BackfillReparseRunStatus::Running], true),
        ];
    }
}
