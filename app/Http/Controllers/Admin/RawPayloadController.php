<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ParseRawPayloadJob;
use App\Models\RawScrapePayload;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class RawPayloadController extends Controller
{
    public function __invoke(RawScrapePayload $rawScrapePayload): View
    {
        $this->authorize('view', $rawScrapePayload);

        return view('admin.raw-payloads.show', [
            'payload' => $rawScrapePayload->load([
                'scrapeRun',
                'scrapeSource',
                'tripReports.speciesCounts.species',
                'tripReports.boat',
                'tripReports.landing',
                'tripReports.tripType',
                'parserReportOverrides.parserBugReport',
            ]),
        ]);
    }

    public function reparse(RawScrapePayload $rawScrapePayload): RedirectResponse
    {
        $this->authorize('reparse', $rawScrapePayload);

        ParseRawPayloadJob::dispatch($rawScrapePayload->id);

        return redirect()->route('admin.raw-payloads.show', $rawScrapePayload)->with('status', 'Payload queued for reparsing.');
    }
}
