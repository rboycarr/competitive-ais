# ADR-0003 — Runtime: Docker Compose with MySQL 8.4 LTS; XAMPP not used

- **Status:** Accepted, 2026-10-31 (Product Owner decision D2). Versions pinned and verified 2026-10-31 (P1-02).

## Context
Environment audit (2026-10-31) of the development PC (Windows 11 25H2):
- An existing XAMPP install with **MariaDB 10.6** on port 3306, serving other projects.
- Windows PATH: PHP 8.3.31 CLI, Composer 2.10.1, Node 24.15.0, Git 2.54. They are not used to run the application, which runs in containers.
- No Docker, WSL, Redis, Meilisearch, or MinIO before setup.
- `CLAUDE.md` and `skills/02` require **MySQL 8.4 LTS**. MariaDB differs in JSON handling, CHECK behavior, functional indexes, and replication, so testing on MariaDB would not reflect production.

## Decision
- Development, CI, staging, and production all run through Docker Compose: PHP-FPM, Nginx, MySQL 8.4, Redis (Valkey), the S3-compatible object store (SeaweedFS, ADR-0007), ClamAV, Mailpit (plus Meilisearch from Phase 2).
- XAMPP stays untouched. Compose publishes on non-conflicting ports, bound to 127.0.0.1 only (8080, 3307, 6380, 9000, 8025, 5173; later 8081, 7700).
- PHP, Node, and all images are pinned to exact versions. Before pinning, Sprint 1.0 verifies that the chosen Laravel release and every approved package support the PHP version. The versions are recorded here when chosen:

| Component | Pinned version | Verified on |
|---|---|---|
| PHP | 8.4.27 (`php:8.4.27-fpm-bookworm`; security support to 2028-12-31) | 2026-10-31 |
| Laravel | 13.x (framework v13.39.0, skeleton v13.11.0; security support to 2028-03-17) | 2026-10-31 |
| Node LTS | 24.22.2 (`node:24.22.2-alpine`; end of life 2028-04-30) | 2026-10-31 |
| MySQL | 8.4.13 (LTS; end of life 2032-04-30) | 2026-10-31 |
| Valkey | 9.1.3 (`valkey/valkey:9.1.3-alpine`) | 2026-10-31 |
| Nginx | 1.30.6 stable (`nginx:1.30.6-alpine`) | 2026-10-31 |
| ClamAV | 1.5.4 (`clamav/clamav:1.5.4`) | 2026-10-31 |
| Mailpit | v1.33.0 (`axllent/mailpit:v1.33.0`) | 2026-10-31 |
| Object storage | SeaweedFS 4.51 (`chrislusf/seaweedfs:4.51`; O17; conformance spike 33/33 and key-policy check 19/19; run with `-master.telemetry=false`) | 2026-10-31 |
| Composer | 2.11.1 (copied from `composer:2.11.1` into the PHP image) | 2026-10-31 |
| phpredis | 6.3.0 (PECL) | 2026-10-31 |
| AWS CLI (bucket init, checks) | 2.39.3 (`amazon/aws-cli:2.39.3`) | 2026-10-31 |

Versions were the latest stable releases found on 2026-10-31. A version is pinned only after `composer install`/`npm ci`, the test suite, and the stack healthchecks pass on it; the date goes in "Verified on". Package versions are fixed by `composer.lock` and `package-lock.json` (2026-10-31: laravel/framework v13.39.0, laravel/horizon v5.50.1, pestphp/pest v5.3.4 with PHPUnit 13.4.5, larastan/larastan v3.13.2 with phpstan 2.3.4, vite 8.3.5, vitest 5.0.5, tailwindcss 4.3.3; CI tools: gitleaks v8.30.1 image, actions/checkout v7.0.1 pinned to commit 3d3c42e5). Upgrades are explicit changes to this table, never silent. PHP 8.4 rather than 8.5: Pest 5 and symfony/html-sanitizer need ≥ 8.4, and 8.4 has the longer track record with extensions; 8.5 remains an option.

### Amendment 2026-10-31 — Docker Engine in WSL2 instead of Docker Desktop
Docker Desktop requires a paid subscription for organizations at or above 250 employees or $10M revenue, and project rules forbid paid services without approval. The Product Owner chose the free **Docker Engine + Compose plugin inside an Ubuntu 24.04 WSL2 distro** (setup in `docs/10` §1.1). This matches the Linux production engine. There is no GUI; Compose commands run inside WSL.

### Amendment 2026-10-31 (b) — installed environment
WSL 3.0.3 (kernel 6.18.54) with systemd, Ubuntu 24.04.5 from the WSL catalog, Docker Engine 29.9.2 with Compose v5.6.1, cgroup v2, and the default `iptables-nft` backend. Docker starts on its own through systemd. Procedure: `docs/10` §1.1; record: `session-history/103126.md`.
- Windows and the WSL kernel receive updates, and the setup matches production (systemd, current kernel, cgroup v2).

## Consequences
- The user must enable WSL2 (admin rights and a reboot); Docker Engine is then installed inside WSL, following `docs/10` §1.1.
- Dev and production parity, including database grants and CHECK constraints.
- Bind-mount performance on NTFS may be slow. If measured as a problem, the working copy moves into the WSL filesystem. Measured 2026-10-31 (P1-02) with the repo on the Windows drive and `vendor`/`node_modules` in named volumes: GET `/` median 0.15 s, `/up` median 0.13 s (20 requests each, debug on). Acceptable; the working copy stays on the Windows drive and is re-measured when the app grows.

## Alternatives considered
- Native Windows services with MySQL 8.4 on another port: more drift from production and manual service management.
- Use XAMPP MariaDB: rejected; it conflicts with the MySQL 8.4 mandate.
