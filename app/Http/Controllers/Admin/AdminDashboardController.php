<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BackfillRun;
use App\Models\ParserError;
use App\Models\ScrapeRun;
use App\Models\User;
use Illuminate\Contracts\View\View;

class AdminDashboardController extends Controller
{
    public function __invoke(): View
    {
        return view('admin.dashboard', [
            'userCount' => User::query()->count(),
            'latestScrapeRun' => ScrapeRun::query()->latest()->first(),
            'latestBackfillRun' => BackfillRun::query()->latest()->first(),
            'openParserErrorCount' => ParserError::query()->whereNull('resolved_at')->count(),
        ]);
    }
}
