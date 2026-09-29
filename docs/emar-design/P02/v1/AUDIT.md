# P02 v1 — today’s code vs the design

Grounded on `origin/main` **`ddb8d3af4`** (fetched 30 September 2026). Paths are from the repo root; `EC` = `app/Http/Controllers/Emar/EmarController.php`, `GSS` = `app/Services/Medication/MedicationGovernanceScopeService.php`, `MGD` = `resources/js/pages/emar/components/mar-governance-dialogs.tsx`.

**Verified** = I read the lines myself in this session. **Reported** = a read-only research agent reported it with file:line and I did not re-read it (audit agents over-report — treat these as leads, not facts). **Live** = seen on oblivionfindings.test as Demo Admin at 1440 × 900.

## 1. The person record page today

| # | Finding | Evidence | Status | P02 |
|---|---|---|---|---|
| 1.1 | `/emar/mar` renders `emar/MarCharts`, a legacy **PageHero** with a “LIVE MEDICATION CHART” eyebrow, stat pills, tabs below the hero (Schedule · Due/overdue · PRN · History) and a clinical rail. Breadcrumb “eMAR › MAR Charts” — not Home-rooted. | Live; `MarCharts.tsx:478-570` | Live + reported | PageHeader profile, rail, tier-2, Home-rooted crumbs (mirrors Fleet vehicle profile) |
| 1.2 | An explicit `?client_id` is checked only against the user’s sites (`whereIn('site_id')->findOrFail()`), never the per-client `viewMedications` policy; the auto-picked client *is* policy-checked. Code comment says “403 on denial”; the result is 404. | `EC:1209-1215` vs `EC:1296-1312` | **Verified** | Contract: every entry point runs the same per-person check (build item, Q-list) |
| 1.3 | Two dose-status models: `marData` marks unrecorded doses “missed” after an hour; the grid’s board schedule says “overdue” and never “missed”. | `EC:1373`; `MedsBoardPayloadService.php:142-150` | Reported | One vocabulary (P00): Due · Late · not yet recorded; no “missed” until confirmed |
| 1.4 | Recorded MAR cells still reopen the record wizard. | `MarCharts.tsx:320-324` | Reported | A recorded cell opens its record (with correction chain) |
| 1.5 | Page permissions don’t match their routes: Add medication (`record` vs `orders.manage`), Manage alerts (`settings.manage` vs `orders.manage`), Start driver (`orders.manage` **or** `administer.record` vs `orders.manage`). | `EC:97-120` (verified map); `routes/emar.php:176-205` (verified group) | **Verified** | Every trigger is gated by the route’s real permission; no Add medication on the record (P04) |
| 1.6 | The page receives `interactions` built from **every** active medicine name, with no controlled-drug filter, so controlled names reach the browser payload of people without `medications.controlled.view` (EM-12 leak). Matching is `LIKE %name%`. | `EC:1263`, `EC:1511-1546` | **Verified** | Interactions: recorded pairs only, controlled ones redacted |
| 1.7 | The `allergies` prop is the medication allergy register only; the header Safety meter and the profile MAR tab read the health profile only. EM-07’s combined reader is used by Meds today but not by these. | `EC:1259-1262`; `ClientAllergyRecordService.php:20-27`; `tabs/mar.tsx:94-116`; `ClientSafetyPayload.php:149-192` | **Verified** (EC, service, tab) + reported (meter) | Both lists read everywhere now; one list on the health profile at build (Stephan) |

## 2. The eight dialogs

| Dialog | Today | Status | In P02 |
|---|---|---|---|
| `RecordInrDialog` | Form fields `inr_value, tested_on, target_range_low/high, dose_mg, next_test_date, notes` — **no `client_medication_id`**. The server accepts it as nullable. | **Verified** `MGD:147-170`, `EC:5125-5160` | Record INR pre-selects the warfarin order; “Not linked” is a labelled choice |
| `SyringeDriverDialog` | The server requires `contents.*.client_medication_id`; the form sends `{ name, dose, unit, requires_witness }` only — **every start is refused**. No check or finish UI on the page. | **Verified** `EC:5222-5234`, `MGD:303-340` | Start wizard picks contents from orders; checks list; record a check; finish (needs ≥ 1 check, today’s rule) |
| `ManageAlertsDialog` | Add-only (no list, edit or resolve although the routes exist); suppression block with basis `capacity_assessment · mdt_decision · clinical_judgement · client_preference`; the attention bar returns nothing at 0 alerts, so the first alert can’t be added from the page. | **Verified** `MGD:483-620`, `EC:5037-5071`, `routes/emar.php:196-199`; bar reported (`attention-bar.tsx:37-39`) | Alerts list + Add/Edit/Resolve; the suppression becomes a Switch “Due and late dose alerts” whose Off needs basis + reason + a destructive “Loosens this check” confirm |
| `WarningsDialog` | “Acknowledge & continue” only closes; nothing is stored. | **Verified** `MGD:1008-1016` | “I’ve read them” records who and when (new: one acknowledgement row per person per day) |
| `CorrectionsReviewDialog` | Approve / reject with reason, but no original-vs-corrected view and no attachments; approving your own correction returns `back()->with('error')`, which Inertia treats as success. | Two-person rule **verified** `MedicationAdministrationCorrectionController.php:50-58`; UI gaps reported (`MGD:839-985`) | Before/after cards, the two-person rule shown before anyone tries |
| `MedicationDetailDialog` | Mounted only on `/emar/medications`; Edit and Discontinue not permission-gated. | Reported `pages/emar/_dialogs.tsx:749-1138` | Sectioned WizardShell (Order · How it’s given · Pack photo & supply · Recent doses); order changes link to Orders & reviews |
| `MedicationEventDrawer` | Mounted only on the audit log; “Open on MAR chart” goes to `/emar/mar` **without** the person. | **Verified** `medication-event-drawer.tsx:187,224` | History › All changes › a change, with “Show on {person}’s chart” |
| `InteractionsDialog` | Mounted only on `/emar/medications`; shows no partner or description. | Reported `pages/emar/_dialogs.tsx:1384-1453` | Recorded pairs with who recorded them and an honest “not a database” notice |

## 3. INR (NF-23) and clinical data

| # | Finding | Evidence | Status |
|---|---|---|---|
| 3.1 | Every INR reader passes `allowNullMedication: false`, which keeps only rows whose `client_medication_id` matches an order — an unlinked result is dropped from the action centre, the dashboard INR watch, the MAR page’s own INR list and reports. | `GSS:199-227`; `MedicationOverviewService.php:1078-1094`; `EC:574-582` | **Verified** (reports reader reported: `MedicationReportingService.php:331`) |
| 3.2 | The due/overdue alert generator *does* include unlinked results. | `MedicationAlertService.php:192-234` | **Verified** |
| 3.3 | The INR card reads `target_range_min/max` and `medication_dose`; the server sends `target_range_low/high` and `dose_mg` — target and dose never show. | `clinical-rail.tsx:17-27`; `EC:590-600` | **Verified** |
| 3.4 | “No target” falls into the out-of-range filter and is labelled “Below range”. | `MedicationOverviewService.php:615-625` | Reported |
| 3.5 | Syringe driver routes (`store`, `checks`, `complete`) are all `orders.manage`; completing needs at least one check. | `routes/emar.php:203-205` verified; completion rule reported `EC:5338-5347` | Verified + reported |
| 3.6 | Dose-linked readings (blood glucose, pulse, blood pressure) are columns on `client_medication_administrations`; `ClinicalObservation` (vitals) has no link to medicines. | Reported `ClientMedicationAdministration.php:57-67`; `ClinicalObservation.php:30-50` | Reported |

## 4. Allergies, house moves, photos, support

| # | Finding | Evidence | Status |
|---|---|---|---|
| 4.1 | Two allergy stores: `client_medical_profiles.allergies` (JSON, web-edited as free text) and `medication_allergies` (severity, reaction; written only by the API). No “reviewed” date and no explicit “none known”: an empty list is saved as `[]`. | `ClientAllergyRecordService.php:20-27` verified; tables/models reported | Verified + reported |
| 4.2 | The client’s house is `clients.site_id` with no site history; medicines have no site. After a move the whole history follows the person to the new house and drops out of the old house’s view (inferred by the agent). | Reported `Client.php:26-27`, `SiteClientPlacementService.php:29,106-122`, `GSS:199-227` | Reported |
| 4.3 | Client profile photos are on the **public** disk; P00/P01 treat the person photo as private. | Reported `ClientController.php:3247-3289` | Reported — build item (Approval record: “after client photos move to private storage”) |
| 4.4 | `client_medications.photo_path` exists but is unused. | Reported (migration `2026_03_26_000001…:330-331`) | Reported |
| 4.5 | Support per medicine is `med_scope` JSON on the self-administration assessment with three values (`self_managed · prompted · staff_given`); P00 uses four words. | Reported `EmarController.php:6127-6130` | Reported — D6, P03 |

## 5. Client profile › MAR tab (live, demo client “Playwright Meds”)

| # | Finding | Status |
|---|---|---|
| 5.1 | The header’s **Medications** meter (“5 · active meds · no pending alerts”) is repeated as a body tile “5 ACTIVE MEDICATIONS”, plus Last administration, Controlled drugs and Next review tiles — “a number lives once” is broken. | Live |
| 5.2 | The tab reads its own queries in `ClientController`, not the eMAR payload, so it can disagree with the record. | Reported `ClientController.php:750-788, 887-892, 1649-1659` |
| 5.3 | Allergy banner only when non-empty — no “none recorded” or “couldn’t load” state. | **Verified** `tabs/mar.tsx:94-116` |
| 5.4 | Four link buttons (Daily MAR, eMAR Dashboard, Controlled Drugs, Reviews), a stock card, per-medicine Sign / Give PRN buttons and a purple “Scheduled Medications” header. | Live |

## 6. Bugs found (for the build, not fixed here)

1. Unlinked INR results hidden everywhere except the alert generator (NF-23) — 3.1.
2. INR card never shows target or dose (field-name mismatch) — 3.3.
3. Start syringe driver always refused — §2.
4. Controlled-medicine names in the `interactions` payload (EM-12) — 1.6.
5. Explicit `client_id` skips the per-client `viewMedications` check — 1.2. (The MAR PDF also checks site scope only: `EmarPdfController.php:36-46`, verified.)
6. Warnings “acknowledged” but not stored — §2.
7. MedicationEventDrawer drops the person — §2.
8. Page gates that disagree with their routes — 1.5.
9. Profile MAR tab numbers repeated from the header; allergy states missing — 5.1, 5.3.
