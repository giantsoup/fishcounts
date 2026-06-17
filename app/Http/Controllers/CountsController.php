<?php

namespace App\Http\Controllers;

use App\Models\SpeciesCount;
use Illuminate\Contracts\View\View;

class CountsController extends Controller
{
    public function __invoke(): View
    {
        return view('counts.index', [
            'counts' => SpeciesCount::query()
                ->with(['species', 'tripReport'])
                ->latest()
                ->paginate(25),
        ]);
    }
}
