# Respite NZ Gap — Implementation Plan (Codex handoff)

Turns **[docs/respite-nz-gap-analysis.md](respite-nz-gap-analysis.md)** (31 gaps: 13 P1 / 12 P2 / 6 P3)
into a sequenced, PR-by-PR build. **Read the gap doc for the full per-gap "Recommendation" —
this plan is the ordering, dependencies and what's already done.**

> **Run order:** do **[docs/nz-localisation-plan.md](nz-localisation-plan.md) FIRST.** It establishes the
> single canonical NZ funder list, extends `fin_funding_streams.funder_type`, and removes the GDPR/`deputy`
> framing — Phases 1, 5 and 8 below depend on that. This plan starts after localisation is merged.

## Guiding principles

1. **Reuse, don't duplicate.** Nearly every gap is a *cross-link to a model that already exists* —
   `ServiceAgreement`, `RestraintEvent`, `NotifiableIncident`, `ClientIncident`, `ClientConsent`,
   `MedicationAllergy`, `safeguarding_alerts`, `BehaviourSupportPlan`, `DataRetentionPolicy`,
   `FamilyPortalSetting`. Add FKs/relations and surface them; do **not** build parallel respite copies.
   (We already removed a duplicative respite "Records" tab for this reason — care records live on the
   client profile. Same rule here: cultural fields, allergies, consent, incidents belong on the **client**
   or the shared engines, linked from the stay.)
2. **The readiness ring is the central gate.** Most P1s become a typed segment on the pre-stay readiness
   ring (funding approved · consent/decision-maker · admission med-rec · interpreter arranged · signed
   agreement · cultural needs). Build a real `RespiteBooking::readiness()` that returns typed segments
   instead of the current heuristic %.
3. **Migrations auto-run on deploy; seeders do NOT.** New permission keys / seeded policy rows need a
   `php artisan db:seed --class=… --force` after deploy. New nullable columns are safe.
4. Each phase = **one PR**, verified (`tsc` 0 · `npm run build` 0 · touched PHP tests green, non-parallel),
   committed to `main` per the shared-checkout rules (stage explicit paths, never `-A`).

## Already shipped this session — DO NOT redo

- `respite_referrals.funding_source` + `funding_reference` (migration `2026_06_05_120000`), captured at
  intake from `App\Support\Respite\RespiteFundingSource` (the canonical NZ list), displayed on the referral
  card/detail. **Gap #1/#2 are PARTLY done** — Phase 1 carries it forward + adds the ServiceAgreement link
  and `funding_status` (those are NOT done).
- The one-page tabbed `/respite` workspace, intake wizard, onboard (= confirm booking), decline/reject/
  discharge **with-reason** pop-ups (shared `ReasonDialog`), "Open client profile" deep-links, the
  `RespiteObligationProvider` (respite shows in the Site Calendar), Tasks tab.
- The Records tab was built then **removed** (duplicated the client profile) — don't re-add it.

---

## Phase 1 — Funding completion: ServiceAgreement link + funding_status + carry-through
**Gaps:** P1 "Funding is free-text and unlinked", P1 "Pipeline models a fresh referral, not a draw-down",
P2 "referrer_type mislabels NZ referrers". **Depends on:** localisation (funder enum).

- Migration: add `funding_source` + `funding_reference` to `respite_booking_requests` and `respite_bookings`
  (carry from the referral on accept/approve); add nullable `service_agreement_id` FK to both; add
  `funding_status` enum (`not_required|pending_approval|approved|declined|expired`) + `funding_approved_ref`
  + `funding_approved_at` to bookings.
- Intake/approve: let the coordinator pick the client's **active `ServiceAgreement`**; surface its
  `budget_remaining` / `hours_remaining` / `ends_at` (accessors already exist) on the request + readiness.
- Readiness ring: a **"Funding approved (or not_required)"** segment; `approve()` warns-with-override when
  `funding_status = pending_approval`. On `discharge()`, post consumed nights to `hours_used`/`budget_used`.
- Convert `referrer_type` to a stored enum: drop `DHB`→`Te Whatu Ora`, add `Whaikaha`, `EGL connector / LAC`,
  `ACC case manager`; when the type implies a funder, pre-select `funding_source`.
- **Verify:** approve→request/booking carry funding; readiness blocks/warns correctly; a feature test for
  ServiceAgreement selection + funding_status transitions.

## Phase 2 — Status vocabulary + per-home occupancy + waitlist promotion
**Gaps:** P2 "Booking/stay statuses lack cancellation/no-show/hold; waitlist is a dead-end",
P2 "No signed service/placement agreement gate".

- Migration: booking status += `no_show`; add `cancellation_source` (provider/family_whanau/client/funder/
  illness) + `cancellation_notice_hours`; add `on_hold_pending_funding` (status or flag); stay
  `discharge_reason` enum (planned/early_by_family/clinical/incident/transferred_to_hospital);
  request `waitlist_position` + `priority` + `expected_availability_date`; booking `agreement_status`
  (not_required/pending_signature/signed) linked to `ServiceAgreement.signed_at`.
- Compute per-home/per-date occupancy against `sites.respite_capacity`; surface "full" on the calendar/
  overview; gate confirm on available capacity (warn-with-override). "Promote from waitlist" action that,
  on a freed bed (cancel/no_show), lists matching waitlisted requests. "Send agreement for signature" +
  signed-agreement readiness tick (non-emergency).
- **Verify:** occupancy counts; freeing a bed re-offers to a matching waitlist entry; the calendar gantt
  already exists — feed real capacity into it.

## Phase 3 — Admission as a transfer of care (med-rec + critical alerts + risk screen)
**Gaps:** P1 "Admission is not a transfer of care", P1 "Discharge is a single free-text box",
P2 "Seizure/IDDSI/admission risk screen".

- Migration: add the readiness columns the design assumed but that don't exist
  (`eligibility_checks`, `consent_records`, `pre_arrival_checklist` JSON) + a real
  **`respite_medication_reconciliations`** table (admission + discharge) — status, by/at, source, count,
  discrepancies(JSON), first-dose-due — linked to the stay. (Replace the imaginary `medications_reconciled`
  boolean.)
- `checkIn()` refuses (hard-warn + override+reason) until admission med-rec is complete for any client with
  active `ClientMedication`. `discharge()` gains a structured discharge med-rec (returned-to/count/changed/
  GP-pharmacy handover/whanau briefing), gated for clients with meds.
- Persistent **"Critical alerts" banner** on booking + stay, eager-loading severe `MedicationAllergy`
  (life_threatening → anaphylaxis ack at check-in), active `ClientMedicationAlert`, active
  `safeguarding_alerts`; included in arrival handover + evidence pack.
- Admission risk screen (falls/skin/mobility) + mandatory IDDSI food/fluid read-back; if client has
  `epilepsy`/`seizure_duration_escalation_seconds`, require an active acknowledged `medical` (seizure)
  `RespiteRiskPlanActivation` before confirm.
- **Verify:** check-in/discharge gates; a client with a life_threatening allergy forces the ack.

## Phase 4 — Wire the compliance engines (restraint · incidents · rights · complaints · evidence)
**Gaps:** P1 "Restraint bypasses the register", P1 "Serious events never reach NotifiableIncident +
discharge gate", P1 "No HDC rights / complaints", P2 "Evidence pack contents discretionary",
P2 "No safeguarding quick-raise + England-framed authorities".

- Restraint: add `stay_id` FK + `RespiteStay::restraintEvents()`; "Record restraint" action on the Stays tab
  creates a `RestraintEvent` pre-filled with site/client + the active `BehaviourSupportPlan`
  (within-support-plan/enduring vs PRN, `authorised_by`). Surface unreviewed-restraint count.
- Incidents: on a daily-note/handover with `incident_occurred`/`sensitive_flag`, prompt to create a
  `ClientIncident` and, where serious, spawn a `NotifiableIncident` (deadline clock). Discharge readiness
  blocks/hard-warns on open incidents / unreviewed restraint / un-notified events.
- Rights/consent step (booleans + recorder + datetime): `code_of_rights_provided`, `consent_to_respite`
  (capacity basis), `advocate_offered`, `format_provided`; readiness can't hit 100% until present.
  Add a lightweight **`Complaint`** entity (reuse the `SafeguardingExternalReport` `hdc` enum) + "Log
  complaint" action + open-complaints overview tile.
- Evidence pack: a **typed manifest** auto-including consent/rights, restraint events + reviews,
  incidents/notifiable + refs, complaints, BSP acks, med-rec; completeness checklist gates "seal".
- Safeguarding: "Raise safeguarding concern" action (pre-linked to client/stay/site); **update
  `safeguarding_external_reports.authority_type` to NZ values** (police, hdc, health_nz, whaikaha,
  coroner, other — coordinate with localisation); `is_minor` flag at triage → Oranga Tamariki/Police path.
- Add a **"Compliance attention"** lane to the overview (notifiable past/near deadline, restraint awaiting
  review, BSP awaiting ack, missing consent/rights).
- **Verify:** restraint/incident creation from a stay; discharge gate; evidence manifest completeness.

## Phase 5 — Consent & PPPR (who legally consents) + family-portal binding
**Gaps:** P1 "No record of who may legally consent; UK/GDPR-framed", P1 "Health info flows with no
consent-to-share; portal not bound to consent". **Depends on:** localisation (`deputy`/GDPR relabel).

- Client legal-status fields: `has_activated_epoa_welfare` (+ attorney name/contact),
  `welfare_guardian_appointed` (+ scope), `advance_directive_on_file`.
- Readiness requires "who is consenting for this stay" (self / activated EPOA / welfare guardian /
  parent of <18); block confirm if a `lacks_capacity` client has no recorded substitute decision-maker.
- `approve()` server-guard: block/warn-with-override (record justification, mirror break-glass) when no
  active Information-Sharing `ClientConsent` exists; consent chip on the request card + overview.
- Bind each `FamilyPortalSetting.show_respite/show_care_notes/show_incidents` to an active matching
  `ClientConsent`; a consent-withdrawal **observer** auto-disables the flags. (Relabel of `deputy`→
  welfare_guardian/attorney_welfare and the GDPR legal-basis text is done in localisation — verify it.)
- **Verify:** confirm blocked without a decision-maker; withdrawing consent flips portal visibility off.

## Phase 6 — Te Tiriti / cultural (from first contact)
**Gaps:** P1 "Cultural identity invisible at referral; no iwi/hapu/marae or interpreter",
P2 "Daily-note omits taha wairua/whanau", P2 "Cultural/religious dietary not carried to catering".

- "Cultural snapshot" on the referral + an intake step: `ethnicity` (reuse client ETHNICITIES),
  `is_maori`, `interpreter_required` + `interpreter_language` + `interpreter_arranged`,
  `cultural_considerations` — prefilled from the Client, badged on cards, **`interpreter_arranged` a HARD
  readiness item**. Add nullable `iwi`/`hapu`/`marae` columns **to `clients`** (the wizard's "Whakapapa"
  step is currently dishonest) under an "If Māori" disclosure; echo read-only on the stay overview.
- `RespiteDailyNote`: add `wairua` (cultural/spiritual wellbeing, same scale) + `whanau_connection`
  (visited/phoned/video/none + note) — complete Te Whare Tapa Whā.
- Structured cultural/religious **dietary** flag (halal/kosher/faith-veg/fasting/rongoā/other) on the
  client → defaulted into the booking request + a "dietary confirmed with receiving home" readiness item +
  pushed to the destination Site's Meal Planner for the stay dates.
- `whanau_decision_maker` (link to a `NextOfKin`) + `decision_basis` + a `whanau_consent_confirmed`
  readiness checkpoint.
- **Verify:** a crisis referral shows is_maori/interpreter at triage; interpreter_arranged gates readiness.

## Phase 7 — Carer entity + crisis fast-path
**Gaps:** P1 "No carer/whanau record or carer-strain; no emergency fast-path".

- Intake: primary carer name/relationship/contact + `carer_strain_level`
  (low/moderate/high/at_breakdown) + `carer_breakdown_flag`; optional `booker_type`
  (clinical_referrer/family_whanau/self).
- "Emergency respite" action: one transaction creating referral + request + **confirmed booking**, marked
  `is_emergency`/`fast_tracked` so readiness completes post-admission. Pin `urgency=crisis` /
  `carer_breakdown_flag` rows to the top of "needs your attention" with a visible **SLA timer**.
- **Verify:** emergency action lands an admitted-ready booking in one step; crisis rows pin with SLA.

## Phase 8 — Data, privacy & records completeness
**Gaps:** P1 "No NHI at referral", P2 "Onboarding loses third-party-collection provenance",
P2 "No retention policy for respite tables", future "DSR export omits respite", future "GDPR consent
taxonomy", future "OPC breach linkage". **Depends on:** localisation (GDPR consent cleanup).

- **NHI at referral:** add nullable encrypted `nhi_number` to `respite_referrals` (carry to requests)
  reusing `Client::nhiValidationRules()`; surface in intake step 1; prefill onto onboard. **Duplicate
  detection requires a deterministic `nhi_hash` column on `clients`** (the encrypted column can't be
  plaintext-queried — we hit this) — add `nhi_hash` (sha256 of normalised NHI) + index, populate on
  Client save (model hook) + a backfill command, then dedup by hash in intake. *(This is the only item
  that touches the clients table broadly — keep it isolated.)*
- Persist `referrer_type`/`referrer_name` as the **collection source**; stamp a HIPC Rule 2/3 note onto the
  client/consent record on onboard; add a "client/representative informed of collection (Rule 3)" checkbox.
- Seed `DataRetentionPolicy` rows for the respite models (10-yr for converted stays per the Health
  (Retention of Health Information) Regulations 1996; shorter window for declined/never-converted
  referrals); verify the anonymiser nulls encrypted NHI.
- Extend `DataSubjectRequestController::export` to include `respite_records` (referrals/bookings/stays/
  handovers/comms). Point the respite consent picker at the NZ-framed `StandardConsentTypesSeeder` and
  deactivate `Data Processing (GDPR)`. Add "Report as privacy breach" → pre-fill `DataBreachLog` from a
  respite incident/handover, linked via `respite_linked_refs`.
- **Verify:** NHI dedup links instead of duplicating; a DSR export now contains respite history.

## Phase 9 — Fast-follows (future-scoped; pick up as capacity allows)
**Gaps (all `future`):** co-payment / client contribution; recurring/repeat respite blocks (RRULE +
`series_id` on requests/bookings, decrement allocated days per occurrence); cultural-placement check +
tangihanga/cultural-leave bed-hold sub-state; restrictive-practice/locked-environment context on the
booking; funding-expiry/unverified-funding **overview lane** (reuse `ServiceAgreement.expiringSoon` +
`CheckExpiringAgreementsJob`); Carer Support **day burn-down** (days_allocated/used + entitlement-year).

---

## Cross-cutting gotchas
- **Shared checkout:** stage explicit paths, verify branch, push to `main`.
- **Permissions are seeded, not migrated**, and deploys skip seeders — any new permission key 403s on the
  server until its seeder runs. Prefer reusing existing `respite.*` keys; if you add keys, add a seeder.
- **Encrypted NHI** can't be plaintext-queried (Phase 8 `nhi_hash`).
- Keep `MSD`/`ACC` (NZ); never reintroduce `NDIS`/`DSS`/`GDPR`/`CQC` (the localisation pass removes them).
- Don't regress the "no duplication with the client profile" decision — link to client/shared models.
- Tests: run **non-parallel** (per-worker DBs aren't migrated here); update demo seeders so the live demo
  exercises the new fields.

## Suggested order of value
P1 funding (1) → admission med-rec/alerts (3) → compliance wiring (4) → consent/PPPR (5) → cultural (6)
→ carer/crisis (7) → status/occupancy (2) → data/privacy (8) → fast-follows (9). Re-sequence to taste,
but Phase 1 and the localisation pass come first.
