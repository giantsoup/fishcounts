<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Support\ApiAudit;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Hard delete for boats, species, and trip types. History and alert rules must never be orphaned,
 * so the delete is refused with a 409 while anything still references the record.
 */
trait DeletesUnreferencedEntities
{
    /**
     * @param  array<string, Closure(): (Builder<covariant Model>|QueryBuilder)>  $references  Label => query for rows that reference the record.
     * @param  string  $alternative  What to do instead, appended to the 409 message.
     */
    protected function destroyUnlessReferenced(Request $request, Model $entity, string $auditResource, array $references, string $alternative): Response
    {
        DB::transaction(function () use ($entity, $references, $alternative): void {
            $entity->newQuery()->lockForUpdate()->findOrFail($entity->getKey());

            $referencedBy = collect($references)
                ->filter(fn (Closure $query): bool => $query()->exists())
                ->keys();

            abort_if(
                $referencedBy->isNotEmpty(),
                Response::HTTP_CONFLICT,
                "This record is still referenced by {$referencedBy->join(', ', ' and ')}, so it cannot be deleted. {$alternative}",
            );

            $entity->delete();
        });

        ApiAudit::write($request, "{$auditResource}.delete", $entity->getKey());

        return response()->noContent();
    }
}
