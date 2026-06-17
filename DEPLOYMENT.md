# Deployment

## Docker Compose

1. Copy `.env.example` to `.env` and set production values for `APP_KEY`, `APP_URL`, mail, database, Redis, and Discord settings.
2. Run `docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d --build`.
3. Run `docker compose -f docker-compose.yml -f docker-compose.prod.yml exec app php artisan migrate --force`.
4. Run `docker compose -f docker-compose.yml -f docker-compose.prod.yml exec app php artisan fish:create-admin`.

## Reverse Proxy

Use `docker/nginx/fishcounts.conf` as the starting Nginx server block and put TLS termination in front of it with Certbot or the existing VPS proxy.

## Backups

Run `scripts/backup-postgres.sh` from cron or the VPS backup system. Set `BACKUP_DIR`, `POSTGRES_CONTAINER`, `POSTGRES_DB`, and `POSTGRES_USER` when the defaults do not match your deployment.

## Deploy Procedure

Run `scripts/deploy.sh` after pulling a new release. It rebuilds containers, applies migrations, optimizes Laravel, and calls `php artisan queue:restart` so workers pick up the new code.
