<?php

namespace App\Http\Controllers\Admin;

use App\Enums\BackfillRunStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBackfillRunRequest;
use App\Jobs\BackfillRunJob;
use App\Models\BackfillRun;
use App\Models\ScrapeSource;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

class BackfillController extends Controller
{
    public function index(): View
    {
        $this->authorize('viewAny', BackfillRun::class);

        return view('admin.backfills.index', [
            'backfills' => BackfillRun::query()
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
                ->paginate(25),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', BackfillRun::class);

        return view('admin.backfills.create', [
            'sources' => ScrapeSource::query()->where('is_enabled', true)->orderBy('priority')->get(),
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
                'from_date' => $from,
                'to_date' => $to,
                'source_ids' => $validated['source_ids'],
                'batch_size_days' => $validated['batch_size_days'],
                'total_days' => $totalItems,
            ]);

            foreach ($validated['source_ids'] as $sourceId) {
                for ($date = $from; $date->lte($to); $date = $date->addDay()) {
                    $backfill->items()->create([
                        'scrape_source_id' => $sourceId,
                        'target_date' => $date,
                    ]);
                }
            }

            return $backfill;
        });

        BackfillRunJob::dispatch($backfill->id);

        return redirect()->route('admin.backfills.index')->with('status', "Backfill {$backfill->id} queued.");
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
}
