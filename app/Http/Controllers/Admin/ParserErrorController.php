<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ParserErrorResolutionType;
use App\Http\Controllers\Controller;
use App\Http\Requests\DismissParserErrorRequest;
use App\Models\Boat;
use App\Models\ParserError;
use App\Models\Species;
use App\Models\TripType;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ParserErrorController extends Controller
{
    public function __invoke(Request $request): View
    {
        $showAll = $request->string('status')->toString() === 'all';

        return view('admin.parser-errors.index', [
            'errors' => ParserError::query()
                ->with(['rawScrapePayload', 'resolver', 'scrapeSource'])
                ->when(! $showAll, fn ($query) => $query->whereNull('resolved_at'))
                ->latest()
                ->paginate(25)
                ->withQueryString(),
            'boats' => Boat::query()->where('is_active', true)->orderBy('name')->get(),
            'species' => Species::query()->where('is_active', true)->orderBy('name')->get(),
            'tripTypes' => TripType::query()->where('is_active', true)->orderedForDisplay()->get(),
            'showAll' => $showAll,
        ]);
    }

    public function dismiss(DismissParserErrorRequest $request, ParserError $parserError): RedirectResponse
    {
        $wasDismissed = ParserError::query()
            ->whereKey($parserError->getKey())
            ->whereNull('resolved_at')
            ->update([
                'resolved_at' => now(),
                'resolved_by_user_id' => $request->user()->getKey(),
                'resolution_type' => ParserErrorResolutionType::Dismissed->value,
            ]) === 1;

        return back()->with('status', $wasDismissed
            ? 'Parser error dismissed without creating an alias.'
            : 'Parser error was already resolved.');
    }
}
