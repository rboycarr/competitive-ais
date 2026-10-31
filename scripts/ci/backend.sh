#!/usr/bin/env bash
# Backend checks (P1-07, docs/09). Used by .github/workflows/ci.yml and runnable locally:
#   bash scripts/ci/backend.sh
# Needs: built php image, `docker compose up -d --wait mysql`, composer install, APP_KEY set.
# Tests and migrations need the migrator account, so they run in the `migrator` service (TASK-0003 #1).
# The migration round-trip uses the disposable test database only, never the main one.
set -euo pipefail
ROOT=$(git -C "$(dirname "$0")" rev-parse --show-toplevel)   # set -e stops here if git cannot read the repo
cd "$ROOT"

php() { docker compose run --rm --no-deps -T php "$@"; }
migrator() { docker compose run --rm --no-deps -T "$@"; }
CURRENT="setup"
step() { CURRENT="$*"; echo; echo "=== $*"; }
# In GitHub Actions, name the failing step in a public annotation (job logs need a login to read).
trap 'rc=$?; if [ $rc -ne 0 ] && [ -n "${GITHUB_ACTIONS:-}" ]; then echo "::error title=backend::failed at step: $CURRENT (exit $rc)"; fi' EXIT

step "Pint (format)";               php vendor/bin/pint --test
step "Larastan (level 8)";          php vendor/bin/phpstan analyse --no-progress --memory-limit=1G
step "Pest (MySQL 8.4, real accounts)"; migrator migrator php artisan test

step "Runtime containers hold no service secrets (TASK-0003 #1, TASK-0004 N2)"
if out=$(php php artisan migrate:status 2>&1); then
  echo "migrate:status succeeded in the php service; it must not have the migrator account" >&2; exit 1
fi
grep -q 'only available in the migrator service' <<<"$out" || { echo "Unexpected failure: $out" >&2; exit 1; }
CURRENT="runtime secrets: files and variables in the php service"
leaks=$(php sh -c 'ls -A /var/www/html/.secrets; env | cut -d= -f1 | grep -E "^(DB_MIGRATOR_|S3_ADMIN_|S3_ANCHOR_|MYSQL_ROOT|DB_READONLY)" || true')
[ -z "$leaks" ] || { echo "Secrets visible in the php service: $leaks" >&2; exit 1; }
# Every service that bind-mounts the repository must hide .secrets with a tmpfs.
CURRENT="runtime secrets: compose config (every repo mount hides .secrets)"
unhidden=$(docker compose --profile tools config --format json | jq -r --arg root "$PWD" '
  .services | to_entries[] | . as $s
  | ($s.value.volumes // [])[] | select(.type == "bind" and .source == $root) | .target as $t
  | select(([$s.value.volumes[] | select(.type == "tmpfs" and .target == ($t + "/.secrets"))] | length) == 0)
  | $s.key')
[ -z "$unhidden" ] || { echo "Services bind-mounting the repo without hiding .secrets: $unhidden" >&2; exit 1; }
echo "ok: schema commands refused; no secret files or variables in runtime containers; every repo mount hides .secrets"

step "init-env scenarios";          bash scripts/ci/init-env-test.sh
step "Account hardening (seeded privileges are removed)"; bash scripts/ci/accounts-test.sh

step "Migrations: migrate -> rollback all -> migrate (test database)"
test_db=$(grep -m1 '^DB_TEST_DATABASE=' .env | cut -d= -f2-); test_db=${test_db:-cais_test}
[[ $test_db == *_test ]] || { echo "DB_TEST_DATABASE must end in _test" >&2; exit 1; }
migrator -e DB_DATABASE="$test_db" migrator sh -c \
  'php artisan migrate --no-interaction && php artisan migrate:reset --no-interaction && php artisan migrate --no-interaction && php artisan migrate:status'
step "Composer audit";              php composer audit --no-interaction
echo; echo "Backend checks passed."
