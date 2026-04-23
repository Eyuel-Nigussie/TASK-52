# VetOps Unified Operations Portal

**Project type:** fullstack (Laravel 13 REST API backend + Vue 3 SPA frontend, served from the same Docker stack)

A unified operations platform for managing veterinary clinic operations — rental equipment, medical supply inventory, internal content publishing, and patient visit feedback — across multiple facilities. The Laravel 13 REST API and the Vue 3 single-page application are built, shipped, and tested together.

---

## Overview

VetOps consolidates the operational workflows of a multi-site veterinary organisation into a single auditable API. The system handles:

- **Rental Equipment Management** — asset catalogue, checkout/return lifecycle, deposit calculation, overdue tracking, and barcode/QR scan lookup.
- **Medical Supply Inventory** — storeroom-level stock control with receive/issue/transfer ledger entries, safety-stock alerts, and periodic stocktake with variance approval.
- **Internal Content Publishing** — draft → review → approve → publish workflow with full version history, media attachments, and rollback capability.
- **Patient Visit Feedback** — star-rating reviews submitted by patients post-visit, with publish/hide/respond/appeal moderation controls.
- **Deduplication** — merge-request workflow for identifying and consolidating duplicate patient records.

### User Roles

| Role | Scope |
|---|---|
| `system_admin` | Full access to all resources, configuration, and audit data |
| `clinic_manager` | Facility and department management, approval authority, moderation |
| `inventory_clerk` | Inventory receive/issue/transfer, stocktake entry |
| `technician_doctor` | Consume inventory items, manage service orders |
| `content_editor` / `content_approver` | Author and publish internal content |

---

## Tech Stack

| Component | Version / Package |
|---|---|
| Backend runtime | PHP 8.4 |
| Backend framework | Laravel 13 |
| Frontend framework | Vue 3 + Pinia + Vue Router |
| Frontend tooling | Vite 5, Tailwind CSS, Axios |
| Database | MySQL 8.0 |
| Authentication | Laravel Sanctum 4.x (bearer token) |
| Authorisation | Custom `role` middleware (`App\Http\Middleware\RoleMiddleware`) |
| CSV Import/Export | league/csv 9.x |
| Image Processing | intervention/image 4.x |
| Backend tests | PHPUnit 11 (Laravel HTTP test client) |
| Frontend tests | Vitest + @vue/test-utils + jsdom |
| Container | Docker (PHP 8.4-FPM Debian Bookworm + Nginx + Supervisor) |

---

## Quick Start (Docker)

The project is fully containerised — no host installs of PHP, Node, Composer, npm, or MySQL are required.

```bash
git clone <repository-url> vetops && cd vetops

# Copy environment file — edit DB credentials if connecting to an external MySQL
cp .env.example .env

# Build images, run migrations, start all services
docker-compose up -d --build
```

The helper script `./start.sh` wraps the same `docker-compose up` with pre-flight port checks and a health-probe loop, and is equivalent to the command above for normal use.

The portal will be available at **http://localhost:8000** (both the Vue SPA and the REST API are served from this origin).

Run the full test suite (no MySQL required — uses SQLite in-memory):

```bash
./run_tests.sh
```

---

## Default Accounts

After running `./start.sh` (which calls `php artisan db:seed`), one account for every role is available. All accounts use a **temporary password** and are enforced to change it on first login.

| Username | Role | Temporary password |
|---|---|---|
| `admin` | System Administrator | `VetOps!Tmp-Admin1` |
| `manager1` | Clinic Manager | `VetOps!Tmp-Mgr01` |
| `clerk1` | Inventory Clerk | `VetOps!Tmp-Clrk1` |
| `doctor1` | Technician / Doctor | `VetOps!Tmp-Doc01` |
| `editor1` | Content Editor | `VetOps!Tmp-Edit1` |
| `approver1` | Content Approver | `VetOps!Tmp-Aprv1` |

> **Important — change all passwords immediately after first login.**
> The application will not allow access to any other page until the temporary password is replaced with a personal password of at least 12 characters. Use the **Change Password** screen that appears automatically on first login, or call `POST /api/auth/change-password` directly.

To re-seed the database from scratch:

```bash
docker compose exec app php artisan migrate:fresh --seed
```

---

## Configuration

The following `VETOPS_*` environment variables control application behaviour. All have sensible defaults defined in `.env.example`.

| Variable | Description | Default |
|---|---|---|
| `VETOPS_INACTIVITY_TIMEOUT` | Minutes of API inactivity before a token is considered expired | `15` |
| `VETOPS_MAX_LOGIN_ATTEMPTS` | Maximum login attempts within the rate-limit window before lockout | `10` |
| `VETOPS_LOGIN_WINDOW_MINUTES` | Rolling window (minutes) for login rate limiting | `10` |
| `VETOPS_CAPTCHA_AFTER` | Number of failed logins that trigger a CAPTCHA challenge | `5` |
| `VETOPS_AUDIT_RETENTION_YEARS` | Years to retain audit log records before purge eligibility | `7` |
| `VETOPS_OVERDUE_HOURS` | Hours after checkout before a rental is flagged overdue | `2` |
| `VETOPS_SAFETY_STOCK_DAYS` | Days of average demand used to calculate safety-stock thresholds | `14` |
| `VETOPS_DEPOSIT_RATE` | Rental deposit as a fraction of replacement cost | `0.20` |
| `VETOPS_DEPOSIT_MIN` | Minimum rental deposit regardless of replacement cost | `50.00` |
| `VETOPS_STOCKTAKE_VARIANCE_PCT` | Variance percentage above which stocktake entries require manager approval | `5` |
| `VETOPS_UPLOAD_MAX_MB` | Maximum file size (MB) for photo/media uploads | `20` |
| `VETOPS_ENCRYPTION_KEY` | Additional application-level encryption key for PII fields at rest | _(empty — set in production)_ |

Standard Laravel environment variables (`DB_*`, `APP_KEY`, `SESSION_*`, etc.) follow the conventions documented in `.env.example`.

---

## API Overview

All endpoints are prefixed with `/api`. Authenticated routes require an `Authorization: Bearer <token>` header.

| Group | Base Path | Description |
|---|---|---|
| Authentication | `/api/auth` | Login, logout, current user, password change, captcha status |
| Facilities | `/api/facilities` | CRUD, CSV import/export, change history |
| Departments | `/api/departments` | List (any authenticated user, scoped to their facility); create/update/delete (manager/admin only) |
| Users | `/api/users` | User management (system admin only) |
| Doctors | `/api/doctors` | Doctor profiles, CSV import |
| Patients | `/api/patients` | Patient records with PII masking |
| Visits | `/api/visits` | Patient visit records |
| Rental Assets | `/api/rental-assets` | Asset catalogue, photo upload, barcode scan |
| Rental Transactions | `/api/rental-transactions` | Checkout, return, cancel, overdue list |
| Storerooms | `/api/storerooms` | Storeroom management per facility |
| Inventory | `/api/inventory` | Items, receive/issue/transfer, ledger, stock levels, low-stock alerts, CSV import/export |
| Stocktake | `/api/stocktake` | Session start/close, entry submission, variance approval |
| Service Orders | `/api/service-orders` | Work orders linked to patients and inventory |
| Content | `/api/content` | Draft/review/publish workflow, versioning, media |
| Reviews | `/api/reviews` | Visit feedback, publish/hide, manager responses, appeals |
| Merge Requests | `/api/merge-requests` | Duplicate patient deduplication workflow |
| Audit Logs | `/api/audit-logs` | Immutable event log with CSV export |

---

## Business Rules

| Rule | Value |
|---|---|
| Rental deposit | `max(20% x replacement cost, $50.00)` |
| Overdue threshold | 2 hours after checkout |
| Safety-stock baseline | 14 days of average consumption |
| Stocktake variance requiring approval | > 5% discrepancy |
| Minimum password length | 12 characters |
| Login rate limit | 10 attempts per 10-minute window, keyed by workstation ID (falls back to IP) |
| Session inactivity timeout | 15 minutes |
| Audit log retention | 7 years (records are immutable) |
| Inventory ledger entries | Immutable — adjustments create corrective entries, not overwrites |

---

## Authentication

VetOps uses **Laravel Sanctum** bearer tokens. Tokens are issued on login and must be sent with every subsequent request.

### Authentication Model

VetOps uses **short-lived Bearer tokens** issued by Laravel Sanctum, never persisted to `localStorage` or any client-accessible browser storage.

| Property | Value |
|---|---|
| Token lifetime | 15-minute inactivity window (enforced server-side by `vetops.inactivity` middleware) |
| Session persistence | HttpOnly `vetops_session` cookie (8-hour TTL, `SameSite=Strict`, `Secure` in production) set by the server on login |
| Page-refresh flow | On mount, the SPA silently calls `POST /api/auth/refresh`; the server reads the HttpOnly cookie, validates the Sanctum token, and returns a fresh in-memory token |
| Logout | Revokes the Sanctum token on the server and clears the `vetops_session` cookie |
| CSRF protection | Bearer tokens are inherently CSRF-safe — browsers cannot automatically include them in cross-origin requests. Additionally, `withCredentials: true` on the Axios client causes browsers to include the Laravel-generated `XSRF-TOKEN` cookie and set `X-XSRF-TOKEN` on every mutating request, providing a defense-in-depth CSRF layer |
| Token storage | In-memory only (Pinia state) — lost on page hard-refresh and restored via the HttpOnly cookie flow above |

### Login

```
POST /api/auth/login
Content-Type: application/json

{
  "username": "admin",
  "password": "supersecretpassword"
}
```

**Example (curl):**

```bash
curl -s -X POST http://localhost:8000/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"username":"admin","password":"supersecretpassword"}' \
  | jq .
```

Successful response:

```json
{
  "token": "1|abc123...",
  "user": {
    "id": 1,
    "username": "admin",
    "name": "System Admin",
    "email": "admin@vetops.local",
    "role": "system_admin"
  },
  "captcha_required": false
}
```

Use the token in all subsequent requests:

```bash
curl -s http://localhost:8000/api/auth/me \
  -H "Authorization: Bearer 1|abc123..."
```

### Workstation Identification and Login Rate Limiting

Login attempts are throttled at **10 per 10-minute window**, keyed by a stable workstation identifier rather than IP address. This prevents a shared clinic LAN (many terminals behind one NAT IP) from having all terminals locked out because one terminal hit the limit.

**`X-Device-ID` header** — send a stable UUID with every login and captcha-status request:

```
POST /api/auth/login
X-Device-ID: 550e8400-e29b-41d4-a716-446655440000
Content-Type: application/json
```

```
GET /api/auth/captcha-status
X-Device-ID: 550e8400-e29b-41d4-a716-446655440000
```

| Behavior | Detail |
|---|---|
| Header present | Rate limit and CAPTCHA counter are keyed by the device ID value |
| Header absent | Falls back to IP address (same behavior as before this feature was added) |
| Key scope | Per-workstation — a second terminal with a different device ID is not affected by the first terminal's failures |
| Browser client | The SPA auto-generates a UUID v4 via `crypto.randomUUID()` and persists it in `localStorage` under `vetops.device_id` |
| Non-browser clients | Generate a random UUID v4 at first use, persist it in stable storage (e.g. a config file or OS keychain), and send it on every request to `/api/auth/login` and `/api/auth/captcha-status` |

**Example (curl with device ID):**

```bash
curl -s -X POST http://localhost:8000/api/auth/login \
  -H "Content-Type: application/json" \
  -H "X-Device-ID: 550e8400-e29b-41d4-a716-446655440000" \
  -d '{"username":"admin","password":"supersecretpassword"}' \
  | jq .
```

### Logout

```bash
curl -s -X POST http://localhost:8000/api/auth/logout \
  -H "Authorization: Bearer 1|abc123..."
```

---

## Running Tests

The backend test suite uses SQLite in-memory — no MySQL instance is required.

```bash
./run_tests.sh           # runs the PHP feature + unit test suite
```

To run a specific backend test file or filter by name:

```bash
docker compose run --rm \
  -e DB_CONNECTION=sqlite -e DB_DATABASE=:memory: \
  -e APP_ENV=testing \
  -e APP_KEY="base64:0eOu7/QTYq8WxLGNGqZZ2LG5FVoEH0iz7rhojHreOJM=" \
  app php artisan test --filter AuthTest
```

Frontend tests only:

```bash
docker compose run --rm app npx vitest run
```

Test configuration is defined in `phpunit.xml` (backend) and `vitest.config.js` (frontend). Backend unit tests live in `tests/Unit/`, feature/HTTP tests in `tests/Feature/`. Frontend tests live next to the components they cover (`resources/js/**/*.test.js`).

---

## Project Structure

```
.
├── app/
│   ├── Http/
│   │   ├── Controllers/Api/    # One controller per resource group
│   │   ├── Middleware/         # Inactivity timeout, role checks
│   │   └── Requests/           # Form request validation
│   ├── Models/                 # Eloquent models with encrypted PII casts
│   ├── Services/               # Business logic (RentalService, InventoryService, ...)
│   └── Console/Commands/       # Scheduled jobs (purge audit logs, mark overdue rentals)
├── database/
│   ├── migrations/             # Versioned schema migrations
│   ├── factories/              # Model factories for testing
│   └── seeders/                # Role/permission seeder, demo data
├── routes/
│   └── api.php                 # All API route definitions
├── tests/
│   ├── Feature/                # HTTP-level integration tests
│   └── Unit/                   # Isolated unit tests for services/models
├── .docker/
│   ├── nginx/default.conf      # Nginx virtual host (port 8000)
│   ├── supervisord.conf        # Supervises nginx + php-fpm
│   └── entrypoint.sh           # Container startup: wait for DB, migrate, start
├── resources/
│   └── js/                     # Vue 3 SPA (views, stores, router, components)
├── Dockerfile                  # Multi-stage: base / development / production
├── docker-compose.yml          # app, mysql, queue worker, scheduler
├── start.sh                    # One-command local startup (wraps docker-compose up)
├── run_tests.sh                # One-command test runner (SQLite in-memory)
├── phpunit.xml                 # PHPUnit configuration
├── vitest.config.js            # Vitest configuration (frontend tests)
├── package.json                # Frontend dependencies
└── composer.json               # PHP dependencies
```

---

## Security

The following controls are implemented across the application:

- **Encrypted PII at rest** — sensitive patient fields (phone number, owner contact) use application-level encryption via `VETOPS_ENCRYPTION_KEY` with masked output in API responses.
- **Phone number masking** — phone numbers are returned as `***-***-XXXX` in listing endpoints; full values are only exposed to authorised roles.
- **Role-based access control** — every authenticated route is gated by Sanctum and the custom `role:` middleware (`App\Http\Middleware\RoleMiddleware`), with object-level decisions delegated to policies registered in `App\Providers\AuthServiceProvider` (see [`docs/RBAC.md`](docs/RBAC.md)).
- **Rate limiting** — login endpoint is throttled to 10 attempts per 10-minute window; CAPTCHA challenge triggers after 5 consecutive failures.
- **Inactivity timeout** — API tokens become invalid after 15 minutes of inactivity, enforced by the `vetops.inactivity` middleware.
- **Session encryption** — session payloads are encrypted at rest (`SESSION_ENCRYPT=true`).
- **Immutable audit trail** — `AuditLog` records cannot be updated or deleted. Retention is enforced at 7 years via a scheduled purge that requires explicit system-admin authorisation.
- **Immutable inventory ledger** — stock movements are append-only; corrections create new counter-entries rather than modifying existing records.
- **Security headers** — all responses include `X-Frame-Options`, `X-Content-Type-Options`, `X-XSS-Protection`, `Referrer-Policy`, and `Strict-Transport-Security` via Nginx.
- **File upload restrictions** — uploads are validated against an allowlist of MIME types and capped at `VETOPS_UPLOAD_MAX_MB` (default 20 MB).
