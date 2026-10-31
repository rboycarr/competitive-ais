# 10 — Deployment and Operations

| Field | Value |
|---|---|
| Status | Approved baseline (Phase 0), 2026-10-31 |
| Owner | Claude Code (configuration and runbooks); Platform Admin (operation) |
| Related | `skills/09_TESTING_DEVOPS_SKILLS.md`, ADR-0003 |

## 1. Local development environment (Windows 11 + WSL2 + Docker Engine)

Development PC: Windows 11 (25H2), set up 2026-10-31 (`session-history/103126.md`).

### 1.1 One-time setup (Docker Engine inside WSL2; decided 2026-10-31, ADR-0003)
We use the free, open-source **Docker Engine inside an Ubuntu 24.04 WSL2 distro**, not Docker Desktop. Docker Desktop requires a paid subscription above its small-business threshold. Docker Engine is also what the Linux server runs.

**Stage 1 — WSL (admin rights + reboot):** in an **elevated** PowerShell, run `wsl --install --no-distribution`, then **reboot**. This enables the `VirtualMachinePlatform` feature and installs the current WSL package. The older `Microsoft-Windows-Subsystem-Linux` feature can stay off; only WSL1 needs it.

**Stage 2 — Ubuntu (normal user):**
1. `wsl --install -d Ubuntu-24.04 --no-launch` (or leave out `--no-launch` and create the Linux user when prompted).
2. Confirm: `wsl -l -v` shows Ubuntu-24.04 with VERSION 2, and `wsl --version` shows a current kernel.

**Stage 3 — Docker Engine inside Ubuntu** (official Docker apt repository; Claude can run this via `wsl -d Ubuntu-24.04 -u root`):
1. Check systemd: `/etc/wsl.conf` has `[boot]` `systemd=true` (the catalog image ships it). If you add it, run `wsl --shutdown`.
2. Install `docker-ce`, `docker-ce-cli`, `containerd.io`, `docker-buildx-plugin`, `docker-compose-plugin` from `download.docker.com`, following Docker's documented Ubuntu procedure. Before installing the key in `/etc/apt/keyrings/docker.asc`, check that its fingerprint is `9DC8 5822 9FC7 DD38 854A E2D8 8D81 803C 0EBF CD88`.
3. `systemctl enable --now docker`.
4. Developer user: if Stage 2 did not create one, run `useradd -m -s /bin/bash -G sudo,docker <user>` and add `[user] default=<user>` to `/etc/wsl.conf`; the developer sets the password with `wsl -d Ubuntu-24.04 -u root passwd <user>`. Otherwise run `usermod -aG docker <user>`.
5. Confirm as that user: `docker version`, `docker compose version`, `docker run --rm hello-world`. After `wsl --terminate Ubuntu-24.04`, Docker must come back on its own (`systemctl is-active docker`).

**Resource limits** so XAMPP and other work keep running. `%USERPROFILE%\.wslconfig`:
```ini
[wsl2]
memory=8GB
processors=6
```
Containers publish ports to WSL. With WSL's default localhost forwarding they are reachable from Windows at `localhost:<port>`.

**Working copy:** run `docker compose` from inside WSL. The repo can stay on the Windows drive at first (`/mnt/<drive>/<path>/competitive-ais`); move it into the Linux filesystem if bind-mount performance is poor (ADR-0003).

Optional: set `git config --global core.autocrlf input` so shell scripts keep LF endings. If VMware Workstation is installed, versions 15.5 and later coexist with the Windows hypervisor that WSL2 enables.

An existing XAMPP install (Apache, MariaDB) keeps running unchanged. Competitive-AIS uses its own ports, all bound to `127.0.0.1` (not reachable from other machines):

| Service | Host port | Notes |
|---|---|---|
| Nginx (app) | 8080 | http://localhost:8080 (`/up` = liveness) |
| Vite dev server | 5173 | assets and hot reload during development |
| MySQL 8.4 | 3307 | XAMPP MariaDB stays on 3306 |
| Redis (Valkey) | 6380 | password required |
| SeaweedFS S3 API | 9000 | container port 8333; no console (O17) |
| Mailpit UI | 8025 | |
| Reverb | 8081 | when enabled |
| Meilisearch | 7700 | Phase 2 |
| ClamAV | not published | internal network only |

### 1.2 Start the stack (P1-02)
Inside WSL, from the repository root:

| When | Command | What it does |
|---|---|---|
| First time (repeatable) | `bash scripts/dev/setup.sh` | Creates `.env` and `.env.services` from the examples and fills every empty secret with a random value (never printed); builds images; `composer install`; `npm ci`; `key:generate` if needed; starts everything and waits for healthchecks; runs migrations. Keeps existing values and data |
| Daily | `docker compose up -d --wait` | Starts the stack and returns once every service is healthy (about 40 s) |
| Stop | `docker compose stop` | Keeps containers and data |
| Object-store key check | `bash scripts/dev/verify-object-store.sh` | 19 checks of the per-purpose keys (ADR-0007); cleans up its test objects |
| Tests | `docker compose exec php php artisan test` | |

The first start downloads the ClamAV signature database and can take several minutes. Only `docker compose down -v` deletes data volumes; do not run it without meaning to.

**Environment files** (both gitignored, mode 600): `.env` is read by Laravel and by Compose for interpolation; `.env.services` holds secrets only services need (MySQL root password, SeaweedFS admin and audit-anchor keys) and is never loaded by Laravel. In local development the repository is bind-mounted into the PHP container, so the app process could still read `.env.services` from disk; production images are not bind-mounted and receive only their own secrets (§3).

### 1.3 Compose services (Phase 1)
`nginx` (serves only `public/`, read-only), `php` (PHP-FPM, pinned version per ADR-0003, runs as the developer's uid), `horizon`, `scheduler`, `node` (Vite dev server, polling file watcher), `mysql` (8.4; utf8mb4 / `utf8mb4_0900_ai_ci`; UTC), `valkey`, `seaweedfs` (`-master.telemetry=false`; identities and per-bucket permissions rendered at start from `docker/seaweedfs/s3-identities.template.json`) + `seaweedfs-init` (one-shot; creates the `evidence-quarantine`, `evidence`, `exports`, and `audit-anchor` buckets, object lock on `evidence` and `audit-anchor`; ADR-0007), `clamav`, `mailpit`. Healthchecks on every service. Named volumes for data, `vendor`, and `node_modules`. Networks `edge`, `app`, `data` as in `docs/07`; `data` is not internal locally because ClamAV needs egress for signature updates. MySQL uses one interim account (`cais_migrator`) until P1-09 creates the least-privilege accounts.

**Performance note:** bind mounts from the Windows drive into WSL2 can be slow for PHP. Measured in Sprint 1.0 (ADR-0003): median 0.15 s per page with `vendor` and `node_modules` in named volumes, which is acceptable. If it becomes slow, move the working copy into the WSL filesystem (`\\wsl$\Ubuntu-24.04\home\<user>\competitive-ais`) and open it with VS Code Remote-WSL.

## 2. Environments and configuration

| Variable group | Source | Notes |
|---|---|---|
| App (`APP_KEY`, `APP_URL`, `APP_TIMEZONE=UTC`) | `.env` (local), secret store (prod) | `.env` is gitignored; `.env.example` holds placeholders only |
| DB (`DB_*` for the app user; `DB_MIGRATOR_*`) | same | separate users (`docs/02` §6) |
| Object store (`AWS_*` = app key, bucket names) | same | separate keys per purpose; admin and audit-anchor keys in `.env.services` locally |
| ClamAV, Redis, Mail | same | |
| Feature flags | DB (Pennant) | collection and AI default off |

## 3. Production target (proposal; O3 open)
Ubuntu Server LTS VM(s) with Docker Compose. Nginx terminates TLS (certificates via ACME or the organization's CA). MySQL, Redis, MinIO, and ClamAV run on a private network. Backups go to encrypted offsite storage. Sizing is decided after O3/O5; Phase 4 local AI needs GPU sizing (O4).

**Deploy procedure (outline):** build and tag images in CI → push to registry → on host: `docker compose pull` → `php artisan down --render=maintenance` (only if the migration is not zero-downtime) → backup → `php artisan migrate --force` (as migrator) → `docker compose up -d` → `php artisan horizon:terminate` → smoke tests → `php artisan up`.
**Rollback:** redeploy the previous image tag. Migrations follow expand/contract, so the previous release runs against the new schema. If a migration must be reverted: restore from the pre-deploy backup following the runbook (only with explicit authorization).

## 4. Backups and recovery (RPO 24 h / RTO 8 h initial)

| Data | Method | Frequency | Retention |
|---|---|---|---|
| MySQL | `mysqldump --single-transaction` (Phase 1); binary-log shipping for point-in-time recovery when RPO tightens | daily + binlogs | 30 daily, 12 monthly (proposal) |
| Object store evidence | versioned copy to offsite S3-compatible storage (method fixed in P1-51), encrypted | daily | per retention policy |
| Config/secrets | secret store backup | on change | |

Restore drills run monthly and before each release: restore into a scratch environment → run migrations status + smoke tests + evidence hash sample check → record results in the ops log.

## 5. Monitoring and alerting

| Signal | Threshold (initial) |
|---|---|
| HTTP 5xx rate | > 1% over 5 min |
| p95 latency (ordinary routes) | > 500 ms over 15 min |
| Queue wait (Horizon) | > 5 min on `default`/`evidence` |
| Failed jobs | any new failure in `evidence` queue; > 10/h elsewhere |
| Audit checkpoint verification | any mismatch → critical |
| Evidence integrity sample | any hash mismatch → critical |
| Backup job | missing or failed → critical |
| Disk (MySQL, MinIO volumes) | > 80% |
| ClamAV signatures age | > 48 h |
| [P3] Source freshness / block rate | per source policy |
| [P4] AI budget usage | > 80% of monthly budget; fail closed at 100% |

Phase 1 uses Laravel logs (JSON), Horizon dashboard (admin-only), `/health`, and scheduled checks that notify admins by email. A metrics stack (e.g., Prometheus/Grafana) is added only when justified (ADR required).

## 6. Runbooks (written in Sprint 1.0; expanded per phase)
Outage triage · Compromised token or account (revoke tokens, force logout, reset MFA, review audit) · Failed evidence scans · Audit checkpoint mismatch and `late_commit` investigation · Audit signing-key rotation · Backup failure and restore (including MinIO object versions, object-lock metadata, DB grants, and an evidence hash sample) · Reindex search [P2] · Broken collector / blocked source [P3] · Model rollback [P4] · Secret rotation.

## Acceptance criteria
- `docker compose up -d` brings all Phase 1 services to healthy on the developer machine.
- A restore drill succeeds before the Phase 1 exit demo.
