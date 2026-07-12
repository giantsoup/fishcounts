# Phase 1 — Parsing Orchestration Refactor

**Status:** Not started  
**Implementation dependency:** Phase 0 approved  
**Secret-handling rule:** No external credentials are needed in this phase.

## Decisions Required Before This Phase

- [ ] **Should this phase preserve exact parsing, normalization, failure, and deduplication behavior?**
  - Recommended: Yes; this is a behavior-neutral refactor.
  - Answer: `TBD`

- [ ] **Should one reusable action serve normal parsing and backfill reparsing?**
  - Recommended: Yes.
  - Answer: `TBD`

- [ ] **Should this phase contain no migrations or external API calls?**
  - Recommended: Yes.
  - Answer: `TBD`

- [ ] **Approved to begin Phase 1?**
  - Answer: `TBD`

## Objective

Extract a stable, reusable orchestration boundary so normal payload parsing and backfill reparsing follow the same code path. This creates the extension point for diagnostics while preserving existing behavior.

## Entry Criteria

- Phase 0 corpus and expected behavior are approved.
- Existing parsing tests pass before the refactor begins.

## Implementation Tasks

1. Use Artisan to generate any new generic PHP classes.
2. Extract a single-purpose `ParseRawPayloadAction` from `ParseRawPayloadJob`.
3. Inject the source adapter registry, normalizer, and other dependencies through the constructor.
4. Update normal parsing and backfill reparsing to call the same action rather than invoking a job's `handle()` method directly.
5. Add a typed result DTO containing the payload ID, parser version, parsed report count, diagnostic count, and deduplication intent.
6. Preserve the existing unique-job identity, queue, attempts, timeout, backoff, and `failed()` behavior.
7. Keep deterministic parsing and deduplication synchronous within the existing parsing workflow; no Luna branching exists yet.

## Security and Reliability

- Do not add external HTTP calls.
- Do not add secrets or environment configuration.
- Use dependency injection rather than `app()` or `resolve()` inside the action.

## Verification

- Run the existing parsing-pipeline tests.
- Add focused tests proving normal and backfill paths produce equivalent results.
- Test action and job idempotency.
- Verify deduplication dispatch remains unchanged.
- Verify job failure still stores a bounded payload error message.
- Run `vendor/bin/pint --dirty --format agent` after PHP changes.

## Exit Gate

- All existing behavior is preserved.
- Normal and backfill parsing share the action.
- No schema, external-call, or production-data behavior has changed.

## Rollout

Deploy as an ordinary application change. Restart queue workers so they load the refactored job code.

## Rollback

Revert the refactor. There is no schema or data migration to reverse.

## Deliverable

One pull request containing only the orchestration refactor, result DTO, and parity tests.

