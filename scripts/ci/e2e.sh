#!/usr/bin/env bash
# End-to-end exit journey (P1-47, docs/09 item 9): seed synthetic E2E data, build the frontend, start the app behind
# nginx, and run Playwright (J1 decision steps, J6, axe on the core pages). Used by CI; runnable locally:
#   bash scripts/ci/e2e.sh
# Needs: migrated main database, composer and npm dependencies, APP_KEY. Adds four e2e.* users and synthetic records
# to the main database (skipped if already there). The Vite dev server must be stopped: its public/hot file would
# point the browser at a server the e2e container cannot reach.
set -euo pipefail
ROOT=$(git -C "$(dirname "$0")" rev-parse --show-toplevel)
cd "$ROOT"
. scripts/ci/lib.sh
mask_secrets .env .secrets/*.env

[ ! -f public/hot ] || { echo "public/hot exists (Vite dev server running): docker compose stop node, then run this again." >&2; exit 1; }

# Throwaway credentials for this run only, never stored; a re-run against an already seeded database needs the same
# values, so they can be passed in.
export E2E_PASSWORD=${E2E_PASSWORD:-$(openssl rand -hex 16)}
export E2E_TOTP_SECRET=${E2E_TOTP_SECRET:-$(head -c 20 /dev/urandom | base32 | tr -d '=')}
if [ -n "${GITHUB_ACTIONS:-}" ]; then echo "::add-mask::$E2E_PASSWORD"; echo "::add-mask::$E2E_TOTP_SECRET"; fi

echo "=== Seed synthetic E2E data"
docker compose run --rm -T -e E2E_PASSWORD -e E2E_TOTP_SECRET migrator php artisan db:seed --class=E2eSeeder --force --no-interaction
echo "=== Build the frontend"
docker compose run --rm --no-deps -T node npm run build
echo "=== Start the app"
docker compose up -d --wait nginx
mkdir -p e2e/.auth
echo "=== Playwright"
docker compose run --rm -T -e E2E_PASSWORD -e E2E_TOTP_SECRET e2e
