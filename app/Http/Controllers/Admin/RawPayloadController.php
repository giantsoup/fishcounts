<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\RawScrapePayload;
use Illuminate\Contracts\View\View;

class RawPayloadController extends Controller
{
    public function __invoke(RawScrapePayload $rawScrapePayload): View
    {
        $this->authorize('view', $rawScrapePayload);

        return view('admin.raw-payloads.show', ['payload' => $rawScrapePayload->load('scrapeSource')]);
    }
}
