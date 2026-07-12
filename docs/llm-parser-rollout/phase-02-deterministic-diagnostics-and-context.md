# Phase 2 — Deterministic Diagnostics and Context

**Status:** Implementation complete
**Implementation dependency:** Phase 1 deployed and verified  
**Secret-handling rule:** Diagnostic context must never contain credentials, cookies, or request headers.

## Decisions Required Before This Phase

- [x] **Are the initial diagnostic categories approved?**
  - Proposed: unknown alias, fractional trip conflict, prose captured as an entity,
    excessive name length, unaccounted numeric tokens, empty or unexpectedly small
    result set, structured-source fallback, and extracted-value/source-span mismatch.
  - Answer: Yes

- [x] **What clean-corpus false-positive ceiling is acceptable?**
  - Recommended starting target: less than 5%, with every known regression detected.
  - Answer: less than 5%, with every known regression detected.

- [x] **What maximum paragraph/context length may be stored?**
  - Recommended: retain the complete sanitized fish-count paragraph with a configurable upper bound; never retain the full page HTML.
  - Answer: retain the complete sanitized fish-count paragraph with a configurable upper bound; never retain the full page HTML.

- [x] **Should repeated bad values in different paragraphs remain separate occurrences?**
  - Recommended: Yes, using a report/paragraph fingerprint.
  - Answer: Yes, using a report/paragraph fingerprint.

- [x] **How should an “unexpectedly small” result be defined?**
  - Recommended: Source-specific evidence only; do not use a global report-count threshold.
  - Answer: Source-specific evidence only; do not use a global report-count threshold.

- [x] **Should fingerprints invalidate when the parser version or sanitized source paragraph changes?**
  - Recommended: Yes.
  - Answer: Yes

- [x] **Approved to begin Phase 2?**
  - Answer: Yes

## Objective

Detect both existing unknown-alias errors and plausible-but-wrong parses before Luna is involved. Persist enough sanitized provenance for a person or model to understand one report without receiving the raw page.

## Entry Criteria

- Phase 1 behavior parity is verified.
- Phase 0 evaluation fixtures cover known silent and explicit errors.
- The context privacy and length decisions above are approved.

## Implementation Tasks

1. Generate typed diagnostic enums and DTOs.
2. Implement a `ParsedReportValidator` with one isolated rule per diagnostic category.
3. Run validation after deterministic parsing and before persistence decisions are finalized.
4. Populate parser-error context with source, date, URL, parser version, format, report index or source identifier, sanitized paragraph, extracted fields, and deterministic evidence.
5. Add stable diagnostic and report fingerprints using documented normalized inputs and a stable hash.
6. Update occurrence identity so identical raw values from different paragraphs do not collapse into one error.
7. Add reversible, focused migrations for fingerprint columns and indexes; do not modify deployed migrations.
8. Add a feature flag that can disable newly introduced suspicious-parse diagnostics without disabling established unknown-alias errors.
9. Preserve an actionable parser error for uncertain diagnostics and later best guesses.

## Security and Privacy

- Sanitize HTML before context is constructed.
- Apply explicit length limits with UTF-8-safe string helpers.
- Store no authentication material or unrelated page text.
- Escape diagnostic content whenever rendered.

## Verification

- Add a PHPUnit test for every diagnostic rule and clean counterexample.
- Test valid fractional and decimal trip types.
- Test repeated values across multiple paragraphs.
- Test fingerprint stability and invalidation.
- Test source-specific low-result rules.
- Test context sanitization, length limits, and Unicode boundaries.
- Test reparse idempotency and uniqueness constraints.
- Run the relevant parsing tests and Pint.

## Exit Gate

- Every approved regression fixture is detected.
- Clean-corpus false positives remain within the approved ceiling.
- Context is sufficient for review and contains no disallowed data.
- New diagnostics can be disabled independently.

## Rollout

Deploy the migration first, then compatible application code with the new diagnostic flag disabled. Enable it for limited sources before expanding.

## Rollback

Disable the new diagnostic flag. Keep additive schema in place until code rollback is complete; reverse the migration only when no deployed code references it.

## Deliverable

One pull request containing diagnostic types, validator rules, provenance/fingerprints, migrations, feature flag, and regression tests.
