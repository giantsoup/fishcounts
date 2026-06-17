<?php

namespace App\Http\Controllers;

use App\Models\AlertEvent;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class AlertHistoryController extends Controller
{
    public function __invoke(Request $request): View
    {
        return view('alerts.index', [
            'events' => AlertEvent::query()
                ->whereBelongsTo($request->user())
                ->latest('event_date')
                ->paginate(25),
        ]);
    }
}
