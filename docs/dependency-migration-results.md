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
