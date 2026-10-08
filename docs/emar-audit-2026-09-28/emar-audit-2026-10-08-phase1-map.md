# eMAR consolidated current-state map

**Baseline**
- `origin/main` is **58a7cae79**: the PR #21 merge, 2026-10-08 21:50 +1300. A fresh fetch found no commits after it.
- Legend: **[V]** = I re-checked this on origin/main in this pass using git reads. **[M]** = taken from the five maps and not re-checked. **[?]** = uncertain.
- This worktree (`busy-shirley-877f95`, branch `claude/codex-emar-audit-review-e681d3`) is stale: its HEAD 52dafa672 is **627 behind main and 0 ahead** [V]. Two files exist only here, untracked: `Approval-record.md` and `docs/emar-audit-2026-09-28/`, which includes `claude-second-review/Approval-record.md`. They are not on main and could be lost. All new eMAR work has to branch from origin/main, not from this HEAD.

---

## 1. What is built and live on main

### Merged PRs [M]
| PR | Merge commit | Date | What it added |
|---|---|---|---|
| #16 | 411de15ec | 6 Oct | 255 commits: all 14 approved packages |
| #17 | bcfd3660d | 7 Oct | Forgotten-PIN fallback; Away from actual client leave; Away from hospital admission/discharge |
| #18 | 12d5665c4 | 7 Oct | Connected care (pharmacy bridge, outside prescribers, transfers, catalogue, backups); MAR entry points |
| #19 | 56856f069 | 8 Oct | Backups sent through central Settings → Email. Also carries roster commits 7b0b46a14 and a82855547 |
| #21 | 58a7cae79 | 8 Oct | Fixes for 28 journey-audit findings: canonical allergies, recording recovery, entry points, SSR |

There is no PR #20. No `codex/emar-*` branch has commits that main lacks.

### Hubs
All 7 hubs come from `resources/js/lib/emar-navigation.ts` (EMAR_HUBS). They are wired in through:
- `app-sidebar.tsx:687` `emarSidebar(can)`
- `emarSearchEntries` (around `:3093`)
- `hooks/use-emar-breadcrumbs.ts`
- the auth.can flags at `HandleInertiaRequests.php:641-673`

A support worker whose only hub is Meds today gets a single "Meds today" link with an overdue badge (`HandleInertiaRequests.php:134-147`).

| Hub | Main routes → page | Packages |
|---|---|---|
| 1 Meds today | `meds.today` `/meds/today` → `WorkerMedsController::today` → `pages/meds/today/index.tsx`. Views: schedule, rounds, followups, asneeded, stockalerts, activity, controlled (`:991`). Writes: `meds.today.record` / `meds.today.prn` (`medications.administer.record`). Also `/meds/today/recording-status`, the requirements endpoints, and `/meds/confirmations/{id}` (PIN-2) | P01, P07a, P08a |
| 2 MAR & medicines | `/emar/mar` → `PersonMedicationRecordController::show` → `emar/record/show.tsx`, or the hub `emar/record/hub.tsx`. Also `/emar/medications`, `/emar/prn`, `/emar/self-admin` (`SelfAdmin.tsx`, `SupportRecord.tsx`), the `/emar/clients/{client}/record/*` JSON endpoints, and `emar.clients.day` (`ClientMedicationDayController`) → the profile MAR tab `operations/clients/tabs/mar.tsx` (P02-1b) | P02, P02-1b, P03 |
| 3 Orders & reviews | `/emar/prescriptions[?view=to_check\|covert\|reconciliation]` → `MedicationOrdersController::index` → `emar/Orders.tsx`. Also `/emar/reviews` → `reviews/index.tsx`, `/emar/connected-care` → `ConnectedCare.tsx`, and the legacy `/emar/prescriptions/legacy` → `Prescriptions.tsx` | P04, P05, connected care |
| 4 Stock & controlled | `/emar/stock` → `StockManagement.tsx` (legacy). `/emar/stock/packs` → `MedicationStockController` → `stock/StockHub.tsx` (P06). `/emar/controlled[?view=discrepancies\|losses]` → `ControlledRegister.tsx`. `/emar/destructions` → `Destructions.tsx` | P06, P07b |
| 5 Safety & oversight | `/emar` → `emar/Index.tsx`. Also `/medication-followups` → `Followups.tsx`, `/emar/errors` → `MedicationErrors.tsx`, `/emar/handovers` → `Handovers.tsx`, `/emar/safety/eligibility` → `StaffEligibility.tsx`, `/emar/safety/witness-overrides` → `WitnessOverrides.tsx`, `/emar/emergency-access` → `pages/emergency/access.tsx` | P07b, P08a, P08b, P10, P11 |
| 6 Reports & audit | `/emar/reports` → `reports/hub.tsx` (standard, audit, exports, downtime pack `_downtime-pack.tsx`). Also `/emar/reports/history[/logs]`, `/emar/reports/builder`, and `/emar/backups` (not approved) | P09, P10 pack |
| 7 Settings | `/emar/settings` → `Settings.tsx` (`#view/tab`; `settings/_nav.ts`). Also `/emar/connections` → `ConnectedServices.tsx`, `/emar/pharmacy-connections`, `/emar/catalogue`, and the personal `/settings/witness-pin` | P11, P00 |

These surfaces are live but have no nav entry:
- `/emar/downtime` (+ show), handled by `MedicationDowntimeController`
- `/clinical-portal`
- the pharmacy connections and catalogue pages (reached only through Connected services cards)

### Status by package [M, mockup comparison]
| Package | Status |
|---|---|
| P00 | Partial |
| P01 | Partial |
| P02 | Partial |
| P03 | Mostly built |
| P04 | Partial (team_lead grant missing) |
| P05 | Built |
| P06 | Built differently: two stock surfaces |
| P07a | Mostly built |
| P07b | Mostly built |
| P08a | Partial |
| P08b | Mostly built |
| P09 | Built |
| P10 | Partial on nav; paper posting built |
| P11 | Mostly built |

Built without an approved mockup:
- Connected care
- `/clinical-portal`
- pharmacy connections, with a public signed acknowledgement endpoint `POST /api/emar/pharmacy-connections/{connection}/acknowledgments`
- licensed catalogue
- protected backups

### Flags and defaults on main
**`config/medications.php`**
- `person_record`: `EMAR_PERSON_RECORD`, default `'p02'`. `'legacy'` falls back to `MarCharts`, `PrnRecords` and `Medications`.
- `stock_lots_enabled`: `MEDICATION_STOCK_LOTS_ENABLED`, default **true** (since 893420402). Existing stock needs an explicit counted opening; nothing is backfilled.
- `witness_pin.login_check_to_set`: default true.
- `witness_pin.forgotten_fallback_enabled`: default **true**, ordinary medicines only. It is an env value, not an org setting.
- `witness_pin.pepper`: unset, so the raw unpeppered hash is used (`WitnessPinService.php:495`).
- `away.from_leave`: default **true**.
- Hospital Away has no flag and is always on (`DoseAwaySources.php:13-58`).

**Other config files**
- `config/emar-catalogue-backups.php`: `qpdf_path` unset (fails closed); `send_enabled` false.
- `config/emar-pharmacy-connect.php`: `'enabled' => false` is hard-coded and there are no partners.
- `config/emar-external-clinical.php`: identity lasts up to 365 days, grants up to 90 days, gated by permission only.

**Settings defaults nobody has reviewed**
| Setting | Default |
|---|---|
| SAC | Off |
| Retention | 10 years |
| Bell pin | Off |
| Review cadence | 3 months |
| Weekly count | "Not configured" |
| Controlled witness | On |
| On-site destruction | Off |
| Triage deadline | End of the next NZ day |
| Allergy-class mapping | Empty ("Not configured") |
| Emergency `second_person` | optional (`review_days` 2) |

### Scheduler (`routes/console.php`) [V for :988-999]
- **Every minute:** `emar:workflow-followups`, `emar:support-review-delivery`, `emar:expire-emergency-access`, `emar:expire-second-person-confirmations`, `PharmacyDispatchService::recover`, `medications:chart-backups`.
- **Every 5 minutes:** `emar:escalate-overdue-cd-checks`.
- **Every 15 minutes:** `emar:send-alerts`, `emar:alert-follow-ups`.
- **Hourly:** `emar:generate-dose-slots` (:05), `clearStaleAlerts`.
- **Daily:**
  - 00:05 `emar:generate-rounds`
  - 00:20 `governance:sync-clinical-data`
  - 02:35 prune `MedicationIdempotencyResult`
  - 06:00 `emar:check-medication-stock` (reads legacy `ClientMedicationStock`) and `emar:support-reviews`
  - 07:05 `emar:check-medication-reviews`
  - 08:00 `breakglass:daily-report`
- **Manual only:** `emar:backfill-dose-slots`, `emar:copy-allergies`, `emar:review-copied-error-incidents`, `medication:workflow-test` (it still points people to `/medications/audit`).

---

## 2. Holds, deferred items, failures and decisions waiting for Stephan (deduplicated)

### A. Decisions waiting for Stephan
1. **Office order authority.** Should `medications.orders.manage` plus site scope be enough to add, change or stop orders without a covering clocked-in shift or emergency access?
2. **Finance and stock.** Does finance keep `medications.stock.update`? It currently has it: RbacSeeder finance block, around l.847 [V].
3. **team_lead and orders.** The approved P04 amendment (1 Oct) says team_lead gets `medications.orders.manage`. It is **not applied**: the team_lead block, `RbacSeeder.php:915-943`, grants only `orders.verify`, `reviews.manage` and `witness_pin.reset` [V]. Ship the grant migration?
4. **P06 stock grants.** The approved P06 grants are held: there is no `medications.stock.receive` permission key [V: the string appears only as an audit action name, `EmarController.php:7264`], and team_lead has no `stock.update` [V]. Without them, house leads cannot open the Stock hub.
5. **Paper reconciliation (P10).** Paper-to-clinical posting was built (`PaperEntryService`, `PaperAdministrationWriter.php:30-84`, `downtime/_paper-review.tsx`) without a recorded scope OK. Stephan should approve it retroactively, or it should be hidden.
6. **Unapproved surfaces.** Approve or hide: Connected care, `/clinical-portal`, pharmacy connections, catalogue, backups. The catalogue contradicts the 29 Sep decision "staff photos, no picture library".
7. **Defaults switched on in source (893420402).** Confirm `stock_lots_enabled`, `forgotten_fallback_enabled` and `away.from_leave`.
8. **Maintenance-window suppression.** Should medication alerts ever be suppressed by Control Room maintenance windows? The H&S lane says never (see §3).
9. **Respite at another house.** Should a respite stay at another house get an eMAR route at that house? Today the person's home doses go Away and the respite house has no way to record them.
10. **Soft-deleted records (P09).** The event recorder skips soft-deleted people and houses, so the closing evidence for removed records is undecided.
11. **P08b scope.** Two design/privacy calls are open: multi-medicine and off-chart error reports, and the controlled-name prompt.
12. **Duplicate surfaces.** Retire one of each pair:
    - `/emar/stock` vs `/emar/stock/packs`
    - `/emar/rounds` vs Meds today › Rounds
    - `/emar/handovers` vs `/operations/handovers`
    - Settings `#connections` vs `/emar/connections`
    - `/emar/prescriptions/legacy`
    - `/emar/destructions`, which should become a redirect into the register
13. **Dead code and orphaned write routes.** About 13k lines are candidates for deletion (see 6.6).
14. **Legacy rollback.** Is `EMAR_PERSON_RECORD=legacy` still wanted? The answer decides whether `MarCharts`, `PrnRecords` and `Medications` stay.
15. **Emergency-access review scope.** `MedicationEmergencyAccessReviewProvider` uses `medications.audit.view` as an all-site bypass [V, provider l.32-62]. That differs from `MedicationGovernanceScopeService::SITE_BYPASS_PERMISSIONS`. Is it intended?
16. **Stale worktrees.** Deleting them needs the user's OK (§4).

### B. Held items with no code on main (each needs a specific human approval)
- **Recording on unassigned rounds.** The assignment gate is unchanged. `MedicationRoundProvider.php:95` requires the round's assignee.
- **Recording on deleted or superseded orders.** `DoseRecordingRequirements.php:132` rejects `superseded_by`.
- **Offline expired emergency grant.** `app/Services/Medication/EmergencyAccess/OfflineGrantEvidence.php` has no caller.
- **P08b entered-in-error writer.** Readers handle status `in_error`, but nothing can set it.
- **Patches never applied:**
  - P06 after-commit stock alert: replaced in effect by `StockAvailability` in alert sources.
  - P07 277d20ba1b: replaced by narrower repairs.
  - P01 d32897b78: the forgotten-PIN part was rebuilt in PR #17; the offline-expiry part is still held.
  - P07 1033a1f0d: rejected; replaced by 3e911ef28 and 433e8df5a.

### C. Approved in mockups but not built [M]
**P00 / P11 settings**
- Longest override: hard-coded to one shift (`ControlledRegisterService.php:584`).
- Heads-up before single staffing.
- Prescriber phone instruction at the dose, and when a lead countersigns.
- Forgotten-PIN switches for general and controlled drugs, plus a confirm time limit. The fallback is env config today.
- Third allergy mode, "block unless prescriber confirmed" (`MedicationSafetyPolicySettings::OPTIONS` offers warn|block only).
- Medicine photos (`_nav.ts:38` lists it, but the sections map at `Settings.tsx:308-345` omits it).
- Time-critical medicines. The only source is the unmerged draft 6d97707e8 (§4).

**P01**
- "Order changed — check the new instructions" (states 38/38b/38c).
- "Prescriber asked for a different dose".
- Fleet still uses its own `AdministerTransportMedicationWizard` (`transport-medication-dialogs.tsx:1276`), which has no refused or withheld outcome.
- The My Day routes are not retired (`web.php:436-438`).
- `EmarRecordDialog` was not removed.

**P02**
- Header meters Due now, Late and the Recorded-today donut.
- Print MAR.
- Medicines › Photos.
- "Chart alerts to read" before recording, with reads stored.
- A "Loosens this check" confirm when pausing alerts (`mar-governance-dialogs.tsx:760`).
- The hub's 6 meters and its "Needs help" filter.
- One-click "Mark given".

**P03**
- A read-only medication support summary in the Care & Support Plan.

**P07a, P07b, P11**
- The witness view, the "Can witness" donut, and the My-eligibility witness lines (`StaffEligibility.tsx:8`).

**P08a**
- The outgoing handover is still free text (`handover-wizard.tsx:81-114`).
- `Handovers.tsx` is the old card/week page, not the approved register.
- Client profile › Actions & reviews has no medication follow-ups (`ActionsAggregator.php:69`).

**P08b**
- No "Ready to close" marker on the Incidents list rows.

**P10**
- Emergency access is shown in the nav only to `breakGlass` holders [V, `emar-navigation.ts:~497-505`; the comment says "joins when P10 builds it"].
- Downtime has no nav entry and no link from Meds today.

### D. Known or likely defects (verify first)
- **[V code path] Overview CD-register quick action fails.** `cd-register-modal.tsx:94` sends `entry_type` but never `movement_type`. `ControlledProductController.php:87-89` aborts with 422 unless `movement_type` is in going_out, coming_back, breakage or spillage. Every submission should fail. Not tested in a browser.
- **[V] The nav flag `emergencyPolicyAccess` is never in auth.can.** It appears only as a Settings page prop (`MedicationSettingsController.php:778,859`), so `emar-navigation.ts:604` falls back to `settingsReader`. Low impact.
- **[V, partly resolves a map question] Legacy direct order writes.** `ClientMedicalController::storeMedication` sets `created_by`, and the `ClientMedication::booted()` creating hook then forces `pending_verification`. `isAdministrable()` requires verification, so these orders are **not administrable until checked**. They still skip the allergy check, versioning, prescriber link and event chain.
- **CI and UI evidence.** No CI result is recorded for the merged heads of PR #17 (91b1c8c60) or PR #21 (b6a203891). The intermittent page-module load failure has no known cause. There is no 200% zoom evidence. Screenshot baselines were replaced twice (645dd32fc on 5 Oct; 9 app-shell images on 8 Oct).
- **[? memory] Known failing main test:** `Rostering/MedicationCompetencyEligibilityTest` (bare rows).
- **Deploy claim [?].** Codex says nothing was deployed, but pushing to main triggers the webhook deploy to the .com test server. So migrations and the new keys are probably live there, without baseline grants, which means 403s. Existing stock has no counted opening while `stock_lots` is on.

### E. Site and rollout actions not yet run
- Save PIN rules and staff PINs.
- Demo reseed.
- Test-server mail and push.
- Dose-slot backfill: dry run, then an approved run.
- `emar:workflow-followups --preview/--import`.
- `emar:review-copied-error-incidents`.
- Reconnect Microsoft mail and reselect it in Settings → Email.
- Set `EMAR_BACKUP_QPDF_PATH` and `EMAR_BACKUP_SEND_ENABLED`.
- Explicitly assign the six keys from migration `2026_10_07_095000`: `pharmacy.send`, `pharmacy.connect.manage`, `external.manage`, `transfers.manage`, `catalogue.manage`, `backups.manage`.
- Unverified: P03's reassessment trigger has not been confirmed as tied to the new hospital admission/discharge events.
- Unverified: P06's stale discrepancy needs an authorised recount; a linked pack count does not complete the old scheduled-count obligation.

---

## 3. In-flight cross-module work and the collision list

### Lanes (all uncommitted or local-only; nothing in them is on main)
| Lane | Path | Base / position | State |
|---|---|---|---|
| H&S `codex/health-safety-workspace` | `C:/Users/steph/.codex/worktrees/health-safety-workspace/oblivionfindings` | 12d5665c4, 32 behind | 228 modified + 284 new files (424 porcelain entries [V]); 9 migrations 2026_10_07_180000–2026_10_08_180400. Paused with 22 failed / 574 passed; Control Room frontend half-edited; migration 180400 not applied. HS21 (medication link-up) not started. |
| Devices `codex/native-endpoint-management` | `C:/Users/steph/.codex/worktrees/native-endpoint-management/oblivionfindings` | 12d5665c4, 32 behind | 63 modified + 626 new (186 porcelain [V]); 15 migrations 2026_10_07_150000–2026_10_08_230000 |
| Workforce integration `codex/workforce-main-integration-20261006` | `C:/Users/steph/.codex/worktrees/workforce-main-integration/oblivionfindings` | 31 behind / 19 ahead [V] | 12 unique commits that exist **only on this machine** (094f39dd8d…7af476585; migrations 2026_10_08_230000 and 235100), plus 17 uncommitted files [V] |
| Workforce foundation `codex/workforce-foundation-20261005` | `C:/Users/steph/.codex/worktrees/workforce-improvements/oblivionfindings` | 344 behind | Stale source lane; 179 files are on neither main nor integration. **Cherry-pick only**: applying it whole would undo eMAR work (`PERMISSIONS_CACHE_VERSION` would go back to v10). |

### Explicit collision list
These are files that further eMAR work would collide on. Rule: do not edit them in an eMAR branch without first agreeing order with the owning lane.

**High**
1. `app/Services/ControlRoom/SignalProcessingService.php` (H&S).
   - `isInMaintenanceWindow()` (main l.1040; H&S l.1044) now exempts `CATEGORY_MEDICAL_WELLBEING` and `CATEGORY_PEOPLE_SAFETY` [V].
   - This breaks `tests/Feature/Emar/OverdueDoseAlertsTest.php` (~l.166) and `tests/Feature/Emar/MedicationErrorsTest.php` (~l.629), and makes `OverdueDoseAlerts.php:243-246` dead code.
   - The same file holds the `medications_due_soon` count that is always 0 (l.1417-1421, 2057-2078).
2. `database/seeders/RbacSeeder.php` (H&S). The new `incidents.owner.*` grants sit within 3 lines of the medications grants for provider_manager (~l.686-693) and coordinator (~l.744-753). **Ship eMAR grants as grant migrations only; do not edit the seeder.**
3. `resources/js/components/app-sidebar.tsx`.
   - H&S: `safetyHubLinkActive` goes before `emarHubLinkActive` (~l.339-360); `buildSafetySubPanelGroups` sits next to `buildEmarSubPanelGroups` (~l.1351); a search block is added (~l.3088).
   - Devices: IT sub-panel (~l.904).
   - **Keep eMAR nav changes inside `lib/emar-navigation.ts`.**
4. `app/Http/Middleware/HandleInertiaRequests.php`.
   - Workforce integration bumps `PERMISSIONS_CACHE_VERSION` from v11 to v12 (main is v11 [V]) and adds flash props and `can.shifts.overrideEligibility`.
   - Devices adds three `can` keys without bumping the version.
   - Any eMAR auth.can change (`emergencyPolicyAccess`, an audit-view emergency flag, the badge rule at l.134-147 and 1344-1435) collides.
5. `app/Http/Controllers/ShiftController.php`.
   - Workforce integration (uncommitted): `shifts.viewAny` holders can open any shift [V l.74, 172, 293-299], which exposes the medications tab, `mar_url` and the witness picker to coordinator and auditor.
   - Workforce foundation: +469/-116.
   - The eMAR shift-card fixes (medicationWitnesses, overnight day, house shifts, l.313-318, 398-472, 588-590, 702-704) live here.
6. `resources/js/pages/sites/calendar/SiteCalendar.tsx` (H&S +368/-128 against main's eMAR changes in c5cb7acd6 and 1fa2cd591).

**Medium-high**
7. `app/Domain/Shifts/Lifecycle/ShiftLifecycleService.php` (workforce: `lockAssignmentBatch` → `medicationGovernance->lockCurrentStaffProfiles`).
8. The eligibility chain (workforce integration and foundation):
   - `app/Services/Eligibility/AssignmentEligibilityGateway.php`
   - `app/Services/ShiftStaffEligibilityService.php`
   - `app/Services/Eligibility/WorkforceEligibilitySources.php`
   - `app/Services/Eligibility/Rules/{AvailabilityRule,FatigueRule,HsTrainingRule}.php`
   - `app/Services/ShiftSeriesEligibilitySampler.php`
   - `MedicationCompetencyRule.php` itself is not edited, but it runs inside this chain.
9. `app/Http/Controllers/IncidentController.php` (H&S hunks next to the medication_error block at l.525-617).
10. `app/Services/UserSiteAccessService.php` (workforce foundation +114/-6; integration timezone fallback). 34 eMAR files use it.

**Medium**
11. `app/Policies/ClientIncidentPolicy.php` (H&S: `close()` now requires `view()`). This feeds `MedicationErrorController.php:146` `can_close`.
12. `app/Http/Controllers/ControlRoom/ControlRoomIncidentController.php` (H&S; `case 'medication_error'` ~l.383).
13. `app/Http/Controllers/MyTasksController.php` (H&S). The My Day medication fixes (l.82-123, 795-960, 1000-1120) live here.
14. `resources/js/pages/my-day/index.tsx` (H&S disables `useLiveRefresh`; workforce foundation care actions). `components/record-care-actions.tsx` (workforce foundation).
15. `app/Http/Controllers/ClientController.php` (H&S). **Both P1 privacy fixes live here**: the Transport tab at l.3381-3398 [V leak: `medication_name` and `is_controlled_drug`], and the Medical tab redaction at l.405-500 and 1857-1884.
16. Incident navigation (H&S): `resources/js/components/incidents/incident-detail-dialog.tsx` (~l.1773/1896), plus the new `pages/incidents/show.tsx` and `lib/incidents/navigation.ts`. `return_to` must start with `/incidents`, which breaks the way back for these eMAR links:
    - `components/emar/record-dose/dialogs.tsx:387`
    - `pages/emar/MedicationErrors.tsx:135`
    - `pages/emar/_error-dialogs.tsx:163`
    - `pages/emar/errors/_detail.tsx:1056`
17. `resources/js/components/ui/file-dropzone.tsx` (H&S: new props; `flash.error` now stops the queue). 10 eMAR importers.
18. `bootstrap/app.php` (devices).
    - Adds lines right before `ScrubNestedWitnessSecrets::fromFlashedInput`.
    - Adds `code`, `credential`, `code_verifier` and `enrolment_token` to global `dontFlash`, which affects `MedicationCatalogueController.php:66` `code`.
19. `resources/js/pages/operations/shifts/show.tsx` (workforce foundation +522/-396; the medication donut becomes a tile; main has `?tab=medications`).
20. `app/Domain/Hr/Services/HrCurrentStaffService.php` (workforce integration timezone fallback).
21. `app/Services/Sites/Calendar/SiteCalendarAggregator.php` (H&S reformat of the provider list, including `MedicationObligationProvider`).

**Medium: eMAR findings in another lane's territory (coordinate before fixing)**
- `app/Domain/Hr/Services/AttendanceService.php`: clock-out blocker l.1991+ and handover l.1920-1972.
- `app/Services/ShiftCancellationService.php`: l.173-232 [V `saveQuietly`].
- `app/Services/CoverageRoleService.php`: l.60-108.
- `app/Http/Controllers/HealthSafety/RestraintController.php`.
- `app/Http/Controllers/Clinical/Client{Bowel,Fluid,...}ChartController.php` and their gates in `routes/operations.php:455-538`.
- `app/Listeners/Care/NotifyOnMedicationCabinetOpen.php` (devices domain).
- `app/Http/Controllers/Operations/HandoverController.php:441-459` [V controlled-only gate] and `handover-wizard.tsx` (workforce, shift-notes and handover seams).

**Low (additive, but expect rebase noise)**
- `app/Services/ControlRoom/ControlRoomAlertLifecycleService.php` (new `incidentId` parameter)
- `resources/js/components/wizard/{primitives,shell}.tsx`
- `resources/js/lib/datetime.ts`
- `config/notification_events.php`
- `app/Services/Tasks/{TaskAggregator,TaskSearch}.php`
- `routes/console.php` (both lanes append at the end of the file)
- `resources/js/types/index.d.ts`
- `resources/css/app.css`
- `package.json`
- `resources/js/hooks/use-settings-leave-confirmation.tsx`
- `resources/js/components/flash-toaster.tsx`
- `resources/js/components/people-locations/record-picker.tsx`
- `config/hr.php`
- `app/Providers/AppServiceProvider.php`
- `bootstrap/providers.php`
- `resources/js/layouts/settings/layout.tsx`
- `routes/web.php`
- `resources/js/lib/status-colors.ts`
- `app/Domain/It/Presenters/ItTicketContextPresenter.php` (~l.266)
- `resources/js/pages/sites/tabs/shift-coverage.tsx`

**Migration timestamps.** Lanes already clash with each other at 2026_10_08_090000, 180000 and 230000. **New eMAR migrations should use timestamps after 2026_10_08_235100.**

**Relatively safe zones [M; re-check `git status` in each lane before starting]** (no lane touches these):
- `app/Services/Medication/**`
- `app/Http/Controllers/Emar/**`
- `resources/js/pages/emar/**`
- `resources/js/pages/meds/**`
- `resources/js/components/emar/**`
- `resources/js/lib/emar-navigation.ts`
- `routes/emar*.php`
- `config/medications.php`
- `tests/Feature/Emar/**`

---

## 4. Stale or unmerged Claude work

| Item | State | Superseded? |
|---|---|---|
| `amazing-bohr-77f3b2` (`claude/emar-p02-2`), 7 uncommitted files | 326 behind | Yes: fe9f6d3f8, a865aea3f, bddda79b8. Discard with OK |
| `elastic-goodall-cac1bf` (`claude/emar-p11-b2`), 3 uncommitted files | 322 behind | Yes: 7fdecb1dd. Discard with OK |
| `claude/emar-p01-ui` (worktree `agent-ad5d8ae5beec87c2b`) | 2 ahead | Yes: 72632e245 ≡ 1e95ce671 |
| `claude/emar-p02` (fef3d1409, 8ebc95202, e38c24d58) | 3 ahead | Yes: e38c24d58 ≡ 4f6ba5e46; logic at `mar-chart.blade.php:87-89` |
| `claude/emar-days-remaining-nz-calendar` | 1 ahead (merge commit only) [V] | Yes, nothing unique |
| `claude/emar-p01-ui-c2wip` b34f7617f "P01 C2 dialog draft (parked)" | 1 unique, 352 behind [V] | Probably, since `RecordDoseDialog` is on main [?] |
| **`claude/emar-p11-b1-time-critical-draft` 6d97707e8** (1 Oct; 31 files, +2576) | 1 unique, 498 behind [V] | **No.** It is the only source for the missing time-critical medicines feature; main has 57ce4160e without it. It needs rework against the current `DoseWindowResolver`. |
| `claude/emar-p03…p10` (local and origin), 3 docs-only commits each | Frozen approved mockups | Not to merge; these are the reference sources |
| `codex/emar-p02-completion`, `p03-safe-net`, `p08b-errors`, `p10-completion` | Rebased equivalents on main | Yes; only scratch, logs and a node_modules link remain |
| This worktree `busy-shirley-877f95` | 627 behind / 0 ahead [V] | Holds the only copy of untracked `Approval-record.md` and `docs/emar-audit-2026-09-28/`. These should be committed to a docs branch with the user's OK |
| `claude/bold-pare-bed009` f24690a76 (respite NZ→UTC WIP) [V not on main] | Unmerged, untested | Not eMAR, but it touches respite times next to the respite medication touchpoints |
| Memory index entries | — | Stale: "eMAR NAV hubs 6acb60230 NOT pushed" and "C6h 508eccb33 NOT pushed". Both are now ancestors of main [V]; C6h is 1b8bd7b07. |

---

## 5. Cross-module touchpoints by module
Status tags: P1 = high, P2 = inconsistent, P3 = gap, OK = consistent. All are [M] unless marked.

**My Day**
- P2: `MyTasksController` main list is consistent (`DoseSlotReaderScope`, `ScheduledDoseStates`, NZ day).
- P2: The pre-shift briefing (`getShiftMedicationsDue`, l.795-960) uses only `shift->client_id`, applies no person rule, drops controlled doses silently, and lists pending-check and Away doses. Its `can_give` and PRN flag fields are dead.
- Dead: `POST /my-day/medications/{id}/administer|refuse|snooze` (`web.php:431-438`, `MyDayMedicationsController`).
- Links: medicines-card, my-day-hero and day-work-list point to `/meds/today`. H&S's `useLiveRefresh` pause affects `medications_due`.

**Sidebar and global search**
- P2: The overdue badge (`HandleInertiaRequests::medsOverdueTodayCount`) counts cancelled and draft shifts and has no site check. The board (`WorkerMedsController::boardPeopleFor` l.1120-1199) uses a different rule, and My Day a third. The badge cache is cleared only by `WorkerMedsController`, so it can be up to 60s stale.
- The badge is computed for users who see the full menu. `global-nav-search.tsx` is OK.

**Client profile**
- P1: The Transport tab exposes medicine names and the controlled flag to fleet users [V].
- P2: The Medical tab removes controlled rows instead of redacting them with a count, and includes all versions.
- OK: the MAR tab (`tabs/mar.tsx`, `/emar/clients/{id}/day`), the calendar (`ClientCalendarDoses`) and `ActionsAggregator` reviews.
- P2: The MAR CSV (`ClientMarController`) labels Away, self-managed and upcoming doses as `not_recorded`.
- Legacy routes `clients.medical.medications.store|update|stock.update|administrations.store|discontinue` and their `operations.*` copies are live with no UI. `EmarRecordDialog` is orphaned.
- Health-monitoring charts and meal logs are gated by `medications.*` instead of `clinical.observations.*`, and allow hard deletes.
- P3: The Care & Support Plan has no medication support section.

**Shifts / Operations**
- P2: The shift medication card builds the MAR for `shift->client` only. The person rule gates only the links. House shifts get no card. The second day of an overnight shift is missing.
- `medicationWitnesses` ignores PIN eligibility and competency, and is never used by `RecordDoseDialog`.
- `/api/medications/shifts/{id}/medication-summary` skips `viewMedications`.
- P1: Shift cancellation calls `saveQuietly` on append-only records with no event and no `review_reason_key`, so no follow-up is created [V `saveQuietly`].

**Handovers**
- P1: `medications_due` input requires `controlled.view` AND `controlled.record` [V l.441-459]. Support workers fall back to free text.
- Two handover pages; the shift-med snapshot reads `/emar/handovers/shift-medications`.
- `HandoverPresenter` and My Day show `medications_due` only to controlled readers.

**Attendance / HR**
- P1: The clock-out `meds_unsigned` check counts the whole shift window [V l.1991-2030] for `client_id` shifts only, including future doses and controlled doses the worker cannot record. Only a manager can force clock-out.
- P3: The HR profile shows performance competency, not medication competency. There is no medication competency or PIN renewal in HR compliance, and no medication clean-up at offboarding.
- On-call work email and phone first; personal cellphone only with consent (OK).

**Rostering / Workforce**
- P2: `MedicationCompetencyRule` runs only when `CoverageRoleService` yields `med_competent`, not from the doses actually due.
- `MedicationRoundGenerationService` (l.160-240) skips a round when its fixed `default_assigned_to` is not current. `MedicationRoundProvider` limits rounds to rostered staff and their assignee.
- `RosteredMedicationRounds` and `AttendanceService` `DoseSlotProjection` are coupled to eMAR. In-flight lane conflicts are listed in §3.

**Health & Clinical**
- Clinical charts use medication permissions.
- `ClientSummary` links into eMAR.
- The falls-risk assessment asks about medicines by hand (`record-assessment-dialog.tsx:112-113,761-762`).

**Health & Safety / Incidents**
- `MedicationIncidentIntegrationService` creates draft incidents with `unrestrictedName` (OK).
- **[V]** Times are printed in UTC: `app.timezone` is UTC, and `->format('H:i')` and `('d/m/Y H:i')` appear at l.82, 91, 414-415 and 944. The same issue is in `MedicationSafetyService.php:662`.
- Auto-incidents have no `shift_id`, so the clock-out draft prompt never fires.
- `ensureIncident` runs `withoutEvents`, so it will bypass H&S owner routing.
- `IncidentController` l.608-617 shows the back-link to readers without `medications.view`.
- The Incidents list has no "Ready to close" marker.
- The restraint register's 'chemical' type has no link to PRN doses.

**Control Room**
- OK: alert access with the controlled boundary, role-routed re-checks, and the `medication_error` source.
- P3: `medications_due_soon` is always 0.
- The deprecated `ComprehensiveAlertBridgeService::bridgeMedication*` methods have no callers.
- **The maintenance-window behaviour change is pending in the H&S lane.**
- Memory note: the missed/late dose rules have no recipients (P11) [?].

**Fleet**
- P2: The medication-transit register uses fleet site scope. Names and the controlled flag are shown to all viewers. `?export=csv` (`ResidentTransportController.php:~930`) bypasses `MedicationExportGuard`, the purpose step and audit. The "packed today" stat and the date filters use UTC.
- `formOptions()` and `show()` list all orders to `fleet.medication.manage` holders who lack `medications.view`.
- Recording goes through the canonical path (OK). There is a separate wizard instead of P01's dialog.

**Respite**
- P1: The check-in anaphylaxis acknowledgement, the evidence pack and the workspace read the legacy `MedicationAllergy` register and profile labels, not `ClientAllergyRecordService`.
- A separate `RespiteMedicationReconciliation` model, gated only by `respite.stays.manage`.
- A stay at another house marks home doses Away, and the respite house has no eMAR route.

**Client leave, excursions, hospital**
- OK: Away sources use actual departure, checked-in respite elsewhere, and hospital events.
- P3: No record of medicines going out and coming back on leave or excursions, and no controlled-drug custody.

**All Tasks and My Calendar**
- OK: 11 medication providers (`TaskAggregator.php:88-98`) and `MyCalendarController` reuse.
- P3: No task for pending-verification orders, controlled counts due or overdue, low stock or reorders, or second-person confirmations.
- The emergency-access review bypass is listed in §2A.15.

**Site calendar**
- P3: `MedicationObligationProvider` drops controlled expiries without counting them.
- It links to `/emar/stock/packs` and `/emar/reviews`.

**Home dashboard**
- P2: `lowStock` uses raw `on_hand <= reorder_level` instead of `StockAvailability`, and excludes controlled stock.
- `overdueReviews` counts 'scheduled' only.
- Staff KPIs use UTC.

**Compliance**
- P2: The break-glass and CD sparklines use UTC days while the MAR sparkline uses NZ days.
- The `/medications?tab=mar|controlled` links lose the tab on redirect (`routes/medications.php:16-20`).

**Reports / builder / governance**
- OK: `MedicationReportAccess` and `ReportAccess` controlled handling; governance re-scoping.
- Minor: `ReportsController` uses UTC; governance writes a snapshot on GET.

**General audit log `/audit-logs`**
- P2: Medication action families are listed per person with no site scope and no `MedicationProfileAuditPrivacy`.

**Family portal**
- P2: `PortalHealthController` uses `where('active',true)` instead of the `active()` scope and sends raw models.
- No controlled rule and no audit of portal reads.
- `FamilyDashboardController` reads legacy allergy labels.
- [?] Do portal roles hold `medications.view`?

**AI / RAG / summaries**
- P1 [V]: `ClientRagIndexer` loads all medications, including controlled ones, plus legacy allergies, and uploads them to an OpenAI vector store.
- `Str::squish()` is called on the array-cast `allergies` [V cast `'array'`], which is a bug.
- `GenerateSummaryJob` and `SummaryController` apply no medication timeline filter.
- [?] Whether RAG is enabled on .com.

**Legacy `/api/medications` (`routes/api_medications.php`)**
- About 30 endpoints. Only scan-verify and allergies are live callers.
- Widget logic is stale (UTC, controlled excluded). The write endpoints are a second write surface.

**Notifications**
- OK: `MedicationAlertNotification` (privacy mode, work email), `ControlledWitnessRequested`, `WitnessPinReminderNotification`, `MedicationSecondPersonConfirmationNotification`, emergency access notifications.
- P3: Legacy notifyCrud keys (`medication.created`, among others) are missing from `config/notification_events.php`.

**Devices & security**
- P3: `NotifyOnMedicationCabinetOpen` only writes a log line.
- `origin_device_id` is free text, not linked to `SecurityDevices`.
- [?] Is the offline queue (`emar-offline.ts`, `offline-queue.ts`) cleared on logout?
- In-flight devices work affects `bootstrap/app.php` (§3).

**Settings / Sites / RBAC**
- P3: Settings › Modules shows `'emar' => false`, but nothing reads it.
- The Add Site copy says storage "feeds eMAR"; it does not.
- `DowntimeAccess::manages` hard-codes role names [V].
- No unseeded permission keys found.

---

## 6. Proposed audit dimensions (13)

**6.1 Workflow correctness and edge cases per persona.** Personas: support worker, house lead (team_lead), coordinator/office, clinical lead, provider manager, auditor, finance, fleet driver, respite-house staff, external prescriber, family.

Flows to walk end to end:
- `RecordDoseDialog` → `/meds/today/record|prn` → `DoseRecordingRequirements.php` → `EnhancedMarService::recordAdministration`
- guided round (`guided-round-dialog.tsx`; `MedicationRoundGenerationService.php:160-240` assignee skip; `MedicationRoundProvider.php:95`)
- order entry, check, send-back, stop and resume (`MedicationOrderWorkflow.php`; office authority)
- stock receive by a lead without a grant
- controlled count at shift change and witness request
- follow-up carry-over at handover acknowledgement (`MedicationFollowupService.php:861`)
- clock-out with unsigned doses (`AttendanceService.php:1991+`, `canForceClinicalClockOut`)
- handover `medications_due` (`HandoverController.php:441-459`)
- shift cancellation (`ShiftCancellationService.php:173-232`)
- paper downtime posting (`PaperEntryService`)
- emergency access request, extend, end and review
- respite at another house (`DoseAwaySources.php`)

Edge cases:
- overnight shifts and house shifts with no `client_id`
- an order superseded mid-round
- Away starting or ending mid-day
- PRN limits across corrections and re-offers
- late doses across midnight
- multi-site staff
- an assignee on leave

**6.2 Permissions and role grants (RBAC).**
- `RbacSeeder.php` role blocks: coordinator ~739, finance ~845, auditor ~891, team_lead 915-943, clinical lead ~1019.
- Grant migrations `2026_10_03_170700`, `200500`, `210100`, `211100`, and `2026_10_07_095000` (six keys, no grants).
- Route gate vs controller drift: `routes/emar.php` vs controllers, including the break-glass destroy gates at `clients.php:206-212` vs `emar.php:545-548`.
- `DowntimeAccess::manages`.
- Nav visibility vs route access: the emergency view (`emar-navigation.ts:~497-505`), `emergencyPolicyAccess`, and the lead-only Overview vs the `/emar` route.
- Deploy without seeders, so 403s on .com.
- Approved but unapplied grants: P04 team_lead, P06 receive.

**6.3 Privacy, scope and controlled concealment.**
- Read paths outside eMAR:
  - `ClientController.php:3381-3398` (Transport) and `:405-500` (Medical)
  - `ClientRagIndexer.php:14-58`, `GenerateSummaryJob.php:40-52`, `SummaryController.php:96`
  - `PortalHealthController.php:12-66`
  - `AuditLogViewService.php:82-131`
  - `ResidentTransportController.php:271-380, 529-575, 839-1030`
  - `MedicationObligationProvider.php`
  - `MyTasksController.php:795-960`
  - `ShiftController.php:398-472` and `MedicationsApiController.php:1758-1786`
  - the `IncidentController.php:608-617` link
- Scope rules: the `MedicationEmergencyAccessReviewProvider` bypass, and the workforce `shifts.viewAny` expansion.
- Exports: guard coverage on every export route, including `/reports/medications/*`, `/medications/audit/export` and the fleet CSV.
- 404-vs-403 consistency.
- Offline storage cleared on logout.

**6.4 NZ time and calendar.**
- Formatting UTC datetimes as local time:
  - `MedicationIncidentIntegrationService.php:82, 91, 414-415, 944`
  - `MedicationSafetyService.php:662`
  - `EmarController.php:2311-2340, 3087, 3180`
- UTC-day logic:
  - `ComplianceMetricsService` `dailyCounts`
  - `DashboardController`
  - `ReportsController`
  - `MedicationAlertService.php:736-760`
  - fleet transit stats and filters
  - `AuditLogViewService` `whereDate`
- Overnight shift day (`ShiftController.php`).
- DST change days (late September and early April) for dose slots, `generate-rounds` 00:05 and the weekly CD count rule.
- `StockAvailability` NZ day.
- Triage deadline.
- `WorkerClock::daysUntil`.
- Test fixtures and `setTestNow` skew (memory).

**6.5 Concurrency, idempotency and offline replay.**
- Request-UUID recording recovery (PR #21).
- `MedicationIdempotencyResult` prune window vs offline replay age.
- Witness re-check on replay (P07b).
- IndexedDB consent queue (P03).
- Stale count rejection (ICR-01).
- Emergency grant lock vs policy save.
- P09 hash chain under concurrent writers.
- The every-minute jobs' `withoutOverlapping`/`onOneServer` and failure behaviour.
- Pharmacy uncertain-send.
- Badge cache invalidation.
- The `saveQuietly` bypass of `lockOrder` and `SupportRecordingGuard`.
- InnoDB snapshot-before-lock pattern (memory).

**6.6 Single write seam and legacy surfaces.** Enumerate every route that reaches `recordAdministration`, order creation or stock movement, and justify or retire each.
- Dose and correction routes:
  - `web.php:436-438`
  - `clients.php:196-204`
  - `operations.php:614-622`
  - `api_medications.php:35-37, 63-66`
  - `GuidedRoundController::show|administer`
  - `/meds/today/prn/effect`, `/emar/prn/effectiveness`
  - legacy corrections
- Order routes: `ClientMedicalController::storeMedication|updateMedication` (pending-verification confirmed; allergy check, versioning and event chain still skipped).
- Stock routes:
  - legacy `/emar/stock/receive|adjust|pharmacy-orders` vs `/emar/stock/packs/commands` while lots are on
  - `CheckMedicationStock` reading legacy stock
  - the overview `StockMovementModal`
- Controlled: the CD-register modal 422.
- `/emar/prescriptions/legacy` dispensing.
- Dead code: `CDLossReportController`, `EmarReportController`, about 14 `EmarController` methods, 7 orphan pages, about 8 orphan dialogs, `Placeholder.tsx` (a "Coming Soon" stub).

**6.7 Cross-module consistency of shared definitions.**
- "My people": badge vs board vs My Day vs briefing.
- "Today": NZ vs UTC.
- Allergies: canonical `ClientAllergyRecordService` vs respite, family and RAG.
- Stock numbers: dashboard vs `StockAvailability`.
- Competency: rostering vs recording.
- Compliance links.
- Control Room `medications_due_soon`.
- Settings › Modules flag.
- Add Site copy.
- All Tasks coverage gaps.
- Care plan support summary.
- `ActionsAggregator` follow-ups.
- HR competency view.
- Restraint 'chemical' vs PRN.

**6.8 Navigation and information architecture.**
- Hand-written rails vs EMAR_HUBS:
  - `record/hub.tsx:94-105`
  - `Orders.tsx:615-640`
  - `reviews/index.tsx:96-102`
  - `StockHub.tsx:328-366`
  - `Rounds.tsx:592-605`
  - the downtime pages and `reports/hub.tsx`
- Missing entries: downtime, the Connected care rail tab, Emergency access for `audit.view`.
- Duplicate destinations.
- Breadcrumbs: `StockHub` hard-coded; `ConnectedCare` has none.
- Alias links: `Index.tsx:815, 822`; `MedicationReportDataset.php:104` → `/emar/rounds`; `meds/today/index.tsx:882` handovers.
- H&S `return_to` restriction.
- Frontline single-entry experience.

**6.9 UI fidelity vs approved mockups and DESIGN.md (Rory rules).**
- Compare at 1440, 1280 and 200% zoom against the frozen `claude/emar-p00…p11` branches: `meds/today/index.tsx`, `record/show.tsx` and `hub.tsx` (meters, Print MAR, photos), `Orders.tsx`, `StockHub.tsx` vs `StockManagement.tsx`, `Handovers.tsx`, `StaffEligibility.tsx`, `Settings.tsx` sections, `emergency/access.tsx`, the downtime pages, `transport-medication-dialogs.tsx:1276`, `ConnectedServicesMenu` in the record header.
- DESIGN.md rules: semantic tokens only, `StatusBadge`, PageHero, typography helpers, row kebab + right-click + row click, approved time picker, 44px frontline targets, full-width layout, dark mode, the anti-pattern list.
- Re-check the replaced screenshot baselines.

**6.10 Empty, error, offline and degraded states.**
- "Not configured" states: weekly count, allergy-class map, cadence.
- Stock with no counted opening under lots.
- Event log down.
- Connected care disabled.
- Backups with no qpdf.
- The page-module load failure reload screen.
- Offline row states.
- Recovery receipts.
- 409 on flat payloads and 422 paths.
- Single-capability users landing on empty hubs.
- Meds today with no shift or no residents.
- Emergency grant expiry while a dialog is open.

**6.11 Data integrity, migrations, flags and rollout.**
- Flag defaults and their reversibility, including whether the `legacy` person record still works.
- Pepper unset.
- Migration order: the restrictive administration link before the controlled writer; the 096000 rollback guard.
- Append-only enforcement holes (`saveQuietly`).
- `in_error` status unreachable.
- Soft-deleted subjects in the event chain.
- Dose-slot backfill, follow-up import and copied-error review.
- Actual state of the .com test server: migrations, grants, stock openings.
- Migration timestamps after 2026_10_08_235100.
- CI on final heads 91b1c8c60 and b6a203891.

**6.12 Notifications, alerts and escalation.**
- `OverdueDoseAlerts` and maintenance-window suppression (decision against H&S).
- CR role routing for missed/late doses with no recipients.
- `emar:send-alerts`, `alert-follow-ups` and `escalate-overdue-cd-checks` cadence and dedupe.
- `MedicationAlertNotification` channels and privacy mode.
- Second-person confirmation 30-minute expiry.
- Emergency access notifications.
- Legacy notifyCrud keys outside `notification_events.php`.
- Cabinet-open listener.
- Auto-incident `shift_id`; `withoutEvents` vs owner routing.
- Bell pin and on-call consent.

**6.13 Governance of unapproved and connected-care surfaces.**
- `/emar/connected-care`, `/clinical-portal`: account isolation, MFA, grant expiry.
- The public signed acknowledgement endpoint.
- `/emar/catalogue` vs the 29 Sep decision.
- `/emar/backups` (password retrieval, recipients).
- The six unassigned permission keys.
- The 1CHART overlap claims.
- Hide-unbuilt compliance while partners, data and config are absent.

**Uncertain, to confirm before acting:** the deploy state of .com; whether RAG/OpenAI is active; portal role permissions; whether the frontend shows the UTC-formatted `at`/`time` fields; whether any sanctum client uses `/api/medications`; whether the P03 hospital trigger is wired up; whether b34f7617f is fully superseded; whether the memory-listed Rostering test still fails. Audit agents have about a 25% false-positive rate (memory), so reproduce each P1 with a payload check or a feature test before fixing.