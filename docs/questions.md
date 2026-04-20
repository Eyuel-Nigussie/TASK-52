# Design Questions, Assumptions & Decisions

This document captures non-obvious design decisions made during the VetOps Portal build, grouped as:
- **Question** — what was ambiguous or underspecified
- **Assumption** — what we chose to assume when the Prompt did not say
- **Solution** — how it was implemented

The goal is to make the delivery statically reviewable without guessing at the author's intent.

---

## 1. What field authenticates a user on login — email, username, or both?

- **Assumption:** Veterinary staff typically have short internal usernames; email may not be unique across clinics.
- **Solution:** Login accepts `username` + `password` (see `AuthController::login`, `routes/api.php` `POST /api/auth/login`). Email is a nullable profile field but is not an auth identifier.

## 2. How strict should the inactivity timeout be, and how is it measured?

- **Assumption:** Sanctum's built-in `last_used_at` is overwritten on every request, so it cannot detect idle time. A separate checkpoint is required.
- **Solution:** `InactivityTimeoutMiddleware` keeps a per-token `vetops.token_idle:{token_id}` value in the cache. If the gap between cached last-seen and now exceeds the user's `inactivity_timeout` (minutes, default 15), the token is deleted and the request returns `401 SESSION_EXPIRED`. Covered by `tests/Feature/InactivityTimeoutTest.php`.

## 3. How is rental deposit calculated, and what is the minimum?

- **Assumption:** `VETOPS_DEPOSIT_RATE` (0.20 default) of replacement cost, with a `VETOPS_DEPOSIT_MIN` floor (default $50).
- **Solution:** `RentalAsset::calculateDeposit()` returns `max(replacement_cost * rate, min)`. Applied on create and on replacement-cost updates. Tests: `tests/Unit/RentalPricingTest.php`, `tests/Feature/RentalAssetTest.php::test_deposit_auto_calculated_on_create`, `::test_deposit_minimum_enforced`.

## 4. When is a stocktake variance "material" enough to require manager approval?

- **Assumption:** >5% absolute variance between counted and system quantity triggers approval; threshold is configurable via `VETOPS_STOCKTAKE_VARIANCE_PCT`.
- **Solution:** Variance is calculated in `InventoryService::recordStocktakeEntry`. Entries above threshold get `requires_approval = true`. Session cannot be applied until every such entry is individually approved with a reason ≥10 chars. Tests: `StocktakeTest` (9 cases), `StocktakeVarianceTest`.

## 5. Do duplicate/near-duplicate content items need to be blocked at creation?

- **Assumption:** Yes — to prevent accidental re-posting; authors can override with `force_create=true`.
- **Solution:** `ContentService::draft` computes a 64-bit SimHash fingerprint; if Hamming distance to an existing item ≤ 6, the request returns `422` with a `body` validation error. Bypass via `force_create`. Tests: `ContentPublishingTest::test_near_duplicate_detection_blocks_similar_content`, `::test_force_create_bypasses_dedup_check`, `SimHashTest`.

## 6. How is patient PII (phone number) stored and exposed?

- **Assumption:** Encrypted at rest via Laravel's `encrypt()/decrypt()` helpers; masked in API responses for non-admin users.
- **Solution:** Patient/doctor/facility/user models all store `*_phone_encrypted` columns (`$hidden` in the model). Controllers read `$user->isAdmin() ? ->getPhone() : ->getMaskedPhone()`. The masked format is `(XXX) ***-XXXX`. Tests: `SecurityTest`, `PhoneMaskingTest`, `FacilityTest`, `DoctorPatientTest`, `UserTest`.

## 7. Is the audit log truly immutable, or only "write-mostly"?

- **Assumption:** Immutable. Updates and deletes must be blocked at the model layer to survive direct-ORM mistakes; bulk retention purge is the only legitimate deletion path and runs via a dedicated Artisan command with elevated privileges.
- **Solution:** `AuditLog::boot()` registers `updating` and `deleting` observers that silently abort both operations. The `vetops:purge-audit-logs` command uses `DB::table()` to bypass the immutability hook for retention-based deletion only. Retention window is `VETOPS_AUDIT_RETENTION_YEARS` (default 7). Tests: `AuditLogTest::test_audit_log_immutable`, `::test_audit_log_not_deletable`, `::test_audit_purge_command`.

## 8. Should inventory ledger entries be editable after the fact?

- **Assumption:** No — ledger is append-only to keep reconciliation possible. Corrections happen via offsetting entries (e.g. a stocktake "stocktake" entry), never in-place edits.
- **Solution:** `StockLedger` model silently rejects `update()` and `delete()`. Test: `InventoryTest::test_stock_ledger_is_immutable`.

## 9. How should two reservation strategies behave when stock runs out?

- **Assumption:** Two strategies per `ServiceOrder`: `lock_at_creation` reserves stock immediately (failing fast if unavailable) and decrements ATP; `deduct_at_close` only decrements on-hand on close. The close operation converts reservations to deductions atomically.
- **Solution:** `InventoryService::reserveForOrder` and `closeOrderReservations`. Insufficient-stock returns `422`. Tests: `ServiceOrderTest::test_can_create_order_with_lock_at_creation_strategy`, `::test_reservation_fails_when_insufficient_stock`.

## 10. Is image upload limited per review, and by how much?

- **Assumption:** 5 images maximum per review submission, capped at `VETOPS_UPLOAD_MAX_MB` each (20 MB default).
- **Solution:** Validated at the controller level: `images` array max 5, each `image|max:20480` (kilobytes). Test: `ReviewTest` covers rating bounds and review submission validation limits.

## 11. How is overdue status detected — at request time or in the background?

- **Assumption:** Both. The `RentalTransaction::isOverdue()` method computes it dynamically for any caller; a scheduled `vetops:mark-overdue-rentals` command promotes `active` → `overdue` status, primarily so lists and dashboards filter correctly without computing on every row.
- **Solution:** Threshold is `VETOPS_OVERDUE_HOURS` (2 hours after `expected_return_at`). Tests: `RentalTransactionTest::test_overdue_detection_works`, `::test_mark_overdue_command_updates_status`, `RentalPricingTest::test_overdue_detection_2_hours_threshold`.

## 12. How is the login rate limit split across captcha vs lockout?

- **Assumption:** A CAPTCHA challenge is shown after 5 failures (`VETOPS_CAPTCHA_AFTER`); the account/IP is fully locked out after 10 failures (`VETOPS_MAX_LOGIN_ATTEMPTS`) within the 10-minute rolling window (`VETOPS_LOGIN_WINDOW_MINUTES`). Route also carries a Laravel `throttle:10,10` middleware as a second line of defence.
- **Solution:** `AuthService::attempt` counts `LoginAttempt` rows for the IP over the window, returning `captcha_required` in the response once the CAPTCHA threshold is crossed and hard-failing once the max is crossed. Tests: `AuthTest::test_login_blocked_after_max_attempts`, `::test_captcha_required_after_threshold_failures`, `::test_login_response_contains_captcha_flag_after_threshold`.
