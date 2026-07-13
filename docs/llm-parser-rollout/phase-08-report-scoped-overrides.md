# Phase 8 — Report-Scoped Parser Overrides

**Status:** Not started  
**Implementation dependency:** Phase 6 issue linkage and sustained Phase 5 shadow evidence  
**Secret-handling rule:** Overrides contain typed parse data only, never credentials or raw HTML.

## Decisions Required Before This Phase

- [ ] **Q31. May Luna eventually propose occurrence-specific report overrides?**
  - Recommended: Yes, with human approval initially.
  - Answer: Yes, with human approval initially.

- [ ] **Q32. Which fields may an override change?**
  - Recommended: Existing boat selection, existing trip type, anglers, existing species selection, retained count, and released count. Disallow arbitrary JSON/database patches.
  - Answer: Existing boat selection, existing trip type, anglers, existing species selection, retained count, and released count. Disallow arbitrary JSON/database patches.

- [ ] **Must overrides require human approval initially?**
  - Recommended: Yes.
  - Answer: Yes

- [ ] **Q33. Should overrides invalidate when source paragraph, report fingerprint, or parser version changes?**
  - Recommended: Yes.
  - Answer: Yes

- [ ] **Q34. Must every parser-defect override link to a deduplicated GitHub issue?**
  - Recommended: Yes.
  - Answer: Yes

- [ ] **May an approved override reparse and therefore alter previously normalized historical data for that payload/date?**
  - Recommended: Yes, but show the affected scope before approval and preserve audit/rollback information.
  - Answer: Yes, but show the affected scope before approval and preserve audit/rollback information.

- [ ] **Q35. Is automatic override application explicitly deferred until separate evidence and approval exist?**
  - Recommended: Yes; do not set an automatic threshold now.
  - Answer: Yes; do not set an automatic threshold now.

- [ ] **Approved to begin Phase 8?**
  - Answer: Yes

## Objective

Correct a specific payload/report when the deterministic parser is wrong, without creating a misleading alias or directly patching normalized database rows.

## Entry Criteria

- Parser-bug issues can be linked reliably.
- Typed correction output has strong manual-review evidence.
- Historical-data and invalidation decisions are approved.

## Implementation Tasks

1. Add a focused migration/model for report overrides keyed by payload, report fingerprint, parser version, and paragraph fingerprint.
2. Define a narrow typed correction DTO and validation rules for the approved fields.
3. Apply active overrides to parsed DTOs before normalization.
4. Resolve every referenced canonical ID in PHP and reject new/missing/inactive targets.
5. Invalidate overrides when the paragraph, report fingerprint, parser version, or correction schema version changes.
6. Record creator/approver, review, bug signature/issue, original parse, corrected parse, and application/invalidation timestamps.
7. Reparse and deduplicate through the normal action; never directly update `trip_reports` or `species_counts`.
8. Add recursion prevention and an independent override kill switch.
9. Begin with manual approval only.

## Security and Data Integrity

- Reject arbitrary keys, SQL-like operations, URLs, and executable content.
- Validate counts as bounded nonnegative integers.
- Restrict entity choices to active canonical records of the correct type.
- Show the affected payload/date before approval.

## Verification

- Test every allowed and forbidden correction field.
- Test missing/inactive/wrong-type IDs and invalid counts.
- Test application before normalization and no direct normalized-row patches.
- Test fingerprint/parser-version invalidation.
- Test enable/disable, approval, reversal, reparse, deduplication, and recursion prevention.
- Test historical data is restored by disabling the override and reparsing.
- Run focused tests and Pint.

## Exit Gate

- Manual overrides consistently produce the approved corrected DTOs.
- Invalidation and rollback work reliably.
- Every parser-defect override is auditable and issue-linked.

## Rollout

Enable manual overrides for designated administrators and limited sources. Automatic application requires a future separately approved plan and evidence gate.

## Rollback

Disable overrides, mark affected overrides inactive, and reparse affected payloads/dates through the deterministic path.

## Deliverable

One pull request containing override schema, typed application layer, admin approval integration, invalidation/rollback logic, and tests.

