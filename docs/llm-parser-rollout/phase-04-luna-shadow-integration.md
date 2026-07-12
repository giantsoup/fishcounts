# Phase 4 — Luna Shadow Integration

**Status:** Application implementation complete; production shadow evaluation pending
**Implementation dependency:** Phase 3 deployed with integration disabled  
**Secret-handling rule:** Record only whether secrets are provisioned and where; never write secret values here.

## Decisions Required Before This Phase

- [x] **Q8. In which environments may real OpenAI calls run?**
  - Recommended: Local off by default, staging on, production on only for controlled shadow traffic.
  - Local answer: off by default, but we need to be able to actually hit the API during development to verify everything works
  - Staging answer: We have no staging at the moment
  - Production answer: On for our specific use cases

- [x] **Is the OpenAI API key provisioned as an environment secret in each enabled environment?**
  - Secret location/status only: its in the .env file under the key `OPENAI_API_KEY`

- [x] **Q9–Q11. Confirm model, reasoning, and `store: false`.**
  - Model: gpt-5.6-luna
  - Reasoning: medium
  - `store: false`: Yes use store: false

- [x] **Q13. What shadow duration and sample size must be reached?**
  - Recommended: At least seven normal production parsing days and 50 human-reviewed recommendations when sufficient diagnostics occur.
  - Answer: At least seven normal production parsing days and 50 human-reviewed recommendations when sufficient diagnostics occur.

- [x] **Q14. What quality gates must shadow mode meet?**
  - Recommended: All known fixtures detected, clean-corpus false positives below the approved ceiling, at least 95% human classification agreement, and zero invalid results applied.
  - Answer: All known fixtures detected, clean-corpus false positives below the approved ceiling, at least 95% human classification agreement, and zero invalid results applied.

- [x] **Q15. Are any scrape sources excluded from OpenAI review?**
  - Recommended: None if only approved sanitized public paragraphs are sent.
  - Answer: None if only approved sanitized public paragraphs are sent.

- [x] **Q38–Q40. May the `ai-parsing` queue be added, with one initial worker and no Redis/Horizon dependency?**
  - Add queue/restart workers: Yes
  - Initial concurrency: Yes
  - Keep database queue: Yes

- [x] **Approved to begin Phase 4?**
  - Answer: Yes

## Objective

Send eligible diagnostics to Luna in read-only shadow mode, record validated recommendations and usage, and prove that provider failures cannot affect deterministic parsing.

## Entry Criteria

- Phase 3 schema and contracts are deployed.
- Account access to the configured model is verified.
- API secrets are provisioned outside source control.
- Queue and budget decisions are approved.

## Implementation Tasks

1. Implement the OpenAI Responses API adapter behind `ParserDiagnosticReviewer` using Laravel's HTTP client.
2. Batch eligible diagnostics by payload to reduce calls and repeated context.
3. Send only sanitized paragraphs, parsed values, deterministic evidence, source/parser metadata, and bounded active candidate lists.
4. Request strict JSON Schema output with `store: false` and no tools.
5. Add explicit `connectTimeout`, request timeout, and output-token limits.
6. Retry only connection failures, rate limits, and server failures with bounded exponential backoff.
7. Create a unique `ReviewParserDiagnosticsJob` on `ai-parsing`.
8. Dispatch after the normalizer transaction commits.
9. Add rate-limited middleware, `failed()`, stale-fingerprint checks, atomic budget reservation, and kill-switch checks.
10. Ensure disabled queued jobs safely no-op and that uncertain/refused results leave an actionable parser error with the best validated guess when available.
11. Keep normal parsing and deduplication independent from Luna completion.
12. Update development and production worker configuration only after code and schema are compatible.

## Security and Privacy

- Treat every source paragraph as untrusted data, not instructions.
- Provide no model tools, GitHub access, or application credentials.
- Never log authorization headers or full request bodies.
- Bound locally stored failure messages and redact secrets.

## Verification

- Use `Http::preventStrayRequests()` and `Http::fake()` in every external-client test.
- Test valid output, refusal, malformed JSON, schema mismatch, invalid IDs, 401, 429, 5xx, and timeout behavior.
- Test queue uniqueness, rate limiting, after-commit dispatch, retries, `failed()`, and `retry_after > timeout`.
- Test stale fingerprints, disabled-job no-op, budget exhaustion, concurrent budget reservation, and prompt-injection paragraphs.
- Confirm deterministic parsing and deduplication succeed during simulated OpenAI outages.
- Run focused tests and Pint.

## Exit Gate

- The approved shadow duration/sample is complete.
- Quality and cost gates are met.
- No AI recommendation has changed application data.
- Provider failures have no deterministic-parser impact.

## Rollout

1. Deploy schema/configuration first with all AI flags off.
2. Deploy dark application and job code.
3. Update and restart workers.
4. With no staging environment available, perform an explicit local opt-in call using sanitized test data.
5. Enable limited production shadow traffic.
6. Expand shadow traffic only after reviewing metrics.

## Rollback

Disable AI dispatch. Queued jobs must no-op or be drained before incompatible code is reverted. Audit records remain available.

## Deliverable

One pull request containing the OpenAI adapter, shadow job, queue configuration, safety controls, and HTTP/job tests.
