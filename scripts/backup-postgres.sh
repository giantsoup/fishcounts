#!/usr/bin/env bash
set -euo pipefail

timestamp="$(date -u +%Y%m%dT%H%M%SZ)"
backup_dir="${BACKUP_DIR:-./backups}"
container="${POSTGRES_CONTAINER:-fishcounts-postgres-1}"
database="${POSTGRES_DB:-fishcounts}"
username="${POSTGRES_USER:-fishcounts}"

mkdir -p "${backup_dir}"
docker exec "${container}" pg_dump -U "${username}" "${database}" | gzip > "${backup_dir}/fishcounts-${timestamp}.sql.gz"
