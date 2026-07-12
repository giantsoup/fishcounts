# Phase 7 — Guarded Existing-Alias Automation

**Status:** Not started  
**Implementation dependency:** Phase 5 human-review evidence meets the approved automation gate  
**Secret-handling rule:** This phase introduces no new credentials.

## Decisions Required Before This Phase

- [ ] **Q26. Which existing entity types are eligible for automatic alias resolution?**
  - Recommended: Species and trip types only initially. Keep boats and every new entity manual.
  - Species: `TBD`
  - Trip types: `TBD`
  - Boats: `TBD`

- [ ] **Q27. What confidence threshold is required?**
  - Recommended provisional value: `0.98`, finalized from measured human-review results.
  - Answer: `TBD`

- [ ] **Q28. What minimum live human-reviewed sample is required?**
  - Recommended: At least 50 recommendations and no incorrect result among cases that would have qualified for automation.
  - Answer: `TBD`

- [ ] **Q29. Must reversal use an explicit audited administrator action?**
  - Recommended: Yes.
  - Answer: `TBD`

- [ ] **Q30. Where should successful automatic resolutions be reported?**
  - Recommended: Admin audit history only initially.
  - Answer: `TBD`

- [ ] **Confirm that Luna may never create a canonical boat, species, or trip type.**
  - Recommended: Confirm.
  - Answer: `TBD`

- [ ] **Approved to begin Phase 7?**
  - Answer: `TBD`

## Objective

Automatically apply only high-confidence mappings to existing canonical entities after independent PHP validation, then reparse through the normal pipeline.

## Entry Criteria

- Phase 5 review sample and quality threshold are satisfied.
- Entity-specific flags and thresholds are approved.
- Reversal/audit policy is approved.

## Implementation Tasks

1. Add separate automation flags for species, trip types, and boats.
2. Revalidate review freshness, classification, threshold, canonical type, canonical ID, active status, and alias uniqueness at mutation time.
3. Apply aliases through the same domain actions used by administrators.
4. Record an explicit AI-assisted resolution type, review ID, evidence, and audit actor.
5. Reparse the payload through `ParseRawPayloadAction` and rerun deduplication.
6. Add a recursion guard so the same fingerprint is not reviewed again after its accepted correction.
7. Add atomic locks/constraints for concurrent reviews.
8. Add a global automation kill switch independent of shadow review and GitHub flags.
9. Keep new entities, uncertain results, and disallowed entity types in human review.

## Security and Data Integrity

- Never trust model confidence without server-side eligibility checks.
- Never accept an ID outside the candidate type sent for that diagnostic.
- Never mutate canonical entity names or create new entities.
- Keep a durable, reversible audit trail.

## Verification

- Test values immediately below, at, and above the threshold.
- Test missing, inactive, wrong-type, stale, and conflicting targets.
- Test concurrent reviews and duplicate aliases.
- Test recursion prevention, reparse idempotency, and deduplication.
- Test flags and global kill switch.
- Test audit attribution and explicit reversal.
- Run focused tests and Pint.

## Exit Gate

- The approved sample and accuracy target are met.
- No invalid candidate can be applied.
- Every automatic change is auditable and reversible.

## Rollout

Enable one entity type at a time for limited traffic, beginning with the strongest measured category. Review every automatic action before expansion.

## Rollback

Disable entity-specific or global automation. Existing aliases remain persistent until explicitly reversed by an administrator, followed by reparse.

## Deliverable

One pull request containing guarded automation, resolution auditing, recursion/concurrency controls, and boundary tests.

