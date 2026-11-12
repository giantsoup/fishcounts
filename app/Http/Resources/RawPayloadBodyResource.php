<?php

namespace App\Http\Resources;

use App\Models\RawScrapePayload;
use App\Services\Parsing\DiagnosticContextFactory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A raw payload's body, passed through the diagnostic secret redaction and then cut to
 * fish.api.raw_payload_body_max_bytes on a UTF-8 character boundary. `original_bytes`
 * is the stored body's size; `truncated` says whether the returned body was cut.
 *
 * @mixin RawScrapePayload
 */
class RawPayloadBodyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $stored = (string) $this->payload;
        $redacted = app(DiagnosticContextFactory::class)->redactSecrets($stored);
        $maxBytes = max(1, (int) config('fish.api.raw_payload_body_max_bytes', 200_000));
        $truncated = strlen($redacted) > $maxBytes;

        return [
            'id' => $this->id,
            'content_type' => $this->content_type,
            'original_bytes' => strlen($stored),
            'truncated' => $truncated,
            'body' => $truncated ? mb_strcut($redacted, 0, $maxBytes, 'UTF-8') : $redacted,
        ];
    }
}
