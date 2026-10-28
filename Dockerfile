FROM dunglas/frankenphp:1-php8.4-alpine AS php-base

WORKDIR /app

RUN apk add --no-cache \
    bash \
    icu-dev \
    libpq-dev \
    postgresql-client \
    tzdata \
    && install-php-extensions \
    bcmath \
    intl \
    opcache \
    pcntl \
    pdo_pgsql \
    redis \
    zip

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

FROM php-base AS dependencies

COPY . .
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts

# The 26.13.0 Docker tags are not published yet. Use the published official
# Node image and replace Node with its checksum-verified official distribution.
FROM node:26.12.1-bookworm-slim@sha256:88e480776c6149ff1d098cd304f44bf18057fb5d284a3329b78162d29821e28c AS assets

WORKDIR /app

RUN apt-get update && apt-get install -y --no-install-recommends ca-certificates curl xz-utils \
    && rm -rf /var/lib/apt/lists/*
COPY .nvmrc ./
RUN set -eu; \
    case "$(dpkg --print-architecture)" in \
        amd64) arch=x64; checksum=d28a96af6c38596f6d9d97c0ee531c13d238d757742387d1928de822d959e997 ;; \
        arm64) arch=arm64; checksum=406fe3fbde1c054adff8b297c16fdb5af6659db327c1ac8acd46b56ef1af9a92 ;; \
        *) exit 1 ;; \
    esac; \
    archive="node-v$(cat .nvmrc)-linux-${arch}.tar.xz"; \
    curl --fail --silent --show-error --location "https://nodejs.org/dist/v$(cat .nvmrc)/${archive}" --output "/tmp/${archive}"; \
    printf '%s  %s\n' "$checksum" "/tmp/${archive}" | sha256sum --check -; \
    tar -xJf "/tmp/${archive}" -C /usr/local --strip-components=1 --no-same-owner; \
    rm "/tmp/${archive}"

COPY package.json package-lock.json .npmrc ./
COPY .github/scripts/setup-node.sh /tmp/setup-node.sh
RUN bash /tmp/setup-node.sh && npm ci

COPY vite.config.js tailwind.config.js postcss.config.js ./
COPY resources resources
COPY public public
COPY --from=dependencies /app/vendor vendor
RUN npm run build && test -f public/build/manifest.json

FROM php-base AS app

COPY --from=dependencies /app /app
COPY --from=assets /app/public/build /app/public/build
RUN composer dump-autoload --optimize \
    && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 8000

CMD ["frankenphp", "php-server", "-r", "public", "--listen", ":8000"]
