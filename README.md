# FishCounts

FishCounts is a Laravel web application for collecting San Diego sportfishing counts, normalizing trip reports, scoring fishing activity, and notifying anglers when a bite is heating up. It combines catch data with marine and lunar conditions and links promising trips to available booking options.

## Features

- Collects daily fish counts from San Diego landing and aggregator sources.
- Preserves raw responses and normalizes boats, landings, trip types, species, and counts.
- Scores configurable alert rules using catch volume, counts per angler, fleet breadth, and source confidence.
- Collects tides, marine observations, swell data, and moon conditions for San Diego and the Coronado Islands.
- Sends hot-bite alerts and weekly digests by email or Discord.
- Provides authenticated views for counts, scores, alert history, notification settings, and booking links.
- Provides administrators with source, backfill, parser-error, alias, environmental-data, queue-failure, notification-log, and user management tools.

## Tech Stack

- PHP 8.4 and Laravel 13
- Laravel Breeze authentication with Blade, Alpine.js, and Tailwind CSS
- Vite 8 and Node.js 20.19+ or 22.12+
- PHPUnit 12
- SQLite for the default local environment
- PostgreSQL and Redis in the Docker Compose environment
- MariaDB, database queues, and database cache are also supported by the native deployment configuration

## Local Setup

### Prerequisites

- PHP 8.4 with the extensions required by Laravel and the selected database
- Composer 2
- Node.js 20.19+ or 22.12+ and npm

### Installation

1. Clone the repository and enter its directory.
2. Create the local environment file and SQLite database:

   ```bash
   cp .env.example .env
   touch database/database.sqlite
   ```

3. Review `.env`. For the default setup, set `APP_URL=http://localhost:8000` and leave `DB_CONNECTION=sqlite`. Never commit secrets or production credentials.
4. Install dependencies, generate the application key, run migrations, and build the frontend:

   ```bash
   composer run setup
   ```

5. Create the first administrator, then seed reference data and the administrator's default alert rule:

   ```bash
   php artisan fish:create-admin
   php artisan db:seed
   ```

6. Start the web server, queue listener, application log viewer, and Vite development server:

   ```bash
   composer run dev
   ```

The application is available at <http://localhost:8000>. Sign in with the administrator account created in step 5.

## Configuration

The checked-in `.env.example` documents the local defaults. Important settings include:

| Setting | Purpose |
| --- | --- |
| `APP_URL` | Base URL used for generated application links. |
| `DB_*` | Database connection settings. SQLite is the local default. |
| `QUEUE_CONNECTION` | Queue backend; the local default is `database`. |
| `MAIL_*` | Mail transport and sender used for alerts and password resets. The local default writes mail to the log. |
| `DISCORD_WEBHOOK_URL` | Optional default Discord webhook for notifications; add it to `.env` when needed. |
| `FISH_SCRAPER_USER_AGENT` | Identifies this application to fish-count sources. Use a real contact URL. |
| `FISH_CONDITIONS_*` | Environmental profile, coordinates, time zone, user agent, and HTTP timeouts. |

User-specific email addresses and Discord webhooks can be managed from the notification settings page. Notification destinations are encrypted at rest.

## Data Pipeline

The daily pipeline runs in `America/Los_Angeles` and uses separate queues for scraping, parsing, scoring, notifications, environmental collection, and backfills.

| Time | Command | Responsibility |
| --- | --- | --- |
| 12:30 a.m. | `booking:sync-provider-identifiers` | Refresh external booking-provider boat identifiers. |
| 12:45 a.m. | `fish:collect-environmental-data today` | Collect current environmental conditions. |
| 12:50 a.m. | `fish:collect-environmental-data yesterday --finalize` | Finalize the prior day's conditions. |
| 1:00 a.m. | `fish:scrape-daily` | Fetch and parse the prior day's fish counts. |
| 1:15 a.m. | `fish:score-latest` | Score enabled alert rules. |
| 1:25 a.m. | `fish:send-hot-alerts` | Send threshold-crossing notifications. |
| Sunday, 7:00 a.m. | `fish:send-weekly-digest` | Send the weekly fishing digest. |

`composer run dev` starts a queue listener, but it does not start the scheduler. To exercise scheduled tasks locally, run this in another terminal:

```bash
php artisan schedule:work
```

Inspect the authoritative schedule at any time with `php artisan schedule:list`.

## Useful Commands

```bash
# Run the daily scrape pipeline for the default date
php artisan fish:scrape-daily

# Scrape and reparse a specific date
php artisan fish:scrape-date 2026-07-01
php artisan fish:reparse-date 2026-07-01

# Collect or backfill environmental data
php artisan fish:collect-environmental-data 2026-07-01
php artisan fish:backfill-environmental-data --from=2026-07-01 --to=2026-07-07

# Score a date and send its alerts
php artisan fish:score-date 2026-07-01
php artisan fish:send-hot-alerts 2026-07-01

# Validate the native VPS production configuration
php artisan fish:production-check
```

Use `php artisan help <command>` before running backfills or other operational commands to review all arguments and options.

## Testing and Code Quality

Run the PHPUnit suite through Laravel's test runner:

```bash
composer test
```

Run a focused test while developing:

```bash
php artisan test --compact tests/Feature/CountsAndScoresIndexTest.php
php artisan test --compact --filter=testName
```

Format modified PHP files with Laravel Pint:

```bash
vendor/bin/pint --dirty --format agent
```

Build production frontend assets with:

```bash
npm run build
```

## Deployment

The repository includes Docker Compose services for the application, PostgreSQL, Redis, the queue worker, and the scheduler. A native Nginx/PHP-FPM/MariaDB deployment is also supported. See [DEPLOYMENT.md](DEPLOYMENT.md) for production configuration, health checks, backups, and the release procedure.

Before deploying, configure real secrets and mail delivery and set `APP_ENV=production` and `APP_DEBUG=false`. For the native database-backed VPS configuration, also run:

```bash
php artisan fish:production-check
```

The unauthenticated `/up` endpoint provides Laravel's application health check. The `/healthz` readiness endpoint also verifies that the application can query its database.

## Responsible Data Collection

Scrapers identify themselves with `FISH_SCRAPER_USER_AGENT`, restrict outbound requests to configured hosts, and apply source-specific rate limits. Keep the user agent's contact URL current, respect source terms and robots policies, and avoid increasing collection frequency without reviewing upstream limits.
