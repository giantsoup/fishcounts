<?php

namespace App\Http\Resources;

use App\Models\ParserReparseRun;
use App\Services\Parsing\DiagnosticContextFactory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A parser reparse run as structured JSON: the state, progress counts, failures, and timestamps
 * that the admin UI's HTML poll renders. Load the run's failed items as `items` to list failures.
 *
 * @mixin ParserReparseRun
 */
class ReparseRunResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'finished' => ! $this->status->isActive(),
            'requested_by_user_id' => $this->requested_by_user_id,
            'initial' => [
                'open_errors' => $this->initial_open_errors,
                'alias_errors' => $this->initial_alias_errors,
                'structural_errors' => $this->initial_structural_errors,
                'payloads' => $this->initial_payloads,
                'dates' => $this->affected_dates,
            ],
            'progress' => [
                'total_items' => $this->total_items,
                'queued_items' => $this->queued_items,
                'completed_items' => $this->completed_items,
                'failed_items' => $this->failed_items,
            ],
            'remaining' => [
                'open_errors' => $this->remaining_open_errors,
                'alias_errors' => $this->remaining_alias_errors,
                'structural_errors' => $this->remaining_structural_errors,
            ],
            'error_message' => $this->sanitize($this->error_message),
            'failures' => $this->whenLoaded('items', fn (): array => $this->items->map(fn ($item): array => [
                'raw_scrape_payload_id' => $item->raw_scrape_payload_id,
                'scrape_source_id' => $item->scrape_source_id,
                'target_date' => $item->target_date?->toDateString(),
                'mode' => $item->mode->value,
                'attempts' => $item->attempts,
                'error_message' => $this->sanitize($item->error_message),
            ])->all()),
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    private function sanitize(?string $message): ?string
    {
        return $message === null ? null : app(DiagnosticContextFactory::class)->sanitizeDiagnosticText($message);
    }
}
