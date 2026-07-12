# Phase 3 — AI Audit Foundation

**Status:** Not started  
**Implementation dependency:** Phase 2 deployed and signal quality approved  
**Secret-handling rule:** This phase adds configuration keys, not secret values.

## Decisions Required Before This Phase

- [ ] **Q9. What exact model should configuration default to, and is it available to the intended OpenAI project?**
  - Recommended: Verify account access to `gpt-5.6-luna` immediately before implementation and keep the model configurable.
  - Answer: `TBD`

- [ ] **Q10. What reasoning effort should be the initial default?**
  - Recommended: `low`, compared with `none` during shadow evaluation.
  - Answer: `TBD`

- [ ] **Q11. What OpenAI data should be stored locally?**
  - Recommended: Validated structured result, response ID, model/version, token usage, cost estimate, and bounded failure metadata; do not retain the full raw response by default.
  - Answer: `TBD`

- [ ] **Q12–Q14. What daily and monthly budgets and enforcement behavior should apply?**
  - Recommended: `$2` daily, `$25` monthly, and a hard stop on new AI calls while deterministic parsing continues.
  - Daily answer: `TBD`
  - Monthly answer: `TBD`
  - Hard-stop answer: `TBD`

- [ ] **How long should AI review/audit records be retained?**
  - Recommended: Retain validated decisions and audit metadata indefinitely; prune raw failure payloads after 30 days. Adjust to the application's operational needs.
  - Answer: `TBD`

- [ ] **What candidate-list and input-token bounds should apply?**
  - Recommended: Send only active canonical candidates relevant to the diagnostic type, with configurable input and output limits.
  - Answer: `TBD`

- [ ] **Approved to begin Phase 3?**
  - Answer: `TBD`

## Objective

Create durable review records, strict typed contracts, configuration, and service boundaries without making any OpenAI calls.

## Entry Criteria

- Diagnostic fingerprints and invalidation behavior are stable.
- The model, audit retention, and budget decisions are answered.
- Production migrations are approved for this phase.

## Implementation Tasks

1. Use Artisan to create focused migrations and models for AI reviews.
2. Add a review table keyed by payload and diagnostic fingerprint, with a nullable parser-error relationship.
3. Store status, provider, model, prompt/schema versions, classification, confidence, validated result, rationale, response ID, token usage, cost estimate, attempts, timestamps, and bounded failure message.
4. Add unique constraints and indexes for idempotency and operational queries.
5. Define explicit review-state and classification enums.
6. Define immutable request/result DTOs and server-side validators.
7. Define the strict JSON Schema with no extra properties and narrow correction operations.
8. Add `ParserDiagnosticReviewer` as an external-boundary contract.
9. Add configuration for feature flags, model, reasoning, timeouts, token bounds, prompt/schema versions, confidence settings, and budgets.
10. Bind the contract through the service provider while keeping the integration disabled.
11. Mirror database defaults in models and use guarded/fillable conventions consistent with sibling models.
12. Design budget accounting for atomic reservation so concurrent jobs cannot exceed configured caps.

## Security and Privacy

- Use `env()` only inside configuration files.
- Never persist or log API keys.
- Validate canonical IDs and result fields in PHP even when Structured Outputs succeeds.
- Keep review records independent of parser-error rows that may be replaced during reparse.

## Verification

- Test migration up/down behavior.
- Test model casts, defaults, relationships, and guarded attributes.
- Test every legal and illegal review-state transition.
- Reject extra keys, unknown enums, invalid IDs, negative counts, and invalid confidence values.
- Test unique constraints and atomic budget accounting.
- Run focused PHPUnit tests and Pint.

## Exit Gate

- Schema and structured-result contracts are approved.
- No external requests are possible while the integration flag is disabled.
- Review records remain valid across parser-error replacement.

## Rollout

Deploy additive schema first, then disabled application code. Clear/configure caches and restart workers only after compatible code is present.

## Rollback

Keep additive tables during application rollback. Reverse migrations only after all referencing code and queued serialized jobs are removed or drained.

## Deliverable

One pull request containing audit schema, models, enums, DTOs, JSON Schema, contracts, configuration, bindings, and validation tests.

