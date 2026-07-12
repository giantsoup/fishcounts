# Deployment

## Docker Compose

1. Copy `.env.example` to `.env` and set production values for `APP_KEY`, `APP_URL`, mail, database, queue/cache, and Discord settings.
2. Run `docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d --build`.
3. Run `docker compose -f docker-compose.yml -f docker-compose.prod.yml exec app php artisan migrate --force`.
4. Run `docker compose -f docker-compose.yml -f docker-compose.prod.yml exec app php artisan fish:create-admin`.

## Native VPS

The production VPS uses Nginx, PHP-FPM, MariaDB, Supervisor, and Certbot. Match the existing server convention by setting `DB_CONNECTION=mariadb`, `QUEUE_CONNECTION=database`, `CACHE_STORE=database`, and `SESSION_DRIVER=database`.

Run the existing application queue worker through Supervisor with the database queue connection, and run the Laravel scheduler from cron. Add a separate `ai-parsing` Supervisor program with `numprocs=1` and this command so AI latency cannot occupy deterministic parsing workers:

```shell
php artisan queue:work database --queue=ai-parsing --sleep=3 --tries=0 --timeout=210
```

Keep `DB_QUEUE_RETRY_AFTER` greater than 210 seconds. Restart Supervisor workers only after the Phase 3 schema and dark Phase 4 code are deployed.

## Reverse Proxy

Use `docker/nginx/fishcounts.conf` as the starting Nginx server block and put TLS termination in front of it with Certbot or the existing VPS proxy.

## Backups

On the native VPS, run `/usr/local/bin/backup-fishcounts-db.sh` from cron to create compressed MariaDB backups under `/var/backups/fishcounts`.

For Docker Compose deployments, run `scripts/backup-postgres.sh` from cron or the container host backup system. Set `BACKUP_DIR`, `POSTGRES_CONTAINER`, `POSTGRES_DB`, and `POSTGRES_USER` when the defaults do not match your deployment.

## Deploy Procedure

Run `scripts/deploy.sh` after pulling a new release. It rebuilds containers, applies migrations, optimizes Laravel, and calls `php artisan queue:restart` so workers pick up the new code.
