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

        return view('admin.backfills.index', ['backfills' => BackfillRun::query()->latest()->paginate(25)]);
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
        $totalDays = $from->diffInDays($to) + 1;

        $backfill = DB::transaction(function () use ($request, $validated, $from, $to, $totalDays): BackfillRun {
            $backfill = BackfillRun::query()->create([
                'created_by_user_id' => $request->user()->id,
                'from_date' => $from,
                'to_date' => $to,
                'source_ids' => $validated['source_ids'],
                'batch_size_days' => $validated['batch_size_days'],
                'total_days' => $totalDays,
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
}
