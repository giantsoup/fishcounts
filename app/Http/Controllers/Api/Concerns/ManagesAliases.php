<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Enums\ParserErrorResolutionType;
use App\Http\Requests\Api\ListAliasesRequest;
use App\Http\Requests\Api\ListParserErrorsRequest;
use App\Http\Requests\Api\StoreAliasRequest;
use App\Http\Resources\AliasResource;
use App\Models\ParserError;
use App\Support\ApiAudit;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Shared alias endpoints for boats, species, and trip types. Each controller supplies the
 * existing alias action; this trait wraps it with the agent's resolution type, the audit line,
 * and a report of which parser errors it resolved.
 */
trait ManagesAliases
{
    /**
     * @param  HasMany<covariant Model, covariant Model>  $aliases
     */
    protected function listAliases(ListAliasesRequest $request, HasMany $aliases): AnonymousResourceCollection
    {
        return AliasResource::collection(
            $aliases
                ->orderBy('alias')
                ->orderBy('id')
                ->paginate((int) $request->validated('per_page', ListParserErrorsRequest::DEFAULT_PER_PAGE))
                ->withQueryString(),
        );
    }

    /**
     * @param  Closure(string $alias, string $normalizedAlias, int $userId, ParserErrorResolutionType $resolutionType): Model  $createAlias
     */
    protected function storeAlias(StoreAliasRequest $request, string $auditResource, Closure $createAlias): JsonResponse
    {
        $userId = $request->user()->getKey();
        $openErrorIds = ParserError::query()
            ->whereNull('resolved_at')
            ->where('error_type', $request->entityType()->aliasErrorType())
            ->pluck('id');

        $alias = $createAlias(
            $request->validated('alias'),
            $request->normalizedAlias(),
            $userId,
            ParserErrorResolutionType::AgentAlias,
        );

        $resolvedCount = ParserError::query()
            ->whereKey($openErrorIds)
            ->whereNotNull('resolved_at')
            ->where('resolution_type', ParserErrorResolutionType::AgentAlias->value)
            ->where('resolved_by_user_id', $userId)
            ->count();

        ApiAudit::write($request, "{$auditResource}.create", $alias->getKey());

        return AliasResource::make($alias)
            ->additional(['meta' => [
                'resolved_parser_errors' => $resolvedCount,
                'parser_error' => $this->parserErrorOutcome($request->validated('parser_error_id')),
            ]])
            ->response()
            ->setStatusCode($alias->wasRecentlyCreated ? Response::HTTP_CREATED : Response::HTTP_OK);
    }

    protected function destroyAlias(Request $request, string $auditResource, Model $alias): Response
    {
        $alias->delete();

        ApiAudit::write($request, "{$auditResource}.delete", $alias->getKey());

        return response()->noContent();
    }

    /**
     * @return array{id: int, resolved: bool, resolution_type: string|null}|null
     */
    private function parserErrorOutcome(int|string|null $parserErrorId): ?array
    {
        if ($parserErrorId === null) {
            return null;
        }

        $parserError = ParserError::query()->findOrFail((int) $parserErrorId);

        return [
            'id' => $parserError->id,
            'resolved' => $parserError->resolved_at !== null,
            'resolution_type' => $parserError->resolution_type?->value,
        ];
    }
}
