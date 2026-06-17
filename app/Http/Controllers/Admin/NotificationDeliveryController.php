<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\NotificationDelivery;
use Illuminate\Contracts\View\View;

class NotificationDeliveryController extends Controller
{
    public function __invoke(): View
    {
        return view('admin.notification-logs.index', [
            'deliveries' => NotificationDelivery::query()->with('user')->latest()->paginate(25),
        ]);
    }
}
