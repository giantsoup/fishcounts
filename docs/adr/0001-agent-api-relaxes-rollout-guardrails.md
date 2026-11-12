# An external agent API relaxes the parser rollout's raw-page and entity-creation rules

The LLM parser rollout says a model never receives the raw page (phase 2) and automation never creates new boats or species (phase 7). We are adding a token-authenticated REST API so an external coding agent can review parser errors, fix alias errors, and investigate parser bugs. That agent may read raw payload bodies, size-capped and passed through the existing secret redaction, and may create a boat or species when the raw value is plainly a real one. The phase 2 and phase 7 rules were written for the unsupervised in-app reviewer. This agent acts through a dedicated admin account whose token is scoped by ability, expires, is rejected once the account is deactivated, and can be revoked. Each write it makes is attributed to that account, and its aliases carry their own `agent_alias` resolution type. The in-app reviewer's rules are unchanged.

## Considered options

- **Drive the admin UI with a browser (Playwright).** Rejected as brittle. The UI also cannot create entities from the parser errors page.
- **Use the app's built-in AI review and GitHub issue flow.** Rejected because that reviewer sees only the error, not the code, so it cannot recommend a code fix.
- **MCP server instead of REST.** Deferred. REST works from `curl`, scripts and scheduled agents, and an MCP wrapper can be added later.
