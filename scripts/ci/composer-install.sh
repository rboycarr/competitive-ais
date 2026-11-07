#!/usr/bin/env bash
# Composer install for CI jobs. Every dist in composer.lock downloads through api.github.com, which allows 60 anonymous
# requests per hour per runner IP (shared between users); the workflow passes the job's read-only token as
# COMPOSER_AUTH to lift that. A failure is reported as an annotation with the redacted tail of Composer's output.
set -euo pipefail
ROOT=$(git -C "$(dirname "$0")" rev-parse --show-toplevel)
cd "$ROOT"
. scripts/ci/lib.sh

log=$(mktemp)
if ! docker compose run --rm --no-deps -T -e COMPOSER_AUTH php composer install --no-interaction --no-progress >"$log" 2>&1; then
  cat "$log"
  if [ -n "${GITHUB_ACTIONS:-}" ]; then
    echo "::error title=composer install::$(annotation_body "$log" .env .secrets/*.env)"
  fi
  exit 1
fi
tail -3 "$log"
