<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Boats\ConsolidateBoatAlias;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBoatAliasRequest;
use App\Http\Requests\StoreBoatRequest;
use App\Http\Requests\UpdateBoatRequest;
use App\Models\Boat;
use App\Models\Landing;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class BoatController extends Controller
{
    public function index(): View
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        return view('admin.boats.index', [
            'selectedBoatId' => old('boat_id', session('selected_boat_id')),
            'boats' => Boat::query()
                ->with([
                    'aliases' => fn ($query) => $query->orderBy('alias'),
                    'landing',
                ])
                ->where('is_active', true)
                ->orderBy('name')
                ->get(),
            'landings' => Landing::query()->where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function store(StoreBoatRequest $request): RedirectResponse
    {
        $boat = Boat::query()->create([
            'name' => $request->validated('boat_name'),
            'slug' => $request->slug(),
            'landing_id' => $request->validated('landing_id'),
            'is_active' => true,
        ]);

        return redirect()->route('admin.boats.index')->with('status', 'Boat saved.')->with('selected_boat_id', $boat->id);
    }

    public function update(UpdateBoatRequest $request, Boat $boat): RedirectResponse
    {
        $boat->update([
            'booking_url' => $request->validated('booking_url'),
        ]);

        return redirect()->route('admin.boats.index')->with('status', 'Boat booking URL updated.')->with('selected_boat_id', $boat->id);
    }

    public function storeAlias(StoreBoatAliasRequest $request, ConsolidateBoatAlias $consolidateBoatAlias): RedirectResponse
    {
        $boat = Boat::query()->findOrFail($request->integer('boat_id'));
        $consolidateBoatAlias->handle(
            $boat,
            $request->validated('alias'),
            $request->normalizedAlias(),
            $request->user()->id,
        );

        return redirect()->back()->with('status', 'Boat alias saved.')->with('selected_boat_id', $boat->id);
    }
}
