#!/usr/bin/env bash
# Scenario tests for scripts/dev/init-env.sh (TASK-0004 missing tests). No Docker needed.
#   bash scripts/ci/init-env-test.sh
set -euo pipefail
REPO="$(git -C "$(dirname "$0")" rev-parse --show-toplevel)"
fail=0
pass() { echo "ok   $1"; }
bad()  { echo "FAIL $1" >&2; fail=1; }
fresh() { local d; d=$(mktemp -d); cp "$REPO"/.env*.example "$d/"; echo "$d"; }
run() { INIT_ENV_ROOT="$1" bash "$REPO/scripts/dev/init-env.sh" >/dev/null 2>"$1/.err"; }
val() { grep -m1 "^$2=" "$1" | cut -d= -f2-; }

# 1. Fresh: all files created, secrets filled, modes 600/700.
d=$(fresh); run "$d"
for f in .env .secrets/migrator.env .secrets/services.env .secrets/s3.env; do
  [ -f "$d/$f" ] && [ "$(stat -c %a "$d/$f")" = 600 ] || bad "fresh: $f missing or not 600"
done
[ "$(stat -c %a "$d/.secrets")" = 700 ] || bad "fresh: .secrets not 700"
[ -n "$(val "$d/.secrets/migrator.env" DB_MIGRATOR_PASSWORD)" ] && [ -n "$(val "$d/.env" DB_PASSWORD)" ] \
  && pass "fresh install" || bad "fresh: secrets not filled"

# 2. Idempotent: a second run changes nothing.
before=$(cat "$d/.env" "$d"/.secrets/*.env | sha256sum); run "$d"
[ "$before" = "$(cat "$d/.env" "$d"/.secrets/*.env | sha256sum)" ] && pass "second run is a no-op" || bad "second run changed values"

# 3. Old layout (root files, migrator keys in .env) migrates and keeps the values.
d=$(fresh)
printf 'DB_MIGRATOR_USERNAME=cais_migrator\nDB_MIGRATOR_PASSWORD=oldmigratorvalue123456\n' > "$d/.env"
printf 'MYSQL_ROOT_PASSWORD=oldrootvalue12345678\nS3_ADMIN_ACCESS_KEY=oldadminkey1234567\n' > "$d/.env.services"
run "$d"
[ "$(val "$d/.secrets/migrator.env" DB_MIGRATOR_PASSWORD)" = oldmigratorvalue123456 ] \
  && [ "$(val "$d/.secrets/services.env" MYSQL_ROOT_PASSWORD)" = oldrootvalue12345678 ] \
  && [ "$(val "$d/.secrets/s3.env" S3_ADMIN_ACCESS_KEY)" = oldadminkey1234567 ] \
  && ! grep -q '^DB_MIGRATOR_' "$d/.env" && [ ! -e "$d/.env.services" ] \
  && pass "old layout migrated with values kept" || bad "old layout migration"

# 4. Conflict: different non-empty values in old and new locations -> refuse.
d=$(fresh); run "$d"
printf 'DB_MIGRATOR_PASSWORD=adifferentvalue123456\n' >> "$d/.env"
if run "$d"; then bad "conflict was not refused"; else grep -q 'different values' "$d/.err" && pass "conflict refused" || bad "conflict: wrong error"; fi

# 5. Empty old key with a filled new one -> old line dropped, new value kept.
d=$(fresh); run "$d"; keep=$(val "$d/.secrets/migrator.env" DB_MIGRATOR_PASSWORD)
printf 'DB_MIGRATOR_PASSWORD=\n' >> "$d/.env"; run "$d"
[ "$(val "$d/.secrets/migrator.env" DB_MIGRATOR_PASSWORD)" = "$keep" ] && ! grep -q '^DB_MIGRATOR_PASSWORD' "$d/.env" \
  && pass "empty old key dropped" || bad "empty old key handling"

[ $fail -eq 0 ] && echo "init-env tests passed." || exit 1
