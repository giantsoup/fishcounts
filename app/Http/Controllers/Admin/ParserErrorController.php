<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Boat;
use App\Models\ParserError;
use App\Models\Species;
use App\Models\TripType;
use Illuminate\Contracts\View\View;

class ParserErrorController extends Controller
{
    public function __invoke(): View
    {
        return view('admin.parser-errors.index', [
            'errors' => ParserError::query()->with(['rawScrapePayload', 'scrapeSource'])->latest()->paginate(25),
            'boats' => Boat::query()->where('is_active', true)->orderBy('name')->get(),
            'species' => Species::query()->where('is_active', true)->orderBy('name')->get(),
            'tripTypes' => TripType::query()->where('is_active', true)->orderedForDisplay()->get(),
        ]);
    }
}
