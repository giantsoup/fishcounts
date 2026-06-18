<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSpeciesAliasRequest;
use App\Models\ParserError;
use App\Models\Species;
use App\Models\SpeciesAlias;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class SpeciesAliasController extends Controller
{
    public function index(): View
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        return view('admin.species-aliases.index', [
            'aliases' => SpeciesAlias::query()->with('species')->latest()->paginate(50),
            'species' => Species::query()->where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function store(StoreSpeciesAliasRequest $request): RedirectResponse
    {
        $alias = SpeciesAlias::query()->create([
            'species_id' => $request->validated('species_id'),
            'alias' => $request->validated('alias'),
            'normalized_alias' => $request->normalizedAlias(),
        ]);

        $this->resolveParserError($request, $alias->alias);

        return redirect()->back()->with('status', 'Species alias saved.');
    }

    private function resolveParserError(StoreSpeciesAliasRequest $request, string $alias): void
    {
        $parserErrorId = $request->validated('parser_error_id');

        if ($parserErrorId === null) {
            return;
        }

        ParserError::query()
            ->whereKey($parserErrorId)
            ->where('raw_field', 'species')
            ->where('raw_value', $alias)
            ->update([
                'resolved_at' => now(),
                'resolved_by_user_id' => $request->user()->id,
            ]);
    }
}
