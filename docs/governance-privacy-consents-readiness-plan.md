# Governance / Privacy / Consents — production-readiness plan

> Implementation completed in this checkout on 2026-05-02. This record mirrors
> the structure of
> [`docs/control-room-readiness-plan.md`](control-room-readiness-plan.md) and
> [`docs/emar-meds-readiness-plan.md`](emar-meds-readiness-plan.md). Companion
> implementation reference: [`docs/consent-requests.md`](consent-requests.md).

## Verdict

**Original verdict: strong foundation, partial wiring.** Privacy has 47 active routes covering
the full GDPR/Privacy-Act surface (DSR, breach, DPIA, legal hold, retention,
deletion log, dashboard, reports). Governance has ~50 frontend pages and a
mature domain layer at [`app/Domain/Governance/`](../app/Domain/Governance/)
with services, jobs, notifications, and policies. Consent has a complete
state-machine in [`ConsentRequestService`](../app/Services/ConsentRequestService.php),
a registered policy pair, five RBAC permissions, two scheduled commands, and
cross-module enforcement on Security Devices tracker assignments.

At audit time, three concrete things explained the "Partial" rating:

1. **`routes/consents.php` was disabled.** [`routes/web.php:183`](../routes/web.php#L183)
   commented it out with `// TODO: Controllers not yet implemented`. The
   controller, four pages, the service, the factory, the policy, the RBAC
   permission, and a feature test for the staff consent-request flow all
   existed - but the routes did not dispatch. Every staff user clicking the
   "Consent Requests" tab on a client profile got a 404.
2. **The disabled file was unsafe to enable as-is.** It had only
   `use Illuminate\Support\Facades\Route;` - no controller imports - yet
   references bare classnames `ConsentTypeController`, `ClientConsentController`,
   `ConsentReportController`, `ConsentWithdrawalRequestController`. Three of
   those did not exist; the fourth was already wired in
   [`routes/operations.php`](../routes/operations.php). Only the four
   FQN-qualified `Operations\ConsentRequestController::class` routes at the
   bottom would actually work.
3. **Privacy test suite had 18 failures of 62.** Status-enum drift between
   tests (`pending_verification`) and controller writes
   (`identity_verification`); see
   [`DataSubjectRequestController.php:86`](../app/Http/Controllers/DataSubjectRequestController.php#L86)
   vs
   [`PrivacyControllerTest.php:548`](../tests/Feature/PrivacyControllerTest.php#L548).

**No redesign required, no schema changes required.** The completed work is a
routes-file cleanup, privacy test alignment, permission cleanup, removal of
dormant model relationships, defense-in-depth permission gates, retention-job
coverage, finance job extraction, and a Playwright smoke layer.

---

## Implementation status

> All planned items are implemented in this checkout.

| ID | Description | Status | Notes |
|---|---|---|---|
| **P0-1** | Re-enable staff consent-request routes | ✅ Complete | Staff routes now live in [`routes/operations.php`](../routes/operations.php); disabled route file deleted |
| **P0-2** | Fix 18 PrivacyControllerTest enum failures | ✅ Complete | `php artisan test --filter=Privacy` passes 198 tests / 955 assertions |
| **P1-1** | Remove dead consent export permission + dead controller refs | ✅ Complete | Permission removed from seeders and shared capabilities; dead route file removed |
| **P1-2** | Playwright spec for consent-request flow | ✅ Complete | [`tests/e2e/operations-clients-consent-requests.spec.ts`](../tests/e2e/operations-clients-consent-requests.spec.ts) |
| **P1-3** | Playwright spec for privacy DSR + breach lifecycle | ✅ Complete | [`tests/e2e/privacy-dsr-and-breach-lifecycle.spec.ts`](../tests/e2e/privacy-dsr-and-breach-lifecycle.spec.ts) |
| **P1-4** | Pest test for `EnforceDataRetentionJob` | ✅ Complete | [`tests/Feature/Privacy/EnforceDataRetentionJobTest.php`](../tests/Feature/Privacy/EnforceDataRetentionJobTest.php) |
| **P1-5** | Defense-in-depth permission gates in privacy controllers | ✅ Complete | DSR, DPIA, retention, deletion-log, and dashboard now guard in-controller |
| **P1-6** | Delete dormant `ClientConsent` relationships to non-existent models | ✅ Complete | Broken relationship methods removed |
| **P2-1** | Drop stale `Route::redirect('/consents', '/operations/clients')` | ✅ Complete | Legacy redirect removed |
| **P2-2** | Extract `pruneFinanceAuditExports` from generic retention job | ✅ Complete | [`PruneFinanceAuditExportsJob`](../app/Domain/Finance/Jobs/PruneFinanceAuditExportsJob.php) scheduled separately |
| **P2-3** | Document or unify HR vetting consent ↔ ClientConsent split | ✅ Complete | Split documented in [`docs/consent-requests.md`](consent-requests.md) |
| **P2-4** | Use `ConsentRequestPolicy::respond` consistently or delete it | ✅ Complete | Portal controller now authorizes through the policy |

## Post-implementation status

The readiness work is implemented end-to-end without schema changes. Staff consent-request routes are enabled under Operations, the stale `/consents` surface is gone, privacy controller authorization has defense-in-depth guards, the retention job is covered by focused Pest tests, finance audit export pruning has moved into the finance domain, and the browser layer covers the two high-risk operator flows.

Verification completed:

- `php artisan test --filter=Governance` - 88 tests / 567 assertions.
- `php artisan test --filter=Consent` - 28 tests / 135 assertions.
- `php artisan test --filter=Privacy` - 198 tests / 955 assertions.
- `php artisan test --filter=EnforceDataRetentionJob` - 5 tests / 13 assertions.
- `php artisan route:list --path=consent`, `--path=privacy`, and `--path=governance` all complete.
- `php artisan route:cache` succeeds, followed by `php artisan route:clear`.
- `npm run types` and `npm run build` pass.
- `npx playwright test -c playwright.config.ts tests/e2e/operations-clients-consent-requests.spec.ts tests/e2e/privacy-dsr-and-breach-lifecycle.spec.ts` passes 4/4 across desktop and mobile projects.

Residual suite signal:

- `npm run visual:test` was attempted for the full browser suite and timed out after 30 minutes. The artifacts produced before timeout point to unrelated pre-existing broad-suite failures in My Day accessibility, rostering dashboard/template coverage, and app-shell visual baselines; the new governance/privacy/consent specs passed independently on both configured projects.

---

## 1. Why this was flagged "Partial" (evidence-based)

At audit time, `php artisan route:list --path=consent` returned 11 routes. The staff-side
`operations/clients/{client}/consent-requests/*` group was absent - confirmed
by the then-failing test
[`ConsentRequestFlowTest::test_staff_creates_consent_request_and_recipient_is_notified`](../tests/Feature/Consents/ConsentRequestFlowTest.php#L62)
which posted to that URL and received HTTP 404.

`php artisan test --filter=PrivacyControllerTest` produced 18F/44P; the first
failure is the enum drift cited above. The remaining 17 are likely the same
class of drift across the test file's setup helpers.

The legacy consent export permission was referenced only by disabled or stale
surfaces - dead code.

`grep -rn "ConsentTypeController\|ConsentReportController\|ConsentWithdrawalRequestController" app/Http/Controllers/`
returns nothing — those controllers do not exist.

[`ClientConsent.php:150-168`](../app/Models/ClientConsent.php#L150) declared
`hasMany` relationships to `ConsentWithdrawalRequest`, `ConsentAuditLog`, and
`ConsentReminder`. The DB tables exist (created by
[`2026_01_28_000002_create_consent_tables.php`](../database/migrations/2026_01_28_000002_create_consent_tables.php))
but the model classes do not. At audit time these methods were dormant; they
would have fatal-errored on first caller.

---

## 2. Module map

### Active and complete

| Surface | Evidence |
|---|---|
| Privacy module — 47 active routes | `php artisan route:list --path=privacy` |
| Privacy controllers | DSR, Breach, DPIA, LegalHold, DataRetention, DataDeletionLog, PrivacyDashboard, PrivacyReport — all present |
| Privacy frontend | 21 pages under [`resources/js/pages/privacy/`](../resources/js/pages/privacy/) |
| Governance module | [`routes/governance.php`](../routes/governance.php) (337 lines), [`app/Domain/Governance/`](../app/Domain/Governance/), ~50 pages, 11 feature + 6 browser + 2 unit tests |
| Consent core CRUD | [`Operations\ClientConsentController`](../app/Http/Controllers/Operations/ClientConsentController.php) wired at `/operations/clients/{client}/consents` |
| Portal-side consent flow | [`ConsentRequestPortalController`](../app/Http/Controllers/Portal/ConsentRequestPortalController.php) wired in [`routes/portal.php:70-75`](../routes/portal.php#L70) |
| Family dashboard surface | [`FamilyDashboardController`](../app/Http/Controllers/Portal/FamilyDashboardController.php) lines 205, 249, 365 surface pendingConsentRequests |
| Models | `ClientConsent`, `ConsentType`, `ConsentTypeVersion`, `ConsentRequest` |
| Services | [`ConsentValidationService`](../app/Services/ConsentValidationService.php), [`ConsentRequestService`](../app/Services/ConsentRequestService.php) |
| Policies | [`ClientConsentPolicy`](../app/Policies/ClientConsentPolicy.php), [`ConsentRequestPolicy`](../app/Policies/ConsentRequestPolicy.php) — both registered in [`AuthServiceProvider.php:139-140`](../app/Providers/AuthServiceProvider.php#L139) |
| RBAC | 5 `consents.*` + 6 `privacy.*` permissions seeded + role-mapped in [`RbacSeeder`](../database/seeders/RbacSeeder.php) |
| Inertia capability share | [`HandleInertiaRequests.php:496-521`](../app/Http/Middleware/HandleInertiaRequests.php#L496) |
| Scheduled jobs | `consent-requests:expire-stale` (hourly), `consent-requests:send-reminders` (hourly), `EnforceDataRetentionJob` (daily 03:00), `governance:sync-clinical-data` (daily 00:20) — all in [`routes/console.php`](../routes/console.php) |

### Resolved disabled / dead audit findings

| Item | Disposition |
|---|---|
| `routes/consents.php` | Deleted; staff consent-request routes now live in [`routes/operations.php`](../routes/operations.php) |
| Stale legacy redirect | Removed from [`routes/web.php`](../routes/web.php) |
| Legacy consent export permission | Removed from seeders and shared capabilities; no active route depends on it |
| Three missing controllers | No longer referenced by active routes |
| Three dormant `ClientConsent` relationships | Removed from [`ClientConsent`](../app/Models/ClientConsent.php); underlying tables can remain inert |

### Duplicated

`routes/consents.php` lines 28-40 redefine `clients/{client}/consents`
index/store/update/withdraw — already on
[`routes/operations.php:132-258`](../routes/operations.php#L132) with proper
namespacing. Deleting the disabled file avoids route-name clashes.

### Partially wired

**Staff-side consent-request UI** — frontend pages
[`Create.tsx`](../resources/js/pages/operations/clients/consent-requests/Create.tsx),
[`Index.tsx`](../resources/js/pages/operations/clients/consent-requests/Index.tsx),
[`Show.tsx`](../resources/js/pages/operations/clients/consent-requests/Show.tsx),
controller, service, factory, policy, seeder, and feature test all exist;
**only the route registration was missing**. It is now registered under Operations.

**Client profile "Consent Requests" tab** —
[`show.tsx:640-647`](../resources/js/pages/operations/clients/show.tsx#L640)
renders an unconditional link (`show: true`, no permission gate) to
`/operations/clients/{id}/consent-requests`, which now loads the consent-request index.

---

## 3. Cross-module integration map

| Source module | Touchpoint | Integration | Status |
|---|---|---|---|
| Operations / Clients | [`ClientConsentController`](../app/Http/Controllers/Operations/ClientConsentController.php) index/store/withdraw | Direct CRUD on `ClientConsent` | ✅ Working |
| Family Portal | [`ConsentRequestPortalController::approve`](../app/Http/Controllers/Portal/ConsentRequestPortalController.php) | Calls `ConsentRequestService::approve` which materialises a `ClientConsent` row, sets `evidence_type='portal_signature'`, and copies substituted-decision metadata when relationship is welfare guardian / EPOA / parent / court-appointed | ✅ Working |
| Family Portal | `FamilyDashboardController` | Surfaces `pendingConsentRequests` count + list | ✅ Working |
| Security Devices / Fleet | [`DeviceAssignmentService`](../app/Domain/SecurityDevices/Services/DeviceAssignmentService.php) | Rejects client-tracker assignments without a valid `consent_id`; tested by [`DeviceAssignmentConsentEnforcementTest`](../tests/Feature/Consents/DeviceAssignmentConsentEnforcementTest.php) | ✅ Working |
| Security Devices / Fleet | [`FleetAssets\DeviceController::grantConsent`](../app/Http/Controllers/FleetAssets/DeviceController.php) | Auto-creates a "Location Tracking" `ConsentType` and writes a `ClientConsent` row | ✅ Working |
| HR Vetting | [`Hr\VettingController::captureConsent`](../app/Http/Controllers/Hr/VettingController.php) | Records consent-to-background-check **into `StaffBackgroundCheck.notes`** — does NOT create a `ClientConsent` row | ⚠️ Intentional staff-vs-client split, but uncoordinated with the unified consent register |
| Privacy / DSR | [`DataSubjectRequestController::export`](../app/Http/Controllers/DataSubjectRequestController.php) | Includes the client's `ClientConsent` records in the JSON export | ✅ Working |
| Privacy / Retention | [`EnforceDataRetentionJob`](../app/Jobs/EnforceDataRetentionJob.php) | Honours `legal_hold_exemption` and `active_case_exemption`; covered by focused Pest tests | ✅ Working |
| Finance / Audit exports | [`PruneFinanceAuditExportsJob`](../app/Domain/Finance/Jobs/PruneFinanceAuditExportsJob.php) | Finance export pruning runs from the finance domain rather than the generic privacy retention job | ✅ Working |
| Governance / Clinical | `governance:sync-clinical-data` daily 00:20 | Pulls eMAR + Health & Clinical metrics into `ClinicalGovernanceIndicator` | ✅ Working |
| Scheduled | `consent-requests:expire-stale` hourly | Flips overdue `pending` → `expired` | ✅ Working |
| Scheduled | `consent-requests:send-reminders` hourly, idempotent | Sends `ConsentRequestReminderNotification` and writes `reminder_sent` audit event | ✅ Working |
| Notifications | 4 consent notifications under [`app/Notifications/Operations/`](../app/Notifications/Operations/) | All present | ✅ Working |
| Audit evidence | `AuditableChanges` trait on `ClientConsent`, append-only `audit_trail` JSON on `ConsentRequest` | ✅ Working |

---

## 4. Original production-readiness gaps (now resolved)

### P0 — must fix before production

**P0-1 — Re-enable staff consent-request routes.** Before implementation,
every staff user clicking "Consent Requests" on any client profile got a 404. The controller,
pages, service, factory, policy, RBAC permission (`consents.request`), test,
and dashboard count all existed; only routes were missing.

**P0-2 — Privacy test suite was failing (18 / 62)** primarily because of the
`pending_verification` vs `identity_verification` enum mismatch. Either the
controller's seed value or the test's expected value is wrong; without tests
passing, the privacy module can't be considered production-ready.

### P1 — important before production

**P1-1 — Remove or implement** the legacy consent export permission and the three
missing controllers (`ConsentTypeController`, `ConsentReportController`,
`ConsentWithdrawalRequestController`) referenced by the dead
`routes/consents.php`. Conservative call: delete the dead permission and the
dead route file rather than ship three new pages.

**P1-2 — Playwright spec for consent-request flow.** Project's modern e2e
framework is Playwright (25+ specs under [`tests/e2e/`](../tests/e2e/)). The
existing [`tests/Browser/Consents/ConsentsTest.php`](../tests/Browser/Consents/ConsentsTest.php)
was a 13-line Dusk smoke test that visited the deprecated `/consents` redirect.
There was no Playwright coverage for the actual staff-create → portal-approve loop.

**P1-3 — Playwright spec for privacy DSR + breach lifecycle.** No e2e
coverage for the 47 privacy routes — only a Pest controller test (which was
then 18-failing).

**P1-4 — Pest test for `EnforceDataRetentionJob`.** Job has soft-delete /
anonymize / archive phases plus legal-hold exemption plus finance-export
pruning. **Untested code that mutates production data is a high-risk surface.**

**P1-5 — Inconsistent permission gating** in privacy controllers.
[`DataBreachController`](../app/Http/Controllers/DataBreachController.php)
and [`LegalHoldController`](../app/Http/Controllers/LegalHoldController.php)
have explicit `abort_unless($user->canDo(...))` guards.
[`DataSubjectRequestController`](../app/Http/Controllers/DataSubjectRequestController.php),
[`DPIAController`](../app/Http/Controllers/DPIAController.php),
[`DataRetentionPolicyController`](../app/Http/Controllers/DataRetentionPolicyController.php),
[`DataDeletionLogController`](../app/Http/Controllers/DataDeletionLogController.php),
and [`PrivacyDashboardController`](../app/Http/Controllers/PrivacyDashboardController.php)
rely solely on route middleware. Defense-in-depth would gate both.

**P1-6 — Stale `ClientConsent` relationships.**
[`ClientConsent.php:150-168`](../app/Models/ClientConsent.php#L150) declared
`hasMany` to three non-existent models. They were dormant but a fatal-error
trap for any contributor.

### P2 — polish

**P2-1 — Stale `/consents → /operations/clients` redirect** at
[`routes/web.php:231`](../routes/web.php#L231). Misleading; the URL pattern
is older than the operations layout and the destination is the clients list,
not a consent overview.

**P2-2 — Hardcoded finance coupling** in
[`EnforceDataRetentionJob::pruneFinanceAuditExports()`](../app/Jobs/EnforceDataRetentionJob.php#L42).
Should be a separate `App\Domain\Finance\Jobs\PruneFinanceAuditExportsJob` so
future modules don't keep extending the generic job.

**P2-3 — HR vetting consent ↔ unified consent register.**
[`Hr\VettingController::captureConsent`](../app/Http/Controllers/Hr/VettingController.php)
writes to vetting notes only. Document the deliberate split, or add a
`StaffConsent`-style record so HR consent shows up in compliance reports.

**P2-4 — `ConsentRequestPolicy::respond`** is defined but the portal
controller checks authorization manually via `authoriseRespondent()`. Use the
policy consistently or delete the unused method.

---

## 5. Implemented plan (small PR-sized phases)

Each phase is independently shippable, has its own acceptance criteria, and
produces a clean test signal before the next.

### Phase A — Re-enable staff consent-requests (P0-1) — 1 PR, small

- Either rewrite [`routes/consents.php`](../routes/consents.php) to **only**
  contain the consent-requests group with proper
  `use App\Http\Controllers\Operations\ConsentRequestController;` import, OR
  (preferred) move the consent-requests route group into
  [`routes/operations.php`](../routes/operations.php) under the existing
  `Route::middleware(['auth'])->prefix('operations')` block, and **delete**
  `routes/consents.php` entirely (along with the `require` line in
  [`routes/web.php:183`](../routes/web.php#L183)).
- Drop the
  [`Route::redirect('/consents', '/operations/clients')`](../routes/web.php#L231)
  line because there's no `/consents` URL left to redirect from.
- Confirm tests pass.

### Phase B — Fix privacy test/controller status drift (P0-2) — 1 PR, small

- Reconcile `pending_verification` vs `identity_verification`. The
  controller's `identity_verification` matches the validation rule list at
  [`DataSubjectRequestController.php:120`](../app/Http/Controllers/DataSubjectRequestController.php#L120) —
  make the test match. Update the 5 test setups in
  [`PrivacyControllerTest.php`](../tests/Feature/PrivacyControllerTest.php)
  (lines 63, 548, 865, 1992, 2046).
- Run `php artisan test --filter=PrivacyControllerTest` to surface and fix the
  remaining 13 failures (likely related drifts of the same kind).

### Phase C — Remove dead consent surfaces (P1-1, P1-6) — 1 PR, small

- Delete the legacy consent export permission from
  [`RbacSeeder.php:399, 541`](../database/seeders/RbacSeeder.php#L399) and
  [`DuskDatabaseSeeder.php:58`](../database/seeders/DuskDatabaseSeeder.php#L58).
- Delete the three dormant relationships (`withdrawalRequests`, `auditLogs`,
  `reminders`) from
  [`ClientConsent.php:150-168`](../app/Models/ClientConsent.php#L150). The
  underlying tables can stay (cheap, no callers).
- Add a section to [`docs/consent-requests.md`](consent-requests.md)
  documenting that consent reports / withdrawal-request workflow / consent-types
  admin are intentionally out of scope for v1.

### Phase D — Playwright e2e coverage (P1-2, P1-3) — 1 PR, medium

The project's modern e2e framework is Playwright at
[`tests/e2e/`](../tests/e2e/) (config:
[`playwright.config.ts`](../playwright.config.ts), helpers:
[`tests/e2e/helpers.ts`](../tests/e2e/helpers.ts), commands:
`npm run visual:test` and `npm run visual:update`). Two new specs under the
existing convention:

**`tests/e2e/operations-clients-consent-requests.spec.ts`**
- Login as admin via `loginAsStaff(page)` helper.
- Navigate to `/operations/clients/{seeded-client-id}/consent-requests` and
  assert the page renders (`data-test="consent-requests-index"` or similar).
- Click "Create" → fill the form (consent type, recipient, purpose,
  justification, retention, withdrawal text) → submit.
- Assert redirect back to index, success flash, and a new row in the list
  with status "pending".
- Logout, login as the seeded family-portal user, navigate to
  `/portal/clients/{client-id}/dashboard`.
- Assert the pending consent request card is visible, click through to the
  detail page.
- Tick the "I have authority" box, fill response notes, click approve.
- Assert redirect to dashboard with success flash.
- Logout, login back as admin, return to the staff index.
- Assert request now shows status "approved" and a `resulting_consent_id`
  link to the new `ClientConsent` row.
- Mobile project (`chromium-mobile`) covers the same flow at Pixel 7
  viewport — many family-portal users are mobile-first.

**`tests/e2e/privacy-dsr-and-breach-lifecycle.spec.ts`**
- DSR: create a request → verify identity → mark in_progress → export → mark
  complete; assert each state transition shows the right status badge and
  audit trail entry.
- Breach: create with `requires_authority_notification=true` → notify ICO →
  notify subjects → resolve; assert 72-hour countdown banner appears for
  the unnotified state.
- Permission boundary: log in as a coordinator (no privacy permissions),
  hit `/privacy/dashboard` directly, assert 403.

These two specs add the operator-facing smoke layer. Add `data-test`
attributes to the existing pages where missing — the convention is
`testIdAttribute: 'data-test'` per [`playwright.config.ts:40`](../playwright.config.ts#L40).

### Phase E — Pest test for retention job (P1-4) — 1 PR, small

- Add `tests/Feature/Privacy/EnforceDataRetentionJobTest.php` covering:
  soft-delete past `retention_period_years`, anonymize past
  `hard_delete_after_years`, archive past `archive_after_years`, **skip
  records under active legal hold**, prune finance audit exports.
- Use `Storage::fake()` for the finance export prune branch.
- Use `Carbon::setTestNow()` to simulate cutoffs without seeding ancient
  fixtures.

### Phase F — Defense-in-depth permission gates (P1-5) — 1 PR, small

- Add `abort_unless($request->user()?->canDo($permission), 403)` to the five
  privacy controllers that previously relied solely on route middleware:
  DSR, DPIA, retention, deletion-log, dashboard.
- No new tests needed — existing Pest tests should still pass; if any test
  was reaching these endpoints without the right permission via a backdoor,
  the test was wrong.

### Phase G — Polish (P2-1 through P2-4) — 1 PR, small

- Drop stale [`Route::redirect('/consents', '/operations/clients')`](../routes/web.php#L231)
  if Phase A didn't already.
- Extract `pruneFinanceAuditExports` from `EnforceDataRetentionJob` into
  `App\Domain\Finance\Jobs\PruneFinanceAuditExportsJob`, scheduled at the
  same cadence; the generic retention job stops importing finance domain.
- Either use `ConsentRequestPolicy::respond` in
  [`ConsentRequestPortalController::authoriseRespondent`](../app/Http/Controllers/Portal/ConsentRequestPortalController.php#L109)
  or remove the unused method.
- Add a short note to [`docs/consent-requests.md`](consent-requests.md)
  explaining that staff-vetting consent (PVCB) is recorded against
  `StaffBackgroundCheck`, not `ClientConsent`, and the rationale.

---

## 6. Acceptance criteria per phase

| Phase | Acceptance criteria |
|---|---|
| **A** | `php artisan route:list --path=consent` shows the 4 staff-side `operations.clients.consent-requests.*` routes. `php artisan test --filter=ConsentRequestFlowTest` is green (was 1+ failure cascade). Clicking "Consent Requests" tab in any client profile loads the index page. No route name collisions. `php artisan route:cache` succeeds. |
| **B** | `php artisan test --filter=PrivacyControllerTest` passes 62/62. Status enums match between schema, controller, and test. |
| **C** | Legacy consent export permission not present in seeders, shared capabilities, or active code search. `ClientConsent` model has no broken relationship methods. `php artisan test` overall doesn't regress. |
| **D** | Targeted Playwright run for the two new specs is green on `chromium-desktop` and `chromium-mobile`. New `data-test` attributes added to consent + privacy pages where missing. Full `npm run visual:test` was attempted and exposed unrelated broad-suite residual failures documented above. |
| **E** | `php artisan test --filter=EnforceDataRetentionJob` covers all 3 phases + legal-hold exemption + finance-export prune. |
| **F** | Hitting any privacy URL with the route middleware mistakenly bypassed (e.g. by a future routing change) still returns 403 from the controller. No regressions in existing tests. |
| **G** | Removed redirect — no test depends on it. Finance retention prune lives in finance domain only. Consent-requests doc updated. |

---

## 7. Verification commands

The implementation was verified with these commands:

```bash
# Pest / PHPUnit
php artisan test --filter=Governance
php artisan test --filter=Consent
php artisan test --filter=Privacy
php artisan test --filter=EnforceDataRetentionJob   # Phase E onward

# Routes + caching
php artisan route:list --path=consent
php artisan route:list --path=privacy
php artisan route:list --path=governance
php artisan route:cache

# Frontend
npm run types
npm run build
npx playwright test -c playwright.config.ts tests/e2e/operations-clients-consent-requests.spec.ts tests/e2e/privacy-dsr-and-breach-lifecycle.spec.ts
npm run visual:test                                  # attempted full Playwright suite; see residual suite signal above
```

For Phase A, [`ConsentRequestFlowTest`](../tests/Feature/Consents/ConsentRequestFlowTest.php)
now shows all 11 tests green.

For Phase B, [`PrivacyControllerTest`](../tests/Feature/PrivacyControllerTest.php)
is aligned with the current controller statuses; the broader `Privacy` filter
passes 198 tests / 955 assertions.

For Phase D, the two new Playwright specs must run green on both
`chromium-desktop` and `chromium-mobile` projects. CI baseline is
`PLAYWRIGHT_BASELINE_ENV=default`; local dev uses `php_builtin`. See
[`playwright.config.ts:4-8`](../playwright.config.ts#L4).

---

## 8. Do not touch / avoid rewrite

- **The Governance domain.** [`app/Domain/Governance/`](../app/Domain/Governance/)
  is large, well-tested (11 feature + 6 browser + 2 unit tests), and
  currently rated complete. No refactor as part of this readiness work.
- **`ClientConsent` schema.** The migration is rich and substantively
  correct: capacity assessment, best-interests decision, evidence-type, and
  substitution metadata are all present. No schema changes.
- **`ConsentRequestService` state machine.** It has audit-trail append, IP/UA
  capture, transactional `approve` that materialises a `ClientConsent`, and
  idempotent reminder logic. Trust it.
- **`Operations\ClientConsentController` index page.**
  [`Index.tsx`](../resources/js/pages/operations/clients/consents/Index.tsx)
  covers record-via-dialog and withdraw-via-dialog. Don't add Create / Edit /
  Show pages unless a UX gap is reported — the single-page-with-dialogs flow
  is intentional and concise.
- **`DataBreachController` 72-hour notify-ICO flow,
  [`DPIAController`](../app/Http/Controllers/DPIAController.php) workflow,
  [`LegalHoldController`](../app/Http/Controllers/LegalHoldController.php)
  lifecycle.** All complete.
- **Cross-module consent enforcement on Security Devices.**
  [`DeviceAssignmentService`](../app/Domain/SecurityDevices/Services/DeviceAssignmentService.php)
  already throws if a client-tracker is assigned without a valid
  `consent_id`; tested.
- **Scheduled jobs.** Don't reschedule or restructure
  `consent-requests:expire-stale`, `consent-requests:send-reminders`,
  `EnforceDataRetentionJob`, or governance jobs. They're working.
- **HR vetting consent.** It writes to `StaffBackgroundCheck.notes`
  deliberately — staff vetting is not the same data subject as client
  consent. Resist the urge to merge them.
- **Existing Dusk smoke at
  [`tests/Browser/Consents/ConsentsTest.php`](../tests/Browser/Consents/ConsentsTest.php).**
  Leave in place; it's harmless. The Phase D Playwright specs are the new
  source of truth for browser coverage.

---

## 9. Larger redesign? — No

There is no evidence in the repo that a larger redesign is necessary.
Specifically:

- **Models, migrations, seeders, factories, services, policies,
  notifications, scheduled commands, RBAC permissions, and frontend pages
  already exist** for the entire consent + consent-request workflow. The
  lift to make it production-ready is removing one comment in
  [`web.php`](../routes/web.php), fixing the bare classnames in one routes
  file (or relocating four routes), restoring test/controller alignment in
  privacy, and adding two Playwright specs.
- The single largest risk surface — `EnforceDataRetentionJob` — is
  functional but untested. Adding tests is a smaller change than rewriting
  the job.
- The privacy / breach / DPIA / legal-hold / retention controllers are
  individually complete; their failing tests are drift, not architecture.

If a redesign were unavoidable (it isn't), the smaller alternative is
always: **enable the existing surface, write tests for the existing surface,
fix what tests reveal**. That's exactly what this plan does.

---

**TL;DR** — The flag is a routing oversight (one comment in `web.php`, plus a
file that's never loaded and wouldn't load anyway because of bare classnames),
plus 18 stale assertions in a privacy test file, plus dead permissions, plus
dormant model relationships, plus missing Playwright e2e coverage. The actual
implementation is mostly there. Seven small PRs in the order above will make
the module production-ready without churn.
