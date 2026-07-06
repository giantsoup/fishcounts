#!/usr/bin/env bash

set -Eeuo pipefail

: "${DEPLOY_PATH:?DEPLOY_PATH must be set.}"
: "${RELEASE_SHA:?RELEASE_SHA must be set.}"

RELEASE_ARCHIVE="${RELEASE_ARCHIVE:-/tmp/fishcounts-release.tar.gz}"
KEEP_RELEASES="${KEEP_RELEASES:-5}"
PHP_BIN="${PHP_BIN:-php}"
COMPOSER_BIN="${COMPOSER_BIN:-composer}"
APP_GROUP="${APP_GROUP:-fishcounts}"
WEB_USER="${WEB_USER:-www-data}"

release_name="$(date +%Y%m%d%H%M%S)"
release_dir="${DEPLOY_PATH}/releases/${release_name}"
shared_dir="${DEPLOY_PATH}/shared"
current_dir="${DEPLOY_PATH}/current"
release_activated=false

cleanup_failed_release() {
    if [[ "${release_activated}" == "false" && -d "${release_dir}" ]]; then
        rm -rf "${release_dir}"
    fi
}

trap cleanup_failed_release ERR

mkdir -p "${DEPLOY_PATH}/releases"
mkdir -p "${shared_dir}/storage/app/private"
mkdir -p "${shared_dir}/storage/app/public"
mkdir -p "${shared_dir}/storage/framework/cache/data"
mkdir -p "${shared_dir}/storage/framework/sessions"
mkdir -p "${shared_dir}/storage/framework/views"
mkdir -p "${shared_dir}/storage/logs"
mkdir -p "${shared_dir}/bootstrap/cache"

if [[ ! -f "${shared_dir}/.env" ]]; then
    echo "Missing shared environment file at ${shared_dir}/.env" >&2
    exit 1
fi

if ! chgrp "${APP_GROUP}" "${shared_dir}/.env"; then
    echo "Warning: unable to normalize shared environment file group ownership; continuing." >&2
fi
if ! chmod 640 "${shared_dir}/.env"; then
    echo "Warning: unable to normalize shared environment file permissions; continuing." >&2
fi

mkdir -p "${release_dir}"
tar -xzf "${RELEASE_ARCHIVE}" -C "${release_dir}"

rm -f "${release_dir}/public/hot"

if [[ ! -f "${release_dir}/public/build/manifest.json" ]]; then
    echo "Missing Vite manifest at ${release_dir}/public/build/manifest.json" >&2
    exit 1
fi

if [[ ! -f "${release_dir}/.release-sha" ]]; then
    echo "Missing release SHA marker at ${release_dir}/.release-sha" >&2
    exit 1
fi

if [[ "$(<"${release_dir}/.release-sha")" != "${RELEASE_SHA}" ]]; then
    echo "Release SHA mismatch for ${release_dir}" >&2
    exit 1
fi

if [[ ! -f "${release_dir}/.release-manifest" ]]; then
    echo "Missing release manifest at ${release_dir}/.release-manifest" >&2
    exit 1
fi

(
    cd "${release_dir}"
    sha256sum -c .release-manifest
)

rm -rf "${release_dir}/storage"
ln -sfn "${shared_dir}/storage" "${release_dir}/storage"

rm -rf "${release_dir}/bootstrap/cache"
ln -sfn "${shared_dir}/bootstrap/cache" "${release_dir}/bootstrap/cache"

ln -sfn "${shared_dir}/.env" "${release_dir}/.env"

shared_writable_paths=(
    "${shared_dir}/storage"
    "${shared_dir}/storage/app"
    "${shared_dir}/storage/app/private"
    "${shared_dir}/storage/app/public"
    "${shared_dir}/storage/framework"
    "${shared_dir}/storage/framework/cache"
    "${shared_dir}/storage/framework/cache/data"
    "${shared_dir}/storage/framework/sessions"
    "${shared_dir}/storage/framework/views"
    "${shared_dir}/storage/logs"
    "${shared_dir}/bootstrap"
    "${shared_dir}/bootstrap/cache"
)

shared_sensitive_paths=(
    "${shared_dir}/storage/app/private"
    "${shared_dir}/storage/framework"
    "${shared_dir}/storage/logs"
    "${shared_dir}/bootstrap/cache"
)

runtime_boundary_paths=(
    "${DEPLOY_PATH}"
    "${DEPLOY_PATH}/releases"
    "${shared_dir}"
    "${release_dir}"
)

if ! chgrp "${APP_GROUP}" "${shared_writable_paths[@]}"; then
    echo "Warning: unable to normalize shared writable group ownership; continuing." >&2
fi
if ! chmod ug+rwx "${shared_writable_paths[@]}"; then
    echo "Warning: unable to normalize shared writable permissions; continuing." >&2
fi
if ! chmod g+s "${shared_writable_paths[@]}"; then
    echo "Warning: unable to normalize shared writable setgid bits; continuing." >&2
fi

if ! chmod o-rwx "${shared_sensitive_paths[@]}"; then
    echo "Warning: unable to remove world access from shared sensitive paths; continuing." >&2
fi

if ! chgrp "${APP_GROUP}" "${runtime_boundary_paths[@]}"; then
    echo "Warning: unable to normalize runtime boundary group ownership; continuing." >&2
fi
if ! chmod 2750 "${runtime_boundary_paths[@]}"; then
    echo "Warning: unable to normalize runtime boundary permissions; continuing." >&2
fi

if ! chgrp -R "${APP_GROUP}" "${release_dir}"; then
    echo "Warning: unable to normalize release group ownership; continuing." >&2
fi
if ! chmod -R u+rwX,g+rX,o-rwx "${release_dir}"; then
    echo "Warning: unable to normalize release permissions; continuing." >&2
fi
chmod -R a+rX "${release_dir}/public/build"

if command -v setfacl >/dev/null 2>&1; then
    if ! setfacl -m "u:${WEB_USER}:rx" "${DEPLOY_PATH}" "${DEPLOY_PATH}/releases" "${shared_dir}" "${release_dir}"; then
        echo "Warning: unable to grant web user release traversal access; continuing." >&2
    fi

    if ! setfacl -R -m "u:${WEB_USER}:rX" "${release_dir}/public"; then
        echo "Warning: unable to grant web user public asset access; continuing." >&2
    fi

    if [[ -d "${shared_dir}/storage/app/public" ]]; then
        if ! setfacl -m "u:${WEB_USER}:rx" "${shared_dir}/storage" "${shared_dir}/storage/app" "${shared_dir}/storage/app/public"; then
            echo "Warning: unable to grant web user public storage traversal access; continuing." >&2
        fi

        if ! setfacl -R -m "u:${WEB_USER}:rX" "${shared_dir}/storage/app/public"; then
            echo "Warning: unable to grant web user public storage asset access; continuing." >&2
        fi
    fi
fi

cd "${release_dir}"

"${COMPOSER_BIN}" install --no-dev --prefer-dist --no-interaction --optimize-autoloader
"${PHP_BIN}" artisan optimize:clear --no-interaction
"${PHP_BIN}" artisan migrate --force --no-interaction
"${PHP_BIN}" artisan db:seed --class=EnvironmentalSourceSeeder --force --no-interaction
"${PHP_BIN}" artisan optimize --no-interaction
"${PHP_BIN}" artisan storage:link --force --no-interaction
"${PHP_BIN}" artisan fish:production-check --no-interaction

ln -sfn "${release_dir}" "${current_dir}"
release_activated=true

cd "${current_dir}"
"${PHP_BIN}" artisan queue:restart --no-interaction
"${PHP_BIN}" artisan schedule:interrupt --no-interaction || true
"${PHP_BIN}" artisan fish:collect-environmental-data today --no-interaction
"${PHP_BIN}" artisan fish:collect-environmental-data yesterday --finalize --no-interaction

releases=()

while IFS= read -r old_release; do
    releases+=("${old_release}")
done < <(find "${DEPLOY_PATH}/releases" -mindepth 1 -maxdepth 1 -type d | sort)

if (( ${#releases[@]} > KEEP_RELEASES )); then
    for old_release in "${releases[@]:0:${#releases[@]}-KEEP_RELEASES}"; do
        rm -rf "${old_release}"
    done
fi

rm -f "${RELEASE_ARCHIVE}"

trap - ERR

echo "Deployment completed: ${release_name}"
