<?php

namespace App\Http\Controllers;

use App\Models\ScoreResult;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class ScoresController extends Controller
{
    public function __invoke(Request $request): View
    {
        return view('scores.index', [
            'scores' => ScoreResult::query()
                ->with(['alertRule.species'])
                ->whereHas('alertRule', fn ($query) => $query->where('user_id', $request->user()->id))
                ->latest('score_date')
                ->paginate(25),
        ]);
    }
}
