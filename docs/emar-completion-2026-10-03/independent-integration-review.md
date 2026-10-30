# Independent P01/P02 integration review — 3 October 2026

## Decision

Source review found **two P1 ownership/privacy defects and five P2 correctness defects** in the requested candidates. No P0 was identified. Fix the ownership defects before integrating these readers, and resolve the applicable P2 findings before accepting the affected screens as complete. This is not a test or browser sign-off.

Only this report was written by this review. No application changes, checkout changes, integration, database commands, builds, browser servers or pushes were performed. Main owns integration and verification. The current delegation authorizes Main's review; no renewed screenshot approval is required here.

## Exact snapshots and acceptance sources

Baseline: `9747cf7cb654c2ef441e8f60c7ea1b5918081925`.

| Candidate | Exact commit reviewed | Scope |
| --- | --- | --- |
| P02-1b recovery | `6ef2c7dc8f0b7865e409f08b2d4d5132db5fcf1c` | Client-profile day grid, recording seam, NZ-day client exports |
| P02 day-grid polish | `612413adc2c334c514e1b28964070f4442bff440` | Grid layout and retained day response during refresh |
| P02 latest outcome PDF | `6974f61762695ae36dc5efdd72e8ae14a21b6a75` | Latest slot outcome with earlier refusal retained as history |
| P02-2 reading shell | `9957e19b3c81051cc6254ed0617f6b46fe0244c6` | Medicines, medicine detail, support reading, default-legacy switch |
| P01 C3 | `6ca61115508ed7239f4138168da7071a155f3d67` | Meds today header, schedule, PRN lists, follow-ups, paginated activity |

At inspection, `codex/emar-p02-completion` resolved to `9957e19b3…` and `codex/emar-p01-reviewed` to `6ca611155…`. Findings refer to those immutable snapshots, not evolving worker files. All paths below are repository-relative and all line numbers refer to the stated commit.

Acceptance references: P01 v2 at `d96e29a52f20c8e29c66428da6eb7e318eb8d673`, P02 v1 at `28a5a2ddf236257c5d188d4853659b815442c099`, P00 v5 at `ff3bff860fd295e7fa5c045766a66a27ac65cb3a`, plus the subsequent approved client-profile one-day MAR amendment and current shared decisions recorded in `mockup-inventory.md`. Single organisation across approved sites; the relevant boundaries are person ownership, roles, permissions, current approved sites and controlled-medicine privacy.

## Actionable findings

### F1 — P1: Refusal follow-ups can expose a different person's medication

**Location:** `6ca611155`, `app/Http/Controllers/Emar/WorkerMedsController.php:442–454` (particularly the eager load at 446–447 and filter at 453–454).

**Trigger:** An open legacy/imported follow-up has an accessible person's `client_id`, but its `client_medication_administration_id` points to another person's administration. Alternatively, its administration belongs to the local person but points to a medication owned by another person. Request `/meds/today` as a reader who may see only the local person.

**Result:** The new query scopes only the follow-up's `client_id`. Its filter checks that a medication exists and its controlled flag, but never verifies that the administration, medication and follow-up share the same person. The response exposes the foreign medication name/id and refusal/scheduled timestamps, labelled with the local person's preferred name. It also offers a re-offer target for that foreign order. The write gate can reject the action, but the read has already disclosed clinical information.

**Fix direction:** Resolve the associated administration through canonical same-person ownership before mapping the follow-up. Require `followup.client_id = administration.client_id = medication.client_id`, and preserve controlled concealment and effective evidence rules. The existing `MedicationGovernanceScopeService::scopeCanonicalClientMedicationRows` and `RefusalFollowUpController::withCanonicalFollowup` demonstrate the boundary; selecting `administration.client_id` is necessary if validating after loading.

**Verification case:** Add mismatched follow-up/administration and mismatched administration/medication fixtures. Neither foreign medicine name nor administration/order identifiers or times should appear anywhere in the Inertia response. Keep a valid local ordinary refusal visible, and retain the valid controlled refusal concealment case.

### F2 — P1: Profile PRN day counts read administrations without person ownership

**Location:** `9957e19b3`, `app/Http/Controllers/Emar/ClientMedicationDayController.php:153–159`.

**Trigger:** A malformed legacy/imported administration has another person's `client_id`, but the local person's visible PRN `client_medication_id`, a `given` outcome and an administration time on the selected NZ day. Open the local person's profile MAR tab.

**Result:** The new per-day query filters by medication IDs and effective evidence only. It omits `client_id` and the canonical ownership scope used by `MedsBoardPayloadService::canonicalAdministrations`. Consequently `given_on_day` and `last_given_on_day` include the foreign person's administration. The profile displays incorrect clinical evidence and discloses the other person's administration time, even though the shared PRN limit calculation rejects that malformed evidence.

**Fix direction:** Apply canonical same-person medication/administration ownership and the requested person IDs before aggregating the day count. Keep the effective-evidence and NZ-day bounds already present.

**Verification case:** Create one valid local PRN administration and one foreign-person administration referring to the local medication, with a later time. The local response must count only the valid administration and use its time. Test an earlier selected NZ day as well as today.

### F3 — P2: Awaiting-check and self-managed doses distort the Recorded meter and completion message

**Locations:** `6ca611155`, `resources/js/pages/meds/today/index.tsx:226–227,277–284`; `resources/js/pages/meds/today/_schedule.tsx:188–215`.

**Trigger:** A scheduled dose's due time has passed, its order change is awaiting its check, and it has no administration. With this as the only row, open Meds today. The same denominator problem applies to projected self-managed slots, which the list maps to `upcoming`.

**Result:** The Recorded denominator includes every past scheduled time except `away`, so the waiting dose becomes an outstanding staff recording obligation. Meanwhile `nothingLeft` treats `pending_check` as finished and displays **“Everything due so far is recorded”** despite that dose having no record. The page can show `0 of 1 recorded`, a waiting/blocked row, and a green completion claim simultaneously. Self-managed doses are also counted as staff doses although the projection explicitly excludes them from staff-dose totals.

**Fix direction:** Derive completion facts from the canonical eligibility/state rules, excluding awaiting-check and self-managed obligations as well as away. Distinguish “nothing you can record now” from “everything is recorded” when waiting orders exist. Expose the required canonical state/aggregate fields rather than inferring them solely from the list status.

**Verification case:** A page with only an unrecorded awaiting-check dose must not claim every dose was recorded or add it to the staff Recorded denominator. Mix one recorded staff dose, one awaiting-check dose, one self-managed dose and one away dose; assert the agreed denominator and truthful caption.

### F4 — P2: Hidden controlled doses produce false zero and all-clear messages

**Locations:** `6ca611155`, `resources/js/pages/meds/today/index.tsx:223–224,252–264`; `resources/js/pages/meds/today/_schedule.tsx:188–214`.

**Trigger:** A worker lacks `medications.controlled.view`, and their shift has an overdue controlled dose. The response correctly omits its named row and supplies `hidden_controlled_overdue > 0`. There are either no ordinary rows or only completed ordinary rows.

**Result:** The new Late meter counts visible schedule rows only, displaying **“0 / Nothing late”**. The schedule can also say **“No scheduled doses on your shift today”** or **“Nothing left to record on your shift”**. These contradict the supplied hidden-dose count and the approved rule that aggregate totals include controlled doses for every reader. A footer concealment caption does not make the all-clear statements true.

**Fix direction:** Compute header totals and completion/empty states from complete scoped aggregates, while continuing to omit controlled medicine/person details from cross-person lists. Use an appropriate concealed-dose caption instead of an all-clear when hidden obligations remain. `hidden_controlled_overdue` already permits a truthful Late count; other meter aggregates need equivalent server facts.

**Verification case:** A reader without controlled view and one hidden overdue dose sees a nonzero Late total and no all-clear/empty claim. Assert the controlled medicine name, dose, notes and identifiers remain absent. Repeat with one completed ordinary dose alongside it.

### F5 — P2: Due-soon rows are presented as already inside their window

**Locations:** `6ca611155`, `resources/js/pages/meds/today/index.tsx:223,252–259`; `resources/js/pages/meds/today/_rows.tsx:253–256`.

**Trigger:** Configure “shows as due soon” earlier than the administration window opens. For example, a 10:00 dose, due-soon setting 60 minutes, window opening 09:45, and current time 09:15.

**Result:** `ScheduledDoseStates::listStatus` deliberately maps canonical `not_due + due_soon` to list status `due` (`app/Services/Medication/DoseSlots/ScheduledDoseStates.php:219`). C3 assumes every `due` row is “Due now”, counts it in that meter and prints “window until …”, although the window has not opened. Opening the common dialog then says it is not yet due. This is a new presentation inconsistency; the save/dialog guard remains in place.

**Fix direction:** Preserve canonical state or the requirements' `window` field. Show due-soon as such with the opening time, and count only doses actually inside their window as Due now. P02 already adds a `state` field to the shared schedule payload; integrate that field deliberately with C3's type and consumers.

**Verification case:** Exercise due-soon before opening, exactly at opening and exactly after closing under nondefault timing settings. Before opening, neither the meter nor row may claim Due now. Keep the common dialog and profile day-grid wording consistent.

### F6 — P2: Historical profile days lose medicines stopped or superseded since that day

**Locations:** `9957e19b3`, `app/Http/Controllers/Emar/ClientMedicationDayController.php:71–79,147`.

**Trigger:** Record a dose yesterday on a generated/covered day, then cease its order today (`active = false`, ceased state), or supersede it with a replacement. Select yesterday in the new profile day grid. Repeat with a PRN order given yesterday and ceased today.

**Result:** Scheduled rows come from `scheduleForDate`, whose `scheduledOrders` delegates to `ScheduledDoseStates::listedOrders`: current active or awaiting-check orders only. The PRN strip also starts from today's `prnMedications(..., $now, ...)`, which uses `active()`. The earlier medicine/record disappears even though coverage is complete; the profile can report no medicines for that day. This reader was adequate for the current board but is not a historical-order catalogue. Current medicine dose/route fields are also used for the historical row, so historical version presentation needs explicit review.

**Fix direction:** Read the selected day's held slots and applicable historical order versions, preserving canonical person/CD boundaries. Include medicines with effective evidence or an order in effect on that day even if stopped or superseded now. Avoid changing today's board semantics merely to support this new historical caller.

**Verification case:** Generate and record yesterday's scheduled and PRN orders, cease them today, and request yesterday. Both must remain present with the correct outcome/day count and historical dose details. Superseding an order must preserve the earlier version on its earlier day; today's display should use the current version.

### F7 — P2: Record subview navigation creates invalid Inertia history entries

**Location:** `9957e19b3`, `resources/js/pages/emar/record/show.tsx:100–114`.

**Trigger:** Enable `EMAR_PERSON_RECORD=p02`, open a person's record, select two different sections/subviews, then use browser Back/Forward; also navigate to another page and back to one of these entries.

**Result:** `go()` creates `window.history.pushState(null, …)` entries while leaving Inertia's page URL/state unchanged. The installed Inertia core's `handlePopstateEvent` treats a null state as a hash navigation and replaces it with the current Inertia page's URL/state (`node_modules/@inertiajs/core/dist/index.esm.js:1383–1389`). The new local popstate listener therefore competes with framework restoration: the address can revert to the old section URL, and a null entry cannot restore the record's page/props after leaving it. The advertised URL-backed sections are not reliable under normal history navigation.

**Fix direction:** Use the application's Inertia-aware navigation convention, updating the page URL and preserving valid page history state. Do not push a null history entry for record navigation. Keep section/subview state in sync after framework restoration.

**Verification case:** Navigate Medicines → Support → another Support subview; Back and Forward must restore each section and its matching query string. Leave the record, return through history, then refresh: the same person's record/subview must remain selected. This needs Main's browser slot; no browser verification was performed here.

## Integration notes and work still pending

- **Shared payload merge:** Both branches edit `MedsBoardPayloadService::scheduleForDate` around the window fields. P02 adds canonical `state` plus both window instants; C3 adds the window instants. Keep one copy of the instants, retain P02's state and C3's recorded facts/activity query, and update C3's `ScheduleRow` type deliberately. A textual conflict resolution alone will not fix F3/F5.
- **Recording seam:** At `9957e19b3`, `resources/js/components/emar/recording/use-dose-recorder.tsx:3–4,93–112` still opens the legacy `RecordDoseWizard`/`PrnWizard`. C3 uses the common `RecordDoseDialog`, already present at the baseline. C5 is explicitly planned to replace this seam, so its absence is a completion dependency rather than an unexpected defect in this partial recovery. Before accepting the combined P01/P02 package, demonstrate the same outcomes, less/more-than-ordered handling, second-person confirmation, refusal follow-up/re-offer and chosen PRN effect-check time from profile and Meds today.
- **P02-2 is partial by design:** `config/medications.php` defaults the person record to `legacy` until P02-4. The two built reading sections hide the unbuilt sections. Do not enable this partial record as a completed P02 product or count Chart/Allergies/Clinical/History as implemented. No separate approval request is needed to continue authorized implementation/review.
- **Recovered wording:** `resources/js/components/emar/record/reading.tsx` still labels support `independent` as “Independent”. Update visible wording to the later approved “Self-managed” when completing support integration; retaining a legacy storage value does not require retaining its old label.
- **Retained day response:** `use-medication-day.ts:25,36` retains old data across every request, including a changed person identifier. The current profile does not supply a person key to `MarTab`. Add a targeted person-change test if navigation can preserve this component; the new record's `useRecordJson` already keys its returned state by URL. This was not promoted to a finding because ordinary GET navigation normally remounts the profile, and no browser reproduction of a preserved-person transition was performed.
- **Inherited PDF limitation:** The newly linked range PDF still filters orders by present `active = true` in `EmarPdfController::marChart`. This predates these commits. Stopped historical orders can therefore appear in CSV history but be absent from the PDF. Keep this visible in the P09/report completion scope and do not describe the existing PDF as a complete historical MAR.

## Reviewed safeguards and verification limits

The new person endpoints invoke `MedicationRecordAccess`; medicine detail lookup additionally constrains the order to the requested person and returns 404 for concealed controlled details. P02-2's JSON responses carry no-store behavior, and `useRecordJson` suppresses a prior URL's data. These are useful existing safeguards, not a reason to omit the aggregate ownership checks in F1/F2.

The export changes bind explicit date ranges to inclusive NZ-day UTC bounds and use effective clinical evidence. The new PDF slot grouping takes the highest-ID effective record, matching the existing slot projection's winner convention, and keeps earlier refusal history. No additional defect was identified in the ordinary refusal → given re-offer case. The source includes focused tests for that case and for NZ-day CSV boundaries; those tests were inspected, not executed.

No database or UI tests were run during this independent read-only review. Existing candidate tests cover valid local follow-ups, ordinary controlled concealment, same-person endpoint denial, day stepping, NZ-day exports and ordinary re-offer presentation. They do not establish the mismatched evidence, stopped historical orders, due-soon/window distinction, complete hidden-dose aggregate states or Inertia history cases above. Main should use its reserved synthetic-database/build/browser verification slot for the affected cases after fixes; this report makes no runtime pass claim.
