<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ScrapeRun;
use Illuminate\Contracts\View\View;

class ScrapeRunController extends Controller
{
    public function index(): View
    {
        return view('admin.scrape-runs.index', ['runs' => ScrapeRun::query()->with('scrapeSource')->latest()->paginate(25)]);
    }

    public function show(ScrapeRun $scrapeRun): View
    {
        return view('admin.scrape-runs.show', ['run' => $scrapeRun->load(['scrapeSource', 'rawScrapePayloads'])]);
    }
}
