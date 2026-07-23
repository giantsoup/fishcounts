<?php

namespace App\Actions\Parsing;

use App\Enums\ParserEngine;
use App\Enums\ParserReportOverrideStatus;
use App\Models\ParserReportOverride;
use App\Models\RawScrapePayload;
use App\Models\User;
use App\Services\Parsing\TripReportNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class DisableParserReportOverride
{
    public function __construct(
        private readonly ParseRawPayloadAction $parseRawPayload,
        private readonly TripReportNormalizer $normalizer,
    ) {}

    public function handle(ParserReportOverride $override, User $actor, string $reason): void
    {
        DB::transaction(function () use ($override, $actor, $reason): void {
            $override = ParserReportOverride::query()->lockForUpdate()->findOrFail($override->id);

            if ($override->status !== ParserReportOverrideStatus::Active) {
                throw ValidationException::withMessages(['override' => 'Only an active override may be disabled.']);
            }

            $payload = RawScrapePayload::query()->with('scrapeSource:id,parser_engine')->lockForUpdate()->findOrFail($override->raw_scrape_payload_id);
            $override->forceFill([
                'status' => ParserReportOverrideStatus::Disabled,
                'disabled_by_user_id' => $actor->id,
                'disabled_by_name' => $actor->name,
                'disabled_by_email' => $actor->email,
                'disable_reason' => $reason,
                'disabled_at' => now(),
            ])->save();

            if ($payload->scrapeSource->parser_engine === ParserEngine::Deterministic) {
                $this->parseRawPayload->handle($payload->id, false);
                $this->normalizer->refreshPrimaryReports($payload->target_date->toDateString());
            }
        }, attempts: 3);
    }
}
