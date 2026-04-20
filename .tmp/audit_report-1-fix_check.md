# VetOps Delivery Acceptance & Project Architecture Audit (Fix-Check, Static-Only)

- Audit mode: static-only (no app start, no Docker start, no test execution).
- Scope: fix-check against prior findings in `.tmp/audit_report-1.md`.
- Evidence base: source/docs/tests under `repo/` and `docs/` only.

## 1) Executive Verdict

- **Overall Delivery Acceptance: Partial Pass**
- **Change vs previous audit:** significant improvement; most previously flagged findings are fixed.
- **Current residual risk:** Medium-High (reduced from High).

### Prior findings status at a glance

1. Merge approval status-only behavior: **Resolved (core issue fixed)**
2. Inventory/stocktake isolation under-enforced: **Resolved for high-risk paths**
3. Unified ingestion/model scope too narrow: **Mostly resolved**
4. Facility endpoints globally readable/not policy-enforced: **Resolved**
5. Import versioning mismatch claim vs implementation: **Resolved**

Primary residual gaps after fixes:
1. Merge execution exists, but supported merge entity types are still limited (`patient`, `doctor`, `rental_transaction_asset`) rather than broad entity-resolution coverage (notably no `service` merge support) (`repo/app/Services/MergeService.php:48-72`, `docs/design.md:388`).
2. Inventory item endpoints still rely mostly on route-role middleware and ad-hoc guards rather than explicit `authorize(...)` policy calls in controller methods (`repo/app/Http/Controllers/Api/InventoryController.php:29-225`, `repo/app/Policies/InventoryItemPolicy.php:12-45`).
3. Dedup helper methods for URL normalization/key-field matching are implemented but not wired into import/edit paths (only SimHash path is visibly used in content workflow) (`repo/app/Services/DeduplicationService.php:50-82`, `repo/app/Services/ContentService.php:27-33`, `repo/app/Services/ContentService.php:82-86`).

## 2) Prior Findings Re-Check

### 2.1 Blocker: merge approval did not execute merge

**Previous:** Fail  
**Now:** **Pass (fixed for supported entity types)**

Evidence of fix:
- Approval path now calls merge executor: `repo/app/Http/Controllers/Api/MergeRequestController.php:60-66`.
- Transactional merge service now relinks FKs, snapshots versions, writes merge audit payload, and soft-deletes source: `repo/app/Services/MergeService.php:74-147`.
- New feature coverage validates relink + soft-delete + audit + snapshot behavior: `repo/tests/Feature/MergeExecutionTest.php:29-171`.

Residual note:
- Supported entity list is explicit and narrow (`patient`, `doctor`, `rental_transaction_asset`): `repo/app/Services/MergeService.php:48-72`.

### 2.2 High: inventory/stocktake tenant-object isolation under-enforced

**Previous:** High  
**Now:** **Partial Pass (materially improved, high-risk vectors fixed)**

Evidence of fix:
- Inventory receive/issue/transfer now enforce storeroom facility access guard before write: `repo/app/Http/Controllers/Api/InventoryController.php:91-93`, `:119-121`, `:148-152`, guard implementation `:231-240`.
- Stocktake controller now applies policy checks at action level (`viewAny`, `start`, `view`, `addEntry`, `approve`, `close`): `repo/app/Http/Controllers/Api/StocktakeController.php:22`, `:49`, `:61`, `:69`, `:87`, `:102`, `:110`.
- Stocktake policy now includes facility-sharing checks: `repo/app/Policies/StocktakeSessionPolicy.php:18-21`, `:28-41`, `:43-59`, `:61-76`.
- Regression tests added for foreign storeroom writes and stocktake cross-facility access: `repo/tests/Feature/InventoryIsolationTest.php:41-180`.

Residual note:
- Inventory item endpoints still do not call policy methods directly (`authorize`) despite policy existence (`repo/app/Http/Controllers/Api/InventoryController.php:29-77`, `repo/app/Policies/InventoryItemPolicy.php:12-45`).

### 2.3 High: unified ingestion/model scope too narrow

**Previous:** High  
**Now:** **Partial Pass (major delta fixed)**

Evidence of fix:
- Service + service pricing domain added to import pipeline and export surface: `repo/app/Services/ImportService.php:93-103`, `:288-351`, `:388-400`.
- Service/pricing API and policy-gated pricing write added: `repo/routes/api.php:133-144`, `repo/app/Http/Controllers/Api/ServiceController.php:27-140`, `repo/app/Policies/ServicePricingPolicy.php:16-41`.
- Service catalog tests added (including facility-scoped pricing): `repo/tests/Feature/ServiceCatalogTest.php:23-120`.
- Facility includes business-hours + address fields: `repo/app/Http/Controllers/Api/FacilityController.php:47-56`, `:89-98`.

Residual note:
- Prompt-level dedup/entity-resolution for services/providers is still not fully evidenced end-to-end; merge support currently excludes `service` (`repo/app/Services/MergeService.php:48-72`).

### 2.4 Medium: facility endpoints not policy-enforced / globally readable

**Previous:** Medium  
**Now:** **Pass (fixed)**

Evidence of fix:
- Facility controller now uses policy checks across list/create/show/update/delete/history: `repo/app/Http/Controllers/Api/FacilityController.php:26`, `:45`, `:73`, `:87`, `:116`, `:150`.
- Query scoping in list endpoint for non-admin facility-bound users: `repo/app/Http/Controllers/Api/FacilityController.php:35-38`.
- Cross-facility access tests added: `repo/tests/Feature/CrossFacilityIsolationTest.php:193-222`.

### 2.5 Medium: import-versioning mismatch

**Previous:** Medium  
**Now:** **Pass (fixed)**

Evidence of fix:
- All import branches now call `DataVersioningService::record()` on create/update (facility, department, inventory item, doctor, patient, rental asset, service, service pricing): `repo/app/Services/ImportService.php:132-137`, `:156-160`, `:184-188`, `:213-217`, `:241-245`, `:281-285`, `:309-313`, `:346-350`.
- Design doc now aligned with this claim: `docs/design.md:365-379`.

## 3) Required Security/Authorization Conclusions

- **authentication entry points:** **Pass**  
  Evidence: public login/refresh/captcha, protected logout/me/change-password, inactivity middleware (`repo/routes/api.php:25-47`, `repo/routes/api.php:40`).

- **route-level authorization:** **Pass**  
  Evidence: broad role middleware coverage including inventory/stocktake/services/reviews/merge (`repo/routes/api.php:107-144`, `:155-223`).

- **object-level authorization:** **Partial Pass**  
  Evidence: substantial controller `authorize(...)` adoption (facility/stocktake/patient/review/etc.) (`repo/app/Http/Controllers/Api/FacilityController.php:26-150`, `repo/app/Http/Controllers/Api/StocktakeController.php:22-110`).  
  Residual: inventory item endpoints still not policy-invoked directly (`repo/app/Http/Controllers/Api/InventoryController.php:29-77`).

- **function-level authorization:** **Partial Pass**  
  Evidence: critical flows protected at route+controller, plus explicit storeroom isolation checks (`repo/app/Http/Controllers/Api/InventoryController.php:231-240`).  
  Residual: core service-layer methods (e.g., inventory) remain caller-trust-based and do not take authorization context (`repo/app/Services/InventoryService.php:22-165`).

- **tenant / user isolation:** **Partial Pass**  
  Evidence: added cross-facility tests for facilities + inventory/stocktake, and controller-level scoping (`repo/tests/Feature/CrossFacilityIsolationTest.php:193-222`, `repo/tests/Feature/InventoryIsolationTest.php:41-180`, `repo/app/Http/Controllers/Api/InventoryController.php:172-207`, `repo/app/Http/Controllers/Api/StocktakeController.php:30-35`).  
  Residual: not all entity-resolution workflows demonstrate tenant-safe behavior across all requested domain entities.

- **admin / internal / debug protection:** **Pass**  
  Evidence: privileged routes role-gated; safer env defaults now (`APP_DEBUG=false`, `LOG_LEVEL=error`) (`repo/routes/api.php:67-73`, `repo/.env.example:4`, `repo/.env.example:18`).

## 4) Tests and Logging Review

- **Unit tests:** Present (`repo/phpunit.xml:8-13`, `repo/tests/Unit/SimHashTest.php`).
- **API/integration tests:** Present and expanded with targeted regression suites (`repo/tests/Feature/MergeExecutionTest.php`, `repo/tests/Feature/InventoryIsolationTest.php`, `repo/tests/Feature/ServiceCatalogTest.php`, `repo/tests/Feature/DataVersioningCoverageTest.php`).
- **Logging categories/observability:** Partial Pass. Explicit audit events across auth/data/export; immutable audit model pattern remains (`repo/app/Services/AuditService.php:35-86`, `repo/app/Models/AuditLog.php`).
- **Sensitive-data leakage risk in logs/responses:** Partial Pass. Recursive redaction exists and tested, but coverage depends on explicit call sites, not global model observers (`repo/app/Services/AuditService.php:20-33`, `:94-112`, `repo/tests/Feature/AuditRedactionTest.php`).

## 5) Test Coverage Assessment (Static)

### 5.1 Test Overview

- Unit + feature suites configured in PHPUnit: `repo/phpunit.xml:8-13`.
- Frontend tests configured via Vitest: `repo/package.json:8-10`, `repo/vitest.config.js:12-37`.
- Test command documentation exists in README (`repo/README.md:60-64`).
- Note: `run_tests.sh` uses Docker (`repo/run_tests.sh:19`, `:28-41`), which is acceptable for project docs but was not executed in this static-only audit.

### 5.2 Coverage Mapping Table

| Requirement / Risk Point | Mapped Test Case(s) | Key Assertion / Fixture / Mock | Coverage Assessment | Gap | Minimum Test Addition |
|---|---|---|---|---|---|
| Merge approval performs real merge | `repo/tests/Feature/MergeExecutionTest.php:29-171` | FK relink + soft-delete + audit + snapshots asserted (`:52-57`, `:121-126`, `:146-147`) | sufficient (for supported types) | No service-entity merge coverage | Add merge execution tests for service/provider entities if supported by product scope |
| Inventory/stocktake cross-facility isolation | `repo/tests/Feature/InventoryIsolationTest.php:41-180` | 403 on foreign storeroom/session + scoped list assertions (`:50-55`, `:141-143`, `:175-179`) | sufficient for previously reported high-risk paths | Inventory item CRUD object-policy invocation not tested | Add endpoint tests asserting policy behavior for item create/update/list if policy-level guarantees are required |
| Facility object/history isolation | `repo/tests/Feature/CrossFacilityIsolationTest.php:193-222` | 403 for foreign facility view/history, scoped list (`:199`, `:208-213`, `:221`) | sufficient | none major | Maintain regression coverage |
| Import versioning breadth | static import implementation + `repo/tests/Feature/DataVersioningCoverageTest.php:24-114` | Version row counts on create/update + revert sample | basically covered | No explicit CSV-path test for every entity type | Add import-driven versioning tests for service and service_pricing CSV rows |
| Public tablet review submit path | `repo/tests/Feature/TabletReviewPublicTest.php:43-129` | Unauthenticated submit success + image/rating validations | sufficient | none major | Maintain regression coverage |
| Dedup heuristics wiring (URL/key-field matching) | `repo/tests/Unit/SimHashTest.php` | Unit-level helper behavior only | insufficient | No integration proof helpers are used in imports/edits for services/providers | Add feature test that import/edit triggers key-field dedup proposal + merge-request creation |

### 5.3 Security Coverage Audit

- **authentication:** Meaningfully covered by feature tests (lockout, CAPTCHA, password policy).
- **route authorization:** Well covered for role-based route access.
- **object-level authorization:** Improved and now covered for many high-risk entities (facilities, stocktake, patient/review/rental/service order).
- **tenant/data isolation:** Stronger than previous report due dedicated inventory/facility isolation tests.
- **admin/internal protection:** Role gates and env hardening improved; deployment-hardening paths still rely on operational discipline.

### 5.4 Final Coverage Judgment

**Partial Pass**

Covered boundary:
- Previously critical defects (merge status-only, facility isolation, inventory/stocktake cross-facility write/read vectors, import-versioning breadth) now have direct static evidence and targeted tests.

Uncovered boundary:
- Dedup/entity-resolution completeness for all prompt-requested domains (especially service-side merge execution and key-field dedup wiring) is not yet end-to-end evidenced; severe defects in those paths could remain while current tests pass.

## 6) Final Notes

- This fix round materially improved the architecture and closed most previously reported material findings.
- Acceptance has moved from “blocked by clear high issues” to “partial pass with narrower residual scope.”
- Conclusions are static-evidence based only; no runtime success was inferred.
