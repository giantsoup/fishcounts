# Phase 0 — Decisions and Evaluation Baseline

**Status:** Implementation complete; awaiting corpus approval and pull request
**Implementation dependency:** None  
**Secret-handling rule:** Never place API keys or tokens in this file.

## Decisions Required Before This Phase

- [x] **Q1. Should implementation use one pull request and one approval per phase?**
  - Recommended: Yes.
  - Answer: Yes

- [x] **Q2. Should the Luna fallback remain non-blocking?**
  - Recommended: Yes. Deterministic parsing and deduplication should complete even when OpenAI is unavailable.
  - Answer: Yes

- [x] **Q3. Are there known failures beyond the current database examples?**
  - Include the source paragraph, incorrect output, and expected output for each example.
  - Answer: Non known failures outside of what we have already handled.

- [x] **Q4. Are sanitized fish-count paragraphs and public source URLs safe to send to OpenAI, store as diagnostic context, and include in GitHub issues?**
  - Recommended: Permit only public fish-count text and URLs. Exclude HTML, cookies, request headers, credentials, and unrelated page content.
  - OpenAI answer: Yes, excluding your recommended list of items
  - Local storage answer: Yes, excluding your recommended list of items
  - GitHub issue answer: Yes, excluding your recommended list of items

- [x] **Q5. What historical scope should be used?**
  - Recommended: Process new payloads first; review unresolved and historical payloads only after shadow validation.
  - Answer: Process new payloads first; review unresolved and historical payloads only after shadow validation.

- [x] **Q6. May the implementation use Laravel's HTTP client without adding an OpenAI or GitHub SDK?**
  - Recommended: Yes.
  - Answer: Yes

- [x] **Q7. Which role may review and approve AI recommendations?**
  - Recommended: Existing administrators only.
  - Answer: Existing administrators only.

- [x] **May sanitized evaluation fixtures be committed to the repository?**
  - Recommended: Yes, provided they contain only public fish-count text and no credentials or private metadata.
  - Answer: Yes, provided they contain only public fish-count text and no credentials or private metadata.

- [x] **How should parser versions be identified for fingerprints and invalidation?**
  - Recommended: Use the explicit parser version already attached to each parsed report; never infer it from deployment time.
  - Answer: Use the explicit parser version already attached to each parsed report; never infer it from deployment time.

- [x] **Approved to begin Phase 0?**
  - Answer: Yes

## Objective

Create a labeled evaluation corpus and freeze the architectural decisions that later phases will use. This phase produces tests and planning data only; it does not call OpenAI, create GitHub issues, or alter production parsing behavior.

## Entry Criteria

- The questions above are answered.
- Public-versus-sensitive source data is clearly identified.
- The user agrees that later confidence thresholds will be based on measured results rather than model self-reported confidence alone.

## Implementation Tasks

1. Collect representative examples from current unresolved parser errors, corrected parser migrations, existing tests, and known silent failures.
2. Add clean examples that must not generate diagnostics.
3. For each example, record the expected diagnostic, classification, canonical target when applicable, corrected parse, and GitHub-issue decision.
4. Cover at least these categories:
   - Unknown but legitimate aliases.
   - New entity candidates.
   - Sentence fragments interpreted as species or boat names.
   - Fractional trip types such as `1/2 Day` and `3/4 Day`.
   - Incorrect anglers, retained counts, or released counts.
   - Reports that disappear instead of producing an error.
   - Clean structured and narrative reports.
5. Define measurable shadow-mode metrics:
   - Diagnostic recall on known defects.
   - False-positive rate on clean reports.
   - Human agreement with Luna classifications and corrections.
   - Invalid-schema and invalid-canonical-ID rates.
   - Average latency and token cost.
6. Record the agreed architecture decisions in the test names and phase answers, without duplicating secrets or environment-specific values.

## Verification

- PHPUnit data providers or fixtures represent every agreed failure category.
- Every fixture has a manually reviewed expected result.
- Fixtures contain no secrets, cookies, authentication headers, or private user data.
- Existing parser tests remain passing.

## Exit Gate

- The evaluation corpus is approved.
- Expected outcomes are unambiguous.
- The initial quality metrics are agreed.
- No external integration or production mutation has been introduced.

## Rollback

Remove the new evaluation fixtures and tests. There is no schema or production-data impact.

## Deliverable

One reviewable pull request containing only the approved evaluation corpus and any test-only supporting code.
