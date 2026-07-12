# Phase 5 — Human Review Workflow

**Status:** Not started  
**Implementation dependency:** Phase 4 shadow output meets initial quality checks  
**Secret-handling rule:** Admin screens must never display provider credentials or authorization headers.

## Decisions Required Before This Phase

- [ ] **Q7. Which role or gate may view and act on AI reviews?**
  - Recommended: Existing administrators only.
  - Answer: `TBD`

- [ ] **Which human actions should be available?**
  - Recommended: Accept existing alias, reject recommendation, dismiss parser error, retry review, and leave open.
  - Answer: `TBD`

- [ ] **What should happen when a review becomes stale before approval?**
  - Recommended: Reject the action, show why it is stale, and offer a fresh review.
  - Answer: `TBD`

- [ ] **Should human review actions send notifications?**
  - Recommended: Audit history only during initial rollout.
  - Answer: `TBD`

- [ ] **How long should human approval/rejection audit history be retained?**
  - Recommended: Indefinitely unless a formal retention policy requires pruning.
  - Answer: `TBD`

- [ ] **Approved to begin Phase 5?**
  - Answer: `TBD`

## Objective

Expose Luna recommendations to authorized administrators while keeping every data-changing decision under human control.

## Entry Criteria

- Luna shadow responses validate reliably.
- Classification and rationale fields are stable enough for display.
- The administrator role/gate is confirmed.

## Implementation Tasks

1. Extend the parser-error admin view with AI status, classification, confidence, rationale, candidate target, sanitized paragraph, versions, usage, and cost.
2. Add authorized accept, reject, dismiss, retry, and leave-open actions.
3. Use Form Request validation and existing policy/gate conventions.
4. Extract species and trip-type alias creation into domain actions consistent with the existing boat alias action.
5. On acceptance, revalidate fingerprint freshness, target type, target activity, and alias uniqueness.
6. Apply the domain action, record the human actor and linked AI review, then reparse and deduplicate.
7. Keep `new_entity_candidate` and `uncertain` outcomes open for human handling.
8. Preserve original diagnostics and audit history after any resolution.

## Security and Privacy

- Escape all source and model text in Blade.
- Require CSRF protection for every mutation.
- Authorize every action server-side.
- Never trust hidden form values for canonical type, target, confidence, or review freshness.

## Verification

- Test admin authorization, validation, and CSRF behavior.
- Test XSS payloads in source paragraphs and model rationale.
- Test stale review rejection and retry.
- Test duplicate-click and concurrent-action idempotency.
- Test accept, reject, dismiss, and leave-open paths.
- Confirm no action creates a new canonical boat, species, or trip type.
- Run relevant admin/parsing tests and Pint.

## Exit Gate

- Administrators can understand and safely act on recommendations.
- Stale or invalid recommendations cannot mutate data.
- Human agreement and rejection metrics are available for later thresholds.

## Rollout

Deploy the UI hidden behind an admin feature flag, enable it for designated reviewers, and expand after validating audit records.

## Rollback

Disable or hide AI review controls. Keep all audit records and existing parser-error workflows intact.

## Deliverable

One pull request containing authorized admin UI/actions, reusable alias domain actions, audit behavior, and security/idempotency tests.

