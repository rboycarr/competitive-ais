#!/usr/bin/env bash
# Secret checks (P1-07; hardened after TASK-0003 #9, #10). Runnable locally:  bash scripts/ci/secrets.sh
#  1. Env example files: every secret-like key must be empty (placeholders only).
#  2. gitleaks over the full git history and over the working tree (findings redacted). .gitleaks.toml only
#     suppresses the empty-placeholder false positive; example files are otherwise scanned like any file.
set -euo pipefail
cd "$(git -C "$(dirname "$0")" rev-parse --show-toplevel)"

GITLEAKS=zricethezav/gitleaks:v8.30.1@sha256:c00b6bd0aeb3071cbcb79009cb16a60dd9e0a7c60e2be9ab65d25e6bc8abbb7f

echo "=== Env example files hold no secret values"
bad=$(grep -HnE '^[A-Z0-9_]*(PASSWORD|SECRET|TOKEN|_KEY|ACCESS_KEY_ID)=.+' .env*.example \
      | grep -vE '=(null|"\$\{[A-Z0-9_]+\}")$' || true)
if [ -n "$bad" ]; then
  echo "Secret-like keys must be empty in example files:" >&2
  cut -d= -f1 <<<"$bad" >&2
  exit 1
fi
# Whatever the key is called (TASK-0003 #9): a long token of letters AND digits with no spaces looks like a
# generated secret and is not allowed as an example value.
bad=$(grep -HnE '^[A-Z0-9_]+="?[A-Za-z0-9+/=_.-]{20,}"?$' .env*.example \
      | awk -F= '{ v=$0; sub(/^[^=]*=/, "", v); if (v ~ /[0-9]/ && v ~ /[A-Za-z]/) print }' || true)
if [ -n "$bad" ]; then
  echo "Example files must not contain secret-looking values:" >&2
  cut -d= -f1 <<<"$bad" >&2
  exit 1
fi

gitleaks() {
  docker run --rm -v "$PWD:/repo:ro" -e GIT_CONFIG_COUNT=1 -e GIT_CONFIG_KEY_0=safe.directory -e GIT_CONFIG_VALUE_0=/repo \
    "$GITLEAKS" "$@" --config /repo/.gitleaks.toml --redact --no-banner --exit-code 1
}

echo "=== gitleaks: full history"
gitleaks git /repo
echo "=== gitleaks: working tree (tracked and untracked, excluding ignored paths such as .env)"
# Scan only what git would track, so local secrets in gitignored .env files are not reported.
tmp=$(mktemp -d); trap 'rm -rf "$tmp"' EXIT
git ls-files -co --exclude-standard -z > "$tmp.list"   # fails (set -e) if git refuses the repository
[ -s "$tmp.list" ] || { echo "No files to scan; refusing to report a pass on an empty scan." >&2; exit 1; }
xargs -0 -I{} cp --parents {} "$tmp" < "$tmp.list"
rm -f "$tmp.list"
docker run --rm -v "$tmp:/scan:ro" -v "$PWD/.gitleaks.toml:/cfg/.gitleaks.toml:ro" \
  "$GITLEAKS" dir /scan --config /cfg/.gitleaks.toml --redact --no-banner --exit-code 1
echo "Secret checks passed."
