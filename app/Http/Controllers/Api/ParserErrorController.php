<?php

namespace App\Http\Controllers\Api;

use App\Actions\Parsing\DismissParserError;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ListParserErrorsRequest;
use App\Http\Resources\ParserErrorDetailResource;
use App\Http\Resources\ParserErrorResource;
use App\Models\ParserError;
use App\Support\ApiAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class ParserErrorController extends Controller
{
    public function index(ListParserErrorsRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();

        $errors = ParserError::query()
            ->with('scrapeSource')
            ->when(
                $filters['status'] ?? 'open',
                fn ($query, string $status) => match ($status) {
                    'open' => $query->open(),
                    'resolved' => $query->where(fn ($query) => $query->whereNotNull('resolved_at')->orWhereNotNull('resolution_type')),
                    'all' => $query,
                },
            )
            ->when($filters['kind'] ?? null, fn ($query, string $kind) => $kind === 'alias' ? $query->aliases() : $query->structural())
            ->when($filters['type'] ?? null, fn ($query, string $type) => $query->where('error_type', $type))
            ->when($filters['source'] ?? null, fn ($query, int|string $source) => $query->where('scrape_source_id', (int) $source))
            ->when($filters['from'] ?? null, fn ($query, string $from) => $query->whereDate('target_date', '>=', $from))
            ->when($filters['to'] ?? null, fn ($query, string $to) => $query->whereDate('target_date', '<=', $to))
            ->latest('id')
            ->paginate((int) ($filters['per_page'] ?? ListParserErrorsRequest::DEFAULT_PER_PAGE))
            ->withQueryString();

        return ParserErrorResource::collection($errors);
    }

    /**
     * Dismiss an open parser error as the token's user. An already resolved error is left untouched.
     */
    public function dismiss(Request $request, ParserError $parserError, DismissParserError $dismissParserError): ParserErrorResource|JsonResponse
    {
        if (! $dismissParserError->handle($parserError, $request->user()->getKey())) {
            return response()->json(['message' => 'Parser error was already resolved.'], Response::HTTP_CONFLICT);
        }

        ApiAudit::write($request, 'parser-error.dismiss', $parserError->getKey());

        return ParserErrorResource::make($parserError->refresh()->load('scrapeSource'));
    }

    public function show(ParserError $parserError): ParserErrorDetailResource
    {
        return ParserErrorDetailResource::make($parserError->load([
            'scrapeSource',
            'rawScrapePayload.latestParserExecution',
            'rawScrapePayload.tripReports' => fn ($query) => $query->orderBy('id'),
            'rawScrapePayload.tripReports.boat',
            'rawScrapePayload.tripReports.landing',
            'rawScrapePayload.tripReports.tripType',
            'rawScrapePayload.tripReports.speciesCounts.species',
        ]));
    }
}
