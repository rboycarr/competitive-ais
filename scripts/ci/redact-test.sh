#!/usr/bin/env bash
# Annotation redaction test (TASK-0005 #2, TASK-0006 #2, #5): synthetic secrets in a failing step's output must not
# reach an annotation. Runs the same annotation_body function as the backend failure trap.
# Fixture values are deliberately low-entropy so the secret scanner does not flag this file.
#   bash scripts/ci/redact-test.sh
set -euo pipefail
ROOT=$(git -C "$(dirname "$0")" rev-parse --show-toplevel)   # set -e stops here if git cannot read the repo
cd "$ROOT"
. scripts/ci/lib.sh

T=$(mktemp -d); trap 'rm -rf "$T"' EXIT
mkdir "$T/.secrets"
cat > "$T/a.env" <<'ENV'
APP_NAME=demo-app
DB_PASSWORD=pppppppp-1
AWS_SECRET_ACCESS_KEY="kkkkkkkk/k+2"
DB_PASS=qqqqqqqq-6
mail_auth_value=mmmmmmmm-7
ENV
printf 'SOME_ODD_NAME=ssssssss-8\n' > "$T/.secrets/x.env"

cat > "$T/step.log" <<'LOG'
SQLSTATE[HY000] [1045] Access denied (using password pppppppp-1)
aws: signature for kkkkkkkk/k+2 rejected
connecting to mysql://cais_app:dddddddd-3@mysql:3306/cais
config dump: api_key=aaaaaaaa-4, token: tttttttt-5
legacy qqqqqqqq-6 and mmmmmmmm-7 and ssssssss-8 printed
100% done
APP_NAME demo-app kept
LOG

out=$(annotation_body "$T/step.log" "$T/a.env" "$T/.secrets/x.env")

leaked=""
for s in pppppppp-1 'kkkkkkkk/k+2' dddddddd-3 aaaaaaaa-4 tttttttt-5 qqqqqqqq-6 mmmmmmmm-7 ssssssss-8; do
  grep -qF -- "$s" <<<"$out" && leaked+=" $s"
done
[ -z "$leaked" ] || { echo "Redaction missed:$leaked" >&2; printf '%s\n' "$out" >&2; exit 1; }
grep -qF 'demo-app kept' <<<"$out" || { echo "Redaction removed non-secret text" >&2; exit 1; }
grep -qF '100%25 done' <<<"$out" || { echo "Annotation is not escaped" >&2; exit 1; }
[ "$(wc -l <<<"$out")" -eq 1 ] || { echo "Annotation spans several lines" >&2; exit 1; }

# A log without error lines still produces its tail, also under `set -euo pipefail` (TASK-0007 #3).
printf 'step output line one\nlast line pppppppp-1\n' > "$T/quiet.log"
out=$(annotation_body "$T/quiet.log" "$T/a.env")
grep -qF 'step output line one%0Alast line ***' <<<"$out" || { echo "No-error-line log not annotated: $out" >&2; exit 1; }
echo "Redaction test passed."
