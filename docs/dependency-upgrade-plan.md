# Implement the deferred dependency upgrades from PR #56

Status: implementation prepared in this isolated branch. See [migration results](dependency-migration-results.md) for verified changes, all 69 candidate decisions, and remaining review gates. The original planning checklists below are retained as the approved design.

Source: [FishCounts PR #56](https://github.com/giantsoup/fishcounts/pull/56), read on 28 October 2026, at head `aed4c40924870e0bb20cae82d00c83838638bda4`.

Planning branch: `codex/dependency-upgrade-plan-20261028`, based on that PR head in a separate worktree. Keep the existing `codex/limits-inference` checkout and its unfinished work separate.

## Scope and intended result

Implement the two proposed manifest edits and assess every deferred candidate in the PR description. Also migrate the project toolchain to Node 26 with the latest compatible stable npm, as requested after the initial plan. The inventories account for all 69 deferred rows plus the related runtime, CI, Docker, lockfile, and documentation changes.

The 118 upgrades already applied in PR #56 are the prerequisite baseline. Do not repeat them. Both GitHub checks for that exact PR head passed when this plan was prepared. The previous local run reported 927 passing tests, one intentionally skipped live test, and zero Composer or npm audit findings. Establish a fresh baseline before implementation.

This request authorizes preparation of the plan. Implementing dependency changes, publishing another PR, merging, and deploying remain future work.

Use one implementation branch with a separately verified commit for each phase. Keep a package-level record of the final version, removed instances, tests, and unresolved blockers. Revisit the cited versions when implementation starts; they are the candidates from this PR, not a promise that they remain latest.

### Proposed manifest edits

| File | Package | Current | Proposed in PR #56 | Intended locked target | Phase |
| --- | --- | --- | --- | --- | --- |
| `composer.json` | `phpunit/phpunit` | `^12.5.12` | `^13.4` | `13.4.5` | 3 |
| `package.json` | `tailwindcss` | `^3.1.0` | `^4.3` | `4.3.3` | 5 |

Boost `^2.4` already admits `2.10.3`. Phase 0A adds Node/npm requirements and a package-manager pin to `package.json`. The other dependency constraints remain unchanged unless a later compatibility finding requires a separately reviewed edit. Remove obsolete frontend dependencies only after their replacements work and no remaining code uses them.

### Rules for the final dependency graph

- Change transitive packages through the parents that require them. Do not add transitive packages to root manifests just to force the listed versions.
- Apply the Node/npm migration explicitly in Phase 0A. Preserve PHP and host-service versions, registries, audit policy, and peer checks. Do not use `--force`, `--legacy-peer-deps`, platform ignores, or security-policy bypasses.
- Review every added, removed, moved, or changed lock instance, including optional binaries unavailable on the local Mac.
- Keep expected major changes explicit. The routine maintenance skill's checker intentionally rejects these majors; do not weaken that checker or describe an expected rejection as a passing policy check. Compare migration changes against this plan's target set and report every additional major separately.
- Treat a package removed with an obsolete parent as resolved by removal. Preserve an upstream constraint when the proposed version cannot fit the supported parent graph, and record the exact blocker.
- Prefer the final targets `brick/math 1.0.0` and `laravel/mcp 1.1.2`. The PR's `0.20.0` and `0.9.7` candidates are alternatives, not additional packages to install alongside them. Use an intermediate version only if its migration guide requires a staged transition.

## Phase 0: establish the implementation baseline

1. Read the current `AGENTS.md`, manifests, lockfiles, CI workflows, and relevant Laravel and frontend guidance.
2. Confirm whether PR #56 is still open. While open, branch from its verified head and record that dependency. After it merges, use the resulting `main` commit. Do not duplicate the maintenance commit through a second merge.
3. Record the starting commit, clean worktree status, manifest hashes, and complete lock inventories. Preserve copies outside Git for recovery.
4. Use the existing Herd PHP runtime. Resolve Composer explicitly if `herd composer` finds the older global executable. The earlier run used `herd php '/Users/Taylor/Library/Application Support/Herd/bin/composer'`, version 2.10.3.
5. Inspect install hooks, then install the committed locks with development packages. Avoid `composer run setup`, which runs application migrations.
6. Use SQLite `:memory:`, a dummy application key, array mail/cache/session stores, and no production credentials. Keep live OpenAI opt-in variables unset and GitHub write features disabled.
7. Run the full backend suite, Pint in check-only mode, Composer validation and platform checks, both security audits, `npm ls --all`, and the frontend build. Record existing failures and skips before proceeding.
8. Record the existing runtime versions before Phase 0A. The observed local versions were Node 26.12.1 and npm 12.2.1; both build workflows still select Node 24. Establish this original baseline before switching the implementation environment to the new toolchain.

Exit gate: a repeatable baseline with no unexplained failing required check. This phase must not modify shared databases or production services.

## Phase 0A: standardize Node 26 and the latest compatible npm

Run this phase before the dependency migration phases. It separates toolchain regressions from changes to the application packages.

### Verified version targets

| Tool | Observed current state | Target verified on 28 October 2026 | Reason |
| --- | --- | --- | --- |
| Node.js | Local 26.12.1; CI 24; Docker uses Alpine's unpinned `nodejs` | `26.13.0`, Node 26 LTS | Latest published stable release in the requested major |
| npm | Local 12.2.1; other environments inherit their Node/image package | `12.2.1` | Latest stable npm; its Node engine range includes `>=26.0.0` |
| Node's bundled npm | Node 26.13.0 publishes npm 11.20.0 in its distribution | Replace with npm 12.2.1 in each build/development environment | Installing Node alone does not select the requested latest npm |

Recheck the official release index and npm's stable dist-tag at implementation time. Stay on the Node 26 line. If either target changes, review its notes and npm engine compatibility, then update all pins together. Do not use a floating `npm@latest` in committed build steps.

Sources: [official Node distribution index](https://nodejs.org/dist/index.json), [Node release status](https://nodejs.org/en/about/previous-releases), [npm stable package metadata](https://registry.npmjs.org/npm/latest), and [npm 12.2.1 release](https://github.com/npm/cli/releases/tag/v12.2.1).

### Update every toolchain entry point

| ID | File or environment | Planned change | Verification |
| --- | --- | --- | --- |
| R01 | New `.nvmrc` | Pin `26.13.0` using the existing nvm workflow; do not introduce another version manager | `nvm use` selects the exact recorded release |
| R02 | `package.json` | Add `engines.node: ">=26.13.0 <27"`, `engines.npm: ">=12.2.1 <13"`, and `packageManager: "npm@12.2.1"` | CI explicitly asserts actual versions; metadata alone is not enforcement |
| R03 | Local implementation environment | Select the pinned Node installation and install npm 12.2.1 into that nvm-managed Node version | Record `node --version`, `npm --version`, and executable paths; leave other Node installations and the PHP runtime alone |
| R04 | `.github/workflows/tests.yml` | Replace Node 24 selection with `node-version-file: '.nvmrc'`; explicitly install the pinned npm before `npm ci` | Test job logs show Node 26.13.0 and npm 12.2.1 before installation/build |
| R05 | `.github/workflows/deploy.yml` verify/build job | Use the same Node pin and npm bootstrap as tests | Release assets are built and checked with the same toolchain as tests |
| R06 | `Dockerfile` | Replace unpinned Alpine `nodejs`/`npm` installation with an explicit Node 26 asset-build stage and pinned npm | Verify stage versions, native optional packages, production build, and final PHP image |
| R07 | `package-lock.json` | Synchronize root engine metadata using the selected npm; keep lock v3 unless a reviewed necessity requires another format | Complete before/after inventory has no unintended dependency version or source changes in this toolchain-only phase |
| R08 | `README.md` | Update tech stack and local prerequisites to Node 26, exact npm setup, `.nvmrc` use, and the supported toolchain policy | A fresh setup follows the documented path; update PHPUnit/Tailwind versions after their phases pass |
| R09 | `.npmrc` and Docker build context | Keep `ignore-scripts=true` and `audit=true`; make the same config available before Docker's npm install | Effective build policy matches local/CI policy, with credential-related settings redacted from evidence |
| R10 | New `.dockerignore` | Exclude real `.env` files, Git data, local installed dependencies, and stale build output from the asset-build context; keep required example/config/source inputs | Build context cannot overwrite the container's clean install with host `node_modules`; required source and vendor inputs still arrive through intended stages |
| R11 | Existing tests, cache keys, scripts, and Compose entry points | Update actual Node/npm-version assumptions and affected workflow assertions; verify every service still builds from the revised Dockerfile | No obsolete Node 24 pin remains in active build configuration; no unnecessary new Node service or runtime process |

Tasks:

- [ ] Review Node 26 release notes across the currently used CI/build version gap and npm 12 release notes relative to each environment's existing npm.
- [ ] Select Node 26.13.0 through nvm. Install npm with an exact registry version, `npm install --global npm@12.2.1`, inside that selected Node installation. This is a future implementation step, not a command run during this planning request.
- [ ] Apply R01-R05, R07-R09, and actual affected test/script assumptions. Have workflow bootstrap read the intended npm version from the manifest, then assert both actual versions before `npm ci`. Do not assume `packageManager` automatically installs npm or requires adding Corepack.
- [ ] Keep GitHub Actions caching scoped to the updated lock/toolchain where necessary. Verify a cold install as well as any reused cache, so an old cache cannot mask incompatible native binaries.
- [ ] Synchronize lock metadata without unrelated package updates. If the chosen npm produces other changes, inspect them and either isolate them into their migration phase or restore this phase's own baseline lock before retrying.
- [ ] Run a clean `npm ci`, `npm ls --all`, `npm audit`, and `npm run build` under the target pair. Preserve lifecycle-script restrictions. If a dependency needs a native build script, inspect and address that package specifically instead of globally enabling scripts.
- [ ] Verify Alpine/Vite start, HMR, process termination, and production asset loading. Verify Linux x64 and the local Mac architecture, including the matching Rolldown, Tailwind Oxide, and Lightning CSS optional binaries.
- [ ] Re-run the PHP suite with the new built assets. Confirm that the build-only toolchain change did not alter application behavior or require a PHP/runtime service update.

### Make the Docker build reproducible

The current Dockerfile installs Node and npm through `apk add`, runs `npm ci --ignore-scripts`, then builds assets in the FrankenPHP image. It does not pin Node 26. The repository also lacks `.dockerignore`.

Use the official Node image in a separate asset-build stage. Before choosing an exact Alpine tag, verify that the Node 26.13.0 image is published for the required architectures; record its image digest. Keep the npm version explicit in that stage. Do not copy glibc-linked Node binaries into the Alpine PHP image. Follow the [official Node Docker image guidance](https://github.com/nodejs/docker-node/blob/main/README.md).

Provide that asset stage with manifests, lock, `.npmrc`, Vite/Tailwind configuration, application sources, images, Blade views, and any Laravel vendor templates required for CSS source scanning. Obtain vendor templates from the controlled Composer stage rather than a host `vendor` directory. Preserve build order and ensure local `.env` or cached assets cannot enter the image.

Copy the resulting `public/build` into the final FrankenPHP stage. Remove the final stage's Node/npm packages only after confirming no application runtime process needs them. Preserve PHP extensions, Composer/runtime requirements, permissions, entry point, and web/queue/scheduler behavior.

Build every Compose application service against the revised Dockerfile without starting queues, scheduler processes, or shared databases as part of the build check. Verify the production manifest and assets inside the final image. The native GitHub deployment already uploads built release assets; the SSH deployment script has no npm build step, so do not install Node on the production PHP host merely to match the CI version. Keep its release checksum, asset, health, and rollback checks intact.

Exit gate: local development, test CI, release builds, and Docker asset builds use the same reviewed Node 26/npm pair; all original required checks pass; the toolchain-only commit has no unexplained package graph change. Keep any unresolved `EALLOWREMOTE` finding for Phase 4. Updating Node does not itself prove that fetch-policy blocker is fixed.

## Phase 1: update Boost, Roster, and MCP together

Targets: Boost `2.4.13` to `2.10.3`, Roster `0.5.1` to `1.0.0`, and MCP `0.8.2` to `1.1.2`.

The current Boost release requires Roster `^0.5.0` and does not admit MCP 1. The target Boost release requires Roster `^1.0.0`, admits MCP `^1.0`, and admits Guzzle 8. Complete this phase before the HTTP-client migration.

Tasks:

- [ ] Read the full relevant Boost, Roster, and MCP release gaps, including MCP 1.0 behavior changes.
- [ ] Resolve all three packages as one bounded Composer group using temporary target constraints and dependency resolution. Keep `composer.json`'s Boost constraint unchanged.
- [ ] Inspect generated guidance and package hooks before running them. Preserve project-specific `AGENTS.md` content, configured tools, and MCP connection settings.
- [ ] Verify package discovery, the application's Artisan command listing, Roster discovery, and a real local Boost MCP initialize/list-tools/read-only-tool exchange. Confirm the client's connection still works after a fresh dependency install.
- [ ] Run backend tests and Composer validation, platform, and security checks before committing the group.

Exit gate: the target tooling graph installs with the existing Laravel 13 application, and the actual MCP connection works. A successful Composer solve alone is insufficient.

Sources: [Boost target manifest](https://github.com/laravel/boost/blob/v2.10.3/composer.json), [MCP 1.0 release](https://github.com/laravel/mcp/releases/tag/v1.0.0), and [Roster 1.0 release](https://github.com/laravel/roster/releases/tag/v1.0.0).

## Phase 2: migrate the HTTP and arithmetic dependencies

Targets:

| Package | Current | Target | Coupling |
| --- | --- | --- | --- |
| `guzzlehttp/guzzle` | `7.16.1` | `8.3.1` | Requires Phase 1 to remove Boost's Guzzle 7 restriction |
| `guzzlehttp/promises` | `2.6.0` | `3.1.0` | Guzzle 8.3.1 requires `^3.1` |
| `guzzlehttp/psr7` | `2.13.2` | `3.2.0` | Guzzle 8.3.1 requires `^3.2` |
| `guzzlehttp/uri-template` | `1.0.11` | `2.0.1` | Laravel 13.39.0 admits `^2.0` |
| `brick/math` | `0.14.8` | `1.0.0` | Laravel 13.39.0 and Ramsey UUID 4.9.4 both admit `^1.0` |

Use separate HTTP and arithmetic commits, even though they share a phase. Recheck runtime requirements and constraints before resolving each group.

Tasks:

- [ ] Read the major-version migration notes and the complete relevant release gaps. Inventory direct Guzzle, PSR-7, promise, and arithmetic usage, including dependencies' usage.
- [ ] Resolve the HTTP packages together without adding them as new direct requirements.
- [ ] Review changed exception types, timeout handling, response/stream behavior, cookies, redirects, request options, and promise behavior against the actual integrations.
- [ ] Exercise `HttpSourceFetcher`, `EnvironmentalHttpFetcher`, both booking availability services, OpenAI parsing/review, regulation research, and GitHub token/issue clients with synthetic responses.
- [ ] Use Laravel HTTP fakes for application behavior. Also exercise the real Guzzle transport against a bounded local fixture for timeout, redirect, stream, and failure behavior that a fake cannot prove. Keep external credentials and paid APIs out of this verification.
- [ ] Run existing `SourceAdapterEndpointTest`, `EnvironmentalDataCollectionTest`, booking provider tests, `AiPrimaryParsingTest`, `GitHubIssueTrackerTest`, and `LimitsResearchTest`. Add focused regression cases only for uncovered changed behavior.
- [ ] Resolve `brick/math 1.0.0` separately. Prove Ramsey/Laravel UUID generation and the parsing execution identifiers remain valid, unique, and stable where persisted or compared. Check any direct numeric conversions, rounding, and exception handling found by the usage inventory.
- [ ] Run the full suite and audits after the final combined Phase 2 graph.

Exit gate: each integration preserves its success and failure behavior, including transport behavior below Laravel's fake layer. The old `brick/math 0.20.0` alternative is superseded by the validated 1.0 target.

Sources: [Guzzle 8.0 release](https://github.com/guzzle/guzzle/releases/tag/8.0.0), [Guzzle 8.3.1 metadata](https://repo.packagist.org/p2/guzzlehttp/guzzle.json), [Brick Math releases](https://github.com/brick/math/releases), and [Laravel HTTP-client testing](https://laravel.com/docs/13.x/http-client#testing).

## Phase 3: migrate PHPUnit and the test dependency graph

Targets: PHPUnit `13.4.5`, the PHPunit/Sebastian majors listed in the inventory, and Hamcrest `3.0.0` through Mockery.

The target PHPUnit package requires PHP `>=8.4.1`. Confirm this patch floor in local, CI, and any environment that installs development dependencies before changing the PHPUnit constraint. The current `laravel/pao 1.1.5` conflict excludes PHPUnit 13 releases below 13.1.7; 13.4.5 clears that floor. Collision and Mockery must also remain compatible.

Tasks:

- [ ] Run PHPUnit 12 with deprecation details enabled. Resolve application-owned deprecations before moving to PHPUnit 13.
- [ ] Review PHPUnit 13 removals, assertion changes, mock behavior, configuration schema changes, and coverage requirements.
- [ ] Change `phpunit/phpunit` from `^12.5.12` to the proposed `^13.4`. Resolve the reviewed `13.4.5` target and its required dependency set using temporary target bounds.
- [ ] Include Hamcrest 3 through Mockery's already-compatible `^2.0 || ^3.0` constraint. Exercise partial mocks, constructor/clone behavior, argument matching, and exception assertions used by this suite.
- [ ] Let PHPUnit determine compatible PHPunit/Sebastian dependencies. For each listed instance, record whether it reaches the candidate, is replaced, or disappears because no parent needs it.
- [ ] Apply only required changes to `phpunit.xml`, test classes, or helpers. Keep PHPUnit classes, preserve all tests, and add behavior-based regression cases for any adaptation.
- [ ] Run each edited test, then all Unit and Feature tests. Verify test discovery and compare assertion/skip counts by reason rather than requiring identical counts across test-runner versions.
- [ ] Verify both `herd php vendor/bin/phpunit --colors=never` and the CI entry point `herd php artisan test --compact`. Do not pass `--no-interaction` through the Artisan test wrapper; it was rejected by PHPUnit in the earlier run.
- [ ] Verify coverage generation with a supported installed driver or CI coverage environment when available. Record a missing driver explicitly rather than claiming coverage was tested.
- [ ] Run Pint, Composer validation/platform checks, and the audit before committing the test-tooling group.

Exit gate: all required tests and the CI test entry point pass under PHPUnit 13, with every new warning, skip, removed transitive, and configuration change explained.

Sources: [PHPUnit 13 migration announcement](https://phpunit.de/announcements/phpunit-13.html) and [target package metadata](https://repo.packagist.org/p2/phpunit/phpunit.json).

## Phase 4: resolve the npm fetch blocker and optional WASM group

Targets: the Tailwind `4.3.3` tooling graph, `@emnapi/core` and `@emnapi/runtime 1.11.3`, `@napi-rs/wasm-runtime 1.2.6`, `@tybys/wasm-util 0.10.4`, and the proposed WASI threads major where parents admit it.

The prior update failed with `EALLOWREMOTE` when npm encountered an oxide WASM tarball URL. The failure also occurred during independent optional-package attempts. This was a resolver/fetch-policy failure, not evidence that the normal Mac-native install or build failed.

Tasks:

- [ ] Reproduce the failure in the isolated branch and identify the effective policy's source and the exact dependency metadata that introduces the direct URL. Record configuration names and provenance without credentials.
- [ ] Compare the target Tailwind/Oxide package metadata and npm's supported behavior for the installed version. Determine whether corrected upstream metadata or an already-supported registry resolution can satisfy the existing policy.
- [ ] Preserve the policy. Do not enable remote URLs globally, disable checks, or omit optional packages to conceal the failure. Use the explicitly planned Node/npm pair from Phase 0A; do not change versions again as an incidental workaround.
- [ ] If the targeted release still cannot resolve under that policy, stop this group and record the exact upstream fix or separate configuration decision needed. Continue independent Composer work.
- [ ] Once the blocker is resolved, update the coupled Tailwind tooling and review every optional platform instance, not only binaries installed on macOS.
- [ ] Resolve WASM helpers through their declared parents. Check whether the target Oxide/Emnapi releases actually admit `@emnapi/wasi-threads 2.2.0`; the current `@emnapi/core` pins 1.2.1 and Oxide also constrains it to 1.x.
- [ ] Treat WASI Threads 2 as blocked for the selected Oxide 4.3.3 graph: its published metadata still requires `^1.2.2`. The compatible helper updates do not remove this restriction. Revisit version 2 only with an admitting upstream parent.
- [ ] Prove clean installs on macOS and Linux. If WASM execution is an intended supported fallback, exercise it through a supported package entry point on an appropriate target. Otherwise document that only its complete locked graph and installation were verified.

Exit gate: a reproducible supported install with unchanged security settings. A native build is not proof that the optional WASM fallback works.

## Phase 5: migrate the active Tailwind 3 pipeline to Tailwind 4

Targets: root `tailwindcss 4.3.3`, `@tailwindcss/vite 4.3.3`, its coupled node/oxide packages, and parent-compatible transitive upgrades or removals.

The active pipeline is currently Tailwind 3 through `postcss.config.js`. Although `@tailwindcss/vite` is declared, `vite.config.js` currently registers only the Laravel plugin. This migration must change the build integration and styles, not just the root version.

Tasks:

- [ ] Resolve Phase 4 and confirm browser-support requirements against Tailwind 4's published minimums before changing the CSS pipeline.
- [ ] Change the root Tailwind constraint from `^3.1.0` to the proposed `^4.3`; lock the reviewed `4.3.3` target.
- [ ] Register the Tailwind Vite plugin alongside the existing Laravel plugin. Preserve both CSS/JS entry points, asset handling, refresh behavior, and production manifest paths.
- [ ] Replace the old Tailwind directives in `resources/css/app.css` with the v4 import. Migrate the theme and forms plugin into the supported v4 configuration, retaining the application's color variables and Figtree font.
- [ ] Prefer a complete v4 theme migration. If the JavaScript config remains necessary, load it explicitly and verify all custom tokens and source detection. Do not leave two active Tailwind pipelines.
- [ ] Review Blade/JS source detection, Laravel pagination views, cached/generated views, custom `@apply` rules, Tom Select styling, Flatpickr styling, borders, focus rings, spacing, and utility changes.
- [ ] Remove obsolete PostCSS plugin configuration and direct `autoprefixer` or `postcss` requirements only after confirming no other project use needs them. Vite may still retain its own PostCSS dependency.
- [ ] Re-resolve the graph. Tailwind 3's old PostCSS, file-watcher, glob, and Sucrase/Jiti packages may disappear. Count a removed instance as removed, not upgraded to an arbitrary latest major.
- [ ] Resolve `enhanced-resolve` through `@tailwindcss/node`. The target Node package 4.3.3 requires `^5.24.1`, which admits the proposed 5.26.0; the old 4.3.1 parent pins 5.21.6. Verify the final solver result instead of overriding either parent.
- [ ] Check the built application at mobile and desktop widths: login, navigation/dropdowns, counts and scores filters/tables, admin forms, validation errors, Tom Select single/multiple/removal, masks, date pickers, disabled fields, and keyboard focus.
- [ ] Use safe local synthetic data and compare before/after screenshots of the same pages. Preserve the site's intended theme; do not change product styling merely to satisfy the personal-report dark-mode preference.
- [ ] Run the build and existing `FormSelectComponentTest`, `CountsAndScoresIndexTest`, and the full backend suite. Review rendered behavior separately from build success.

Exit gate: one working Tailwind 4 pipeline, preserved user-visible styling and interaction, and no unexplained old Tailwind 3 graph. Verify the forms plugin and its 0.x constraints rather than changing it by assumption.

Source: [Tailwind upgrade guide](https://tailwindcss.com/docs/upgrade-guide).

## Phase 6: close remaining frontend parent constraints

Targets: every remaining npm candidate in the inventory, including CLI styling, watcher/glob instances, Nano ID, Magic String, the full-reload plugin, and Oxc types.

Tasks:

- [ ] Rebuild the complete npm inventory after Phase 5. Match removed or moved instances by package name and parent, so a moved instance cannot conceal an unreviewed major.
- [ ] Inspect the remaining `concurrently` graph before attempting Chalk, Supports Color, Yargs, String Width, Emoji Regex, ANSI Styles, and Wrap ANSI upgrades. The currently selected Concurrently release exactly pins Chalk 5.6.2, Supports Color 10.2.2, and Yargs 18.0.0.
- [ ] Inspect PostCSS before attempting Nano ID 6. PostCSS 8.5.32 requires Nano ID `^3.3.20`; upgrading Nano ID alone is not a supported solve.
- [ ] Inspect Tailwind Node before attempting Magic String 1. Its current parent range is `^0.30.21`. Inspect the full-reload plugin before replacing its nested Picomatch 2 instance.
- [ ] Inspect Rolldown before attempting `@oxc-project/types 0.156.0`. Rolldown 1.2.15 pins exactly 0.155.0. Select a compatible reviewed parent release if one exists; otherwise retain the matched pair and record the blocker.
- [ ] Apply parent-compatible updates in separate groups. Review any new parent major, manifest change, override, or package replacement as an explicit expansion before implementing it.
- [ ] Test the development command's process startup, termination, and output handling in an isolated safe environment. Do not start the repository's complete `composer run dev` against a real `.env`; it launches queue workers. Use harmless child commands for Concurrently behavior and a separate safe Vite/HMR check.
- [ ] Verify Vite refresh/HMR after Blade and JS changes, then perform a production build and repeat affected rendered-control checks.
- [ ] For every still-unreachable version, record the exact parent/range, inspected upstream release, effect of retaining it, and next action. A registry's latest version alone is not an implementation requirement.

Exit gate: no deferred row is silently omitted. Each candidate is installed at the reviewed version, resolved by removal/supersession, or documented as blocked by a specific supported parent graph.

The inspected target manifests already show that some proposed versions cannot all coexist in the selected graph. Tailwind Node 4.3.3 still requires Magic String `^0.30.21`, PostCSS 8.5.32 still requires Nano ID `^3.3.20`, and Concurrently 10.0.6 still pins the older Chalk, Supports Color, and Yargs versions. Track those as parent-blocked until a reviewed upstream parent changes its requirements. Do not replace these packages or their parents merely to clear an outdated-package listing.

## Phase 7: verify and prepare the implementation review

Run these checks against the final combined set, then reinstall without re-resolving:

| Check | Required evidence |
| --- | --- |
| Full Unit and Feature suites | Passing direct PHPUnit and Artisan/CI invocations; explain skips and warnings |
| Focused migration checks | HTTP transport, UUID/math behavior, Mockery/Hamcrest behavior, and actual Boost/MCP connection |
| Frontend | Production build, development refresh, affected page/control checks, and responsive comparison |
| Formatting | Check-only Pint during review; run the required formatter if PHP source was changed |
| Dependency integrity | Composer strict validation, actual platform requirements, npm peer tree, and unchanged registry/security settings |
| Security | Successful Composer and npm audits; no new unresolved advisory |
| Complete graph | All Composer/dev and npm nested/optional/platform entries accounted for, with explicit major and 0.x evidence |
| Reinstall | Composer install and npm ci succeed; manifests and locks remain byte-identical afterward |
| Toolchain | R01-R11 completed; exact Node 26/npm versions recorded in local, CI, and Docker build evidence |
| CI | Required PHP 8.4 / Node 26 Linux jobs pass for the final head with the explicitly pinned npm |
| Review scope | Exact expected files only; no credentials, generated logs, installed dependencies, or unrelated work |

Use the isolated test environment described in Phase 0. Add or extend tests where a migration changes behavior, run each changed test, then run the full dependency-validation suite. Do not count the intentionally disabled live OpenAI test as exercised provider integration.

Create a review description with the final direct constraints, complete graph delta, before/after checks and audits, rendered evidence, and the disposition of every inventory row. Publish only after implementation is separately authorized. Do not merge, deploy, or enable auto-merge as part of dependency preparation.

Update the README's Node/npm, PHPUnit, and Tailwind guidance to the verified result. Inspect workflow tests and runtime-version assertions, build cache behavior, Docker/Compose instructions, and any setup scripts affected by those changes. Keep PHP, databases, queues, unrelated CI actions, and application behavior outside this migration unless a concrete compatibility finding requires an explicitly reviewed change.

## Roll back a failed group

Keep each verified phase in a separate commit. If a later group fails, preserve its evidence, revert only that group's authorized changes, and reinstall the previous locks. For the CSS migration, restore the corresponding manifest, lock, config, and stylesheet changes together and rebuild assets. Do not restore or reset unrelated work.

For a toolchain rollback, restore the version file, manifest/lock metadata, CI bootstrap, Docker stages, build-context rules, and setup documentation as one unit. Re-select the previously recorded local Node/npm pair and rebuild assets. Preserve other nvm installations; no PHP-host runtime rollback is needed if Node remains build-only there.

No schema change or production reparse is expected. If implementation introduces either, stop and review the expanded scope before proceeding.

## Complete candidate inventory

Each `Dxx` row below corresponds to one deferred row in the source PR, in its original order. Candidate versions are the PR's snapshots. Phase ownership and planned outcomes were checked against the locked parent graph when preparing this plan.

| ID | Manager and package instance | PR baseline | Proposed candidate | Phase | Current controlling parent | Planned outcome |
| --- | --- | --- | --- | --- | --- | --- |
| D01 | Composer `brick/math` | 0.14.8 | 1.0.0 | 2 | laravel/framework ^0.14.2 \|\| ^0.15 \|\| ^0.16 \|\| ^0.17 \|\| ^0.18 \|\| ^0.19 \|\| ^0.20 \|\| ^1.0; ramsey/uuid ^0.8.16 \|\| ^0.9 \|\| ^0.10 \|\| ^0.11 \|\| ^0.12 \|\| ^0.13 \|\| ^0.14 \|\| ^0.15 \|\| ^0.16 \|\| ^0.17 \|\| ^0.18 \|\| ^0.19 \|\| ^0.20 \|\| ^1.0 | Upgrade through compatible Composer parents and prove integration behavior. |
| D02 | Composer `guzzlehttp/guzzle` | 7.16.1 | 8.3.1 | 2 | laravel/framework ^7.8.2 \|\| ^8.0; laravel/boost ^7.9 | Upgrade through compatible Composer parents and prove integration behavior. |
| D03 | Composer `guzzlehttp/promises` | 2.6.0 | 3.1.0 | 2 | guzzlehttp/guzzle ^2.6; laravel/framework ^2.0.3 \|\| ^3.0 | Upgrade through compatible Composer parents and prove integration behavior. |
| D04 | Composer `guzzlehttp/psr7` | 2.13.2 | 3.2.0 | 2 | guzzlehttp/guzzle ^2.13.2; laravel/framework ^2.9 \|\| ^3.0 | Upgrade through compatible Composer parents and prove integration behavior. |
| D05 | Composer `guzzlehttp/uri-template` | v1.0.11 | v2.0.1 | 2 | laravel/framework ^1.0 \|\| ^2.0 | Upgrade through compatible Composer parents and prove integration behavior. |
| D06 | Composer `hamcrest/hamcrest-php` | v2.1.1 | v3.0.0 | 3 | mockery/mockery ^2.0 \|\| ^3.0 | Resolve with PHPUnit/Mockery; upgrade or remove if the new test graph no longer needs this package. |
| D07 | Composer `laravel/mcp` | v0.8.2 | v1.1.2 | 1 | laravel/boost ^0.7.1\|^0.8.0\|^0.9.0 | Upgrade with the Boost/Roster/MCP group. |
| D08 | Composer `laravel/roster` | v0.5.1 | v1.0.0 | 1 | laravel/boost ^0.5.0 | Upgrade with the Boost/Roster/MCP group. |
| D09 | Composer `phpunit/php-code-coverage` | 12.5.8 | 14.4.1 | 3 | phpunit/phpunit ^12.5.8 | Resolve with PHPUnit/Mockery; upgrade or remove if the new test graph no longer needs this package. |
| D10 | Composer `phpunit/php-file-iterator` | 6.0.2 | 7.0.2 | 3 | phpunit/phpunit ^6.0.2 | Resolve with PHPUnit/Mockery; upgrade or remove if the new test graph no longer needs this package. |
| D11 | Composer `phpunit/php-invoker` | 6.0.0 | 7.0.0 | 3 | phpunit/phpunit ^6.0.0 | Resolve with PHPUnit/Mockery; upgrade or remove if the new test graph no longer needs this package. |
| D12 | Composer `phpunit/php-text-template` | 5.0.1 | 6.0.1 | 3 | phpunit/php-code-coverage ^5.0.1; phpunit/phpunit ^5.0.1 | Resolve with PHPUnit/Mockery; upgrade or remove if the new test graph no longer needs this package. |
| D13 | Composer `phpunit/php-timer` | 8.0.0 | 9.0.0 | 3 | phpunit/phpunit ^8.0.0 | Resolve with PHPUnit/Mockery; upgrade or remove if the new test graph no longer needs this package. |
| D14 | Composer `phpunit/phpunit` | 12.5.42 | 13.4.5 | 3 | composer.json ^12.5.12, change to ^13.4 | Resolve with PHPUnit/Mockery; upgrade or remove if the new test graph no longer needs this package. |
| D15 | Composer `sebastian/cli-parser` | 4.2.1 | 5.0.1 | 3 | phpunit/phpunit ^4.2.1 | Resolve with PHPUnit/Mockery; upgrade or remove if the new test graph no longer needs this package. |
| D16 | Composer `sebastian/comparator` | 7.1.8 | 8.4.0 | 3 | phpunit/phpunit ^7.1.8 | Resolve with PHPUnit/Mockery; upgrade or remove if the new test graph no longer needs this package. |
| D17 | Composer `sebastian/complexity` | 5.0.0 | 6.0.0 | 3 | phpunit/php-code-coverage ^5.0 | Resolve with PHPUnit/Mockery; upgrade or remove if the new test graph no longer needs this package. |
| D18 | Composer `sebastian/diff` | 7.0.1 | 9.0.1 | 3 | phpunit/phpunit ^7.0.1; sebastian/comparator ^7.0 | Resolve with PHPUnit/Mockery; upgrade or remove if the new test graph no longer needs this package. |
| D19 | Composer `sebastian/environment` | 8.1.2 | 9.3.3 | 3 | phpunit/php-code-coverage ^8.1.2; phpunit/phpunit ^8.1.2 | Resolve with PHPUnit/Mockery; upgrade or remove if the new test graph no longer needs this package. |
| D20 | Composer `sebastian/exporter` | 7.0.3 | 8.2.1 | 3 | phpunit/phpunit ^7.0.3; sebastian/comparator ^7.0.3 | Resolve with PHPUnit/Mockery; upgrade or remove if the new test graph no longer needs this package. |
| D21 | Composer `sebastian/global-state` | 8.0.3 | 9.0.1 | 3 | phpunit/phpunit ^8.0.3 | Resolve with PHPUnit/Mockery; upgrade or remove if the new test graph no longer needs this package. |
| D22 | Composer `sebastian/lines-of-code` | 4.0.1 | 5.0.2 | 3 | phpunit/php-code-coverage ^4.0.1 | Resolve with PHPUnit/Mockery; upgrade or remove if the new test graph no longer needs this package. |
| D23 | Composer `sebastian/object-enumerator` | 7.0.0 | 8.1.0 | 3 | phpunit/phpunit ^7.0.0 | Resolve with PHPUnit/Mockery; upgrade or remove if the new test graph no longer needs this package. |
| D24 | Composer `sebastian/object-reflector` | 5.0.0 | 6.1.0 | 3 | sebastian/global-state ^5.0; sebastian/object-enumerator ^5.0 | Resolve with PHPUnit/Mockery; upgrade or remove if the new test graph no longer needs this package. |
| D25 | Composer `sebastian/recursion-context` | 7.0.1 | 8.0.1 | 3 | phpunit/phpunit ^7.0.1; sebastian/exporter ^7.0.1; sebastian/global-state ^7.0.1; sebastian/object-enumerator ^7.0 | Resolve with PHPUnit/Mockery; upgrade or remove if the new test graph no longer needs this package. |
| D26 | Composer `sebastian/type` | 6.0.4 | 7.0.2 | 3 | phpunit/phpunit ^6.0.4 | Resolve with PHPUnit/Mockery; upgrade or remove if the new test graph no longer needs this package. |
| D27 | Composer `sebastian/version` | 6.0.1 | 7.0.1 | 3 | phpunit/php-code-coverage ^6.0; phpunit/phpunit ^6.0.1 | Resolve with PHPUnit/Mockery; upgrade or remove if the new test graph no longer needs this package. |
| D28 | npm `node_modules/@emnapi/wasi-threads` | 1.2.1 | 2.2.0 | 4 | @emnapi/core 1.2.1; @tailwindcss/oxide-wasm32-wasi ^1.2.1 | Resolve through the WASM parents after the fetch blocker; retain only an admitted major. |
| D29 | npm `node_modules/@nodelib/fs.scandir` | 2.1.5 | 4.0.1 | 5 | @nodelib/fs.walk 2.1.5 | Resolve through Tailwind 4; remove obsolete Tailwind 3 instances or document any remaining parent restriction. |
| D30 | npm `node_modules/@nodelib/fs.stat` | 2.0.5 | 4.0.0 | 5 | @nodelib/fs.scandir 2.0.5; fast-glob ^2.0.2 | Resolve through Tailwind 4; remove obsolete Tailwind 3 instances or document any remaining parent restriction. |
| D31 | npm `node_modules/@nodelib/fs.walk` | 1.2.8 | 3.0.1 | 5 | fast-glob ^1.2.3 | Resolve through Tailwind 4; remove obsolete Tailwind 3 instances or document any remaining parent restriction. |
| D32 | npm `node_modules/ansi-styles` | 6.2.3 | 7.0.0 | 6 | wrap-ansi ^6.2.1 | Upgrade only through an admitting parent release; record the exact blocker otherwise. |
| D33 | npm `node_modules/anymatch/node_modules/picomatch` | 2.3.2 | 4.0.7 | 5 | anymatch ^2.0.4 | Resolve through Tailwind 4; remove obsolete Tailwind 3 instances or document any remaining parent restriction. |
| D34 | npm `node_modules/binary-extensions` | 2.3.0 | 3.2.0 | 5 | is-binary-path ^2.0.0 | Resolve through Tailwind 4; remove obsolete Tailwind 3 instances or document any remaining parent restriction. |
| D35 | npm `node_modules/chalk` | 5.6.2 | 6.0.1 | 6 | concurrently 5.6.2 | Upgrade only through an admitting parent release; record the exact blocker otherwise. |
| D36 | npm `node_modules/chokidar` | 3.6.0 | 6.0.0 | 5 | tailwindcss ^3.6.0 | Resolve through Tailwind 4; remove obsolete Tailwind 3 instances or document any remaining parent restriction. |
| D37 | npm `node_modules/chokidar/node_modules/glob-parent` | 5.1.2 | 6.0.2 | 5 | chokidar ~5.1.2 | Resolve through Tailwind 4; remove obsolete Tailwind 3 instances or document any remaining parent restriction. |
| D38 | npm `node_modules/commander` | 4.1.1 | 15.0.0 | 5 | sucrase ^4.0.0 | Resolve through Tailwind 4; remove obsolete Tailwind 3 instances or document any remaining parent restriction. |
| D39 | npm `node_modules/emoji-regex` | 10.6.0 | 11.0.0 | 6 | string-width ^10.3.0 | Upgrade only through an admitting parent release; record the exact blocker otherwise. |
| D40 | npm `node_modules/fast-glob/node_modules/glob-parent` | 5.1.2 | 6.0.2 | 5 | fast-glob ^5.1.2 | Resolve through Tailwind 4; remove obsolete Tailwind 3 instances or document any remaining parent restriction. |
| D41 | npm `node_modules/is-binary-path` | 2.1.0 | 3.0.0 | 5 | chokidar ~2.1.0 | Resolve through Tailwind 4; remove obsolete Tailwind 3 instances or document any remaining parent restriction. |
| D42 | npm `node_modules/lines-and-columns` | 1.2.4 | 2.0.4 | 5 | sucrase ^1.1.6 | Resolve through Tailwind 4; remove obsolete Tailwind 3 instances or document any remaining parent restriction. |
| D43 | npm `node_modules/magic-string` | 0.30.21 | 1.4.5 | 5 | @tailwindcss/node ^0.30.21 | Resolve through Tailwind 4; remove obsolete Tailwind 3 instances or document any remaining parent restriction. |
| D44 | npm `node_modules/micromatch/node_modules/picomatch` | 2.3.2 | 4.0.7 | 5 | micromatch ^2.3.1 | Resolve through Tailwind 4; remove obsolete Tailwind 3 instances or document any remaining parent restriction. |
| D45 | npm `node_modules/nanoid` | 3.3.20 | 6.0.2 | 6 | postcss ^3.3.20 | PostCSS currently requires ^3.3.20; version 6 needs an admitting parent, otherwise record blocked. |
| D46 | npm `node_modules/postcss-import` | 15.1.0 | 17.0.1 | 5 | tailwindcss ^15.1.0 | Resolve through Tailwind 4; remove obsolete Tailwind 3 instances or document any remaining parent restriction. |
| D47 | npm `node_modules/postcss-js` | 4.1.0 | 5.1.0 | 5 | tailwindcss ^4.0.1 | Resolve through Tailwind 4; remove obsolete Tailwind 3 instances or document any remaining parent restriction. |
| D48 | npm `node_modules/postcss-nested` | 6.2.0 | 9.0.1 | 5 | tailwindcss ^6.2.0 | Resolve through Tailwind 4; remove obsolete Tailwind 3 instances or document any remaining parent restriction. |
| D49 | npm `node_modules/postcss-selector-parser` | 6.1.5 | 7.1.7 | 5 | postcss-nested ^6.1.1; tailwindcss ^6.1.2 | Resolve through Tailwind 4; remove obsolete Tailwind 3 instances or document any remaining parent restriction. |
| D50 | npm `node_modules/readdirp` | 3.6.0 | 5.1.1 | 5 | chokidar ~3.6.0 | Resolve through Tailwind 4; remove obsolete Tailwind 3 instances or document any remaining parent restriction. |
| D51 | npm `node_modules/readdirp/node_modules/picomatch` | 2.3.2 | 4.0.7 | 5 | readdirp ^2.2.1 | Resolve through Tailwind 4; remove obsolete Tailwind 3 instances or document any remaining parent restriction. |
| D52 | npm `node_modules/string-width` | 7.2.0 | 8.3.0 | 6 | cliui ^7.2.0; wrap-ansi ^7.0.0; yargs ^7.2.0 | Upgrade only through an admitting parent release; record the exact blocker otherwise. |
| D53 | npm `node_modules/supports-color` | 10.2.2 | 11.0.0 | 6 | concurrently 10.2.2 | Upgrade only through an admitting parent release; record the exact blocker otherwise. |
| D54 | npm `node_modules/tailwindcss` | 3.4.19 | 4.3.3 | 5 | package.json ^3.1.0 | Apply the proposed root constraint and migrate the active CSS pipeline. |
| D55 | npm `node_modules/tailwindcss/node_modules/jiti` | 1.21.7 | 2.7.0 | 5 | tailwindcss ^1.21.7 | Resolve through Tailwind 4; remove obsolete Tailwind 3 instances or document any remaining parent restriction. |
| D56 | npm `node_modules/ts-interface-checker` | 0.1.13 | 1.0.2 | 5 | sucrase ^0.1.9 | Resolve through Tailwind 4; remove obsolete Tailwind 3 instances or document any remaining parent restriction. |
| D57 | npm `node_modules/vite-plugin-full-reload/node_modules/picomatch` | 2.3.2 | 4.0.7 | 6 | vite-plugin-full-reload ^2.3.1 | Upgrade only through an admitting parent release; record the exact blocker otherwise. |
| D58 | npm `node_modules/wrap-ansi` | 9.0.2 | 10.0.2 | 6 | cliui ^9.0.0 | Upgrade only through an admitting parent release; record the exact blocker otherwise. |
| D59 | Composer `laravel/boost` | v2.4.13 | v2.10.3 | 1 | composer.json ^2.4 | Upgrade with the Boost/Roster/MCP group. |
| D60 | Composer `brick/math` | 0.14.8 | 0.20.0 | 2 | laravel/framework ^0.14.2 \|\| ^0.15 \|\| ^0.16 \|\| ^0.17 \|\| ^0.18 \|\| ^0.19 \|\| ^0.20 \|\| ^1.0; ramsey/uuid ^0.8.16 \|\| ^0.9 \|\| ^0.10 \|\| ^0.11 \|\| ^0.12 \|\| ^0.13 \|\| ^0.14 \|\| ^0.15 \|\| ^0.16 \|\| ^0.17 \|\| ^0.18 \|\| ^0.19 \|\| ^0.20 \|\| ^1.0 | Alternative superseded by the validated 1.0.0 target; do not retain two versions. |
| D61 | Composer `laravel/mcp` | v0.8.2 | v0.9.7 | 1 | laravel/boost ^0.7.1\|^0.8.0\|^0.9.0 | Alternative superseded by the validated 1.1.2 target. |
| D62 | npm `@tailwindcss/vite` | 4.3.1 | 4.3.3 | 4 | package.json ^4.0.0 | Resolve fetch blocker, then activate the 4.3.3 Vite integration in Phase 5. |
| D63 | npm `@emnapi/core` | 1.10.0 | 1.11.3 | 4 | @tailwindcss/oxide-wasm32-wasi ^1.10.0 | Resolve through the WASM parents after the fetch blocker; retain only an admitted major. |
| D64 | npm `@emnapi/runtime` | 1.10.0 | 1.11.3 | 4 | @tailwindcss/oxide-wasm32-wasi ^1.10.0 | Resolve through the WASM parents after the fetch blocker; retain only an admitted major. |
| D65 | npm `@napi-rs/wasm-runtime` | 1.1.5 | 1.2.6 | 4 | @tailwindcss/oxide-wasm32-wasi ^1.1.4 | Resolve through the WASM parents after the fetch blocker; retain only an admitted major. |
| D66 | npm `@tybys/wasm-util` | 0.10.2 | 0.10.4 | 4 | @napi-rs/wasm-runtime ^0.10.2; @tailwindcss/oxide-wasm32-wasi ^0.10.2 | Resolve through the WASM parents after the fetch blocker; retain only an admitted major. |
| D67 | npm `enhanced-resolve` | 5.21.6 | 5.26.0 | 5 | @tailwindcss/node 5.21.6 | Update only when the chosen Tailwind Node parent admits the target. |
| D68 | npm `yargs` | 18.0.0 | 18.2.0 | 6 | concurrently 18.0.0 | Upgrade only through an admitting parent release; record the exact blocker otherwise. |
| D69 | npm `@oxc-project/types` | 0.155.0 | 0.156.0 | 6 | rolldown =0.155.0 | Rolldown currently pins =0.155.0; update through a matched parent or record blocked. |

## Decisions required at implementation time

- Confirm the final target versions after re-reading current upstream notes and audits. Do not silently broaden this plan to newly discovered unrelated majors.
- Recheck the latest Node 26 patch and latest compatible stable npm, then update all runtime and build pins together. The verified planning targets are Node 26.13.0 and npm 12.2.1.
- Confirm supported browsers before the Tailwind 4 migration.
- Resolve the npm remote-URL blocker through a policy-compatible upstream path, or request review of a concrete alternative.
- Decide how to handle remaining parent-pinned candidates after the main migrations. A documented blocker is an explicit result; a forced transitive override is a separate proposal.

## Planning verification

This document covers all 69 deferred PR rows, both proposed dependency manifest edits, and the 11 Node/npm toolchain entries R01-R11. The PR head, lockfile parent constraints, active frontend configuration, named test paths, target Composer/npm package metadata, Node release index, CI/Docker configuration, `.npmrc`, and README requirements were inspected. No candidate installation, dependency resolution, application test run, runtime upgrade, or migration was performed for this planning request.

Target metadata references: [Packagist](https://repo.packagist.org/p2/laravel/boost.json), [PHPUnit metadata](https://repo.packagist.org/p2/phpunit/phpunit.json), [Tailwind Vite metadata](https://registry.npmjs.org/@tailwindcss/vite/4.3.3), [Tailwind Node metadata](https://registry.npmjs.org/@tailwindcss/node/4.3.3), [Concurrently metadata](https://registry.npmjs.org/concurrently/10.0.6), and [Rolldown metadata](https://registry.npmjs.org/rolldown/1.2.15). These verify declared constraints, not application compatibility.
