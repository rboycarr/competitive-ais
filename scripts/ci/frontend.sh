#!/usr/bin/env bash
# Frontend checks (P1-07, docs/09). Used by .github/workflows/ci.yml and runnable locally:
#   bash scripts/ci/frontend.sh
# Needs: built node image and `npm ci`. The production build goes to a temporary directory, not public/build.
set -euo pipefail
ROOT=$(git -C "$(dirname "$0")" rev-parse --show-toplevel)   # set -e stops here if git cannot read the repo
cd "$ROOT"

. scripts/ci/lib.sh
mask_secrets .env .secrets/*.env

node() { docker compose run --rm --no-deps -T node "$@"; }
CURRENT="setup"
step() { CURRENT="$*"; echo; echo "=== $*"; }
trap 'rc=$?; if [ $rc -ne 0 ] && [ -n "${GITHUB_ACTIONS:-}" ]; then echo "::error title=frontend::failed at step: $CURRENT (exit $rc)"; fi' EXIT

step "Vitest";                  node npm test
step "Production build";        node npx vite build --outDir /tmp/ci-build --emptyOutDir
step "npm audit";               node npm audit
echo; echo "Frontend checks passed."
