<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateScrapeSourceRequest;
use App\Models\ParserEngineChange;
use App\Models\ScrapeSource;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ScrapeSourceController extends Controller
{
    public function index(): View
    {
        $this->authorize('viewAny', ScrapeSource::class);

        return view('admin.sources.index', [
            'sources' => ScrapeSource::query()
                ->with('latestParserExecution.aiAttempts')
                ->orderBy('priority')
                ->get(),
        ]);
    }

    public function update(UpdateScrapeSourceRequest $request, ScrapeSource $source): RedirectResponse
    {
        DB::transaction(function () use ($request, $source): void {
            $validated = $request->safe()->except('parser_engine_change_reason');
            $lockedSource = ScrapeSource::query()->lockForUpdate()->findOrFail($source->id);
            $previousEngine = $lockedSource->parser_engine;
            $lockedSource->update(array_merge($validated, [
                'is_enabled' => $request->boolean('is_enabled'),
                'supports_historical_dates' => $request->boolean('supports_historical_dates'),
                'supports_landing_filter' => $request->boolean('supports_landing_filter'),
            ]));

            if ($lockedSource->parser_engine !== $previousEngine) {
                if ($request->string('parser_engine_change_reason')->trim()->isEmpty()) {
                    throw ValidationException::withMessages([
                        'parser_engine_change_reason' => 'A reason is required when changing parser engines.',
                    ]);
                }
                ParserEngineChange::query()->create([
                    'scrape_source_id' => $lockedSource->id,
                    'user_id' => $request->user()?->id,
                    'previous_engine' => $previousEngine,
                    'new_engine' => $lockedSource->parser_engine,
                    'reason' => $request->string('parser_engine_change_reason')->toString(),
                ]);
            }
        }, attempts: 3);

        return redirect()->route('admin.sources.index')->with('status', 'Source updated.');
    }
}
