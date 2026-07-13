<?php

namespace App\Http\Controllers\Admin;

use App\Actions\TripTypes\CreateTripTypeAlias;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTripTypeAliasRequest;
use App\Http\Requests\StoreTripTypeRequest;
use App\Http\Requests\UpdateTripTypeRequest;
use App\Models\TripType;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class TripTypeAliasController extends Controller
{
    public function index(): View
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        return view('admin.trip-type-aliases.index', [
            'selectedTripTypeId' => old('order_trip_type_id', old('trip_type_id', session('selected_trip_type_id'))),
            'tripTypes' => TripType::query()
                ->where('is_active', true)
                ->with(['aliases' => fn ($query) => $query->orderBy('alias')])
                ->orderedForDisplay()
                ->get(),
        ]);
    }

    public function storeTripType(StoreTripTypeRequest $request): RedirectResponse
    {
        TripType::query()->create([
            'name' => $request->validated('name'),
            'slug' => $request->slug(),
            'sort_order' => $request->validated('sort_order') ?? ((int) TripType::query()->max('sort_order') + 1),
            'is_active' => true,
        ]);

        return redirect()->route('admin.trip-type-aliases.index')->with('status', 'Trip type saved.');
    }

    public function updateTripType(UpdateTripTypeRequest $request, TripType $tripType): RedirectResponse
    {
        $tripType->update([
            'sort_order' => $request->validated('order_sort_order'),
        ]);

        return redirect()
            ->back()
            ->with('status', 'Trip order saved.')
            ->with('selected_trip_type_id', $tripType->id);
    }

    public function store(StoreTripTypeAliasRequest $request, CreateTripTypeAlias $createTripTypeAlias): RedirectResponse
    {
        $tripType = TripType::query()->findOrFail($request->integer('trip_type_id'));
        $createTripTypeAlias->handle(
            $tripType,
            $request->validated('alias'),
            $request->normalizedAlias(),
            $request->user()->id,
        );

        return redirect()
            ->back()
            ->with('status', 'Trip type alias saved.')
            ->with('selected_trip_type_id', $request->validated('trip_type_id'));
    }
}
