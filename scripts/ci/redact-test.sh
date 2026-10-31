#!/usr/bin/env bash
# Annotation redaction test (TASK-0005 #2): synthetic secrets in failing-step output must not reach an annotation.
# Fixture values are deliberately low-entropy so the secret scanner does not flag this file.
#   bash scripts/ci/redact-test.sh
set -euo pipefail
ROOT=$(git -C "$(dirname "$0")" rev-parse --show-toplevel)   # set -e stops here if git cannot read the repo
cd "$ROOT"
. scripts/ci/lib.sh

T=$(mktemp -d); trap 'rm -rf "$T"' EXIT
cat > "$T/a.env" <<'ENV'
APP_NAME=demo-app
DB_PASSWORD=pppppppp-1
AWS_SECRET_ACCESS_KEY="kkkkkkkk/k+2"
ENV

out=$(redact "$T/a.env" <<'LOG'
SQLSTATE[HY000] [1045] Access denied (using password pppppppp-1)
aws: signature for kkkkkkkk/k+2 rejected
connecting to mysql://cais_app:dddddddd-3@mysql:3306/cais
config dump: api_key=aaaaaaaa-4, token: tttttttt-5
APP_NAME demo-app kept
LOG
)

leaked=""
for s in pppppppp-1 'kkkkkkkk/k+2' dddddddd-3 aaaaaaaa-4 tttttttt-5; do
  grep -qF -- "$s" <<<"$out" && leaked+=" $s"
done
[ -z "$leaked" ] || { echo "Redaction missed:$leaked" >&2; printf '%s\n' "$out" >&2; exit 1; }
grep -qF 'demo-app kept' <<<"$out" || { echo "Redaction removed non-secret text" >&2; exit 1; }
echo "Redaction test passed."
