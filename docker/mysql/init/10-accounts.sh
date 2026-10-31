#!/bin/bash
# Least-privilege MySQL accounts (P1-09, docs/02 §6). Idempotent.
# Runs automatically when the data volume is first initialised (/docker-entrypoint-initdb.d) and again from
# scripts/dev/setup.sh:  docker compose exec -T mysql bash /docker-entrypoint-initdb.d/10-accounts.sh
#
# Schema- and global-level privileges are set here. Table-level privileges for the app and retention accounts
# are owned by `php artisan db:apply-grants`, which runs after every migration; this script never touches them.
set -euo pipefail

: "${MYSQL_ROOT_PASSWORD:?}" "${CAIS_DATABASES:?}"
: "${CAIS_MIGRATOR_USER:?}" "${CAIS_MIGRATOR_PASSWORD:?}"
: "${CAIS_APP_USER:?}" "${CAIS_APP_PASSWORD:?}"
: "${CAIS_RETENTION_USER:?}" "${CAIS_RETENTION_PASSWORD:?}"
: "${CAIS_READONLY_USER:?}" "${DB_READONLY_PASSWORD:?}"

# Values are inlined into SQL, so only plain identifiers and alphanumeric secrets are accepted.
for v in CAIS_MIGRATOR_USER CAIS_APP_USER CAIS_RETENTION_USER CAIS_READONLY_USER; do
  [[ ${!v} =~ ^[a-z_][a-z0-9_]{0,31}$ ]] || { echo "10-accounts.sh: $v is not a plain identifier" >&2; exit 1; }
done
for db in $CAIS_DATABASES; do
  [[ $db =~ ^[a-z_][a-z0-9_]{0,63}$ ]] || { echo "10-accounts.sh: database name '$db' is not a plain identifier" >&2; exit 1; }
done
for v in CAIS_MIGRATOR_PASSWORD CAIS_APP_PASSWORD CAIS_RETENTION_PASSWORD DB_READONLY_PASSWORD; do
  [[ ${!v} =~ ^[A-Za-z0-9]{16,}$ ]] || { echo "10-accounts.sh: $v must be at least 16 alphanumeric characters" >&2; exit 1; }
done

# The first database is the main one; any others (e.g. cais_test) are for automated tests only.
read -r MAIN_DB _ <<<"$CAIS_DATABASES"

account() { # user password
  cat <<SQL
CREATE USER IF NOT EXISTS '$1'@'%' IDENTIFIED BY '$2';
ALTER USER '$1'@'%' IDENTIFIED BY '$2';
REVOKE IF EXISTS ALL PRIVILEGES ON *.* FROM '$1'@'%';
REVOKE IF EXISTS GRANT OPTION ON *.* FROM '$1'@'%';
SQL
  for db in $CAIS_DATABASES; do
    echo "REVOKE IF EXISTS ALL PRIVILEGES ON \`$db\`.* FROM '$1'@'%';"
    echo "REVOKE IF EXISTS GRANT OPTION ON \`$db\`.* FROM '$1'@'%';"
  done
}

{
  for db in $CAIS_DATABASES; do
    echo "CREATE DATABASE IF NOT EXISTS \`$db\` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;"
  done

  account "$CAIS_MIGRATOR_USER" "$CAIS_MIGRATOR_PASSWORD"
  account "$CAIS_APP_USER" "$CAIS_APP_PASSWORD"
  account "$CAIS_RETENTION_USER" "$CAIS_RETENTION_PASSWORD"
  account "$CAIS_READONLY_USER" "$DB_READONLY_PASSWORD"

  # Migrator: schema changes and data for migrations. GRANT OPTION lets db:apply-grants manage the
  # app/retention table grants without root.
  for db in $CAIS_DATABASES; do
    echo "GRANT ALTER, CREATE, CREATE TEMPORARY TABLES, CREATE VIEW, DELETE, DROP, INDEX, INSERT, LOCK TABLES, REFERENCES, SELECT, SHOW VIEW, UPDATE ON \`$db\`.* TO '$CAIS_MIGRATOR_USER'@'%' WITH GRANT OPTION;"
  done

  # Read-only (backups, reporting): main database only.
  echo "GRANT SELECT, SHOW VIEW ON \`$MAIN_DB\`.* TO '$CAIS_READONLY_USER'@'%';"

  # App and retention: no schema-level privileges at all (table grants come from db:apply-grants).
} | MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql --protocol=socket -uroot

echo "10-accounts.sh: accounts and schema privileges applied for: $CAIS_DATABASES"
