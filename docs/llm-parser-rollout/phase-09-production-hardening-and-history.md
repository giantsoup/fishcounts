# Phase 9 — Production Hardening and Historical Processing

**Status:** Not started  
**Implementation dependency:** Earlier enabled phases meet their production exit gates  
**Secret-handling rule:** Record secret-management systems and provisioning status only, never secret values.

## Decisions Required Before This Phase

- [ ] **Q36. What is the production deployment method?**
  - Confirm whether the repository's Docker Compose deployment workflow remains authoritative.
  - Answer: Deployment is Ci/CD github actions, I will have to manually update things like the .env file after pushing to production

- [ ] **Q37. Where are OpenAI/GitHub secrets and feature flags managed?**
  - Answer: In the .env file and at the respective Github and OpenAI online dashboards

- [ ] **Q38. May deployment add the `ai-parsing` queue and restart workers?**
  - Answer: Yes

- [ ] **Q39. What production AI-worker concurrency should be used?**
  - Recommended initial value: One worker with provider rate limiting.
  - Answer: One worker with provider rate limiting.

- [ ] **Q40. Should the existing database queue remain in use without adding Redis or Horizon?**
  - Recommended: Yes unless measured operations justify a dependency change.
  - Answer: Yes unless measured operations justify a dependency change.

- [ ] **Q41. Are reversible production migrations authorized after each pull request is approved?**
  - Answer: All migrations are approved once they are committed to the main branch

- [ ] **Q42. Must each historical/backfill run receive separate authorization with a call count and hard budget?**
  - Recommended: Yes.
  - Answer: Yes

- [ ] **Where should operational alerts be sent?**
  - Options: application logs/admin dashboard only, email, Discord, or another destination.
  - Answer: application logs/admin dashboard and email

- [ ] **What retention/pruning cadence should apply to bounded failure metadata and obsolete reviews?**
  - Answer: Keep only 3 months of data

- [ ] **May the full PHPUnit suite be run after phase-specific tests pass?**
  - This approval will be reconfirmed when implementation reaches this phase.
  - Answer: Yes

- [ ] **Approved to begin Phase 9?**
  - Answer: Yes

## Objective

Add production monitoring, budgets, retention, failure drills, and explicitly authorized historical processing without weakening the deterministic parser's availability.

## Entry Criteria

- Enabled integration phases meet their accuracy and failure-isolation gates.
- Production deployment, secrets, queue, monitoring, and migration decisions are documented.
- Historical scope and maximum spend are approved.

## Implementation Tasks

1. Add metrics for queue depth/age, review success/failure/refusal, schema failures, stale reviews, human acceptance/rejection, automatic resolutions, tokens/cost, GitHub duplicates, and override invalidations.
2. Enforce atomic daily/monthly budget limits and expose remaining budget to administrators.
3. Add bounded retention/pruning commands for approved transient data.
4. Schedule maintenance with `withoutOverlapping()` and `onOneServer()` where supported by the deployment topology.
5. Add bounded Artisan commands for new/unresolved/historical review selection with dry-run counts and estimated maximum cost.
6. Require an explicit maximum item count, date range, and budget for every historical run.
7. Add safe pause/resume/stop behavior and idempotent fingerprints for historical processing.
8. Validate queue/cache support for uniqueness, atomic locks, and rate limiting.
9. Confirm queue `retry_after` remains greater than every applicable job timeout.
10. Add failure drills for OpenAI unavailable, GitHub unavailable, invalid credentials, budget exhaustion, worker restart, and rollback with queued jobs.
11. Define backward-compatible deployment order: migrations/configuration, dark code, workers, limited traffic, then expansion.

## Security and Operations

- Rotate credentials through the approved secret manager, never the database or repository.
- Ensure diagnostic source content is absent from routine logs and metrics.
- Keep historical operations bounded and administrator-authorized.
- Do not add Redis, Horizon, or another dependency without separate approval.

## Verification

- Test budget concurrency and hard-stop behavior.
- Test pruning boundaries and preservation of required audits.
- Test scheduled-command overlap protection and single-server behavior.
- Test historical dry run, bounded execution, pause/resume, idempotency, and budget exhaustion.
- Perform failure drills proving deterministic parsing and deduplication continue.
- Run phase-specific tests, Pint, and the full PHPUnit suite after approval.

## Exit Gate

- Monitoring and hard budget controls are operational.
- Failure drills confirm external providers cannot stop deterministic parsing.
- Historical processing is bounded, observable, and explicitly approved.
- Deployment and rollback procedures are proven with queued-job compatibility.

## Rollout

Deploy observability and limits before historical processing. Begin with a dry run, then a small approved batch, review cost/quality, and expand only through additional approvals.

## Rollback

Disable AI/GitHub/override flags, stop new historical dispatch, drain or no-op queued jobs, and preserve audit tables. Reparse overridden payloads when reverting active corrections.

## Deliverable

One pull request containing production metrics, budgets, pruning/scheduling, bounded historical commands, and operational/failure tests. Historical execution remains a separate approved operation.

