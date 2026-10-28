# FishCounts security audit, 2026-10-28

One confirmed high-severity account-deactivation defect is fixed with HTTP regressions. Three broader security recommendations remain for review. Fresh dependency scans report no known advisories for the final locked versions. This assessment does not establish that the application is secure.

## Scope and starting state

- Repository: `giantsoup/fishcounts`, public GitHub repository, default branch `main`.
- Final reviewed base: `bc4e51d715a7a9ccb4478aa2dba75a5677faf8a0`, fetched from `origin/main` before publication. The initial base was `c0acc3fca011caba3852471614c10e30976ca62f`; an upstream dependency migration landed during the assessment, and the audit was rebased and revalidated against it.
- Audit branch: `codex/security-audit-20261028-a7f3`, created in a managed, initially clean worktree. The prior checkout and its uncommitted work were left untouched.
- Runtime: PHP 8.4.27; locked Laravel 13.39.0, PHPUnit 13.4.5, Breeze 2.4.2, Boost 2.10.3, MCP 1.1.2, Pint 1.32.1. Frontend: Blade, Alpine, Tom Select, Flatpickr, Tailwind 4, Vite 8.3.5. Node 26.13.0 and npm 12.2.1 match the final base toolchain.
- Authentication: Eloquent user provider and the `web` session guard; administrator/user roles. Public registration is not registered. Email verification is optional in this version.
- Persistence: SQLite, MySQL/MariaDB, PostgreSQL, and SQL Server configurations; database/Redis cache and queues; array/file/database sessions; local/private/public disks and an optional S3 disk. No search service, custom storage adapter, broadcast channel, GraphQL operation, Livewire action, or separate API route file was found.
- External integrations: source and environmental HTTP fetchers, booking providers, OpenAI extraction/review/research, GitHub App issue publishing, email, and Discord webhooks.
- Assessment covered route registration, access middleware, controller/request/policy boundaries, sensitive service callers, frontend output sinks, persistence configuration, package advisories, tracked configuration, and deployment/CI source. It was not an exhaustive line-by-line review of every class.

## Execution isolation

No development or production environment file, cached configuration, database, uploaded file, or log was copied into the audit checkout. Dependency installation used the existing locks with Composer scripts/plugins and npm lifecycle scripts disabled. This audit did not change dependency manifests or lockfiles; it installed the updated locks inherited from the final base.

Application commands ran through an owner-only local PHP wrapper with a cleared inherited environment. The effective database was SQLite `:memory:` with no database URL; cache, session, and mail were array-backed; filesystem access stayed in this disposable checkout. The queue default was synchronous, with queue/notification fakes in denial tests. Explicit database queue and cache connections also resolved to the in-memory database. Integration flags and credentials were absent or disabled. PHP outbound connection functions and URL-based file reads were disabled. No workers, scheduler, deployment command, live AI test, live endpoint probe, or application network request was run.

Boot inspection covered application providers, configured services, console registration, explicit connections, dependency hooks, and discovered HTTP clients. The runtime inventory also resolved the HTTP kernel to obtain expanded middleware. A bare console route listing in this installation retained middleware aliases.

Version-specific Boost documentation was searched before implementation. After the managed worktree move, that MCP tool was unavailable; official Laravel 13 documentation supplied the fallback.

## F1. Deactivated accounts retained session and remembered access

**Status:** confirmed, fixed. **Severity:** high. **Evidence:** HTTP integration with synthetic users, real session guard, encrypted remember cookies, and registered routes.

The intended rule is established by `LoginRequest::authenticate`, which requires an active account, and `UserController::update`, which deletes database sessions on deactivation. The original `auth` middleware restored accounts by session identifier or remember token without checking `is_active`. Deleting database sessions alone did not revoke remember cookies or sessions stored by another supported driver.

Synthetic inactive normal users and administrators could still reach protected pages. The original code also accepted a profile change and an administrator's valid user-creation request. Administrator access made this a privileged-access revocation failure, rather than only a stale UI session.

The correction is `app/Http/Middleware/EnsureUserIsActive.php:17`, registered for the web group at `bootstrap/app.php:17`. It logs out a restored inactive account, rotates its remember token through Laravel's logout behavior, invalidates the session, regenerates its CSRF token, and redirects to login before the controller action. Active users and administrators retain their existing permitted workflows. No schema or dependency change is required.

`InactiveSessionSecurityTest` covers existing sessions and remember cookies for both roles, profile-write denial, administrator user-creation denial, session invalidation, expired recaller cookies, token rotation, and active controls. The final tests were rerun with the original base bootstrap temporarily restored only in this run-owned checkout, then restored in a `finally` block. Six deny scenarios failed against the original behavior, and three active controls passed. All nine pass with the correction. Database and notification assertions verify denied actions have no corresponding effect.

In-flight requests and worker behavior are separate boundaries. This middleware does not promise to cancel already-running work or define whether a disabled account should continue receiving previously configured notifications.

## Dependency assessment

Fresh `composer audit --locked` and `npm audit --package-lock-only` runs on the final base report zero advisories. Composer also reports no abandoned packages. These results supersede the earlier lockfile findings from the initial base. The audit itself makes no dependency change and does not attribute the upstream migration to this PR.

The final base includes Laravel 13.39.0, Guzzle 8.3.1, CommonMark 2.10.4, and Symfony HTTP Foundation 8.1.10. Applicability review covered the configured session guard, fixed relationship names, debug-page conditions, exact host allowlists, Markdown mail, signed local files, application-defined MIME parameters, and absence of untrusted YAML endpoints. Frontend dependency review distinguished build/dev-server packages from shipped assets. No public arbitrary build-command, source-map, CSS, Markdown, or YAML input endpoint was discovered.

The earlier HTTP Foundation warning is covered by the updated final lockfile according to the [maintainer advisory](https://symfony.com/cve-2026-108082). Primary applicability references also included the [Laravel TokenGuard advisory](https://github.com/laravel/framework/security/advisories/GHSA-f9x3-7vq9-cph6), [relationship-inclusion advisory](https://github.com/laravel/framework/security/advisories/GHSA-5wcm-3v2w-8362), [Guzzle host advisory](https://github.com/guzzle/guzzle/security/advisories/GHSA-v5mv-p594-2x33), [CommonMark parser advisory](https://github.com/thephpleague/commonmark/security/advisories/GHSA-j8pm-gj4c-rq4x), [Flysystem advisory](https://github.com/thephpleague/flysystem/security/advisories/GHSA-cxf4-7mrp-vvpr), and [YAML advisory](https://symfony.com/cve-2026-109413). One Mime maintainer page was inaccessible through the web tool; its feed metadata and caller review were used instead. Zero scanner advisories is not proof of complete security.

## Deferred recommendations

### D1. Enforce outbound destination rules across redirects

**Status:** source-confirmed control gap; exploitation not reproduced. **Severity:** medium, conditional on remote-source or provider behavior.

`HttpSourceFetcher` and `EnvironmentalHttpFetcher` validate the initial HTTPS hostname but retain the HTTP client's automatic redirect behavior. Booking clients fetch saved provider URLs without the same shared destination checks. `OfficialRegulationResearcher` already rejects redirects and bounds response size, providing an existing stricter pattern.

Propose one approved destination policy for source, environmental, booking, and Discord clients. Reject redirects or validate every hop, constrain schemes/authorities, and bound response bytes. Defer implementation until legitimate cross-host provider redirects and configured endpoints have been mapped; a blanket redirect change could break supported providers.

Acceptance requires synthetic transport-level redirect tests, allowed-provider controls, rejected disallowed destinations, response-size limits, and retry/failure behavior checks. Laravel HTTP fakes alone do not exercise Guzzle's redirect transport. This run did not access internal addresses or probe a real provider.

### D2. Redact Discord failures before logging or retry persistence

**Status:** source-confirmed incomplete redaction; no real secret or live failure inspected. **Severity:** medium.

`UpdateNotificationSettingsRequest` accepts two Discord hostnames. Notification error handlers mask one hostname. `DiscordWebhookSender` propagates upstream transport exceptions, and notification jobs rethrow them after recording their masked delivery message. Raw exception chains can therefore reach failed-job or application logging paths even when the delivery message was masked.

Propose sanitization at the Discord client boundary and a safe retry exception contract. Acceptance requires synthetic connection and HTTP failures for both supported hostnames, assertions over delivery records and failed-job/log serialization, and successful email/Discord controls. No webhook rotation or shared log cleanup is authorized by this audit. Existing failure-isolation tests depend on exception behavior, so changing the shared retry/error contract needs its own focused review.

### D3. Make the optional Docker production environment explicit

**Status:** source-confirmed configuration mismatch; deployment usage unverified. **Severity:** medium if that deployment path is used.

The base Compose application sets a local environment. The production overlay does not override that value. The documented active VPS path is separate and uses Nginx/PHP-FPM/MariaDB; no evidence establishes that the Docker path is deployed.

Before using the Compose production path, explicitly set its production environment and review inherited bind mounts, database configuration, debug flags, and exposed ports. Validate the merged configuration with synthetic environment values, run the existing production checks in a disposable container, and verify only the public document root is served. Container execution and live server configuration were outside this run.

## Route inventory and access coverage

`SECURITY-AUDIT-20261028-routes.csv` records each domain/method/path/name/action, guard, expanded middleware, expected allow/deny case, scope, rule source, and evidence state.

The isolated testing and production-like inventories each registered 95 routes and 140 method rows: 85 administrator rows, 37 other authenticated rows, nine guest authentication/recovery rows, six public redirect/health rows, and three signed-file rows. GET and HEAD are separate rows; resource PUT and PATCH are separate rows. A local-environment inventory added one conditional Boost browser-log POST route. The companion has 141 data rows including that optional route. It is absent with production-like environment and debug disabled. This does not establish live registration or cached-route state.

Access rules in the companion are:

| Rule | Intended access and source |
| --- | --- |
| A | Active administrator; global operational/user/reference data. Product plan section 2.1, admin route group, middleware, policies, and Form Requests. Nested parser actions also validate matching review/error/payload and state. |
| U | Active authenticated account; shared normalized fish data. Product plan section 2.1 and dashboard/count controllers. |
| R | Own alert-rule lists/creation; owner or policy-authorized administrator for individual mutations. `AlertRulePolicy`, controller, and store/update requests. |
| S | Own score data and own-score resend. Scores request/controller and `ScoreHotBiteEmailController`. |
| N | Own settings; destination owner or explicit administrator policy exception for testing. `NotificationDestinationPolicy` and settings controller/request. |
| E | Own alert history, scoped by `whereBelongsTo`. `AlertHistoryController`. |
| P | Current account/profile/session with validated fields and applicable current-password checks. Auth/profile controllers and requests. |
| V | Current account email verification; signed route, matching ID/hash, throttling. Auth routes and verification request. |
| G | Guest login/recovery; active credential condition, login rate limit, and password broker tokens. Auth routes, `LoginRequest`, and password broker config. |
| H | Intentional public redirect and minimal health responses. Web routes and application health registration. |
| L | Correct unexpired signature for the exact private disk path and download/upload purpose. Filesystem config and framework file handlers. |
| B | Conditional development logging endpoint; absent in production-like inventory. Boost provider registration conditions. |

The new denial sweep requests all 122 authenticated method rows as a guest. It also requests all 85 administrator rows as an ordinary user. Parameterless admin cases require 403; parameterized cases can return 403 or 404 because model binding can precede the admin check. Placeholder IDs deliberately limit those cases to negative reachability checks. They do not prove record authorization. Valid synthetic owner/admin controls in the focused suites prove selected real workflows, rather than every parameterized route.

The three signed-file method rows have unsigned, valid, changed-path, expired, and wrong-purpose checks using a local fake disk. Additional route tests pair cross-user rule-update/deletion denials with successful owner controls and reject an injected ownership field. A profile-write test runs the real route with the framework's testing CSRF exemption disabled through the application environment binding, verifies 419 without a token and no write, then verifies a valid token permits the write. Resource configuration remains isolated throughout.

All 140 normal method rows have an access-boundary source-review disposition. At least 125 have explicit negative HTTP scenarios from the new route/signed-file sweep. Full allowed behavior, HEAD semantics, provider behavior, alternate guards, and engine-specific concurrency were not tested for every row. No route was blanket-locked solely because it was public.

## Other components

| Component | Disposition and evidence |
| --- | --- |
| Blade/JavaScript/mail | Source-reviewed escaped Blade output, URL attributes, inline data, polling HTML, and Markdown callers. Raw-payload escaping is HTTP-tested. Polling HTML is rendered by server-owned escaped partials. No input-driven script execution or browser token persistence was found in the searched sources. No browser/DOM verification was performed. |
| Input/query/model boundaries | Reviewed validated field maps, role changes, policy calls, owner scopes, fixed SQL expressions, and fixed relationship lists. Focused HTTP tests cover representative cross-user writes, score filters/resend, profiles, and administration. No mass-assignment path from ordinary-user input to role was confirmed. |
| SQL/cache/session/queues | Source-reviewed configured destinations and explicit database stores/connections. SQLite integration verifies application effects only. No live privileges, Redis exposure, real engine locks, shared caches, or production sessions were inspected. |
| Files/public/static delivery | Inventoried tracked public files and checked this worktree for public symlinks. Local files are signed; public disk linkage remains intentionally public. No app upload/export controller or application-issued object URL was found. Remote bucket ACL/IAM, server aliases, backups, and live symlinks are unverified. |
| Scheduled/queued work | Reviewed dispatch entry points, stored actor/resource IDs, AI/GitHub feature gates, and notification send/retry paths. Selected HTTP tests fake and assert queued effects. Whether deactivation cancels existing notifications is a product decision; the request fix does not claim worker revocation. |
| Scraping/booking/environmental integrations | Source-reviewed initial allowlists, timeouts, retries, provider URL construction, response consumption, and remote-content rendering. Redirect and size consistency remain D1. No live providers were called. |
| AI and issue publishing | Source-reviewed extraction schemas, tool restrictions, diagnostic sanitization, approval/state matching, feature gates, budget/dispatch callers, and GitHub credential loading. Parser-review/override HTTP regressions pass. Model/provider behavior and automatic external publication were not exercised. |
| Secrets/logs | Limited redacted current-tree pattern scan covered 729 tracked files and found no matching credential candidate. Only the environment example is tracked; the scan did not print values. No dedicated secret scanner was installed. Historical scanning, all token formats, private runtime files, and live log retention remain gaps. D2 addresses error-path handling. |
| Deployment/CI | Reviewed GitHub workflow permissions, PR tests/lint, main-only push deployment, artifact packaging, deployment script, Docker files, and Nginx example. The audit branch does not match production deployment triggers. No cloud/server settings or deployment actions were accessed. D3 covers the optional Compose mismatch. |
| Absent entry-point types | No application broadcast channels, GraphQL, Livewire, webhook/callback controller, search index, archive import, arbitrary shell endpoint, or custom filesystem adapter was discovered. These types are not applicable to this source inventory. |

## Executed checks

- Locked Composer install without scripts/plugins; locked npm install without lifecycle scripts. No manifest/lockfile changed.
- `php artisan route:list --help --no-interaction` and `route:list --json -vv --no-interaction`, followed by isolated HTTP-kernel middleware expansion and local/production-like registration comparisons.
- `composer audit --locked --format=json --no-interaction --no-plugins`: passed on the final base with zero advisories and zero abandoned packages.
- `npm audit --package-lock-only --json --ignore-scripts`: passed on the final base with zero advisories.
- `npm run check:platform` and `npm run build` with a cleared application environment: passed with the final base toolchain.
- `vendor/bin/pint --dirty --format agent`: passed after formatting.
- Original-behavior regression: nine deactivation scenarios, six expected failures and three passing active controls. The corrected focused file passes all nine.
- Final targeted PHPUnit run: **126 tests, 1,058 assertions, zero failures, zero skips**. It includes `InactiveSessionSecurityTest`, `RouteAccessSecurityTest`, `AdminAuthorizationTest`, `AdminUserManagementTest`, `PolicyAuthorizationTest`, `Auth/`, `ProfileTest`, `AuthAndDashboardTest`, `AlertRuleManagementTest`, `NotificationSettingsTest`, `RawPayloadSecurityTest`, `CountsAndScoresIndexTest`, `ParserDiagnosticHumanReviewTest`, and `ParserReportOverrideTest`.
- PHPUnit ran directly through the isolated PHP wrapper so outbound restrictions applied to the actual test process. The full suite was offered separately and was not run during this assessment. Live/cost-incurring tests were not run.
- `git diff --check` passed. Outgoing paths and commit contents are reviewed before publication. Remote CI is not a substitute for these results and was not awaited.

Review is required before merge. The audit branch is retained for that review. Nothing was merged, auto-merged, deployed, or activated. Production configuration and the deferred recommendations remain unresolved by this PR.
