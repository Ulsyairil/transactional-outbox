#!/usr/bin/env bash
#
# PHPUnit matrix without GitHub Actions.
#
# Runs the suite on every supported PHP version using official Docker images.
# Each run is isolated: fresh container, composer install inside, no host state.
#
# Usage:
#   bash scripts/test-matrix.sh              # all versions
#   bash scripts/test-matrix.sh 8.1 8.3      # selected versions
#   PHP_VERSIONS="8.2" bash scripts/test-matrix.sh
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
VERSIONS=("${@:-}")
if [[ -z "${VERSIONS[0]:-}" ]]; then
    # shellcheck disable=SC2206
    VERSIONS=(${PHP_VERSIONS:-8.1 8.2 8.3 8.4})
fi

if ! command -v docker >/dev/null 2>&1; then
    echo "docker not found; running the suite on the host PHP only." >&2
    (cd "$ROOT" && composer install --no-interaction --prefer-dist && vendor/bin/phpunit)
    exit $?
fi

status=0

for version in "${VERSIONS[@]}"; do
    echo "=== PHP ${version} ==="
    if ! docker run --rm \
        -v "${ROOT}:/app" \
        -w /app \
        -e COMPOSER_ALLOW_SUPERUSER=1 \
        "php:${version}-cli" \
        sh -lc '
            set -e
            apt-get update -qq >/dev/null
            apt-get install -yqq git unzip libonig-dev libsqlite3-dev >/dev/null
            docker-php-ext-install pdo_sqlite >/dev/null
            curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer >/dev/null
            mkdir -p /tmp/build && cp -a /app/. /tmp/build/ && cd /tmp/build && rm -rf vendor .phpunit.cache
            composer install --no-interaction --prefer-dist --no-progress
            vendor/bin/phpunit
        '; then
        status=1
        echo "PHP ${version} FAILED" >&2
    fi
done

exit "$status"
