#!/usr/bin/env bash
# First-time (and repeatable) local setup for the Docker stack (P1-02, docs/10 §1).
# Run inside WSL from anywhere in the repo:  bash scripts/dev/setup.sh
# Idempotent: existing .env values, volumes, and data are kept. Secrets are generated locally and never printed.
set -euo pipefail
cd "$(git -C "$(dirname "$0")" rev-parse --show-toplevel)"

command -v docker >/dev/null || { echo "Docker is not available in this shell (docs/10 §1.1)." >&2; exit 1; }

bash scripts/dev/init-env.sh

echo "Building images"
DEV_UID=$(id -u) DEV_GID=$(id -g) docker compose build

echo "Installing PHP and Node dependencies (lock files)"
docker compose run --rm --no-deps php composer install --no-interaction
docker compose run --rm --no-deps node npm ci

if grep -q '^APP_KEY=$' .env; then
  docker compose run --rm --no-deps php php artisan key:generate --no-interaction
fi

echo "Starting the stack (the first ClamAV start downloads signatures and can take several minutes)"
docker compose up -d --wait --wait-timeout 1200

# Accounts and schema privileges (also runs on first volume init; repeated here for existing volumes),
# then migrations in the migrator service, which re-apply table grants. The mysql container is recreated by
# `up` when its env files change. Workers restart to pick up credentials.
docker compose exec -T mysql bash /docker-entrypoint-initdb.d/10-accounts.sh
docker compose run --rm --no-deps migrator php artisan migrate --no-interaction
docker compose restart php horizon scheduler >/dev/null

cat <<'EOF'

Ready:
  App        http://localhost:8080
  Vite       http://localhost:5173
  Mailpit    http://localhost:8025
  Horizon    http://localhost:8080/horizon (local only)
Check the object-store key policy:  bash scripts/dev/verify-object-store.sh
Migrations and tests (migrator account):  docker compose run --rm migrator php artisan migrate | test
Daily start:  docker compose up -d --wait     Stop:  docker compose stop
EOF
