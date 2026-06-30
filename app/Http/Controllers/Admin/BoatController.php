<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateBoatRequest;
use App\Models\Boat;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class BoatController extends Controller
{
    public function index(): View
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        return view('admin.boats.index', [
            'boats' => Boat::query()
                ->with('landing')
                ->where('is_active', true)
                ->orderBy('name')
                ->paginate(50),
        ]);
    }

    public function update(UpdateBoatRequest $request, Boat $boat): RedirectResponse
    {
        $boat->update([
            'booking_url' => $request->validated('booking_url'),
        ]);

        return redirect()->route('admin.boats.index')->with('status', 'Boat booking URL updated.');
    }
}
