#!/usr/bin/env bash
# Create or update .env and .env.services from the examples (used by setup.sh and CI).
# Keeps existing values, appends keys that are new in the examples, and fills every empty secret
# with a random value generated locally. Secrets are never printed.
set -euo pipefail
cd "$(git -C "$(dirname "$0")" rev-parse --show-toplevel)"

# Append keys that exist in the example but not yet in the env file (new settings after a pull).
add_missing_keys() {
  local file=$1 line
  while IFS= read -r line; do
    [[ $line =~ ^([A-Z0-9_]+)= ]] || continue
    if ! grep -q "^${BASH_REMATCH[1]}=" "$file"; then
      printf '%s\n' "$line" >> "$file"
      echo "  ${file}: added ${BASH_REMATCH[1]}"
    fi
  done < "$file.example"
}

# Fill KEY= lines that are empty with a random hex value.
fill_secrets() {
  local file=$1; shift
  for key in "$@"; do
    if grep -q "^${key}=$" "$file"; then
      sed -i "s/^${key}=$/${key}=$(openssl rand -hex 24)/" "$file"
      echo "  ${file}: generated ${key}"
    fi
  done
}

for f in .env .env.services; do
  if [ ! -f "$f" ]; then cp "$f.example" "$f"; echo "Created $f from $f.example"; fi
  chmod 600 "$f"
  add_missing_keys "$f"
done
fill_secrets .env DB_PASSWORD DB_MIGRATOR_PASSWORD DB_RETENTION_PASSWORD REDIS_PASSWORD AWS_ACCESS_KEY_ID AWS_SECRET_ACCESS_KEY
fill_secrets .env.services MYSQL_ROOT_PASSWORD DB_READONLY_PASSWORD S3_ADMIN_ACCESS_KEY S3_ADMIN_SECRET_KEY S3_ANCHOR_ACCESS_KEY S3_ANCHOR_SECRET_KEY
