# Deployment

## Docker Compose

1. Copy `.env.example` to `.env` and set production values for `APP_KEY`, `APP_URL`, mail, database, queue/cache, and Discord settings.
2. Run `docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d --build`.
3. Run `docker compose -f docker-compose.yml -f docker-compose.prod.yml exec app php artisan migrate --force`.
4. Run `docker compose -f docker-compose.yml -f docker-compose.prod.yml exec app php artisan fish:create-admin`.

## Native VPS

The production VPS uses Nginx, PHP-FPM, MariaDB, Supervisor, and Certbot. Match the existing server convention by setting `DB_CONNECTION=mariadb`, `QUEUE_CONNECTION=database`, `CACHE_STORE=database`, and `SESSION_DRIVER=database`.

Run the existing application queue worker through Supervisor with the database queue connection, and run the Laravel scheduler from cron. Add a separate external-services Supervisor program with `numprocs=1` and this command so AI and GitHub latency cannot occupy deterministic parsing workers. AI jobs permit unlimited rate-limit releases but fail after the first unhandled exception or timeout:

```shell
php artisan queue:work database --queue=ai-primary-parsing,ai-parsing,github-issues --sleep=3 --tries=0 --timeout=330
```

Set `DB_QUEUE_RETRY_AFTER=360`, which remains safely above the 330-second AI worker timeout. Restart Supervisor workers after deploying the Phase 6 schema and dark GitHub-write configuration.

### AI-primary parser rollout

Apply the parser migrations before enabling the feature, then provision the OpenAI key outside source control and configure:

```dotenv
FISH_AI_PARSING_ENABLED=true
FISH_AI_PARSING_MODEL=gpt-5.6-luna
FISH_AI_PARSING_REASONING_EFFORT=medium
FISH_AI_PARSING_SERVICE_TIER=default
FISH_AI_PARSING_CONNECT_TIMEOUT=5
FISH_AI_PARSING_TIMEOUT=120
FISH_AI_PARSING_JOB_TIMEOUT=300
FISH_AI_PARSING_LOCK_SECONDS=330
FISH_AI_PARSING_DAILY_LIMIT_MICROS=5000000
FISH_AI_PARSING_MONTHLY_LIMIT_MICROS=50000000
FISH_AI_PARSING_MAX_INPUT_TOKENS=64000
FISH_AI_PARSING_MAX_OUTPUT_TOKENS=16000
FISH_AI_PARSING_RATE_LIMIT_PER_MINUTE=5
FISH_APPLICATION_QUEUE_CONNECTION=database
```

`FISH_APPLICATION_QUEUE_CONNECTION` must point to the connection consumed by the normal `parsing` worker (`redis` in Docker Compose and `database` on the native VPS). Keep `FISH_AI_PARSING_PRICING_MODEL` and `FISH_AI_PARSING_PRICING_SERVICE_TIER` equal to the request model and tier. Before enabling any source, clear cached configuration, restart queue workers, and run:

```shell
php artisan fish:production-check
```

All sources remain deterministic after migration. Select one source from the admin Sources page, run one explicit payload smoke test with `php artisan fish:parse-payload PAYLOAD_ID --sync`, and verify its parser execution, comparison, budget, and saved reports before enabling more sources. The global switch is a kill switch: setting `FISH_AI_PARSING_ENABLED=false`, clearing configuration, and restarting workers makes queued AI parses use deterministic fallback. To roll back already-saved AI data, select deterministic and explicitly reparse the latest payload for the affected source/date.

Nightly scoring runs at 02:00 and verifies that every enabled source has a completed daily scrape and authoritative parse before refreshing deduplication and queueing score jobs. Hot alerts run at 02:15 and stop safely if any enabled rule is still missing its score result.

For the Phase 6 rollout, provision `GITHUB_APP_CLIENT_ID`, `GITHUB_APP_INSTALLATION_ID`, and either `GITHUB_APP_PRIVATE_KEY_PATH` or `GITHUB_APP_PRIVATE_KEY_BASE64` outside source control. Start with:

```dotenv
FISH_GITHUB_ISSUES_ENABLED=true
FISH_GITHUB_ISSUES_WRITE_ENABLED=false
FISH_GITHUB_ISSUES_PREVIEW_MODE=true
FISH_AI_REVIEW_HUMAN_WORKFLOW_ENABLED=true
```

Review and approve the first five previews in the parser-error admin screen. After review, set `FISH_GITHUB_ISSUES_WRITE_ENABLED=true`, clear the configuration cache, restart the external-services worker, and use each approved preview's **Publish approved issue** action. Keep preview mode enabled until the initial batch has been published and reviewed; disabling it permits later eligible candidates to publish automatically.

## Reverse Proxy

Use `docker/nginx/fishcounts.conf` as the starting Nginx server block and put TLS termination in front of it with Certbot or the existing VPS proxy.

## Backups

On the native VPS, run `/usr/local/bin/backup-fishcounts-db.sh` from cron to create compressed MariaDB backups under `/var/backups/fishcounts`.

For Docker Compose deployments, run `scripts/backup-postgres.sh` from cron or the container host backup system. Set `BACKUP_DIR`, `POSTGRES_CONTAINER`, `POSTGRES_DB`, and `POSTGRES_USER` when the defaults do not match your deployment.

## Deploy Procedure

Run `scripts/deploy.sh` after pulling a new release. It rebuilds containers, applies migrations, optimizes Laravel, and calls `php artisan queue:restart` so workers pick up the new code.
