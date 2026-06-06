# Respite NZ — Finish Plan (final close-out, Codex handoff)

**Purpose:** close the last mile so the Respite module can be signed off. The 9-phase NZ build
(`docs/respite-nz-implementation-plan.md`) + the localisation pass (`docs/nz-localisation-plan.md`)
are **done at the data + backend + test layer** — this plan is **only the remaining work**, found by
an audit on 2026-06-06. Read this top-to-bottom; do **not** redo the "verified done" list below.

> **Audit result (2026-06-06):** localisation greps come back empty; `npm run types` = 0; the respite
> suite is **32 tests / 276 assertions green**. The schema for all 9 phases exists and the migration is
> clean (idempotent guards, reversible, MySQL enum expansion). The core safety gates are real and tested.
> **BUT** the work has a consistent shape: Codex built and unit-tested the **backend**, and several of those
> backend endpoints/validated fields **have no UI to drive them**, plus **three safety gates the gap
> analysis specifically asked for are absent or fake**. Those are this plan.

---

## Verified DONE — do not rebuild

- **Localisation:** the discovery grep (`NDIS|DSS|GDPR|ICO|CQC|DPIA|DPO|deputy|Care Act 2014|Court of
  Protection|Disability Support Services`) and the soft sweep (`Data Subject|72-hour|lawful basis|Article
  6/15/33/35|DHB|Ministry of Health`) both return **zero** hits in `app resources database routes tests config`.
- **Readiness ring is genuinely typed.** `RespiteBooking::readiness()` (`app/Models/RespiteBooking.php:128-169`)
  returns 10 typed segments; `confirm()` blocks on them via `assertReadinessForBooking()`
  (`RespiteBookingController.php:289-303`). Funding, interpreter-when-required, agreement, cultural-placement
  and setting-restriction segments are real gates. ✅
- **Check-in med-rec gate** (`RespiteStayController::guardAdmissionMedicationReconciliation` `:418-456`),
  **discharge med-rec + compliance block** (open incidents / unreviewed restraint / pending notifiables;
  `:458-494`, hard block), and **per-home capacity gate** on `sites.respite_capacity`
  (`RespiteBookingController::assertCapacityForBooking` `:257-287`) are all real warn/​block logic, tested.
- **Restraint → shared `RestraintEvent`** (FK `restraint_events.stay_id`) and **incident → shared
  `ClientIncident` / `NotifiableIncident`** (FK `client_incidents.respite_stay_id`, deadline clock) are wired
  to the real shared models — **backend + routes** (`routes/respite.php:85-86`). No parallel respite copies. ✅
- **Evidence pack** is now a **typed manifest** with a real seal gate (`RespiteEvidencePackController::buildManifest`
  `:311-380`, `seal()` throws on incomplete required items `:232-244`).
- **Family-portal consent binding:** withdrawal of family-information consent disables the sensitive portal
  surfaces (`ClientConsentObserver`), and settings can't re-enable without active consent — tested.
- **Funding completion:** `funding_source`/`funding_reference`/`service_agreement_id`/`funding_status` carry
  referral → request → booking; discharge posts consumed nights to the agreement once. Tested.
- **Cultural, carer, NHI(+`nhi_hash` dedup), status vocab (`no_show`/`on_hold_pending_funding`), waitlist
  promote, copay/recurrence/cultural-leave fast-follow columns** all exist and the **intake wizard captures
  the cultural + carer + NHI + funding fields** (`modals/referral-intake.tsx`).

---

## The gap in one sentence

Several **live backend endpoints and validated columns are unreachable from the UI**, and **3 medico-legal
gates are stored-but-not-enforced** — so a user can't actually perform (or is not actually stopped by) the
NZ-fit behaviours the gap analysis was about. Parts A + B are needed to honestly call respite "done"; Part C
is deploy; Part D is polish.

---

## Part A — Missing / fake safety gates (P0: finish these or the NZ-fit claim is hollow)

### A1. Anaphylaxis acknowledgement at check-in is **fake** (display-only)
- **Now:** `life_threatening` allergies are surfaced only as a `requiresAcknowledgement` boolean in the
  workspace *display* payload (`RespiteWorkspaceController.php:466`). `checkIn()` (`RespiteStayController.php:88`)
  never reads it — there is **no acknowledgement captured or gated**. This is exactly the "stored but never
  gated" pattern the audit was meant to kill.
- **Do:** in `checkIn()`, if the client has any active `MedicationAllergy` with `severity = life_threatening`,
  require an `anaphylaxis_acknowledged` input (EpiPen location + escalation note) — block check-in (hard-warn
  + override+reason, mirroring the med-rec gate) until acknowledged; persist it (extend the stay's
  `admission_risk_screen` JSON or a column) and include it in the arrival handover + evidence pack manifest.
- **Verify:** a feature test — a client with a life_threatening allergy cannot check in without the ack; the
  ack appears in the evidence manifest.

### A2. No capacity/consent block for a client who **lacks capacity**
- **Now:** `consent_authority` is `nullable` on store/update (`RespiteBookingController.php:70, 171`) and
  `confirm()` (`:209-220`) never references it. The readiness consent segment (`RespiteBooking.php:142`) is
  satisfied by `consent_records` **OR** `consent_authority` and is **not capacity-aware** — so a booking for a
  `lacks_capacity` client can be confirmed with **no recorded substitute decision-maker**. (Gap analysis P1
  "No record of who may legally consent.")
- **Do:** make the consent readiness segment capacity-aware: when the client is flagged `lacks_capacity`,
  require a `consent_authority` of `activated_epoa_welfare` / `welfare_guardian` / `parent_guardian` (self is
  invalid) before the segment is complete → `confirm()` blocks. Keep the soft path for capable clients.
- **Verify:** a test — confirm is blocked for a lacks_capacity client until a welfare-authority is recorded.

### A3. HDC Code-of-Rights / informed-consent / advocate-offered capture is **unbuilt**
- **Now:** `code_of_rights_provided` / `consent_to_respite` / `advocate_offered` exist **only in the docs** —
  no column, no field, no readiness item. (Gap analysis P1 "No HDC rights / complaints".) The booking has PPPR
  `consent_authority` but not the rights record.
- **Do:** add the typed rights/consent record (booleans + recorder + datetime: `code_of_rights_provided`,
  `consent_to_respite` with capacity basis, `advocate_offered`, `format_provided`) — captured in the intake or
  confirm pop-up, made a readiness item that blocks 100% until present, and added to the evidence manifest's
  `consent_rights` check (which today only verifies `consent_authority` + agreement-signed,
  `RespiteEvidencePackController.php:327`).
- **Verify:** readiness can't reach ready without rights recorded; manifest reflects it.

> A "lightweight Complaint entity + Log-complaint action" (gap P1, paired with HDC) is **also unbuilt** — see
> D4. If you have appetite, fold it in here; otherwise it's a fast-follow.

---

## Part B — Wire the UI to the live backend (P1: orphaned endpoints / uncaptured fields)

Hard rule for this module: **every action/detail is a pop-up modal, not a page; no stub buttons.** All the
endpoints below already exist and validate — the only work is the modal + the POST.

### B1. "Record restraint" action on the Stays detail
- **Endpoint exists:** `POST /respite/stays/{stay}/restraints` → `recordRestraint`
  (`RespiteStayController.php:235-282`, route `routes/respite.php:85`). The Stays UI only shows a read-only
  `unreviewedRestraints` pill (`panes/stays.tsx:167-171`) — **no button calls it.**
- **Do:** add a "Record restraint" pop-up on the stay detail (restraint type, description, within-support-plan
  vs PRN, `authorised_by`, etc.) that POSTs to the endpoint. **Also fix the backend:** `recordRestraint`
  accepts `behaviour_support_plan_id` as nullable input but **never resolves the client's active
  `BehaviourSupportPlan`** (`:241`) — auto-resolve and pre-fill it so within-support-plan authorisation is real.

### B2. "Log incident" action on the Stays detail
- **Endpoint exists:** `POST /respite/stays/{stay}/incidents` → `recordIncident`
  (`RespiteStayController.php:284-368`, route `:86`; spawns `NotifiableIncident` when `is_notifiable`).
  **No UI calls it.**
- **Do:** add a "Log incident" pop-up (nature, severity, `is_notifiable`) that POSTs to the endpoint and shows
  the resulting notifiable deadline. (Minor backend nit: the deadline is hard-coded `now()->addDay()` `:323-340`
  regardless of authority/type — make it type-aware if cheap, else leave.)

### B3. Admission medication-reconciliation **capture** at check-in
- **Now:** the only way a `RespiteMedicationReconciliation` row is created is **inside the override path**
  (`RespiteStayController.php:445`), and the check-in UI only offers a free-text `med_rec_override_reason`
  (`workspace.tsx:176`). **There is no UI (or endpoint) to actually *perform* a reconciliation** — so in
  practice the med-rec gate is always satisfied by override, never by a real recon.
- **Do:** add a med-rec **store endpoint** + a "Reconcile medications" pop-up (status, `reconciled_by/at`,
  source, `count_received`, `discrepancies` JSON, first-dose-due) that completes the recon and sets the
  booking's `medications_reconciled` — so check-in passes by genuine reconciliation, with override as the
  exception, not the only path.

### B4. Structured discharge med-rec in the discharge flow
- **Now:** `guardDischargeMedicationReconciliation()` (`:458-471`) accepts a structured
  `discharge_medication_reconciliation` array (validates `medicines_returned_to` + `count`), but the discharge
  UI sends only `discharge_summary` free-text (`workspace.tsx:189`, via the shared `ReasonDialog`).
- **Do:** replace the free-text-only discharge with a structured pop-up (medicines returned to /count/received
  by, changed-during-stay, GP/pharmacy handover sent, whānau briefing acknowledged) that sends the array.
  Backend is ready.

### B5. Consent-authority **capture** before confirm
- **Now:** the confirm path (`OnboardModal.submit`, `respiteActions.confirmBooking`) POSTs an empty `{}`
  (`modals/onboard.tsx:22-31`, `workspace.tsx:380`); `consent_authority` is **only displayed read-only**
  ("Who consents", `detail-modal.tsx:124`). The store/update endpoints validate it but nothing captures it.
- **Do:** add the `consent_authority` picker (self / activated_epoa_welfare / welfare_guardian /
  parent_guardian / other) + name/contact to the confirm pop-up, POSTing real values. This is the UI half of A2.

---

## Part C — Deploy + demo data (do at ship time)

### C1. Post-deploy seeder (REQUIRED for retention enforcement)
Deploys run `migrate --force` only — **seeders do not run**. After deploying, run:
```
php artisan db:seed --class=RespiteRetentionPolicySeeder --force
```
`RespiteRetentionPolicySeeder` is registered in `DatabaseSeeder` and idempotent (`updateOrInsert`).
- **Permissions note (de-risked):** the audit confirmed **no new `respite.*` permission keys** were added —
  the only `RbacSeeder` change is the privacy GDPR→Privacy Act *relabel*. The respite permissions were already
  seeded on the live server on 2026-06-05, and `RespiteReadinessTest` asserts RBAC covers all routed respite
  permissions. So **respite will not 403 on deploy.** Re-running `RbacSeeder --force` is harmless if you want
  the privacy label refresh, but not required for respite to work.
- **Do NOT** run the legacy `ConsentTypesSeeder` (non-idempotent — duplicates rows). The GDPR consent type was
  renamed to "Data Processing (Privacy Act 2020)", not deactivated; the respite flow uses a boolean
  `third_party_collection_consent`, not that picker, so no action needed.

### C2. Respite demo data (decide: build or accept empty)
- **Gap:** there is **no `RespiteDemoSeeder`** and `OperationsDemoSeeder` seeds zero respite data; nothing sets
  `sites.offers_respite` / `sites.respite_capacity`. So oblivionfindings.com renders the respite workspace
  **empty** and none of the new NZ fields are exercised in the live demo (consistent with the 2026-06-05 finding
  of "no respite homes, all counts 0").
- **Do (recommended):** write a `RespiteDemoSeeder` (idempotent) that flips 1–2 demo sites to
  `offers_respite` + a `respite_capacity`, and seeds a few referrals/requests/bookings/stays across the pipeline
  **populated with the new NZ fields** (funding_source + ServiceAgreement link, cultural snapshot, NHI, carer +
  strain, a completed admission med-rec, one restraint + one notifiable incident, an evidence pack). Register it
  and run `php artisan db:seed --class=RespiteDemoSeeder --force` after deploy. Without this, the module can't be
  demoed or visually verified on the server even though the code is correct.

---

## Part D — Fast-follow polish (P2: data exists, surfacing/automation incomplete — not blockers)

- **D1. Critical-alerts banner.** Today alerts render as inline pills (`panes/stays.tsx:178-190`) / a `dl` row
  (`detail-modal.tsx:146`), absent from the bookings card, and `requiresAcknowledgement` is ignored. Make a
  persistent high-contrast banner on booking **and** stay detail.
- **D2. Render readiness as typed segments.** `ReadinessRing` is a single-arc % gauge (`panes/bookings.tsx:103-140`)
  and only the next-pending label is shown. Render the per-segment list/legend (complete/pending/attention)
  beside the ring — the typed data is already sent.
- **D3. Funding surfacing.** `budgetRemaining` and agreement `endsAt`/`reviewDueDate` are on the type
  (`types.ts:29,33`) but never rendered (only hours/carer-days are). Add a **funding-expiry / unverified-funding
  lane** to the overview (gap P3) reusing `ServiceAgreement::expiringSoon`. Add the **NHI duplicate-match hint**
  at intake step 1 (backend `nhi_hash` dedup already works; the UI shows no "matches existing client" hint), and
  show NHI in existing-client mode too.
- **D4. Complaint entity + "Log complaint" action** (gap P1, pairs with A3) — a lightweight `Complaint`
  (source/received_at/nature/acknowledged_at/resolution/escalated_to_hdc) with a Stays action and an
  open-complaints overview tile; add a `complaints` entry to the evidence manifest (currently absent, alongside a
  missing `bsp_acknowledgements` entry).
- **D5. Daily-note → incident automation.** `incident_occurred`/`sensitive_flag` on a daily note/handover create
  nothing today (`RespiteDailyNoteController::store:89-107`; `RespiteEvent` has no listeners; `linked_incident_id`
  is a manual link). Add the prompt/listener that offers to create a `ClientIncident` (reusing B2's path).
- **D6. Compliance "lane" is a single integer.** `complianceAttention` is one merged count
  (`RespiteWorkspaceController.php:216-218` → `overview.tsx:152-159`). Break it into the structured lane the gap
  asked for: notifiable past/near deadline (compare `notification_deadline`), restraint awaiting review, BSP
  awaiting ack, missing consent/rights.
- **D7. Misc surfacing:** "Emergency respite" should be an **action** that creates referral+request+confirmed
  booking in one step (today `isEmergency`/`fastTracked` are display-only pills, `panes/requests.tsx:175-181`);
  add a **ServiceAgreement selection** control at intake/confirm (today agreements are display-only); add a
  per-home **"Full"** badge using the unused `RespiteHome.full`/`available` (`types.ts:13-14`); surface
  `cancellation_source` on cancelled bookings. Verify `RespiteEvidencePackController::export()` (`:285`) produces
  a real artifact (one read suggested a placeholder) — implement the PDF/zip if it's a stub.
- **D8. Backend nit:** add `whaikaha` to `SafeguardingExternalReportController` validation `in:` list (`:21`) —
  it's in the documented authority set but missing from the rule.

---

## Cross-cutting gotchas (unchanged from prior handoffs)

- **Shared checkout:** stage explicit paths (`git add <path>`), never `-A`/`-u`; verify branch; push to `main`.
- **Tests run NON-parallel** here (per-worker DBs aren't migrated). Touch-scoped: the new tests live in
  `tests/Feature/Respite/` — keep them green and add ones for A1/A2/A3/B3.
- **`php` is only on PATH via PowerShell** in this environment, not the bash shell — run `php artisan test ...`
  from PowerShell.
- **Don't regress "no duplication with the client profile."** Care records live on the client profile; respite
  surfaces only respite-unique workflows and links out. New gates link to the **shared** models
  (`RestraintEvent`, `ClientIncident`, `NotifiableIncident`, `MedicationAllergy`, `BehaviourSupportPlan`,
  `ClientConsent`), never a parallel respite copy.
- **Verification bar per change:** `npm run types` = 0, `npm run build` = 0 (use
  `NODE_OPTIONS=--max-old-space-size=8192`), touched `tests/Feature/Respite/` green.

## Suggested order

A1 → A2 → A3 (cheap, high medico-legal value) → B5 (pairs with A2) → B1 → B2 → B3 → B4 (orphaned endpoints) →
C1/C2 (ship + demo) → D as capacity allows. After A+B+C, respite is honestly closeable; D is polish.

---

## Codex close-out notes (2026-06-06)

Implemented:

- A1: check-in now hard-blocks active life-threatening allergy clients until an anaphylaxis acknowledgement is captured with EpiPen location and escalation notes; the acknowledgement is persisted in the stay risk screen and appears in the evidence manifest.
- A2/A3/B5: booking readiness is capacity-aware, blocks lacks-capacity clients unless a lawful welfare authority is recorded, and adds Code of Rights / informed-consent / advocate-offered capture to confirm/onboard flows plus the evidence manifest.
- B1/B2/B3/B4: the respite workspace now has modal-driven stay actions for medication reconciliation, check-in, restraint, incident, complaint, and structured discharge medication reconciliation. Within-plan restraint records auto-link the client's active behaviour support plan.
- D4/D5/D6/D7/D8: added a lightweight respite complaint table/action/manifest item, daily-note incident automation, structured compliance lanes, funding/home-full surfacing, real evidence-pack JSON export, and `whaikaha` external-report validation.
- C2: added and registered an idempotent `RespiteDemoSeeder` with an Aroha Respite home, NZ funding/rights fields, active stay, med-rec, allergy acknowledgement, BSP-linked restraint, notifiable incident, complaint, evidence pack, and a pending booking.

Local verification:

- `npm run types` passed.
- `NODE_OPTIONS=--max-old-space-size=8192 npm run build` passed.
- `php artisan migrate --force` passed.
- `php artisan db:seed --class=RespiteRetentionPolicySeeder --force` passed.
- `php artisan db:seed --class=RespiteDemoSeeder --force` passed.
- `php artisan test tests/Feature/Respite` passed: 37 tests, 306 assertions.

Ship commands required after pulling `main` on the dev server:

```bash
php artisan migrate --force
php artisan db:seed --class=RespiteRetentionPolicySeeder --force
php artisan db:seed --class=RespiteDemoSeeder --force
```

---

## Audit + close-out verification (Claude session, 2026-06-06)

Independently audited the close-out commit (`a210584b`) against the actual code (not the completion
note) — read each enforcement path and ran the suite. **All A/B/C/D items confirmed genuinely done, not
stored-only:** A1 anaphylaxis gate throws without ack+EpiPen+escalation (`RespiteStayController.php:521`);
A2 consent segment is capacity-aware; A3 HDC rights gate + manifest; B1–B5 all wired via real modals
(`modals/stay-actions.tsx`, `booking-confirm.tsx`) POSTing populated bodies to live endpoints (incl. the
new med-rec capture route and BSP auto-resolve); D4 complaint entity, D5 daily-note→incident automation,
D6 structured compliance lane, D7 real JSON `streamDownload` export, D8 `whaikaha` validation. Migration
`..._140000` idempotent + reversible; `RespiteDemoSeeder` idempotent + registered.

Closed the only residual nits found:

- **Added** `RespiteNzWorkflowCompletionTest::test_logging_a_complaint_persists_and_surfaces_in_the_evidence_manifest`
  — the complaint feature was wired but had **no test**; now proves it persists a `RespiteComplaint` and
  surfaces in the evidence manifest as open/incomplete, then resolves to complete (also exercises the
  export endpoint).
- **Added** `RespiteFundingCompletionTest` › "the welfare consent-authority readiness segment blocks
  independently of the rights segment" — the existing A2 test bundled the `consent` and `consent_rights`
  segments (both incomplete in its before-state); this isolates the welfare-authority requirement with all
  rights fields valid, so a regression that left the consent segment always-complete can no longer hide.
- **Fixed** `RespiteEvidencePackController::export()` audit log: `export_format` was hard-coded `'pdf'`
  while the endpoint streams JSON → now `'json'` (matches the actual artifact).

Final verification: `npm run types` 0 · `npm run build` 0 · localisation grep empty ·
`php artisan test tests/Feature/Respite` green (39 tests). **Respite is closeable.**
