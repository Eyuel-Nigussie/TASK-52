# VetOps Operations Runbook

On-premise operations guide for the VetOps Unified Operations Portal. This
document covers provisioning, day-to-day operations, security-sensitive
procedures, and recovery drills. It assumes a LAN-only deployment with no
outbound internet access.

---

## 1. Target Environment

| Component          | Minimum                              | Recommended                |
|--------------------|--------------------------------------|----------------------------|
| OS                 | Debian 12 / Ubuntu 22.04 / RHEL 9    | Debian 12                  |
| CPU / RAM          | 4 vCPU / 8 GB                        | 8 vCPU / 16 GB             |
| Disk               | 100 GB SSD                           | 250 GB SSD + separate `/data` volume |
| Network            | Private LAN, no outbound internet    | VLAN-segmented, TLS termination at reverse proxy |
| Runtime (bare)     | PHP 8.4, MySQL 8.0, Nginx            | Docker 24 + Compose        |
| Runtime (Docker)   | Docker 24, docker-compose v2         | —                          |

The application is designed to run **fully offline** — no outbound calls are
made at runtime once Composer/NPM dependencies are pre-fetched.

---

## 2. First-Time Provisioning

### 2.1 Docker-based (recommended)

```bash
# 1. Copy the repository bundle to the host
tar -xzf vetops-<version>.tar.gz -C /opt && cd /opt/vetops

# 2. Create and edit environment file
cp .env.example .env
editor .env    # set DB creds, APP_KEY, VETOPS_ENCRYPTION_KEY — see §3

# 3. Build + start (migrations run automatically via entrypoint)
./start.sh

# 4. Seed the initial system administrator
docker compose exec app php artisan db:seed --class=AdminSeeder

# 5. Verify health
curl -fsS http://localhost:8000/up
```

### 2.2 Bare-metal (air-gapped)

Pre-fetch vendor directories on a connected workstation, then ship the bundle:

```bash
# On build workstation (internet available)
composer install --no-dev --optimize-autoloader --prefer-dist
npm ci && npm run build
tar --exclude='.git' --exclude='node_modules' -czf vetops-<version>.tar.gz .

# On target host (offline)
tar -xzf vetops-<version>.tar.gz -C /opt && cd /opt/vetops
cp .env.example .env && editor .env
php artisan key:generate --ansi          # only if APP_KEY is empty
php artisan migrate --force
php artisan storage:link
```

Place the application behind Nginx + PHP-FPM (see `.docker/nginx/default.conf`
for a reference virtual host) and run the queue worker + scheduler under
systemd (see §7).

---

## 3. Required Secrets

All three secrets below **must** be generated fresh per deployment. Never
reuse values from `.env.example`.

| Variable                  | Purpose                                        | How to generate                       |
|---------------------------|------------------------------------------------|---------------------------------------|
| `APP_KEY`                 | Laravel cookie / session / encrypter key       | `php artisan key:generate --show`     |
| `VETOPS_ENCRYPTION_KEY`   | Additional AES key for PII fields at rest      | `openssl rand -base64 32`             |
| `DB_PASSWORD`             | MySQL user password                            | `openssl rand -base64 24`             |

Store the values in your organization's password vault (e.g. HashiCorp Vault,
Bitwarden on-prem). Losing `APP_KEY` or `VETOPS_ENCRYPTION_KEY` makes every
encrypted field in the database unreadable; there is no recovery path.

### 3.1 Encryption key backup

Immediately after provisioning, back up the `.env` file to two physically
separate offline media:

```bash
# Example: encrypted GPG copy to removable media
gpg --symmetric --cipher-algo AES256 -o /media/usb1/vetops.env.gpg .env
```

Record in the change log who holds each backup and rotate custodians at
staff changes.

### 3.2 Encryption key rotation

PII encryption is currently AES-GCM via the application encrypter. To rotate:

1. Put the portal into read-only maintenance mode (set `APP_MAINTENANCE_DRIVER`
   to `file` and run `php artisan down --render=errors::503`).
2. Export the existing `VETOPS_ENCRYPTION_KEY` into `VETOPS_ENCRYPTION_KEY_PREVIOUS`.
3. Generate and install a new `VETOPS_ENCRYPTION_KEY`.
4. Run `php artisan vetops:rotate-pii-keys --batch=500` (re-encrypts in
   batches; safe to re-run).
5. Once the command reports zero remaining rows, remove
   `VETOPS_ENCRYPTION_KEY_PREVIOUS` from `.env`.
6. Bring the portal back online: `php artisan up`.

Keep the previous key offline for at least one full audit cycle before
destroying it, in case of restored backups that still contain old ciphertext.

---

## 4. Storage Layout & Permissions

| Path                       | Owner          | Mode   | Notes                                   |
|----------------------------|----------------|--------|-----------------------------------------|
| `storage/app/public`       | `www-data`     | `0750` | Public-readable uploads (linked)        |
| `storage/app/private`      | `www-data`     | `0750` | Encrypted documents, review images      |
| `storage/logs`             | `www-data`     | `0750` | Laravel logs (rotate via logrotate)     |
| `storage/framework/*`      | `www-data`     | `0750` | Sessions, views, cache                  |
| `bootstrap/cache`          | `www-data`     | `0750` | Route/config cache                      |
| `public/storage`           | `www-data`     | symlink| `php artisan storage:link`              |
| `/var/lib/mysql`           | `mysql`        | `0700` | Database cluster                        |

Set with:

```bash
chown -R www-data:www-data storage bootstrap/cache
find storage bootstrap/cache -type d -exec chmod 0750 {} \;
find storage bootstrap/cache -type f -exec chmod 0640 {} \;
```

All uploaded files are checksummed on write (`FileStorageService::store`) —
verify integrity periodically:

```bash
php artisan vetops:verify-file-checksums --since=7d
```

---

## 5. Database Operations

### 5.1 Migrations

Migrations are idempotent and safe to re-run. On each deploy:

```bash
php artisan migrate --force
```

Reversing a migration in production requires manager + DBA approval. Do not
use `migrate:rollback` against a live database without a verified backup.

### 5.2 Backups

Daily full dump + hourly binlog archiving is the baseline:

```bash
# Daily (cron or systemd timer)
mysqldump --single-transaction --quick --routines --events \
    --set-gtid-purged=OFF vetops > /backup/vetops-$(date +%F).sql

# Binary log archive (set in my.cnf under [mysqld])
log_bin           = /var/log/mysql/mysql-bin.log
expire_logs_days  = 7
server_id         = 1
```

Retain local dumps for 30 days; copy weekly dumps to offline media for the
full 7-year audit retention window (see §6).

### 5.3 Restore drill

Practiced quarterly:

```bash
mysql vetops_restore < /backup/vetops-<date>.sql
php artisan vetops:verify-file-checksums   # ensure disk matches DB
```

---

## 6. Audit Log Retention (7-Year Requirement)

Audit records are immutable (see `App\Models\AuditLog`). The purge command
removes records past the configured retention window:

```bash
php artisan vetops:purge-audit-logs --dry-run   # preview
php artisan vetops:purge-audit-logs             # executes
```

Retention is controlled by `VETOPS_AUDIT_RETENTION_YEARS` (default `7`).

### 6.1 Scheduling

The command is registered with the Laravel scheduler; ensure the scheduler
runs every minute via cron:

```cron
* * * * * cd /opt/vetops && php artisan schedule:run >> /var/log/vetops-schedule.log 2>&1
```

Under Docker, the `scheduler` service in `docker-compose.yml` handles this.
Verify with:

```bash
docker compose logs --tail=50 scheduler
```

### 6.2 Export before purge

Each scheduled purge writes a CSV export to `storage/app/private/audit-exports/`
before deletion. Copy these exports to long-term offline media before the
on-disk retention expires.

---

## 7. Scheduled & Background Jobs

| Job                                | Frequency  | Command                                            |
|------------------------------------|------------|----------------------------------------------------|
| Mark overdue rentals               | Every 5 min| `php artisan vetops:mark-overdue-rentals`          |
| Refresh low-stock alerts           | Hourly     | `php artisan vetops:refresh-stock-levels`          |
| Audit log export + purge           | Nightly    | `php artisan vetops:purge-audit-logs`              |
| File checksum verification         | Nightly    | `php artisan vetops:verify-file-checksums`         |

### 7.1 Queue worker (systemd unit example)

```ini
# /etc/systemd/system/vetops-worker.service
[Unit]
Description=VetOps queue worker
After=network.target mysql.service

[Service]
Type=simple
User=www-data
WorkingDirectory=/opt/vetops
ExecStart=/usr/bin/php artisan queue:work --tries=3 --sleep=3 --timeout=60
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

### 7.2 Scheduler (systemd timer example)

```ini
# /etc/systemd/system/vetops-schedule.timer
[Unit]
Description=Run VetOps scheduler every minute

[Timer]
OnCalendar=*:0/1
Persistent=true

[Install]
WantedBy=timers.target

# /etc/systemd/system/vetops-schedule.service
[Unit]
Description=VetOps scheduler tick
[Service]
Type=oneshot
User=www-data
WorkingDirectory=/opt/vetops
ExecStart=/usr/bin/php artisan schedule:run
```

---

## 8. Access Control & RBAC

Role definitions and their server-side enforcement are documented in
[`docs/RBAC.md`](docs/RBAC.md). Key operational points:

- All authorization decisions pass through either the `role` middleware
  (`App\Http\Middleware\RoleMiddleware`) or a named policy registered in
  `App\Providers\AuthServiceProvider`.
- `system_admin` is an unscoped bypass — grant it to at most two staff and
  log every assignment in the audit trail.
- User deactivation is soft-delete only (`active=false`); never hard-delete a
  user, because audit rows reference `created_by` / `updated_by`.

### 8.1 Adding a user

```bash
php artisan tinker --execute='
  \App\Models\User::create([
    "username" => "jdoe",
    "name"     => "Jane Doe",
    "email"    => "jdoe@vetops.local",
    "password" => bcrypt("change-me-12+chars"),
    "role"     => "clinic_manager",
    "facility_id" => 1,
    "active"   => true,
  ]);
'
```

Passwords must be ≥12 characters (enforced on the login/change-password
endpoints, see `App\Services\AuthService`).

---

## 9. Incident Response

### 9.1 Failed login storm

1. Inspect recent attempts: `php artisan tinker` → `App\Models\LoginAttempt::latest()->limit(50)->get()`.
2. If a single workstation is hammering the endpoint, block its IP at the
   firewall — the built-in rate limit (10/10min) already refuses the attempts,
   but firewall shedding reduces load.
3. Captcha threshold kicks in after 5 failures within the window; no code
   changes are required to enforce it.

### 9.2 Suspected data tampering

Audit log records are append-only at the model layer (`AuditLog` rejects
`update()` / `delete()`). To confirm DB-level integrity:

```sql
-- No UPDATE/DELETE privileges on audit_logs for the application role
REVOKE UPDATE, DELETE ON TABLE audit_logs FROM vetops;
```

If tampering is suspected, restore from the most recent pre-event `mysqldump`
into a read-only replica and diff the `audit_logs` tables.

### 9.3 Encryption key compromise

1. Immediately rotate both `APP_KEY` and `VETOPS_ENCRYPTION_KEY` per §3.2.
2. Force logout of all sessions: `php artisan sanctum:prune-expired --hours=0`
   followed by a mass token revoke (`DELETE FROM personal_access_tokens`).
3. Require every user to reset their password on next login.

---

## 10. Observability

- **Application logs**: `storage/logs/laravel.log` (rotate daily via
  logrotate, keep 30 days local + 7 years on archival media).
- **Access logs**: Nginx `access.log` / `error.log` (`.docker/nginx/default.conf`).
- **Audit trail**: queryable at `GET /api/audit-logs` (system_admin /
  clinic_manager only).
- **Health probe**: `GET /up` returns `200` when the app is alive.

Recommended dashboards (local Grafana if available):

- Login failure rate by workstation (tripwire at 5/min).
- Overdue rentals per facility.
- Low-stock alerts per storeroom.
- Audit log insert rate (sudden dips indicate a pipeline failure).

---

## 11. Upgrade Procedure

1. Announce a maintenance window.
2. Snapshot database: `mysqldump --single-transaction ...`.
3. Snapshot storage volume.
4. `php artisan down --render=errors::503`.
5. Deploy new bundle, run `composer install --no-dev --optimize-autoloader`.
6. `php artisan migrate --force`.
7. `php artisan config:cache && php artisan route:cache`.
8. `php artisan up`.
9. Smoke test: login, list facilities, create a throwaway rental checkout &
   return, verify audit entries appear.

Roll back by restoring the snapshots from step 2–3 and redeploying the
previous bundle. Never roll back migrations on production without DBA review.

---

## 12. Contacts & Escalation

Replace the placeholders below during provisioning:

| Role                 | Name / Group       | Contact                    |
|----------------------|--------------------|----------------------------|
| Primary on-call      | _TBD_              | _TBD_                      |
| Backup on-call       | _TBD_              | _TBD_                      |
| DBA                  | _TBD_              | _TBD_                      |
| Security / Compliance| _TBD_              | _TBD_                      |
| Key custodian #1     | _TBD_              | _TBD_                      |
| Key custodian #2     | _TBD_              | _TBD_                      |
