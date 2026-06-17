<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ScrapeSource;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ScrapeSourceController extends Controller
{
    public function index(): View
    {
        $this->authorize('viewAny', ScrapeSource::class);

        return view('admin.sources.index', ['sources' => ScrapeSource::query()->orderBy('priority')->get()]);
    }

    public function update(Request $request, ScrapeSource $source): RedirectResponse
    {
        $this->authorize('update', $source);

        $source->update($request->validate([
            'priority' => ['required', 'integer', 'min:1', 'max:1000'],
            'is_enabled' => ['sometimes', 'boolean'],
            'rate_limit_seconds' => ['required', 'integer', 'min:1', 'max:3600'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]));

        return redirect()->route('admin.sources.index')->with('status', 'Source updated.');
    }
}
