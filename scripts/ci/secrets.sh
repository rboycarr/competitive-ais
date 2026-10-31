#!/usr/bin/env bash
# Secret checks (P1-07; hardened after TASK-0003 #9, #10 and TASK-0004 N3). Runnable locally:
#   bash scripts/ci/secrets.sh
#  1. Env example files are policy, not heuristics: only blank lines, comments and single-line KEY=VALUE are
#     allowed, and every non-empty value must be listed in scripts/ci/env-example-values.txt.
#  2. gitleaks over the full git history and over the working tree (findings redacted) as an extra detector.
#     .gitleaks.toml only suppresses the empty-placeholder false positive.
set -euo pipefail
ROOT=$(git -C "$(dirname "$0")" rev-parse --show-toplevel)   # set -e stops here if git cannot read the repo
cd "$ROOT"

GITLEAKS=zricethezav/gitleaks:v8.30.1@sha256:c00b6bd0aeb3071cbcb79009cb16a60dd9e0a7c60e2be9ab65d25e6bc8abbb7f
ALLOWED=scripts/ci/env-example-values.txt

echo "=== Env example files: only approved values"
problems=""
for f in .env*.example; do
  n=0
  while IFS= read -r line || [ -n "$line" ]; do
    n=$((n + 1))
    [[ -z $line || $line =~ ^# ]] && continue
    if [[ ! $line =~ ^[A-Z0-9_]+= ]]; then
      problems+="  $f:$n: not a single-line KEY=VALUE assignment"$'\n'; continue
    fi
    [[ $line =~ ^[A-Z0-9_]+=$ ]] && continue
    grep -qxF -- "$line" "$ALLOWED" || problems+="  $f:$n: ${line%%=*} has a value that is not in $ALLOWED"$'\n'
  done < "$f"
done
if [ -n "$problems" ]; then
  printf 'Example env files must hold empty placeholders or approved values:\n%s' "$problems" >&2
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
