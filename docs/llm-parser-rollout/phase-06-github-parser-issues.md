# Phase 6 — GitHub Parser-Bug Issues

**Status:** Not started  
**Implementation dependency:** Phase 5 review quality supports parser-bug classification  
**Secret-handling rule:** Never put a GitHub token in this file, the database, logs, prompts, or issue bodies.

## Decisions Required Before This Phase

- [ ] **Q16. What repository should receive parser-bug issues?**
  - Expected current repository: `giantsoup/fishcounts`; confirm before implementation.
  - Answer: giantsoup/fishcounts

- [ ] **Q17. Is the repository public or private, and may sanitized paragraphs/source URLs appear in its issues?**
  - Visibility: Public
  - Paragraph/URL answer: Yes

- [ ] **Q18. What authentication method should be used?**
  - Recommended long-term: GitHub App. Simplest initial option: fine-grained PAT restricted to this repository with `Issues: write`.
  - Answer: GitHub App. Simplest initial option: fine-grained PAT restricted to this repository with `Issues: write`.
  - Secret location/status only: .env file

- [ ] **Q19. Which labels should be applied, and may missing labels be created?**
  - Recommended: `parser-bug`, `llm-detected`, and an existing source label when available.
  - Labels: `parser-bug`, `llm-detected`, and an existing source label when available.
  - Create missing labels: Yes

- [ ] **Q20. What issue-title format should be used?**
  - Recommended: `[Parser][source-slug] Short description of incorrect parse`.
  - Answer: `[Parser][source-slug] Short description of incorrect parse`

- [ ] **Q21. What confidence threshold permits automatic issue creation?**
  - Recommended provisional value: `0.95`, subject to Phase 4–5 evidence and PHP validation.
  - Answer: `0.95`, subject to Phase 4–5 evidence and PHP validation.

- [ ] **Q22. Should the first five issue candidates be previews requiring approval?**
  - Recommended: Yes.
  - Answer: Yes

- [ ] **Q23. How should recurring occurrences be handled?**
  - Recommended: Count/link locally without repetitive comments; comment only for materially different reproductions if later approved.
  - Answer: Count/link locally without repetitive comments; comment only for materially different reproductions if later approved.

- [ ] **What should happen when a matching GitHub issue is already closed?**
  - Recommended: Link and count locally; require human approval before reopening or commenting.
  - Answer: Link and count locally; require human approval before reopening or commenting.

- [ ] **Q24. Should issues have an automatic assignee or milestone?**
  - Recommended: Neither initially.
  - Answer: Automatically assign me (Taylor Oyer)

- [ ] **Q25. Should issue bodies include a copy-ready Codex task?**
  - Recommended: Yes.
  - Answer: Yes

- [ ] **Approved to begin Phase 6?**
  - Answer: Yes

## Objective

Create actionable, sanitized, deduplicated GitHub issues for validated parser defects without giving Luna credentials or issue-creation tools.

## Entry Criteria

- The repository, visibility, auth, labels, and privacy decisions are answered.
- GitHub credentials are provisioned outside source control.
- Parser-bug classifications meet the agreed review quality.

## Implementation Tasks

1. Add a focused migration/model for parser bug reports keyed by a stable bug signature.
2. Store issue number, URL, status, first/last seen, occurrence count, and linked review.
3. Define an `IssueTracker` contract and a GitHub HTTP implementation.
4. Create a separate `CreateParserBugIssueJob`; GitHub failures must never rerun or rebill Luna.
5. Render titles and bodies from application-owned templates using validated facts only.
6. Include source/parser version, minimal reproduction, actual parse, expected parse, deterministic evidence, review ID/confidence, regression-test suggestion, acceptance criteria, and approved Codex prompt.
7. Add atomic locking and database uniqueness around issue signatures.
8. Add an independent GitHub-write flag and preview mode.
9. Implement the approved closed-issue and recurrence policy.

## Security and Privacy

- Luna never receives GitHub tools or credentials.
- Sanitize and bound all issue fields.
- Restrict the token to the approved repository and minimum permission.
- Never log authorization headers.

## Verification

- Use HTTP fakes and prevent stray requests.
- Assert the exact sanitized issue title/body and fixed repository.
- Test permissions, validation failures, secondary rate limits, server failures, and timeouts.
- Test concurrent duplicates and existing signatures.
- Test open and closed issue recurrence behavior.
- Confirm GitHub failure does not dispatch another OpenAI review.
- Run focused tests and Pint.

## Exit Gate

- The first approved preview batch produces useful issue content.
- Duplicate/noisy issues are prevented.
- GitHub outages have no parsing or Luna-review impact.

## Rollout

Deploy with GitHub writes off, generate previews, approve the initial batch, then enable high-confidence issue creation separately.

## Rollback

Disable GitHub writes. Preserve local bug signatures and issue links. Drain/no-op queued issue jobs before reverting incompatible job code.

## Deliverable

One pull request containing issue audit schema, contract/client/job, deterministic templates, feature flags, and dedupe/error tests.

