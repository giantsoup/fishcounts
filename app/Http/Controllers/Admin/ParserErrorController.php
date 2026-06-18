<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ParserError;
use App\Models\Species;
use App\Models\TripType;
use Illuminate\Contracts\View\View;

class ParserErrorController extends Controller
{
    public function __invoke(): View
    {
        return view('admin.parser-errors.index', [
            'errors' => ParserError::query()->with('scrapeSource')->latest()->paginate(25),
            'species' => Species::query()->where('is_active', true)->orderBy('name')->get(),
            'tripTypes' => TripType::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(),
        ]);
    }
}
