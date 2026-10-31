#!/usr/bin/env bash
# Secret checks (P1-07). Runnable locally:  bash scripts/ci/secrets.sh
#  1. Env example files: every secret-like key must be empty (placeholders only).
#  2. gitleaks over the full git history (findings redacted). .gitleaks.toml exempts only the example files,
#     which check 1 covers.
set -euo pipefail
cd "$(git -C "$(dirname "$0")" rev-parse --show-toplevel)"

echo "=== Env example files hold no secret values"
bad=$(grep -HnE '^[A-Z0-9_]*(PASSWORD|SECRET|TOKEN|_KEY|ACCESS_KEY_ID)=.+' .env.example .env.services.example \
      | grep -vE '=(null|"\$\{[A-Z0-9_]+\}")$' || true)
if [ -n "$bad" ]; then
  echo "Secret-like keys must be empty in example files:" >&2
  cut -d= -f1 <<<"$bad" >&2
  exit 1
fi

echo "=== gitleaks (full history)"
docker run --rm -v "$PWD:/repo:ro" -e GIT_CONFIG_COUNT=1 -e GIT_CONFIG_KEY_0=safe.directory -e GIT_CONFIG_VALUE_0=/repo \
  zricethezav/gitleaks:v8.30.1 git /repo --config /repo/.gitleaks.toml --redact --no-banner --exit-code 1
echo "Secret checks passed."
