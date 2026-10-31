#!/usr/bin/env bash
# Account-hardening test (TASK-0004 N1). Seeds privileges the managed accounts must never keep, reruns the
# account init script, and fails if any survive. Cleans up its probe database and role. Runnable locally:
#   bash scripts/ci/accounts-test.sh        (needs `docker compose up -d --wait mysql`)
set -euo pipefail
ROOT=$(git -C "$(dirname "$0")" rev-parse --show-toplevel)   # set -e stops here if git cannot read the repo
cd "$ROOT"

val() { grep -m1 "^$2=" "$1" | cut -d= -f2-; }
APP=$(val .env DB_USERNAME); RET=$(val .env DB_RETENTION_USERNAME); MIG=$(val .secrets/migrator.env DB_MIGRATOR_USERNAME); RO=cais_readonly
root() { docker compose exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot -N -B'; }

cleanup() { printf 'DROP DATABASE IF EXISTS zz_probe_other;\nDROP ROLE IF EXISTS zz_probe_role;\n' | root >/dev/null 2>&1 || true; }
trap cleanup EXIT

echo "=== Seeding unexpected privileges"
root <<SQL
CREATE DATABASE IF NOT EXISTS zz_probe_other;
CREATE TABLE IF NOT EXISTS zz_probe_other.t (id INT PRIMARY KEY, c INT);
CREATE ROLE IF NOT EXISTS zz_probe_role;
GRANT SELECT ON zz_probe_other.* TO zz_probe_role;
GRANT zz_probe_role TO '$APP'@'%';
GRANT CREATE, EVENT, TRIGGER ON zz_probe_other.* TO '$APP'@'%';
GRANT SELECT (c) ON zz_probe_other.t TO '$APP'@'%';
GRANT PROCESS ON *.* TO '$RET'@'%';
GRANT XA_RECOVER_ADMIN ON *.* TO '$RO'@'%';
GRANT SELECT ON zz_probe_other.t TO '$MIG'@'%';
GRANT PROXY ON '$RO'@'%' TO '$RET'@'%';
SQL

echo "=== Rerunning the account init script"
docker compose exec -T mysql bash /docker-entrypoint-initdb.d/10-accounts.sh

echo "=== Checking that nothing seeded survived"
left=$(for u in "$APP" "$RET" "$RO" "$MIG"; do echo "SHOW GRANTS FOR '$u'@'%';"; done | root \
       | grep -E 'zz_probe|PROCESS|XA_RECOVER_ADMIN|PROXY|EVENT|TRIGGER' || true)
if [ -n "$left" ]; then
  echo "Seeded privileges survived:" >&2; printf '  %s\n' "$left" >&2; exit 1
fi
echo "Account hardening test passed."
