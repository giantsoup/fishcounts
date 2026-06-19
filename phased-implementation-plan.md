# San Diego Fish Count Hot Bite Alert App — Phased Implementation Plan

## 0. Project Objective

Build a self-hosted Laravel 13 application that scrapes San Diego sportfishing count sources once daily, normalizes fish counts, computes configurable species-specific “Hot Bite Scores,” and sends email/Discord notifications when configured alert rules cross thresholds.

The application must support:

* Laravel 13
* Dockerized deployment on an existing Linode VPS
* PostgreSQL
* Redis
* Laravel queues
* Laravel scheduler
* Public subdomain behind login
* Laravel official authentication scaffolding
* No Filament
* Multiple users
* Admin and normal user roles
* Configurable alert rules
* Manual dashboard-triggered backfill from January 1, 2026
* Daily scrape at 1:00 AM America/Los_Angeles
* Hot alert notifications only when thresholds are crossed
* Weekly Sunday digest regardless of threshold
* Email and Discord notifications

Primary V1 fishing scope:

* Region: San Diego
* Data entities: region, landing, boat
* Species: configurable per alert rule
* Trip types: configurable per alert rule
* Default initial rule: Local Yellowtail on 1/2 Day, 3/4 Day, and Full Day trips

---

# 1. Non-Negotiable Implementation Rules

## 1.1 Framework and packages

Use:

* Laravel 13
* PHP 8.4
* PostgreSQL
* Redis
* Laravel official starter kit/auth scaffolding
* Blade or Livewire-based server-rendered UI
* Laravel queues
* Laravel notifications
* Laravel scheduler
* Laravel policies/gates for authorization

Do not use:

* Filament
* Nova
* External SaaS dashboard dependencies
* Browser automation unless absolutely necessary
* Scraping in controllers
* Business logic in Blade views
* Hardcoded credentials
* Hardcoded notification recipients
* Hardcoded scraper thresholds
* Hardcoded source parsing inside jobs

Follow project conventions:

* Use PHPUnit for tests. Do not introduce Pest.
* Use `php artisan make:* --no-interaction` generators for Laravel classes, migrations, requests, policies, jobs, notifications, seeders, and tests.
* Prefer the conventions already present in this Laravel 13 skeleton, including attribute-based model metadata where sibling models use it.
* Use explicit PHP parameter and return types on all methods.
* Use PHP 8 constructor property promotion where constructors are needed.
* Use TitleCase enum case names.
* Run `vendor/bin/pint --dirty --format agent` after modifying PHP files.
* Run the smallest relevant PHPUnit test set after each implementation slice.

## 1.2 Application principles

The app must be:

* Source-adapter based
* Idempotent
* Restartable
* Queue-driven for scraping/backfill work
* Respectful of source websites
* Rate-limited
* Auditable
* Multi-user from the start
* Admin-controlled for system-level actions
* User-controlled for personal alert preferences

## 1.3 Data handling principle

Always store raw source payloads before parsing.

Never overwrite raw scrape data. Normalized rows may be updated/deduplicated, but the raw HTML/text/json payload should remain available for troubleshooting and future parser improvements.

## 1.4 Laravel implementation conventions

Before each implementation phase:

* Search the version-specific Laravel documentation for the framework feature being used.
* Inspect sibling files before choosing naming, structure, casts, fillable fields, route style, and test style.
* Keep controllers thin. Put business workflows in action/service classes and use jobs only to orchestrate queued work.
* Use Form Request classes for all create/update/admin forms. Put authorization in the Form Request or policy, and only persist `$request->validated()`.
* Use policies or gates for every user-owned or admin-only action.
* Use named routes and `route()` for links and redirects.
* Use implicit route model binding and scoped bindings where parent/child resources are involved.
* Avoid queries in Blade views; eager load all data in controllers or query/action classes.
* Prefer Eloquent relationships and local scopes over raw queries.

---

# 2. Finalized Product Requirements

## 2.1 Users and roles

Roles:

### Admin

The admin can:

* Manage users
* Assign roles
* Manage scrape sources
* Enable/disable sources
* Trigger manual backfills
* Cancel/pause backfills
* View raw scrape payloads
* View scraper errors
* Manage global species/trip-type/source aliases
* View all users’ alert rules
* View all notification delivery logs
* Run system diagnostics

### Normal user

A normal user can:

* Log in
* Manage own profile
* Manage own alert rules
* Manage own email/Discord notification settings
* View own alert history
* View public/normalized fish-count dashboard
* View score history relevant to their rules

Normal users must not be able to:

* Trigger backfills
* Manage scrape sources
* Manage users
* View secrets belonging to other users
* Modify system aliases
* View raw payloads unless admin explicitly allows later

## 2.2 Authentication

Use Laravel’s official starter kit/auth scaffolding.

Requirements:

* Public subdomain behind login
* Registration disabled after initial setup, unless admin invites/creates users
* Password reset enabled via email
* Login rate limiting
* CSRF protection
* Secure session cookies
* Authenticated routes only
* Admin-only routes protected by policy/gate/middleware
* Session regeneration on login
* Email verification optional for V1, but the decision must be explicit before production deployment

## 2.3 Notifications

Supported notification channels:

* Email
* Discord webhook

Notification behavior:

* Daily scrape runs at 1:00 AM Pacific.
* Daily hot-bite notification sends only when at least one enabled rule crosses threshold.
* Sunday weekly digest always sends, regardless of threshold.
* Notification events must be deduplicated.
* Discord delivery failures must not block email delivery.
* Email delivery failures must not block Discord delivery.
* Each channel delivery attempt should be logged separately so partial delivery can be diagnosed and retried.
* Notification logs must be retained.

## 2.4 Alert rules

Alert rules are user-owned and configurable.

Each rule targets exactly one species.

Each rule supports:

* Name
* User owner
* Species
* Enabled/disabled
* Selected regions
* Selected landings, optional
* Selected boats, optional
* Selected trip types
* Minimum Hot Bite Score
* Minimum total count, optional
* Minimum count per angler, optional
* Trend window days
* Baseline comparison window days
* Email enabled
* Discord enabled
* Include in weekly digest
* Quiet mode, optional future field
* Created by
* Updated by

Default initial rule for admin user:

* Name: Local Yellowtail
* Species: Yellowtail
* Region: San Diego
* Trip types: 1/2 Day, 3/4 Day, Full Day
* Minimum score: 70
* Email enabled: true
* Discord enabled: true
* Include in weekly digest: true

## 2.5 Scoring

Use a configurable Hot Bite Score from 0 to 100.

Initial balanced scoring weights:

* Total species count: 30%
* Count per angler: 25%
* Trend acceleration: 20%
* Boat breadth: 15%
* Landing breadth: 10%

Initial alert levels:

* 0–59: Cold/No alert
* 60–69: Watch/Stored only
* 70–79: Active/Notify
* 80–89: Hot/Notify
* 90–100: Wide Open/Notify

Important scoring constraints:

* A single huge boat count should not automatically produce “Wide Open.”
* Multiple boats producing the target species should increase confidence.
* Multiple landings producing the target species should increase confidence.
* Consecutive productive days should increase confidence.
* Missing angler count should not break scoring.
* Missing angler count should reduce confidence for count-per-angler, not discard the record.

## 2.6 Backfill

Backfill requirements:

* Manual dashboard trigger only
* Default range: January 1, 2026 through today
* Admin-only
* Source-selectable
* Date-range selectable
* Batch-size configurable
* Restartable
* Cancellable
* Pausable if reasonable
* Rate-limited
* Progress tracked
* Errors tracked per source/date
* Unavailable historical dates marked as unavailable, not as zero-count days

## 2.7 Dashboard

Use regular Laravel controllers, routes, policies, Blade/Livewire components, and forms.

Dashboard areas:

### Normal user pages

* Dashboard summary
* Fish count history
* Score history
* Alert rule CRUD
* Notification settings
* Alert history
* Profile/password pages

### Admin pages

* User management
* Source management
* Backfill management
* Scrape run diagnostics
* Raw payload viewer
* Parser error viewer
* Species alias management
* Trip type alias management
* System settings
* Notification delivery logs

---

# 3. Proposed Application Architecture

## 3.1 Runtime architecture

Linode host:

```text
Existing Linode VPS
├── Existing personal website
│   └── Existing host-level Nginx/PHP setup
│
├── Docker Compose project: fish-alerts
│   ├── app              Laravel PHP-FPM
│   ├── queue            Laravel queue worker
│   ├── scheduler        Laravel scheduler runner or host cron target
│   ├── postgres         PostgreSQL
│   ├── redis            Redis
│   └── nginx            Optional internal app Nginx
│
└── Host Nginx
    └── Reverse proxy fish.example.com to Docker app
```

Recommended:

* Keep current website untouched.
* Run fish app as a separate Docker Compose project.
* Expose only app HTTP port to host Nginx.
* Do not expose PostgreSQL or Redis publicly.
* Use Let’s Encrypt on the host Nginx layer.
* Use daily database backups.

## 3.2 Laravel application layers

Use these directories/concepts when they fit the code being implemented:

```text
app/
├── Actions/
├── Console/Commands/
├── DTOs/
├── Enums/
├── Http/Controllers/
├── Http/Middleware/
├── Jobs/
├── Models/
├── Notifications/
├── Policies/
├── Services/
│   ├── Scraping/
│   ├── Parsing/
│   ├── Normalization/
│   ├── Scoring/
│   ├── Deduplication/
│   └── Notifications/
└── Support/
```

Do not create empty base directories ahead of need. Add each directory when the first real class belongs there.

Keep controllers thin.

Keep scraping/parsing/scoring logic in service classes.

Jobs should orchestrate work but should not contain parser/scoring algorithms directly.

---

# 4. Phase 1 — Project Bootstrap

## Goal

Create a clean Laravel 13 project with auth, Docker, PostgreSQL, Redis, and role-based access.

## Tasks

1. Create Laravel 13 project.
2. Install official Laravel starter kit for authentication.
3. Prefer a server-rendered stack:

   * Blade/Livewire is preferred for this app.
   * Avoid SPA complexity unless explicitly needed later.
4. Configure PostgreSQL.
5. Configure Redis.
6. Configure queue connection.
7. Configure mail driver.
8. Create Docker Compose files.
9. Add local development environment.
10. Add production Docker environment.
11. Add Makefile or composer scripts for common commands.
12. Add Pint formatting.
13. Use the existing PHPUnit test suite.
14. Add role enum:

    * admin
    * user
15. Add admin gate/policy helpers.
16. Create initial admin seed command.

## Acceptance criteria

* App boots locally in Docker.
* Login works.
* Registration is disabled or admin-controlled.
* PostgreSQL migrations run.
* Redis connection works.
* Queue worker runs.
* Authenticated dashboard route works.
* Admin-only route blocks normal users.
* Tests run successfully.
* PHP runtime and Composer platform requirements align with PHP 8.4.

---

# 5. Phase 2 — Core Database Schema

## Goal

Create normalized, future-proof schema for regions, landings, boats, trip types, species, source payloads, reports, counts, rules, scores, backfills, and notifications.

## Core tables

Schema implementation rules:

* Create migrations with Artisan.
* Keep each migration focused on one concern.
* Add foreign keys with `constrained()` where practical.
* Add indexes during the migration for columns used in filters, joins, ordering, uniqueness, and deduplication.
* Use PostgreSQL `jsonb` for metadata/settings/explanation fields.
* Mirror database defaults in model attributes.
* Define fillable fields or guarded fields on every model.
* Define casts for booleans, dates, encrypted fields, JSON fields, decimals, and enums.
* Prefer enum-backed status/type fields for internal state values.
* Make migration `down()` methods reversible unless the migration is intentionally forward-only and documented.

### users

Add fields:

```text
id
name
email
email_verified_at
password
role
timezone
is_active
last_login_at
remember_token
created_at
updated_at
```

### regions

```text
id
name
slug
is_active
created_at
updated_at
```

Seed:

* San Diego

### landings

```text
id
region_id
name
slug
website_url nullable
is_active
created_at
updated_at
```

Initial San Diego landings:

* Fisherman's Landing
* Seaforth Sportfishing
* H&M Landing
* Point Loma Sportfishing

### boats

```text
id
landing_id nullable
name
slug
is_active
created_at
updated_at
```

A boat may move landings over time, so do not rely only on current boat.landing_id for historical analysis. Store landing_id on trip_reports as the historical landing for that report.

### species

```text
id
name
slug
is_active
created_at
updated_at
```

Seed common species:

* Yellowtail
* Bluefin Tuna
* Yellowfin Tuna
* Dorado
* White Seabass
* Bonito
* Calico Bass
* Sand Bass
* Rockfish
* Barracuda
* Sheephead
* Whitefish
* Sculpin
* Lingcod
* Halibut

### species_aliases

```text
id
species_id
alias
normalized_alias
created_at
updated_at
```

Purpose:

* Normalize “YT” to Yellowtail if encountered.
* Normalize spelling and source-specific variants.

### trip_types

```text
id
name
slug
sort_order
is_active
created_at
updated_at
```

Seed examples:

* 1/2 Day AM
* 1/2 Day PM
* 1/2 Day Twilight
* 1/2 Day
* 3/4 Day
* Full Day
* Full Day Coronado Islands
* Overnight
* 1.5 Day
* 2 Day
* 2.5 Day
* 3 Day
* Multi-day
* Long Range
* Unknown

### trip_type_aliases

```text
id
trip_type_id
alias
normalized_alias
created_at
updated_at
```

### scrape_sources

```text
id
name
slug
source_type
base_url
priority
is_enabled
supports_historical_dates
supports_landing_filter
rate_limit_seconds
notes nullable
last_success_at nullable
last_failure_at nullable
created_at
updated_at
```

Source types:

* aggregator
* landing
* report_feed
* fallback

Initial source keys:

* sandiego_fish_reports
* fishermans_landing
* seaforth_landing
* hm_landing
* point_loma_sportfishing
* sportfishingreport_landing_pages

### scrape_runs

```text
id
scrape_source_id nullable
run_type
target_date
status
started_at
finished_at
error_message nullable
metadata jsonb nullable
created_at
updated_at
```

Run types:

* daily
* backfill
* manual
* reparse

Statuses:

* pending
* running
* succeeded
* failed
* partial
* cancelled

### raw_scrape_payloads

```text
id
scrape_run_id
scrape_source_id
target_date
url
http_status nullable
content_type nullable
payload
payload_hash
fetched_at
parsed_at nullable
parser_version nullable
error_message nullable
metadata jsonb nullable
created_at
updated_at
```

Unique index suggestion:

```text
scrape_source_id + target_date + url + payload_hash
```

### trip_reports

```text
id
source_id
raw_scrape_payload_id nullable
region_id nullable
landing_id nullable
boat_id nullable
trip_type_id nullable
trip_date
source_trip_identifier nullable
anglers nullable
raw_boat_name nullable
raw_landing_name nullable
raw_trip_type nullable
raw_fish_count_text nullable
is_deduped_primary
dedupe_key
source_confidence
metadata jsonb nullable
created_at
updated_at
```

Unique/indexing:

```text
index trip_date
index landing_id + trip_date
index boat_id + trip_date
index trip_type_id + trip_date
index region_id + trip_date
index source_id + trip_date
unique source_id + source_trip_identifier nullable where available
index dedupe_key
```

### species_counts

```text
id
trip_report_id
species_id
count
released_count default 0
is_retained_count boolean default true
raw_species_name nullable
raw_count_text nullable
created_at
updated_at
```

Unique index:

```text
trip_report_id + species_id + is_retained_count
```

### alert_rules

```text
id
user_id
name
species_id
is_enabled
minimum_score
minimum_total_count nullable
minimum_count_per_angler nullable
trend_window_days
baseline_window_days
email_enabled
discord_enabled
include_in_weekly_digest
settings jsonb nullable
created_at
updated_at
```

### alert_rule_trip_type

```text
alert_rule_id
trip_type_id
```

### alert_rule_region

```text
alert_rule_id
region_id
```

### alert_rule_landing

```text
alert_rule_id
landing_id
```

### alert_rule_boat

```text
alert_rule_id
boat_id
```

### notification_destinations

```text
id
user_id
channel
name
destination
is_enabled
verified_at nullable
created_at
updated_at
```

Channels:

* email
* discord

Security:

* Discord webhook URLs should be encrypted at rest using Laravel encrypted casts.
* Mask webhook URLs in UI.
* Do not log full webhook URLs.
* Store email destinations separately from user profile email only when alternate email support is implemented.
* Add a unique constraint that prevents duplicate enabled destinations for the same user/channel/name where practical.

### notification_deliveries

```text
id
alert_event_id nullable
user_id
notification_destination_id nullable
channel
notification_type
status
attempted_at
sent_at nullable
failed_at nullable
error_message nullable
metadata jsonb nullable
created_at
updated_at
```

Purpose:

* Track email and Discord attempts independently.
* Make partial failures visible.
* Support safe retry without duplicating already successful channel deliveries.

### score_runs

```text
id
run_date
status
started_at
finished_at
created_at
updated_at
```

### score_results

```text
id
score_run_id
alert_rule_id
score_date
score
level
total_count
total_anglers nullable
count_per_angler nullable
boat_count
landing_count
trend_score
count_score
count_per_angler_score
breadth_score
source_confidence_score
explanation jsonb
created_at
updated_at
```

Unique index:

```text
alert_rule_id + score_date
```

Recommended indexes:

```text
score_date
level + score_date
```

### alert_events

```text
id
user_id
alert_rule_id
score_result_id nullable
event_type
event_date
level
score
email_sent_at nullable
discord_sent_at nullable
status
error_message nullable
created_at
updated_at
```

Unique index:

```text
alert_rule_id + event_type + event_date
```

Recommended index:

```text
user_id + event_date
```

### backfill_runs

```text
id
created_by_user_id
from_date
to_date
status
source_ids jsonb
batch_size_days
current_date nullable
total_days
processed_days
failed_days
started_at nullable
finished_at nullable
cancel_requested_at nullable
error_message nullable
created_at
updated_at
```

### backfill_run_items

```text
id
backfill_run_id
scrape_source_id
target_date
status
started_at nullable
finished_at nullable
error_message nullable
created_at
updated_at
```

Unique/indexing:

```text
unique backfill_run_id + scrape_source_id + target_date
index status + target_date
```

### parser_errors

```text
id
raw_scrape_payload_id nullable
scrape_source_id
target_date nullable
error_type
raw_field nullable
raw_value nullable
message
context jsonb nullable
resolved_at nullable
resolved_by_user_id nullable
created_at
updated_at
```

Purpose:

* Preserve parser warnings and failures without losing the raw payload.
* Drive alias-management workflows from real unmatched source data.

---

# 6. Phase 3 — Authenticated Dashboard Without Filament

## Goal

Build a small custom dashboard using Laravel auth, controllers, policies, Blade/Livewire, and forms.

## User routes

Create authenticated routes:

```text
GET /dashboard
GET /counts
GET /scores
GET /alert-rules
GET /alert-rules/create
POST /alert-rules
GET /alert-rules/{rule}/edit
PUT /alert-rules/{rule}
DELETE /alert-rules/{rule}
GET /notification-settings
PUT /notification-settings
GET /alerts
GET /profile
```

Prefer `Route::resource()` for CRUD resources such as alert rules and use named route groups for user and admin areas.

## Admin routes

Create admin-only routes:

```text
GET /admin
GET /admin/users
GET /admin/users/{user}/edit
PUT /admin/users/{user}
GET /admin/sources
PUT /admin/sources/{source}
GET /admin/backfills
GET /admin/backfills/create
POST /admin/backfills
POST /admin/backfills/{backfill}/cancel
GET /admin/scrape-runs
GET /admin/scrape-runs/{run}
GET /admin/raw-payloads/{payload}
GET /admin/parser-errors
GET /admin/species
GET /admin/trip-types
GET /admin/notification-logs
```

## Policies

Implement:

* AlertRulePolicy
* NotificationDestinationPolicy
* UserPolicy
* ScrapeSourcePolicy
* BackfillRunPolicy
* RawScrapePayloadPolicy

Rules:

* Users can only manage their own alert rules.
* Users can only manage their own notification destinations.
* Admin can manage all.
* Only admin can trigger backfills.
* Only admin can view raw payloads.
* Form Requests must call the relevant policy or gate in `authorize()`.
* Feature tests must cover both allowed and denied paths for every policy.

## Dashboard page requirements

### /dashboard

Show:

* Latest scrape status
* Latest score summary
* Active alert rules
* Recent alert events
* Link to weekly digest history

### /counts

Filters:

* Date range
* Species
* Trip type
* Landing
* Boat

Columns:

* Date
* Landing
* Boat
* Trip Type
* Anglers
* Species
* Count
* Count per angler

### /scores

Show per-rule score history.

Columns:

* Date
* Rule
* Species
* Score
* Level
* Total count
* Count per angler
* Boat count
* Landing count

### /alert-rules

CRUD for user-owned rules.

Must include:

* Species selector
* Trip type multi-select
* Region selector
* Optional landing selector
* Optional boat selector
* Threshold fields
* Channel toggles
* Weekly digest toggle

Implementation requirements:

* Use Form Requests for store/update.
* Validate numeric thresholds with explicit min/max bounds.
* Validate selected region/landing/boat/trip type IDs against active records.
* Ensure optional landings and boats belong to the selected regions where applicable.
* Use database transactions when syncing many-to-many rule filters.

### /notification-settings

Allow:

* Email notifications to user email
* Optional alternate email later
* Discord webhook URL
* Test email button
* Test Discord button

---

# 7. Phase 4 — Source Adapter System

## Goal

Implement source adapters that fetch raw data and return parseable payloads.

## Adapter contract

Create interface:

```php
namespace App\Services\Scraping\Contracts;

use App\Models\ScrapeSource;
use Carbon\CarbonImmutable;

interface FishCountSourceAdapter
{
    public function sourceKey(): string;

    public function supportsDate(CarbonImmutable $date): bool;

    public function fetchForDate(ScrapeSource $source, CarbonImmutable $date): FetchResult;

    public function parse(RawPayloadData $payload): ParsedFishCountCollection;
}
```

## Data transfer objects

Create DTOs:

```text
FetchResult
- url
- statusCode
- contentType
- body
- fetchedAt
- metadata
```

```text
ParsedTripReportData
- sourceKey
- tripDate
- regionName
- landingName
- boatName
- tripTypeName
- anglers
- rawFishCountText
- speciesCounts[]
- metadata
```

```text
ParsedSpeciesCountData
- speciesName
- count
- releasedCount
- rawText
```

## Initial adapters

Implement in priority order:

1. SanDiegoFishReportsAdapter
2. FishermansLandingAdapter
3. SeaforthLandingAdapter
4. HmLandingAdapter
5. PointLomaSportfishingAdapter
6. SportfishingReportLandingAdapter

## Source priority

Use priority to resolve duplicates:

1. Direct landing source
2. SanDiegoFishReports aggregator
3. SportfishingReport landing pages

This can be adjusted after empirical comparison.

## Scraping rules

* Use Laravel HTTP client.
* Set clear User-Agent.
* Respect robots/TOS.
* Rate-limit requests by source.
* Set explicit `timeout()` and `connectTimeout()` values.
* Retry transient connection/server failures conservatively with backoff.
* Do not retry 404-like historical misses aggressively.
* Store raw response even if parsing fails.
* Record parser version.
* Do not scrape more than necessary.
* Backfill should process slowly.
* Use source-domain allowlists; never accept arbitrary user-supplied fetch URLs.
* In tests, use `Http::fake()` and prevent stray real HTTP requests.

## Commands

Create:

```text
php artisan fish:scrape-daily
php artisan fish:scrape-date {date}
php artisan fish:parse-payload {payloadId}
php artisan fish:reparse-date {date}
```

---

# 8. Phase 5 — Parsing, Normalization, and Deduplication

## Goal

Convert raw source payloads into clean normalized trip reports and species counts.

## Parsing rules

Each parser should:

* Extract trip date
* Extract landing
* Extract boat
* Extract trip type
* Extract anglers
* Extract raw fish count text
* Extract each species count
* Preserve released counts separately
* Preserve unknown/unmatched species as parser errors or pending aliases

## Normalization services

Create:

```text
SpeciesNormalizer
TripTypeNormalizer
LandingNormalizer
BoatNormalizer
FishCountTextParser
```

Normalization requirements:

* Store unresolved aliases as parser errors or pending alias candidates.
* Do not silently create new species/trip types from scraped text without admin review unless explicitly approved later.
* Use case-insensitive normalized alias matching.
* Preserve raw source names on trip reports for auditability.

## Fish count parsing

Must handle examples like:

```text
62 Yellowtail
73 Yellowtail, 11 Rockfish, 10 Barracuda
50 Calico Bass Released, 31 Calico Bass
0
No Fish Report
```

Rules:

* “Released” counts should be stored separately.
* Retained and released counts must not be merged.
* Unknown species should not break entire trip parsing.
* Parser should emit structured warnings.

## Deduplication

Create `TripReportDeduplicator`.

Potential dedupe key:

```text
trip_date
normalized_landing
normalized_boat
normalized_trip_type
anglers
normalized species-count signature
```

Deduping behavior:

* Multiple sources may report the same trip.
* Keep all raw payloads.
* Keep one primary normalized trip report.
* Mark duplicates as non-primary or link duplicate group if implemented.
* Prefer higher priority source.
* Never delete duplicate raw payloads.
* Use database transactions when replacing normalized report/count rows for a payload.
* Make repeated parse/reparse operations idempotent.

---

# 9. Phase 6 — Scoring Engine

## Goal

Compute Hot Bite Scores per enabled alert rule per date.

## Core service

Create:

```text
HotBiteScoringService
```

Input:

* AlertRule
* Score date

Output:

* ScoreResult

## Score components

Scoring implementation requirements:

* Use retained counts for alert scoring unless the rule explicitly opts into released counts later.
* Exclude non-primary duplicate trip reports from score aggregation.
* Store enough explanation JSON to reproduce the displayed score inputs.
* Clamp every component and final score to 0-100.
* Define species default targets in config or seeded settings, not hardcoded inside the scoring service.

### Count score

Based on total retained count for the rule’s species and trip filters.

Initial approach:

```text
count_score = min(100, total_count / configured_target_count * 100)
```

If `configured_target_count` is missing, use a species-specific default.

### Count-per-angler score

```text
count_per_angler = total_count / total_anglers
count_per_angler_score = min(100, count_per_angler / configured_target_cpa * 100)
```

If anglers are missing:

* Do not fail scoring.
* Set count_per_angler to null.
* Reduce confidence.
* Redistribute weight or assign partial neutral value.

### Trend score

Compare recent window to previous baseline window.

Example:

```text
current_window = last 3 days
baseline_window = prior 3 days
trend_ratio = current_window_count / max(1, baseline_window_count)
```

Map trend ratio to 0–100.

### Boat breadth score

Reward number of distinct boats with the target species.

```text
boat_breadth_score = min(100, distinct_successful_boats / configured_target_boats * 100)
```

### Landing breadth score

Reward multiple landings producing the target species.

```text
landing_breadth_score = min(100, distinct_successful_landings / configured_target_landings * 100)
```

## Initial balanced formula

```text
score =
    count_score * 0.30
  + count_per_angler_score * 0.25
  + trend_score * 0.20
  + boat_breadth_score * 0.15
  + landing_breadth_score * 0.10
```

## Score levels

```text
cold: 0-59
watch: 60-69
active: 70-79
hot: 80-89
wide_open: 90-100
```

## Commands

Create:

```text
php artisan fish:score-date {date}
php artisan fish:score-latest
php artisan fish:score-backfill {--from=} {--to=}
```

## Acceptance criteria

* Score can be computed independently of scraping.
* Score results are persisted.
* Score explanations are stored as JSON.
* Each component score is inspectable in the UI.
* Tests cover edge cases.

---

# 10. Phase 7 — Notification System

## Goal

Send daily hot alerts and weekly digests via email and Discord.

## Notifications

Create Laravel notifications:

```text
HotBiteAlertNotification
WeeklyFishingDigestNotification
TestNotification
```

## Delivery channels

Email:

* Use Laravel mail notification channel.
* Send to user email initially.
* Support additional notification destination later.

Discord:

* Use a small custom Discord webhook notification sender.
* Store webhook encrypted.
* Never log full webhook.
* Mask in UI.
* Give each channel send its own queued job or isolated delivery attempt so one failed channel cannot mark the other as failed.

## Daily alert behavior

After daily scrape and scoring:

1. Find enabled alert rules whose score crossed threshold.
2. Create alert event.
3. Send email if enabled.
4. Send Discord if enabled.
5. Mark delivery status.
6. Prevent duplicate alerts for same user/rule/date/type.
7. Record a `notification_deliveries` row per attempted channel.

## Weekly digest behavior

Every Sunday morning:

1. Generate previous 7-day summary.
2. Include all rules with `include_in_weekly_digest = true`.
3. Send regardless of hot threshold.
4. Include:

   * Weekly score trend
   * Best day
   * Top boats
   * Top landings
   * Total count
   * Count per angler when available
   * Data quality notes

## Commands

Create:

```text
php artisan fish:send-hot-alerts {date?}
php artisan fish:send-weekly-digest {--date=}
php artisan fish:test-notifications {userId}
```

---

# 11. Phase 8 — Scheduler and Queue Pipeline

## Goal

Wire daily automation safely.

## Scheduler

Configure:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('fish:scrape-daily')
    ->dailyAt('01:00')
    ->timezone('America/Los_Angeles')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('fish:score-latest')
    ->dailyAt('01:15')
    ->timezone('America/Los_Angeles')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('fish:send-hot-alerts')
    ->dailyAt('01:25')
    ->timezone('America/Los_Angeles')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('fish:send-weekly-digest')
    ->sundays()
    ->at('07:00')
    ->timezone('America/Los_Angeles')
    ->withoutOverlapping()
    ->onOneServer();
```

Scheduler requirements:

* Use Redis or another shared cache store in production so `onOneServer()` works correctly.
* Consider grouping scheduled fishing commands with shared timezone and `onOneServer()` configuration.
* Ensure scrape, score, and notification commands are idempotent because manual retries and delayed queues can overlap.

## Queue jobs

Create:

```text
ScrapeSourceForDateJob
ParseRawPayloadJob
NormalizeTripReportsJob
DeduplicateTripReportsJob
ComputeScoreForRuleJob
SendHotBiteAlertJob
SendWeeklyDigestJob
BackfillDateJob
BackfillSourceDateJob
```

## Queue design

Queues:

```text
default
scraping
parsing
scoring
notifications
backfill
```

Use Redis queue driver.

Failed jobs must be inspectable.

Queue implementation requirements:

* Configure queue `retry_after` greater than the longest job timeout.
* Set explicit `$tries`, `$timeout`, and `$backoff` on queued jobs.
* Implement `failed()` on jobs that update scrape/backfill/notification state.
* Use `RateLimited` job middleware or cache locks for per-source scraping limits.
* Use unique jobs or locks for source/date work to prevent duplicate scraping.
* Use `Bus::batch()` for backfill groups when it improves progress tracking and cancellation.

---

# 12. Phase 9 — Manual Backfill Dashboard

## Goal

Allow admin to run controlled historical backfills from the dashboard.

## Admin UI

Create page:

```text
/admin/backfills/create
```

Fields:

* From date
* To date
* Source selector
* Batch size
* Dry run checkbox
* Notes

Default:

* From: 2026-01-01
* To: today
* Sources: all enabled
* Batch size: 7 days

## Backfill execution

Flow:

```text
Admin starts BackfillRun
→ BackfillRun rows created
→ BackfillDateJob dispatched
→ BackfillSourceDateJob dispatched for each source/date
→ Raw payloads stored
→ Payloads parsed
→ Reports normalized
→ Dedupe runs
→ Progress updates
```

Implementation requirements:

* Validate date range and source selection with a Form Request.
* Use admin policy authorization before creating a backfill.
* Create backfill items inside a database transaction.
* Use unique backfill item constraints so retries cannot enqueue duplicate source/date rows.
* Record unavailable historical dates distinctly from successful zero-count reports.

## Controls

Admin can:

* View progress
* View failed source/date pairs
* Cancel a run
* Retry failed dates
* Reparse payloads after parser fixes

## Backfill constraints

* Must not run uncontrolled massive parallel jobs.
* Must respect per-source rate limits.
* Must be restartable.
* Must not duplicate normalized data.
* Must distinguish “source unavailable for date” from “zero fish count.”

---

# 13. Phase 10 — Source Diagnostics and Troubleshooting

## Goal

Make scraping transparent enough to debug without shell access.

## Admin diagnostics pages

All diagnostics pages must be admin-only, escape raw payload output by default, and avoid rendering untrusted HTML directly.

### Scrape runs

Show:

* Run date
* Source
* Status
* Started/finished
* Payload count
* Parsed trip reports
* Error message

### Raw payload viewer

Show:

* Source
* Date
* URL
* HTTP status
* Payload hash
* Parser version
* Raw text/html preview
* Parsed result preview
* Reparse button

### Parser errors

Show:

* Source
* Date
* Raw field
* Error type
* Error message
* Suggested alias action

### Source management

Show:

* Enabled toggle
* Priority
* Rate limit seconds
* Last success
* Last failure
* Historical support
* Notes

---

# 14. Phase 11 — Deployment on Existing Linode

## Goal

Deploy safely without disturbing the existing personal website.

## Deployment structure

Suggested directory:

```text
/opt/fish-alerts
├── app source
├── docker-compose.yml
├── docker-compose.prod.yml
├── .env
├── backups
└── scripts
```

## Docker services

Required services:

```text
app
queue
postgres
redis
```

Optional:

```text
nginx
scheduler
```

## Production requirements

* Host Nginx reverse proxy to app container
* HTTPS via Let’s Encrypt
* `.env` not committed
* Docker volumes for PostgreSQL
* Daily database backups
* Log rotation
* Restart policies
* Health check endpoint
* Queue worker restart strategy
* `APP_DEBUG=false`
* Secure session cookie settings
* `config:cache`, `route:cache`, and event cache during deployment where compatible
* Documented queue restart on deploy with `php artisan queue:restart`

## Host Nginx

Use subdomain:

```text
fish.example.com
```

Proxy only HTTP traffic to the app.

Do not expose:

* PostgreSQL
* Redis
* Queue dashboard unless admin-protected
* Internal debugging routes

## Backup strategy

Daily backup:

* PostgreSQL dump
* `.env` encrypted/off-server
* Uploaded none expected initially
* Retain at least 14 daily backups

---

# 15. Phase 12 — Testing Strategy

## Unit tests

Cover:

* Species parsing
* Trip type normalization
* Species alias normalization
* Fish count text parser
* Dedupe key generation
* Hot Bite Score components
* Alert threshold decisions
* Discord payload formatting
* Backfill status transitions
* Form Request validation boundaries
* Policy authorization decisions

## Feature tests

Cover:

* Login required
* Normal user cannot access admin routes
* Admin can access admin routes
* User can CRUD own alert rules
* User cannot CRUD another user’s alert rules
* Admin can start backfill
* Normal user cannot start backfill
* Notification settings save correctly
* Disabled rule does not notify
* Weekly digest sends even below threshold
* Alert rule filters reject inactive or out-of-scope related records
* Raw payload viewer escapes untrusted payloads
* Per-channel notification failure does not block the other channel

## Integration-style tests

Use stored HTML/text fixtures for each source adapter.

Fixtures:

```text
tests/Fixtures/sources/sandiego_fish_reports/
tests/Fixtures/sources/fishermans_landing/
tests/Fixtures/sources/seaforth_landing/
tests/Fixtures/sources/hm_landing/
tests/Fixtures/sources/point_loma/
```

Every parser should be tested against fixtures.

No parser should rely only on live HTTP tests.

Testing implementation requirements:

* Use PHPUnit classes generated with `php artisan make:test --phpunit`.
* Prefer `LazilyRefreshDatabase` where it fits the suite.
* Use factories and factory states for model setup.
* Use `Http::fake()` for scraper tests and prevent stray requests.
* Run the specific changed test file or filter before moving to the next phase.

---

# 16. Phase 13 — Seed Data

## Required seeders

Create:

```text
RegionSeeder
LandingSeeder
SpeciesSeeder
SpeciesAliasSeeder
TripTypeSeeder
TripTypeAliasSeeder
ScrapeSourceSeeder
AdminUserSeeder
DefaultAlertRuleSeeder
```

Seeder requirements:

* Make seeders idempotent with stable slugs/keys.
* Do not overwrite user-managed notification secrets.
* Keep seed data separate from schema migrations.

## Initial source registry

Seed enabled sources:

```text
sandiego_fish_reports
fishermans_landing
seaforth_landing
hm_landing
point_loma_sportfishing
sportfishingreport_landing_pages
```

## Default admin alert rule

Create for initial admin:

```text
Name: Local Yellowtail
Species: Yellowtail
Regions: San Diego
Trip types: 1/2 Day, 1/2 Day AM, 1/2 Day PM, 3/4 Day, Full Day, Full Day Coronado Islands
Minimum score: 70
Email enabled: true
Discord enabled: true if webhook configured
Weekly digest: true
```

---

# 17. Phase 14 — Security Requirements

## Authentication security

* Login throttling
* Password reset via email
* Secure cookies in production
* HTTPS only
* CSRF enabled
* Session regeneration on login
* Registration disabled unless admin-enabled

## Authorization security

* Every dashboard route behind auth
* Every admin route behind admin gate
* Policies for user-owned resources
* Tests for cross-user access denial
* No controller action should rely on UI-hidden buttons as the only authorization boundary

## Secret handling

* Discord webhook encrypted
* Mail credentials in `.env`
* Database password in `.env`
* No secrets in logs
* No secrets in frontend HTML
* Mask sensitive settings in UI
* Access secrets through `config()` values, not direct `env()` calls outside config files

## Scraper safety

* Do not expose arbitrary URL fetcher to users.
* Source URLs must come from admin-managed scrape_sources.
* Normal users cannot create scrape sources.
* Validate and constrain source domains.
* Never render raw remote HTML as trusted markup in diagnostics.

---

# 18. Phase 15 — Observability and Maintenance

## App-level observability

Implement:

* Scrape run status
* Backfill status
* Parser error logs
* Notification delivery logs
* Failed job visibility
* Health check route
* Latest successful scrape timestamp
* Per-channel notification delivery status
* Source/date unavailable markers

## Optional later additions

* Laravel Horizon for Redis queue monitoring
* Sentry or Flare
* Laravel Pulse
* Uptime monitoring
* Discord admin-only scraper failure alerts

Do not add these in the first implementation unless needed.

---

# 19. Suggested Build Order for Codex

Codex should implement in this order:

1. Bootstrap Laravel 13 app with auth and Docker.
2. Add roles, policies, and route protection.
3. Add database schema and seeders.
4. Add factories and base seeders for core lookup tables.
5. Build basic authenticated dashboard shell.
6. Build alert rule CRUD with policies, Form Requests, and tests.
7. Build source adapter contract and raw payload storage.
8. Implement one adapter first: SanDiegoFishReportsAdapter.
9. Add parser fixtures and parser tests.
10. Implement normalization and deduplication.
11. Implement additional adapters one at a time.
12. Implement scoring engine.
13. Implement email notification.
14. Implement Discord notification.
15. Implement scheduler commands.
16. Implement manual backfill dashboard.
17. Add diagnostics pages.
18. Add deployment scripts.
19. Add backup scripts.
20. Harden security.
21. Final acceptance test pass.

Do not implement all source adapters before the parser architecture is tested with the first adapter.

---

# 20. Final Acceptance Criteria

The project is ready when:

* App deploys on Linode under a subdomain.
* Login works.
* Admin and normal user roles work.
* Admin can manage users.
* User can manage own alert rules.
* User can configure email and Discord notification settings.
* Daily scrape runs at 1:00 AM Pacific.
* Scrape stores raw payloads.
* Parser creates normalized trip reports.
* Species counts are stored correctly.
* Boat, landing, and region are preserved.
* Rules can target any species and any configured trip types.
* Hot Bite Scores are computed per rule.
* Daily alerts send only when thresholds are crossed.
* Sunday weekly digest sends regardless of threshold.
* Admin can manually trigger backfill from January 1, 2026.
* Backfill progress is visible.
* Backfill is restartable/cancellable.
* Source failures are visible.
* Parser errors are visible.
* No Filament is installed.
* Tests cover parser, scoring, auth, policies, notifications, and backfill state.
* Database backups are configured.
* Secrets are not exposed.
