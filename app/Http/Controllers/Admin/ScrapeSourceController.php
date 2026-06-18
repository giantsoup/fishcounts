<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateScrapeSourceRequest;
use App\Models\ScrapeSource;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class ScrapeSourceController extends Controller
{
    public function index(): View
    {
        $this->authorize('viewAny', ScrapeSource::class);

        return view('admin.sources.index', ['sources' => ScrapeSource::query()->orderBy('priority')->get()]);
    }

    public function update(UpdateScrapeSourceRequest $request, ScrapeSource $source): RedirectResponse
    {
        $source->update(array_merge($request->validated(), [
            'is_enabled' => $request->boolean('is_enabled'),
            'supports_historical_dates' => $request->boolean('supports_historical_dates'),
            'supports_landing_filter' => $request->boolean('supports_landing_filter'),
        ]));

        return redirect()->route('admin.sources.index')->with('status', 'Source updated.');
    }
}
