# Independent UI source closure checkpoint

Completed 4 October 2026. This is a bounded, read-only follow-up to `independent-integration-review.md` (F1–F7) and `independent-ui-review.md` (UIR-01–03 and navigation acceptance gaps).

**Result:** F1, F2, F3, F4, F5 and F7 are addressed in source. UIR-01, UIR-02 and UIR-03 are addressed in source. F6 remains partly open: historical stopped-order retention is implemented, but the historical reader still selects current prescription wording. Two concrete P07 navigation gaps remain.

## Snapshot and verification boundary

- Requested integrated snapshot: `e52054efc430d8f01bd4eac7b954c98d78158e7c`.
- Main's integration worktree advanced during this review to `92466adc2dd321db5c3514ffa738db4a379a334b`. The only intervening commits are `44b798fce` (P05 legacy care-parameter separation, from `b1d7d868a`) and `92466adc2` (P08b canonical error-export release guard, from `42882d4e2`). Their changed-file list does not intersect the source locations supporting the finding closures or open issues below. These conclusions therefore also apply to that observed head.
- All line references below refer to `e52054efc`; the referenced source files are unchanged at `92466adc2`.
- No application edits, tests, builds, database commands or browser sessions were performed. Main's reported TypeScript pass and build activity are not independent verification by this review. “Addressed in source” does not certify browser behavior or runtime acceptance.
- Held P07 `277d20ba1b` and P01 `d32897b78` were not applied, copied or ported. This checkpoint does not release either hold or alter any permission grant.
- Authorization remains one organisation, approved sites, roles/permissions, canonical person/record ownership and controlled-medicine privacy.

## Original integration findings

| Finding | Source status | Current evidence and implication |
| --- | --- | --- |
| F1 — foreign refusal evidence in the worker board | Addressed | `app/Http/Controllers/Emar/WorkerMedsController.php:458–471` requires effective refused evidence, matches the administration's person to the follow-up's person at line 464, and matches the medicine's person to the administration's person at line 466. Controlled concealment remains in the nested medicine query. The mismatched records described in F1 are excluded before eager loading. |
| F2 — foreign-person administrations counted in profile PRN totals | Addressed | `app/Http/Controllers/Emar/ClientMedicationDayController.php:158–166` now applies `scopeCanonicalClientMedicationRows`, an explicit person filter, effective evidence, selected medicine IDs and NZ-day bounds before grouping. The count and last-given calculation use that constrained result. |
| F3 — waiting/self-managed rows counted as staff recording obligations | Addressed | `resources/js/pages/meds/today/_rows.tsx:223–231` excludes away, pending-check and self-managed states from staff obligations. `index.tsx:417–420` applies this predicate to the Recorded meter denominator. `_schedule.tsx:339–351,387–407` distinguishes waiting checks and self-managed/away rows from staff work and prevents a waiting-check all-clear. |
| F4 — concealed late work omitted from meters/all-clear | Addressed | `app/Services/Emar/MedsBoardPayloadService.php:239–269` returns concealed due-now, open, waiting and recorded/due-so-far aggregates as well as late totals. `WorkerMedsController.php:219` passes them through. `resources/js/pages/meds/today/index.tsx:439–447,503–515,913–915` includes them in meters and completion inputs. `_schedule.tsx:347–374` suppresses an all-clear when concealed work remains and replaces the false empty schedule with a controlled-dose handoff explanation. |
| F5 — pre-window due-soon row labelled Due now | Addressed | `resources/js/pages/meds/today/_rows.tsx:214–231` distinguishes due-soon visibility from an open recording window. `index.tsx:412` uses `isDueNow` for the meter; `_rows.tsx:408–413` says “Due soon” with the window-opening time for the pre-window case. |
| F6 — historical days lose stopped orders/use current clinical details | Partly addressed; remains open | `ClientMedicationDayController.php:74–76,148–152` chooses a historical reader for past days. `app/Services/Medication/MedicationRecordDayService.php:19–24,71–81` retains stopped/soft-deleted scheduled and PRN orders using held slots or historical dates/evidence. However, scheduled rows select the version matching **the order's current `version`** at line 33 and use its current name/dose/route at lines 48–49. PRN rows use current medicine fields at lines 83–89. See the remaining case below. |
| F7 — null browser-history entries in the person record | Addressed | `resources/js/pages/emar/record/show.tsx:115–136,141–146` uses Inertia `router.push` and restores the active location from `page.url`. The earlier raw `pushState(null, …)` path is gone. Back/Forward and return-from-another-page behavior still require Main's browser check. |

### F6 remaining case — historical dose wording after a checked in-place change

The new reader fixes retention but does not select the prescription version effective on the selected day. This is a supported current operation: `app/Services/Medication/MedicationOrderWorkflow.php:148–157` snapshots another version on the existing medicine row, and line 229 publishes it. `app/Models/ClientMedication.php:27–34` updates that same row's prescription payload and `version`.

For example, hold and record yesterday's 5 mg oral order, then check a 10 mg change on the same order today. Yesterday's held dose can remain in the grid while its row heading reads 10 mg because `MedicationRecordDayService.php:33,48–49` resolves today's version. Historical PRN wording has the corresponding current-field path at lines 83–89. The original F6 request explicitly included correct historical dose details, so it cannot be marked fully closed.

Resolve this through the historical reader's applicable version/held evidence; then exercise scheduled and PRN past-day reads after a checked in-place dose/route change, plus cessation and replacement. This report does not propose changing today's recording authority or using either held commit.

## Later UI findings

| Finding | Source status | Current evidence |
| --- | --- | --- |
| UIR-01 — older outstanding P07 tasks stranded behind newest-500 history | Addressed | `app/Services/Medication/Controlled/ControlledProductPayload.php:56–63` applies outstanding predicates for discrepancies, losses, pharmacy-return receipts and witness overrides. `retainOutstanding` at lines 183–188 retrieves all outstanding rows, then merges capped completed history. The original older-open-row/newer-completed-row case is no longer excluded by the cap. |
| UIR-02 — assessment errors sent to a step without the field | Addressed | `resources/js/pages/emar/support/validation.ts:2–38` maps storage/reassessment fields to step 3, confirmation to step 4, scores/checks to step 1 and medicine scope to step 2. `_dialogs.tsx:264–281` changes to the mapped step and requests focus after rendering; its save error callback uses this mapping. Actual keyboard focus remains a browser acceptance check. |
| UIR-03 — every rejected order returned to Source | Addressed | `resources/js/pages/emar/orders/_entry.tsx:248–303` distinguishes source, medicine, spoken read-back and swap-review errors, changes to the relevant step and selects the corresponding focus target. The previous unconditional `setStep(0)` is gone. Invalid witness PIN and rejected prescription fields remain useful browser cases. |

## Remaining pure UI navigation issues for Main

### NAV-01 [P2] — count links fall back to the schedule

`resources/js/pages/emar/ControlledRegister.tsx:277` and `WitnessOverrides.tsx:316` send the count task to `/meds/today?view=controlled`. But `resources/js/pages/meds/today/index.tsx:103–117` has no `controlled` view, and lines 183–184 reject that value and select `schedule`. Its rendered views do not mount the existing controlled-checks component.

`resources/js/components/emar/controlled/controlled-checks.tsx:45,66` exports `ControlledChecks` and `ControlledChecksContent`, but the integrated application has no consumer outside that module. Therefore the count meter currently lands on the ordinary schedule rather than the count task it advertises. Main can resolve the landing/adapter using the already integrated component and server-controlled capabilities, preserving current site scope and grants. No held P07 mutation proposal is needed to correct this navigation gap.

### NAV-02 [P2] — witness-overrides breadcrumb points to an unregistered route

`resources/js/pages/emar/WitnessOverrides.tsx:244` still uses `/emar/witness-overrides`. The registered route is `/emar/safety/witness-overrides` at `routes/emar.php:133`, and the central navigation uses that canonical destination. No `/emar/witness-overrides` alias is registered. Update that breadcrumb to the registered destination; this is a UI URL correction outside the held clinical proposals.

## Earlier navigation/acceptance gaps now addressed in source

- Central navigation retains the frontline boundary: `resources/js/lib/emar-navigation.ts:163,362` gates Safety through lead capability, audit or emergency access. Ordinary frontline medication access does not create another Safety sidebar entry. Today/Safety follow-ups remain explicit scoped destinations.
- Reports use the canonical medication Reports permission at `emar-navigation.ts:174–175,421–453`; Audit additionally requires audit access. P11 house-alert and witness-PIN capabilities are represented at lines 168–169,486–494. Central report links retain the scope helper and query-aware destinations. This does not certify every legacy export seam.
- P03's register now supplies the common hub rail (`resources/js/pages/emar/SelfAdmin.tsx:210`), and its mobile cards expose the shared menu (`:326–334`). The canonical person Support section is mounted in `resources/js/pages/emar/record/show.tsx:336–339`. The legacy support-action page also replaced raw `replaceState(null, …)` with Inertia `router.replace` (`SupportRecord.tsx:107–113`).
- P04 order entry now uses `TimePicker` (`orders/_entry.tsx:742`) and a success pane after `onSuccess` (`:247,317–320`), addressing the raw time-entry/immediate-close gaps.
- P11 no longer labels all dates NZDT. `resources/js/pages/emar/Settings.tsx:804,837` uses Pacific/Auckland wording.

Main still owns visual comparison with the approved mockups, mobile hit areas/menu behavior, error-focus checks, browser history, offline replay, exports and database-backed evidence cases. Those acceptance checks are pending from this independent review's perspective; absence of a new source finding does not establish their completion. The separate clinical report remains the owner of P06 stock/receipt and other clinical findings.
