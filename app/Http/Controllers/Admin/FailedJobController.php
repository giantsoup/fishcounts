<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;

class FailedJobController extends Controller
{
    public function __invoke(): View
    {
        $jobs = DB::table('failed_jobs')
            ->latest('failed_at')
            ->paginate(25)
            ->through(function (object $job): object {
                $payload = json_decode($job->payload, true);
                $job->display_name = data_get($payload, 'displayName', 'Unknown job');
                $job->exception_summary = str((string) $job->exception)->before("\n")->limit(240)->toString();

                return $job;
            });

        return view('admin.failed-jobs.index', ['jobs' => $jobs]);
    }
}
