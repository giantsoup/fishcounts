<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A reparse preview from ParserReparsePlanner::preview(), plus the scope it was planned for.
 * The planner's item manifest is left out; its counts and fingerprint are what a caller needs.
 *
 * @property-read array{source: ?int, from: ?string, to: ?string, plan: array<string, mixed>} $resource
 */
class ReparsePreviewResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $plan = $this->resource['plan'];

        return [
            'source' => $this->resource['source'],
            'from' => $this->resource['from'],
            'to' => $this->resource['to'],
            'open_errors' => $plan['open_errors'],
            'alias_errors' => $plan['alias_errors'],
            'structural_errors' => $plan['open_errors'] - $plan['alias_errors'],
            'skipped_errors' => $plan['skipped_errors'],
            'payloads' => $plan['payloads'],
            'dates' => $plan['dates'],
            'trips' => $plan['trips'],
            'related_trips' => $plan['related_trips'],
            'fingerprint' => $plan['fingerprint'],
        ];
    }
}
