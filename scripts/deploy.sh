#!/usr/bin/env bash
set -euo pipefail

docker compose -f docker-compose.yml -f docker-compose.prod.yml pull
docker compose -f docker-compose.yml -f docker-compose.prod.yml build
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d --remove-orphans
docker compose -f docker-compose.yml -f docker-compose.prod.yml exec app php artisan migrate --force
docker compose -f docker-compose.yml -f docker-compose.prod.yml exec app php artisan optimize
docker compose -f docker-compose.yml -f docker-compose.prod.yml exec app php artisan queue:restart
