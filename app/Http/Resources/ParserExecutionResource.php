<?php

namespace App\Http\Resources;

use App\Models\ParserExecution;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Parser execution summary. Snapshots and comparisons are left out to keep responses small.
 *
 * @mixin ParserExecution
 */
class ParserExecutionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'requested_engine' => $this->requested_engine?->value,
            'selected_engine' => $this->selected_engine?->value,
            'parser_version' => $this->parser_version,
            'comparison_status' => $this->comparison_status,
            'fallback_category' => $this->fallback_category,
            'failure_category' => $this->failure_category,
            'attempts' => $this->attempts,
            'cost_micros' => $this->cost_micros,
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'failed_at' => $this->failed_at?->toIso8601String(),
        ];
    }
}
