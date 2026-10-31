#!/usr/bin/env bash
# Backend checks (P1-07, docs/09). Used by .github/workflows/ci.yml and runnable locally:
#   bash scripts/ci/backend.sh
# Needs: built php image, `docker compose up -d --wait mysql`, composer install, APP_KEY set.
# The migration round-trip uses the disposable test database only, never the main one.
set -euo pipefail
cd "$(git -C "$(dirname "$0")" rev-parse --show-toplevel)"

php() { docker compose run --rm --no-deps -T php "$@"; }
step() { echo; echo "=== $*"; }

step "Pint (format)";               php vendor/bin/pint --test
step "Larastan (level 8)";          php vendor/bin/phpstan analyse --no-progress --memory-limit=1G
step "Pest (MySQL 8.4, real accounts)"; php php artisan test
step "Migrations: migrate -> rollback all -> migrate (test database)"
test_db=$(grep -m1 '^DB_TEST_DATABASE=' .env | cut -d= -f2-); test_db=${test_db:-cais_test}
[[ $test_db == *_test ]] || { echo "DB_TEST_DATABASE must end in _test" >&2; exit 1; }
docker compose run --rm --no-deps -T -e DB_DATABASE="$test_db" php sh -c \
  'php artisan migrate --no-interaction && php artisan migrate:reset --no-interaction && php artisan migrate --no-interaction && php artisan migrate:status'
step "Composer audit";              php composer audit --no-interaction
echo; echo "Backend checks passed."
