# Dependency migration evidence

Implementation of the [approved plan](dependency-upgrade-plan.md), starting from `aed4c40924870e0bb20cae82d00c83838638bda4`. PR #56 was open at that exact head with passing lint and tests when implementation began. This branch depends on that maintenance PR. No merge or deployment is part of this work.

## Baseline and isolation

The worktree initially contained only the untracked plan, now preserved unchanged in Git. Recovery copies, complete raw inventories, manifest hashes, upstream metadata, and command logs are retained locally under `/tmp/fishcounts-migration-20261028`. The original checkout and other worktrees were not used for installation or testing.

Baseline: Herd PHP 8.4.27, Composer 2.10.3, Node 26.12.1, npm 12.2.1. All 115 Composer packages and 188 npm lock instances, including non-native optional packages, were inventoried before changes. The actual inventory files determine counts.

Checks used SQLite `:memory:`, a dummy app key, array cache/mail/session, sync queues, disabled AI/GitHub write features, an empty OpenAI API key, and unset live OpenAI opt-ins. No generic setup script or shared database was used.

- PHPUnit 12.5.42: 927 passed, 5,401 assertions, one intentionally skipped live OpenAI test. Deprecation-detail invocation passed.
- Composer install, strict validation, platform requirements, and audit passed. No advisories.
- npm clean install, complete peer tree, audit, and production build passed. No vulnerabilities.
- Check-only Pint passed.
- Real Boost MCP initialization, tool listing, application-info, and version-specific Laravel 13 documentation search passed.

## Node and npm

Official Node release metadata and npm stable metadata were refreshed on 28 October 2026. Selected Node 26.13.0 LTS and npm 12.2.1. npm's engine range includes Node 26. Node bundles npm 11.20.0, so the shared bootstrap installs the manifest's exact npm version and asserts actual versions and project security settings.

Local nvm selection and cold npm install, peer tree, audit, and build passed. The toolchain-only lock synchronization changes root metadata only. The manifest now names the package `fishcounts` so npm does not derive lock metadata from an arbitrary worktree directory name. `.npmrc` remains unchanged.

The official `node:26.13.0-alpine`, `26.13.0-alpine3.24`, and `26.13.0-bookworm-slim` image tags were unpublished. The asset stage uses published official `node:26.12.1-bookworm-slim` at index digest `sha256:88e480776c6149ff1d098cd304f44bf18057fb5d284a3329b78162d29821e28c`, then installs the official Node 26.13.0 Linux distribution with the published architecture-specific SHA-256 checked before extraction. Both amd64 and arm64 are supported. This keeps the actual selected Node version consistent without referencing an unavailable tag. Only assets cross from that Debian builder to the Alpine PHP runtime. Node/npm are absent from the final runtime.

The initial Linux arm64 asset-stage build passed, including Node distribution checksum verification, exact toolchain assertions, native optional binaries, and the production build. The final PHP image and Linux x64 results are recorded below once verified.

## Sources

- [Node release index](https://nodejs.org/dist/index.json), [Node 26 changelog](https://github.com/nodejs/node/blob/main/doc/changelogs/CHANGELOG_V26.md), and [26.13.0 checksums](https://nodejs.org/dist/v26.13.0/SHASUMS256.txt).
- [npm stable metadata](https://registry.npmjs.org/npm/latest) and [npm 12 changelog](https://github.com/npm/cli/blob/v12.2.1/CHANGELOG.md).
- [Official Node Docker guidance](https://github.com/nodejs/docker-node/blob/main/README.md).

## Boost, Roster, and MCP

Boost 2.10.3, Roster 1.0.0, and MCP 1.1.2 were resolved together using temporary Composer constraints and `--with-dependencies --minimal-changes`. The root Boost constraint remains `^2.4`. Composer Semver 3.5.2 is the only added package in this group. Guzzle stayed at its baseline version for independent transport verification.

The full backend suite passed after this group: 927 passed, 5,401 assertions, one live-test skip. Composer validation, platform requirements, and audit passed. Package discovery, Artisan registration, Roster's actual scan, and a fresh MCP initialize/list-tools/application-info/search-docs exchange passed. The Roster 1 API is consumed by the upgraded Boost release; no application Roster callers exist. Project guidance and MCP settings were preserved.

## HTTP transport

Guzzle 8.3.1, Promises 3.1.0, PSR-7 3.2.0, and URI Template 2.0.1 were resolved as a bounded group. PSR-7 removes `ralouphie/getallheaders`; application code does not call that polyfill. No direct application Guzzle, Promise, PSR-7, or Brick API subclasses were found. Existing outbound headers are strings; the GitHub API version is configured as a string.

All 116 focused integration tests passed with 661 assertions, covering scraping, environmental collection, both booking providers, AI parsing/review, regulation research, and GitHub token/issue clients using synthetic responses. Six new local transport tests passed before and after the Guzzle upgrade. They exercise real loopback HTTP redirects, JSON headers, streaming, retry/failure responses, timeout and connection-refusal exception mapping, cookies, and asynchronous promise completion. Each fixture process is bounded and stopped by test teardown. No external account or paid API is used. Composer validation, platform checks, and audit passed.

## UUID arithmetic

Brick Math 1.0.0 is admitted by the unchanged Laravel and Ramsey UUID constraints. The 0.20.0 alternative is superseded. The application uses Laravel UUIDs for parsing execution/idempotency identifiers and client request IDs, with no direct Brick numeric API calls.

A new regression test passed on both graphs: 100 unique Laravel UUIDs remain valid and survive byte and integer round trips through Ramsey's arithmetic. Ordered UUID generation remains valid. Existing parsing/limits integration tests passed, followed by the full combined suite: 934 passed, 5,719 assertions, one live OpenAI skip. Composer audit remained clear.

## PHPUnit and Hamcrest

The root PHPUnit constraint is now `^13.4`, locked to 13.4.5 with its supported Sebastian/coverage graph. Hamcrest is 3.0.0 through Mockery's admitted range. PHP 8.4.27 meets PHPUnit's PHP 8.4.1 floor; CI selects the current PHP 8.4 patch. The existing XML schema reference tracks the installed runner and required no migration. No tests were removed.

Two focused Mockery/Hamcrest tests pass, covering partial mocks, constructor-initialized headers, clone isolation, argument matching, and exception type/message assertions. The partial-mock fixture initializes its constructor after enabling partial behavior because HeaderBag's constructor calls overridable methods. Both direct PHPUnit and Artisan full-suite invocations pass with 936 tests, 5,727 assertions, and the same intentionally skipped live test. Deprecation detail flags produced no application-owned deprecations. Pint, strict Composer validation, platform checks, and audit passed.

No PCOV or Xdebug driver is installed locally. Coverage generation is unavailable; passing tests do not establish coverage generation. CI retains its existing `coverage: none` configuration.

## npm fetch policy and optional WASM graph

The lock-only update reproduced `EALLOWREMOTE` under Node 26.13.0/npm 12.2.1. `allow-remote=none` is npm 12's built-in default, not a project override. Arborist's complete-tree/lock-only path cracks open packages declaring bundled dependencies by passing their resolved URL to Pacote. That path classifies the Oxide WASM tarball as a remote URL. The normal registry-based `npm update` path succeeds without changing any security setting. `npm ci`, peer validation, audit, and the existing production build then pass.

The coupled Tailwind Vite/Node/Oxide packages are 4.3.3. Their optional platform entries were retained and reviewed. The normal solve also refreshes four Lightning CSS Linux entries' libc metadata without changing versions, source URLs, or integrity. This resolves the initial Linux arm64 `npm ls` failure on the baseline lock's musl metadata. Final native Linux peer checks pass.

Oxide's WASM package targets `cpu: wasm32` and bundles six helpers. Its downloaded registry tarball was checked against the lock's SHA-512, and every bundled `package.json` was inspected. Root helper lock entries disappear because the reviewed tarball owns their versions:

| Bundled package | Actual version | Proposed target disposition |
| --- | --- | --- |
| `@emnapi/core` | 1.11.1 | 1.11.3 blocked by the selected tarball's bundled contents |
| `@emnapi/runtime` | 1.11.1 | 1.11.3 blocked by bundled contents |
| `@emnapi/wasi-threads` | 1.2.2 | 2.2.0 blocked by Oxide's `^1.2.2` range and bundled Core's exact 1.2.2 |
| `@napi-rs/wasm-runtime` | 1.1.4 | 1.2.6 blocked by bundled contents |
| `@tybys/wasm-util` | 0.10.2 | 0.10.4 blocked by bundled contents |
| `tslib` | 2.8.1 | Bundled supporting dependency |

These are bundle dispositions, not claims that the proposed versions installed. No transitive overrides or new root requirements were added. WASM execution is not a supported platform in the project's native-build check; its integrity and complete bundled graph were inspected, but fallback execution was not exercised.

## Tailwind 4 and remaining parents

Tailwind 4.3.3 now runs once through `@tailwindcss/vite`. The JavaScript theme is fully migrated into `@theme inline`; explicit CSS sources retain Blade, JavaScript, compiled views, and Laravel pagination. The forms plugin remains in its admitted 0.5 release line. Old PostCSS configuration, direct Autoprefixer/PostCSS requirements, and obsolete Tailwind 3 dependencies are removed. Renamed shadow, radius, and outline utilities preserve the previous appearance. Default border color remains the application's existing border token. Figtree and all custom color aliases are retained. Browser minimums are documented.

The 15 existing form/counts tests passed with 83 assertions. The full backend suite passes with the final dependency graph. Production assets build and load in the isolated Chrome session. Baseline and migrated desktop counts screenshots were visually compared. A fresh 390px mobile page reports `innerWidth=390`, `body.scrollWidth=390`, and `visualViewport.scale=1`; navigation opens, and the saved [mobile screenshot](dependency-migration-mobile.png) shows the responsive filters and native mobile date controls. Login, dashboard, counts filters, the desktop date picker, and the alert-rule form were exercised with an isolated SQLite database and synthetic records. Tom Select's multiple-option selection produced a removable chip. The chip was subsequently removed by keyboard. Scores filters, the administrator invitation form, and native required-field validation were also inspected. Exhaustive admin/error states and the visual disabled-field matrix remain manual review gates; existing component tests cover disabled markup. Decimal number fields intentionally remain text inputs unless an explicit mask is supplied.

The browser check exposed Flatpickr 4.6.13's cross-origin `CSSStyleSheet.cssRules` error when resizing an open desktop calendar into touch emulation. The font stylesheet was loaded without CORS. Both layouts now request it with `crossorigin="anonymous"`; the font server's `Access-Control-Allow-Origin: *` response was verified. Chrome now reads all six font CSS rules, and repeating the open-calendar resize succeeds without SecurityError. No Flatpickr vendor code or application JS was changed. A non-passive wheel warning was traced to the 1Password browser extension.

Vite's actual WebSocket delivered a CSS update and full reloads for JS and Blade changes. Every temporary HMR edit was restored byte-for-byte. A Chrome development page connected to Vite. The isolated Concurrently checks passed for two successful children and for termination of a long-running child with SIGTERM when the first child exits. No application queue worker or scheduler was started.

All remaining parent candidates were checked against current registry metadata. Concurrently 10.0.6, PostCSS 8.5.32, vite-plugin-full-reload 1.2.0, and Rolldown 1.2.15 remain their inspected stable parent releases. Their exact restrictions are recorded per candidate below. No override, forced install, peer relaxation, or unsupported parent replacement was introduced.

## Docker and platform proof

Both Linux arm64 and Linux x64 asset images build with actual Node 26.13.0/npm 12.2.1. Their clean npm trees pass, and the platform check directly loads the matching Rolldown, Oxide, and Lightning CSS native bindings. The local macOS arm64 check loads the three corresponding native bindings too.

The controlled Composer dependency stage runs on the build host architecture and provides vendor templates to either asset architecture. The first emulated x64 attempt compiled PHP extensions and hit a compiler segmentation fault; moving the build-only Composer stage to the build architecture removes that unnecessary emulated compilation. The final PHP runtime remains FrankenPHP/PHP 8.4 on Alpine with the original extensions, permissions, entry point, and web/queue/scheduler commands.

`docker compose --env-file .env.example build app queue ai-queue scheduler` passes. Each final application image contains the production asset manifest, passes non-development Composer platform requirements, and excludes `.env`, Git data, Node/npm, and host `node_modules`. No Compose service or database was started. The native SSH release checksum, health, and rollback logic is unchanged.

## Complete inventories and policy

Run `python3 .github/scripts/dependency-migration-inventory.py` to regenerate the [full inventory](dependency-migration-inventory.json). It reads the exact baseline from Git, exports every Composer/dev and nested/optional/platform npm lock instance, verifies source stability at unchanged versions, and rejects an existing-package major or 0.x minor outside the approved target set. The [bundle inventory](dependency-migration-bundles.json) records both integrity-verified WASM tarballs, including their nested dependency requirements. Baseline bundled WASM Runtime was already 1.1.4 despite the old separate lock entry showing 1.1.5; the actual bundle did not downgrade.

The final lock inventory contains 118 Composer packages and 111 npm instances, compared with 115 and 188 at baseline. It records 45 version changes, 78 removed instances, four additions, and four metadata-only corrections. Additions are Composer Semver through Roster, Symfony's PHP 8.2 polyfill through Guzzle, and Sebastian File Filter/Git State through PHPUnit's graph. Removed nested instances are compared by package name and remaining parent, so moves cannot hide an unapproved major.

The routine maintenance checker remains untouched. Its actual-manifest run rejects the intentional manifest edits. A diagnostic run with manifest hashes held constant also rejects the authorized major upgrades. These expected rejections are not passing maintenance-policy results. The separate migration inventory check passes against this plan's target set.

Coverage generation and WASM fallback execution remain unavailable. The Linux GitHub test job result is recorded in the PR after publication; local and container checks alone do not establish CI success.

## Reinstall and final review gates

Final Composer install and cold `npm ci` passed after verification. SHA-256 checks confirm `composer.json`, `composer.lock`, `package.json`, and `package-lock.json` remain byte-identical. The npm peer tree, native Mac imports, production build, both audits, Composer validation/platform requirements, and required PHP formatting checks pass. Vite terminates cleanly and leaves no `public/hot` file.

This implementation remains a draft review while its stacked parent PR is open and the remaining manual UI gates are incomplete. No schema change, merge, deployment, auto-merge setting, production data write, or external notification was performed. Only the disposable SQLite UI database was migrated.

## R01-R11 disposition

| ID | Outcome |
| --- | --- |
| R01 | `.nvmrc` pins 26.13.0. |
| R02 | Root engines and `packageManager` declare Node 26/npm 12 with exact npm bootstrap. |
| R03 | nvm Node 26.13.0 at `/Users/Taylor/.nvm/versions/node/v26.13.0/bin/node`, npm 12.2.1. Other Node/PHP installations were preserved. |
| R04 | Tests workflow uses `.nvmrc`, explicit npm installation/assertions, native imports and lock checks. |
| R05 | Release verification uses the same toolchain checks. Deploy/SSH behavior is preserved. |
| R06 | Separate Node asset stage; amd64/arm64 builds and final Compose image checks pass. |
| R07 | Lock v3 retained; toolchain-only graph unchanged, final migration inventory complete. |
| R08 | README setup, browser minimums, PHPUnit 13 and Tailwind 4 guidance updated. |
| R09 | `.npmrc` unchanged and copied before install; scripts remain disabled and audits enabled. Remote-URL policy remains `none`. |
| R10 | `.dockerignore` prevents host dependencies, build output, Git and real environment files entering images. |
| R11 | Shared scripts assert actual versions, native bindings and lock stability. All four application services build. No Node runtime service was added. |

## Candidate decisions

Each row is an original PR candidate, not an installed-package count. Full package identities, remaining duplicate instances, requirements, sources, and metadata deltas are in the JSON inventory.

| ID | Package | Proposed | Final result | Decision |
| --- | --- | --- | --- | --- |
| D01 | `brick/math` | 1.0.0 | 1.0.0 | installed: Selected through the supported parent graph. |
| D02 | `guzzlehttp/guzzle` | 8.3.1 | 8.3.1 | installed: Selected through the supported parent graph. |
| D03 | `guzzlehttp/promises` | 3.1.0 | 3.1.0 | installed: Selected through the supported parent graph. |
| D04 | `guzzlehttp/psr7` | 3.2.0 | 3.2.0 | installed: Selected through the supported parent graph. |
| D05 | `guzzlehttp/uri-template` | v2.0.1 | v2.0.1 | installed: Selected through the supported parent graph. |
| D06 | `hamcrest/hamcrest-php` | v3.0.0 | v3.0.0 | installed: Selected through the supported parent graph. |
| D07 | `laravel/mcp` | v1.1.2 | v1.1.2 | installed: Selected through the supported parent graph. |
| D08 | `laravel/roster` | v1.0.0 | v1.0.0 | installed: Selected through the supported parent graph. |
| D09 | `phpunit/php-code-coverage` | 14.4.1 | 14.4.1 | installed: Selected through the supported parent graph. |
| D10 | `phpunit/php-file-iterator` | 7.0.2 | 7.0.2 | installed: Selected through the supported parent graph. |
| D11 | `phpunit/php-invoker` | 7.0.0 | 7.0.0 | installed: Selected through the supported parent graph. |
| D12 | `phpunit/php-text-template` | 6.0.1 | 6.0.1 | installed: Selected through the supported parent graph. |
| D13 | `phpunit/php-timer` | 9.0.0 | 9.0.0 | installed: Selected through the supported parent graph. |
| D14 | `phpunit/phpunit` | 13.4.5 | 13.4.5 | installed: Selected through the supported parent graph. |
| D15 | `sebastian/cli-parser` | 5.0.1 | 5.0.1 | installed: Selected through the supported parent graph. |
| D16 | `sebastian/comparator` | 8.4.0 | 8.4.0 | installed: Selected through the supported parent graph. |
| D17 | `sebastian/complexity` | 6.0.0 | 6.0.0 | installed: Selected through the supported parent graph. |
| D18 | `sebastian/diff` | 9.0.1 | 9.0.1 | installed: Selected through the supported parent graph. |
| D19 | `sebastian/environment` | 9.3.3 | 9.3.3 | installed: Selected through the supported parent graph. |
| D20 | `sebastian/exporter` | 8.2.1 | 8.2.1 | installed: Selected through the supported parent graph. |
| D21 | `sebastian/global-state` | 9.0.1 | 9.0.1 | installed: Selected through the supported parent graph. |
| D22 | `sebastian/lines-of-code` | 5.0.2 | 5.0.2 | installed: Selected through the supported parent graph. |
| D23 | `sebastian/object-enumerator` | 8.1.0 | 8.1.0 | installed: Selected through the supported parent graph. |
| D24 | `sebastian/object-reflector` | 6.1.0 | 6.1.0 | installed: Selected through the supported parent graph. |
| D25 | `sebastian/recursion-context` | 8.0.1 | 8.0.1 | installed: Selected through the supported parent graph. |
| D26 | `sebastian/type` | 7.0.2 | 7.0.2 | installed: Selected through the supported parent graph. |
| D27 | `sebastian/version` | 7.0.1 | 7.0.1 | installed: Selected through the supported parent graph. |
| D28 | `@emnapi/wasi-threads` | 2.2.0 | bundled 1.2.2 | blocked: Oxide 4.3.3 bundles @emnapi/wasi-threads 1.2.2; the tarball owns this version. Oxide requires WASI Threads ^1.2.2 and bundled Core pins 1.2.2. |
| D29 | `@nodelib/fs.scandir` | 4.0.1 | removed | removed: Obsolete Tailwind 3 instance removed with its parent. |
| D30 | `@nodelib/fs.stat` | 4.0.0 | removed | removed: Obsolete Tailwind 3 instance removed with its parent. |
| D31 | `@nodelib/fs.walk` | 3.0.1 | removed | removed: Obsolete Tailwind 3 instance removed with its parent. |
| D32 | `ansi-styles` | 7.0.0 | 6.2.3 | blocked: wrap-ansi 9.0.2 requires ^6.2.1; cliui requires wrap-ansi ^9.0.0. |
| D33 | `picomatch` | 4.0.7 | removed | removed: Obsolete Tailwind 3 instance removed with its parent. Other supported instances are listed separately. |
| D34 | `binary-extensions` | 3.2.0 | removed | removed: Obsolete Tailwind 3 instance removed with its parent. |
| D35 | `chalk` | 6.0.1 | 5.6.2 | blocked: concurrently 10.0.6 pins chalk exactly 5.6.2. |
| D36 | `chokidar` | 6.0.0 | removed | removed: Obsolete Tailwind 3 instance removed with its parent. |
| D37 | `glob-parent` | 6.0.2 | removed | removed: Obsolete Tailwind 3 instance removed with its parent. |
| D38 | `commander` | 15.0.0 | removed | removed: Obsolete Tailwind 3 instance removed with its parent. |
| D39 | `emoji-regex` | 11.0.0 | 10.6.0 | blocked: string-width 7.2.0 requires emoji-regex ^10.3.0. |
| D40 | `glob-parent` | 6.0.2 | removed | removed: Obsolete Tailwind 3 instance removed with its parent. |
| D41 | `is-binary-path` | 3.0.0 | removed | removed: Obsolete Tailwind 3 instance removed with its parent. |
| D42 | `lines-and-columns` | 2.0.4 | removed | removed: Obsolete Tailwind 3 instance removed with its parent. |
| D43 | `magic-string` | 1.4.5 | 0.30.21 | blocked: @tailwindcss/node 4.3.3 requires ^0.30.21. |
| D44 | `picomatch` | 4.0.7 | removed | removed: Obsolete Tailwind 3 instance removed with its parent. Other supported instances are listed separately. |
| D45 | `nanoid` | 6.0.2 | 3.3.20 | blocked: postcss 8.5.32 requires ^3.3.20; Vite retains PostCSS. |
| D46 | `postcss-import` | 17.0.1 | removed | removed: Obsolete Tailwind 3 instance removed with its parent. |
| D47 | `postcss-js` | 5.1.0 | removed | removed: Obsolete Tailwind 3 instance removed with its parent. |
| D48 | `postcss-nested` | 9.0.1 | removed | removed: Obsolete Tailwind 3 instance removed with its parent. |
| D49 | `postcss-selector-parser` | 7.1.7 | removed | removed: Obsolete Tailwind 3 instance removed with its parent. |
| D50 | `readdirp` | 5.1.1 | removed | removed: Obsolete Tailwind 3 instance removed with its parent. |
| D51 | `picomatch` | 4.0.7 | removed | removed: Obsolete Tailwind 3 instance removed with its parent. Other supported instances are listed separately. |
| D52 | `string-width` | 8.3.0 | 7.2.0 | blocked: cliui and yargs require ^7.2.0; wrap-ansi requires ^7.0.0. |
| D53 | `supports-color` | 11.0.0 | 10.2.2 | blocked: concurrently 10.0.6 pins exactly 10.2.2. |
| D54 | `tailwindcss` | 4.3.3 | 4.3.3 | installed: Selected through the supported parent graph. |
| D55 | `jiti` | 2.7.0 | removed | removed: Obsolete Tailwind 3 instance removed with its parent. Other supported instances are listed separately. |
| D56 | `ts-interface-checker` | 1.0.2 | removed | removed: Obsolete Tailwind 3 instance removed with its parent. |
| D57 | `picomatch` | 4.0.7 | 2.3.2 | blocked: vite-plugin-full-reload 1.2.0 requires ^2.3.1. |
| D58 | `wrap-ansi` | 10.0.2 | 9.0.2 | blocked: cliui requires ^9.0.0. |
| D59 | `laravel/boost` | v2.10.3 | v2.10.3 | installed: Selected through the supported parent graph. |
| D60 | `brick/math` | 0.20.0 | 1.0.0 | superseded: Validated final major target replaces this alternative. |
| D61 | `laravel/mcp` | v0.9.7 | v1.1.2 | superseded: Validated final major target replaces this alternative. |
| D62 | `@tailwindcss/vite` | 4.3.3 | 4.3.3 | installed: Selected through the supported parent graph. |
| D63 | `@emnapi/core` | 1.11.3 | bundled 1.11.1 | blocked: Oxide 4.3.3 bundles @emnapi/core 1.11.1; the tarball owns this version. A newer parent tarball must update its bundled copy. |
| D64 | `@emnapi/runtime` | 1.11.3 | bundled 1.11.1 | blocked: Oxide 4.3.3 bundles @emnapi/runtime 1.11.1; the tarball owns this version. A newer parent tarball must update its bundled copy. |
| D65 | `@napi-rs/wasm-runtime` | 1.2.6 | bundled 1.1.4 | blocked: Oxide 4.3.3 bundles @napi-rs/wasm-runtime 1.1.4; the tarball owns this version. A newer parent tarball must update its bundled copy. |
| D66 | `@tybys/wasm-util` | 0.10.4 | bundled 0.10.2 | blocked: Oxide 4.3.3 bundles @tybys/wasm-util 0.10.2; the tarball owns this version. A newer parent tarball must update its bundled copy. |
| D67 | `enhanced-resolve` | 5.26.0 | 5.26.0 | installed: Selected through the supported parent graph. |
| D68 | `yargs` | 18.2.0 | 18.0.0 | blocked: concurrently 10.0.6 pins exactly 18.0.0. |
| D69 | `@oxc-project/types` | 0.156.0 | 0.155.0 | blocked: rolldown 1.2.15 pins exactly 0.155.0; it remains the latest stable parent inspected. |
