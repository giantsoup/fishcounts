<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Enums\CanonicalEntityType;
use App\Http\Requests\Api\ListEntitiesRequest;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared listing for boats, species, and trip types: active and inactive records, searched by
 * name or alias, with their aliases embedded.
 */
trait ListsEntitiesWithAliases
{
    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return LengthAwarePaginator<int, TModel>
     */
    protected function listEntities(ListEntitiesRequest $request, Builder $query, CanonicalEntityType $type): LengthAwarePaginator
    {
        $filters = $request->validated();

        return $query
            ->with(['aliases' => fn ($query) => $query->orderBy('alias')])
            ->when($filters['search'] ?? null, function (Builder $query, string $search) use ($type): void {
                $pattern = '%'.addcslashes($search, '\%_').'%';
                $normalizedPattern = '%'.addcslashes($type->normalizeAlias($search), '\%_').'%';

                $query->where(fn (Builder $query) => $query
                    ->where('name', 'like', $pattern)
                    ->orWhereHas('aliases', fn (Builder $query) => $query
                        ->where('alias', 'like', $pattern)
                        ->orWhere('normalized_alias', 'like', $normalizedPattern)));
            })
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($request->perPage())
            ->withQueryString();
    }
}
