<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreEnvironmentalBackfillRequest;
use App\Services\Environmental\EnvironmentalBackfillDispatcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class EnvironmentalBackfillController extends Controller
{
    public function __invoke(
        StoreEnvironmentalBackfillRequest $request,
        EnvironmentalBackfillDispatcher $dispatcher,
    ): RedirectResponse {
        $from = $request->fromDate();
        $to = $request->toDate();
        $locationProfile = $request->locationProfile();
        $jobCount = $dispatcher->dispatchRange($from, $to, $locationProfile);

        if ($jobCount === 0) {
            throw ValidationException::withMessages([
                'location_profile' => 'No enabled historical data sources are available for this profile.',
            ]);
        }

        return redirect()
            ->route('admin.conditions.index', [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'location_profile' => $locationProfile,
            ])
            ->with('status', "Submitted {$jobCount} planned historical provider collection(s). Overlapping work is serialized safely; refresh this page as summaries finish.");
    }
}
