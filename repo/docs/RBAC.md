# VetOps RBAC Design

This document describes the role-based access control model used by the
VetOps portal, the rationale for its shape, and how every authorization
decision maps to a verifiable source artifact in the codebase.

---

## 1. Model at a Glance

VetOps uses a **single-role-per-user model with facility scoping and
object-level policies**. Each `User` row carries exactly one `role`, one
optional `facility_id`, and one optional `department_id`. The role
determines *what kind of action* a user may take; the facility scope
determines *which objects* those actions apply to.

This is deliberately simpler than a full RBAC system with assignable
permission bags or multi-role users. Rationale:

- **Operational clarity for a single veterinary group.** The organizational
  reality is that staff have one role at one site at a time. Encoding the
  reality directly keeps administration (provisioning, audit review, RCA)
  comprehensible to non-engineer clinic managers.
- **Smaller attack surface.** No permission UI, no role-assignment API,
  no per-user permission drift. Misconfiguration risk is concentrated in
  a small, reviewable set of route declarations and policy classes.
- **Auditable central mapping.** Every decision is one of: (a) a
  `role:` middleware declaration in `routes/api.php`, (b) a Gate in
  `App\Providers\AuthServiceProvider`, or (c) a policy method under
  `App\Policies`. Grep coverage is complete.

If the organization later needs multi-role users (e.g. a content approver
who is also an inventory clerk), the migration path is to add a pivot
table `role_user` and extend `User::hasRole()` to read from it — the
policy layer does not need to change.

---

## 2. Roles

| Role              | Purpose                                                                 |
|-------------------|-------------------------------------------------------------------------|
| `system_admin`    | Cross-facility administration, user management, unrestricted access.    |
| `clinic_manager`  | Facility-level management, approvals, content approval, moderation.     |
| `inventory_clerk` | Receives/issues/transfers inventory, runs stocktakes, manages rentals.  |
| `technician_doctor` | Consumes inventory for service orders, closes orders, checks out rentals. |
| `content_editor`  | Authors content; submits for review; cannot self-approve.               |
| `content_approver`| Reviews, approves, publishes, and rolls back content.                   |

`system_admin` is modeled as an **unscoped bypass**: a `Gate::before`
callback in `AuthServiceProvider` returns `true` for any ability check.
This matches the organizational reality that a superuser must be able to
recover any clinic from any terminal during an incident. The tradeoff is
that the role must be granted sparingly — see `OPERATIONS.md §8`.

---

## 3. Enforcement Layers

Authorization is enforced in three stacked layers; a request must pass
all three to reach a write operation.

### 3.1 Route middleware

Coarse role membership is checked by `App\Http\Middleware\RoleMiddleware`
at the route layer. Example:

```php
Route::post('/', [StoreroomController::class, 'store'])
    ->middleware('role:system_admin,clinic_manager');
```

This rejects anyone whose single `role` is not in the allow-list.
Evidence: `routes/api.php`, `app/Http/Middleware/RoleMiddleware.php`.

### 3.2 Gates (named abilities)

Cross-cutting abilities not tied to a single Eloquent model are defined
in `AuthServiceProvider::boot()` as named Gates:

```
manage-facilities, manage-departments, manage-doctors, manage-users,
export-audit-logs, resolve-merge-request, approve-stocktake,
moderate-reviews, approve-content, author-content, receive-inventory,
issue-inventory, transfer-inventory, checkout-rental
```

Usage: `Gate::allows('export-audit-logs')` or `$user->can('export-audit-logs')`.

### 3.3 Policies (object-level)

Every model whose access is scoped by ownership, facility, or content
status has a dedicated policy under `App\Policies`. The full map is
declared in `AuthServiceProvider::$policies`:

| Model                  | Policy                          | Ownership rule                            |
|------------------------|---------------------------------|-------------------------------------------|
| `Facility`             | `FacilityPolicy`                | Facility match; admin for delete          |
| `Doctor`               | `DoctorPolicy`                  | Facility match; manager for unmasked PII  |
| `RentalAsset`          | `RentalAssetPolicy`             | Facility match                            |
| `RentalTransaction`    | `RentalTransactionPolicy`       | Facility match                            |
| `InventoryItem`        | `InventoryItemPolicy`           | Role match (items are cross-facility)     |
| `Storeroom`            | `StoreroomPolicy`               | Facility match                            |
| `StocktakeSession`     | `StocktakeSessionPolicy`        | Facility match (via storeroom) + role     |
| `ContentItem`          | `ContentItemPolicy`             | Author for edits; target facility for read |
| `Patient`              | `PatientPolicy`                 | Facility match; manager for unmasked PII  |
| `Visit`                | `VisitPolicy`                   | Facility match                            |
| `VisitReview`          | `VisitReviewPolicy`             | Facility match + manager for moderation   |
| `Service`              | `ServicePolicy`                 | Cross-facility catalog; manager writes    |
| `ServicePricing`       | `ServicePricingPolicy`          | Facility match; manager writes            |
| `User`                 | `UserPolicy`                    | Admin-only                                |
| `AuditLog`             | `AuditLogPolicy`                | Manager read; no updates or deletes ever  |
| `MergeRequest`         | `MergeRequestPolicy`            | Manager only                              |
| `ServiceOrder`         | `ServiceOrderPolicy`            | Facility match                            |

Policies can be invoked from controllers (`$this->authorize(...)`), from
Blade templates, or from feature tests (`$this->assertTrue($user->can(...))`).

### 3.4 Data-scope queries

Read endpoints additionally apply a query scope so that even a successful
authorization check returns only facility-scoped rows. Example:
`ContentItem::scopeForUser($user)` filters by `facility_ids` and
`role_targets`. This prevents IDOR via enumeration.

Two scoping patterns are used, and both apply deny-all for non-admin users
with `facility_id = null`:

- **`ScopesByFacility::applyFacilityScope()`** — used by controllers that
  inherit the trait; automatically returns `whereRaw('1 = 0')` for
  null-facility non-admins.
- **Inline guard block** — used by controllers that scope via a sub-query
  (e.g., storeroom → facility join) or that need to abort early with a 403
  (analytics/create endpoints). The pattern is:
  ```php
  if (!$user->isAdmin()) {
      if ($user->facility_id === null) { /* whereRaw('1=0') or abort(403) */ }
      else { /* apply facility filter */ }
  }
  ```

All list, analytics, export, and create endpoints follow one of these two
patterns. There are no endpoints where a null-facility non-admin falls
through to an unfiltered result.

---

## 4. Permission Matrix

Legend: ✅ allowed, ❌ forbidden, 🔒 requires same-facility, ✍ same author only.

| Action / Role                       | admin | mgr  | clerk | tech | editor | approver |
|-------------------------------------|:-----:|:----:|:-----:|:----:|:------:|:--------:|
| Manage users                        | ✅    | ❌   | ❌    | ❌   | ❌     | ❌       |
| Create facility                     | ✅    | ✅   | ❌    | ❌   | ❌     | ❌       |
| Delete facility                     | ✅    | ❌   | ❌    | ❌   | ❌     | ❌       |
| Import/export CSV (facilities)      | ✅    | ✅   | ❌    | ❌   | ❌     | ❌       |
| Create rental asset                 | ✅    | ✅🔒 | ✅🔒  | ❌   | ❌     | ❌       |
| Delete rental asset                 | ✅    | ✅🔒 | ❌    | ❌   | ❌     | ❌       |
| Checkout / return rental            | ✅    | ✅🔒 | ✅🔒  | ✅🔒 | ❌     | ❌       |
| Cancel rental transaction           | ✅    | ✅🔒 | ❌    | ❌   | ❌     | ❌       |
| Create/update inventory item        | ✅    | ❌   | ✅    | ❌   | ❌     | ❌       |
| Receive / transfer inventory        | ✅    | ✅   | ✅    | ❌   | ❌     | ❌       |
| Issue inventory                     | ✅    | ✅   | ✅    | ✅   | ❌     | ❌       |
| Start stocktake session             | ✅    | ✅🔒 | ✅🔒  | ❌   | ❌     | ❌       |
| Approve stocktake variance          | ✅    | ✅🔒 | ❌    | ❌   | ❌     | ❌       |
| Author content draft                | ✅    | ❌   | ❌    | ❌   | ✅✍   | ✅       |
| Approve / publish content           | ✅    | ❌   | ❌    | ❌   | ❌     | ✅       |
| Rollback content version            | ✅    | ❌   | ❌    | ❌   | ✅✍   | ✅       |
| Submit review (patient-tablet flow) | open — no auth, order-scoped token (see §6)                     |||||
| Publish / hide review               | ✅    | ✅🔒 | ❌    | ❌   | ❌     | ❌       |
| Respond to review                   | ✅    | ✅🔒 | ❌    | ❌   | ❌     | ❌       |
| Appeal review                       | ✅    | ✅🔒 | ❌    | ❌   | ❌     | ❌       |
| View unmasked patient phone         | ✅    | ✅🔒 | ❌    | ❌   | ❌     | ❌       |
| View audit logs                     | ✅    | ✅   | ❌    | ❌   | ❌     | ❌       |
| Export audit logs                   | ✅    | ✅   | ❌    | ❌   | ❌     | ❌       |
| Approve / reject merge request      | ✅    | ✅   | ❌    | ❌   | ❌     | ❌       |

`system_admin` bypass is implemented once in `AuthServiceProvider::boot()`
via `Gate::before`. Every other ✅ is gated by one of the three layers
above.

---

## 5. How to Verify Coverage

1. **Policies wired**: `AuthServiceProvider::$policies` lists every model
   with object-level rules.
2. **Route coverage**: `grep -n "middleware('role:" routes/api.php` should
   return a decision for every write route; index routes rely on scoped
   queries and policy `view*` methods.
3. **Tests**: see `tests/Feature/PolicyAuthorizationTest.php` and
   `tests/Feature/ObjectAuthorizationTest.php` for executable assertions
   of the matrix above.

---

## 6. Null-Facility Non-Admin Invariant

Every non-admin user without a `facility_id` assignment is treated as
having **no access** to facility-scoped objects and list endpoints.
Specifically:

- All policy `sharesFacility()` / `view()` helpers return `false` when
  `$user->facility_id === null` and `$user->isAdmin()` is false.
- All controller query scopes apply `whereRaw('1 = 0')` for null-facility
  non-admins (rather than omitting the filter), ensuring the list
  returns an empty set rather than leaking all rows.
- `DedupController` aborts with 403 rather than treating a null-facility
  non-admin as an admin.

This closes the legacy "unassigned superuser" shortcut that predated the
`system_admin` role. `UserController` blocks creation of new non-admin
users without a facility assignment.

---

## 7. Notable Exceptions

- **Review submission** (`POST /api/reviews/visits/{visit}/submit`) is
  intentionally unauthenticated: a pet owner uses a shared tablet, not a
  login. The endpoint is scoped by the parent visit id (an opaque integer
  provided on the tablet by staff) and rate-limited. No PII from other
  visits is reachable, and each submission is immutable.
- **`GET /up`** is unauthenticated for monitoring probes.
- **`GET /api/auth/captcha-status`** is unauthenticated so the client
  can render the captcha challenge before the user has a session.

---

## 8. Change Management

Any change to the matrix above requires:

1. Edit the relevant policy method (one file change per model).
2. Update `routes/api.php` if the coarse allow-list changes.
3. Add or update an assertion in `tests/Feature/PolicyAuthorizationTest.php`.
4. Update this matrix in the same commit — the doc and the tests drift
   otherwise.

The CI test suite will fail if the policy map loses a class listed under
`AuthServiceProvider::$policies` (the provider boots during test setup).
