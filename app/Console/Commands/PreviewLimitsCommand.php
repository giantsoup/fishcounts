<?php

namespace App\Console\Commands;

use App\Services\Parsing\LimitsResolver;
use App\Services\Parsing\LimitsSourceReconciler;
use App\Services\Parsing\ParsedCollectionSnapshot;
use App\Services\Parsing\RawPayloadEvaluator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('fish:limits-preview {payloads* : Saved payload IDs to evaluate without writes}')]
#[Description('Print a limits shadow evaluation without changing catches, diagnostics, scores, or notifications')]
class PreviewLimitsCommand extends Command
{
    public function handle(RawPayloadEvaluator $evaluator, LimitsSourceReconciler $reconciler, LimitsResolver $resolver, ParsedCollectionSnapshot $snapshots): int
    {
        config()->set('limits.enabled', true);
        $output = [];
        foreach ($this->argument('payloads') as $id) {
            if (! ctype_digit((string) $id)) {
                $this->error('Payload IDs must be integers.');

                return self::FAILURE;
            }
            [$payload, , $parsed] = $evaluator->evaluate((int) $id);
            if ($payload->authoritativeParserExecution?->ai_snapshot) {
                $parsed = $snapshots->restore($payload->authoritativeParserExecution->ai_snapshot);
            }
            $reconciled = $reconciler->reconcile($parsed, $payload);
            $after = $resolver->enrich($reconciled, $payload, research: false);
            $output[] = ['payload_id' => $payload->id, 'before' => $snapshots->make($parsed), 'after' => $snapshots->make($after)];
        }
        $this->line(json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
