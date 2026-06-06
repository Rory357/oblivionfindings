# Respite - New Zealand Market Gap Analysis

_Generated 2026-06-05 from a 6-lens multi-agent audit (funding/Whaikaha - Nga Paerewa standards - Te Tiriti/cultural - clinical/safeguarding - NASC pipeline fit - privacy/NHI), then synthesised. Per-lens raw detail lives in the `respite-nz-gap-audit` workflow run._

## Executive summary

The respite redesign is built on a genuinely strong backend — eMAR, risk-plan activations, RestraintEvent, NotifiableIncident, ServiceAgreement (with full NASC/Whaikaha budget, hours and expiry fields), DataRetentionPolicy, consent and family-portal machinery all already exist — but the respite pipeline is almost entirely disconnected from it, and several columns the design context assumes on respite_bookings (eligibility_checks, consent_records, funding_verification, pre_arrival_checklist, medications_reconciled) do not actually exist in the migration. The single biggest theme is funding: it is captured only as a free-text string on the booking request (nothing on the referral), uses none of the three funder vocabularies already in the codebase, is never linked to the ServiceAgreement that holds the allocation, and has no authorisation status — so a NZ provider can commit a bed and roster staff against money that was never approved or is already exhausted/expired, which is the sector's classic unrecoverable-revenue failure. The funder picklists are also still Australian-contaminated (NDIS/DSS, plus disestablished 'DHB' and contradictory 'Whaikaha (MoH)'), seeded that way in the demo data. The second theme is that admission and discharge are bare status flips: check-in does no medication reconciliation, surfaces no allergy/anaphylaxis or safeguarding alerts, and never records who may legally consent under the PPPR Act (the consent model is still UK/GDPR-framed with a 'deputy' role that doesn't exist in NZ); discharge is a single free-text box with no med return or GP/pharmacy handover and no escalation gate, and restraint/serious incidents during a stay never reach the registers HealthCERT audits. The third theme is that Te Tiriti and cultural-safety obligations — cultural identity, iwi/hapu/marae, interpreter need (NZSL/te reo), taha wairua/whanau wellbeing, and who in the whanau decides — begin far too late (only in the onboarding wizard) or not at all, despite a step literally labelled 'Whakapapa'. Encouragingly, the highest-value fixes are cheap in a rebuild because they are mostly typed enums, a handful of nullable columns, and cross-links to models that already exist; folding the funding enum/ServiceAgreement link, NHI-at-referral, the cultural snapshot, the admission med-rec/alerts banner, the restraint/incident wiring, PPPR consent capture, family-portal consent binding, and the cancellation/no-show + occupancy vocabulary into this UI/data rebuild would close the great majority of the NZ-fit risk. Privacy/retention completeness (DSR export, respite retention policy, GDPR-taxonomy cleanup, OPC breach linkage), Carer Support day burn-down, recurring/emergency one-action booking, co-payment, and deeper cultural-placement/leave handling are well-scoped as fast-follows.

## Workflows the redesign implies

### Planned NASC-allocated respite booking (the dominant path)

**Steps:** NASC/family referral received -> triage (risk + funding) -> accept -> booking request against an allocation -> approve (creates booking + syncs staff Shift) -> confirm (projects calendar event) -> pre-stay readiness ring -> check-in/admit -> stay -> discharge + evidence pack

**NZ nuance:** Most NZ respite is a draw-down against a NASC-allocated Carer Support day budget or an Individualised/Flexible Funding budget that ALREADY exists in a ServiceAgreement (nasc_assessor_name, nasc_support_package_ref, whaikaha_reference, allocated hours/budget). The pipeline currently models every booking as a fresh clinical referral and never links the ServiceAgreement, so 'does this person have funded days left?' is unanswerable at intake and consumed nights are never decremented against hours_used/budget_used.

### Family/whanau self-booking against an existing allocation

**Steps:** Family (booker, not clinical referrer) initiates a request against allocated days -> coordinator confirms funded nights remain -> approve -> readiness -> stay

**NZ nuance:** Whaikaha/MyWhaikaha lets whanau book relief directly against their entitlement; the model only knows a clinical 'referrer', has no booker_type (family/whanau vs clinical vs self), and cannot show remaining funded nights, so a family-initiated draw-down is indistinguishable from a clinical referral.

### Crisis / emergency respite (carer breakdown or hospital-discharge-tonight)

**Steps:** Urgent/crisis referral -> (ideally) one-action create of referral+request+confirmed booking -> immediate admit -> back-fill paperwork/readiness post-arrival

**NZ nuance:** urgency='crisis' and risk_level='critical' are only labels; the flow still forces referral->request->approval->booking with no fast-path, no is_emergency/fast_tracked flag, no SLA timer, and no carer_breakdown_flag — yet carer breakdown is the #1 driver of urgent NZ respite and Te Whatu Ora ARC emergency beds are allocated same-day.

### Onboard new client from an approved request

**Steps:** Approved request -> 'Onboard client' opens 8-step Add-client wizard PREFILLED from referral+request -> creates client + confirmed booking + marks request onboarded

**NZ nuance:** Third-party-collection provenance (NASC/GP/DHB referrer as source under HIPC Rule 2/3) is lost on prefill; NHI is only captured here (not at referral), so the same person can be referred twice under two new client shells before a health identifier ever exists; funder picklist (FUNDING_OPTIONS) still contains Australian NDIS/DSS and contradictory 'Whaikaha (MoH)'/'DHB'.

### Pre-stay readiness & check-in (admission as a transfer of care)

**Steps:** Booking -> readiness ring (funding/consent/meds/risk-plan/transport/agreement) -> check-in -> admitted/active

**NZ nuance:** checkIn() is a bare status flip with zero pre-conditions; the booking table has NONE of the eligibility_checks/consent_records/funding_verification/pre_arrival_checklist/medications_reconciled columns the design context assumes. Admission med-rec, allergy/anaphylaxis acknowledgement, who-consents (PPPR), HDC Code-of-Rights provision, interpreter (NZSL/te reo) arrangement, and a signed service agreement are all ungated.

### Stay management: daily wellbeing, handover, incidents, restraint

**Steps:** Admitted -> active -> (extended) -> daily notes + handovers -> incident/restraint capture -> discharge

**NZ nuance:** Daily notes score only taha tinana + taha hinengaro (mood/appetite/sleep/engagement) — taha wairua and taha whanau (Te Whare Tapa Wha) are absent; restraint/seclusion during a stay never reaches the existing RestraintEvent register or its authorisation gate; serious incidents never reach the NotifiableIncident engine (WorkSafe/Health NZ deadlines); there is no complaint (HDC Right 10) capture and no safeguarding quick-raise from the stay screen.

### Cultural / whanau leave during a stay (e.g. tangihanga)

**Steps:** Active stay -> resident must attend tangihanga/whanau obligation -> temporary absence with bed-hold -> return

**NZ nuance:** Stay statuses are admitted->active->extended->discharged with no leave/bed-hold sub-state, so a tikanga-required absence (tangihanga) breaks the occupancy timeline and bed counts and isn't evidenced as culturally supported; also intersects Whaikaha/NASC bed-night funding rules.

### Discharge & funder reconciliation / evidence pack

**Steps:** Discharge (free-text summary) -> return meds -> GP/pharmacy handover -> seal evidence pack -> claim consumed days against funder

**NZ nuance:** discharge() requires only a free-text box: no discharge med-rec / return-of-meds count / GP-pharmacy handover, no escalation gate for open incidents or unreviewed restraint, no posting of consumed nights back to the ServiceAgreement/Carer Support burn-down, and the evidence pack contents are operator-discretionary rather than assembled from the certification-relevant record set.

### Calendar / per-home occupancy & waitlist promotion

**Steps:** Confirmed bookings -> per-home occupancy timeline -> on cancellation/no-show a bed frees -> promote matching waitlisted request

**NZ nuance:** sites.respite_capacity exists but no per-date occupancy is computed against it, so 'home full' is unknowable; waitlisted is a dead-end status (no position/priority/expected-availability, no promotion workflow); booking status lacks no_show and a typed cancellation_source, so DNA rates and freed-bed re-offers can't be reported and funded days may be wrongly consumed.

### Overview triage: 'needs your attention'

**Steps:** Aggregate funnel + bed occupancy + this-week arrivals/departures + compliance/funding/carer alerts

**NZ nuance:** Surfaces only funnel/occupancy/arrivals today; missing the high-value NZ lanes: funding unverified/expiring (ServiceAgreement ends_at/review_due_date + existing expiringSoon scope/CheckExpiringAgreementsJob), notifiable-event notification deadlines, unreviewed restraint, missing consent/rights, carer-at-breakdown, and open privacy breaches.

## Top recommendations to fold into this rebuild

1. Typed funding_source enum captured at the REFERRAL step (carried onto request+booking) drawn from ONE shared NZ list (extend fin_funding_streams.funder_type with carer_support/egl_ind_funding/te_whatu_ora/msd; deprecate bare 'moh'), and rip the Australian NDIS/DSS + contradictory 'Whaikaha (MoH)'/'DHB' labels out of the wizard FUNDING_OPTIONS and the demo seeders — pure data/enum work that unblocks all downstream funder logic and reporting.

2. Link the pipeline to the existing ServiceAgreement: add nullable service_agreement_id to respite_booking_requests + respite_bookings, surface budget_remaining/hours_remaining/ends_at on the request and readiness panel, add funding_status (not_required|pending_approval|approved|declined|expired), and make 'funding approved or not_required' an explicit readiness-ring segment — turns respite from a blind clinical referral into a visible draw-down against a known NASC/Whaikaha allocation.

3. Capture NHI at referral (nullable encrypted nhi_number reusing Client::nhiValidationRules) with an inline duplicate-match against existing clients, prefilled into the onboard wizard — prevents duplicate client shells and gives every record a Te Whatu Ora identifier from first contact.

4. Add a 'Cultural snapshot' to the referral + intake wizard (ethnicity, is_maori, interpreter_required + language + interpreter_arranged, cultural_considerations) and add the missing iwi/hapu/marae columns to clients so the wizard's 'Whakapapa' step is honest — with interpreter_arranged a hard readiness-ring item; cheap fields that satisfy Te Tiriti/HDC Right 5 duties that currently begin too late.

5. Make admission a real transfer of care: a structured medication_reconciliation record gating check-in for any client with active meds, plus a persistent 'Critical alerts' banner on the booking/stay eager-loading severe allergies + clinical + safeguarding alerts (with an anaphylaxis acknowledgement for life_threatening allergies) — reuses existing MedicationAllergy/safeguarding_alerts data already on the Client.

6. Wire respite into the existing compliance engines instead of free-text: a 'record restraint' action that creates a RestraintEvent (add stay_id FK) with BSP authorisation, an incident path that can spawn a ClientIncident/NotifiableIncident with its deadline clock, and a discharge gate that hard-warns on open incidents/unreviewed restraint — the models already exist; respite just needs the cross-links.

7. Capture who legally consents (self / activated EPOA-welfare / welfare guardian / parent of <18) on the booking and require it before confirm, plus relabel ClientConsent's 'deputy'->welfare_guardian/attorney_welfare and swap GDPR wording for HDC/Privacy Act 2020 — small relabel, large medico-legal correctness gain under PPPR.

8. Bind family-portal show_respite/show_care_notes/show_incidents to an active matching ClientConsent and auto-disable them on consent withdrawal (observer) — closes a live privacy hole where whanau keep seeing data after consent is revoked.

9. Add the NZ reporting vocabulary the funnel needs: no_show + typed cancellation_source + on_hold_pending_funding on bookings, a typed stay discharge_reason, and per-home/per-date occupancy computed against the already-existing sites.respite_capacity so 'home full' and freed-bed re-offer to the waitlist actually work.

10. Add a lightweight carer record + carer_strain_level/carer_breakdown_flag to intake and pin carer-breakdown/crisis rows to the top of the overview with an SLA — recognises that carer breakdown is the #1 driver of urgent NZ respite, which the current clinical-referrer-only model can't even represent.

## Prioritised gaps (31)

### P1

#### Funding is free-text and unlinked: no typed funder source, no ServiceAgreement link, no authorisation status across the whole pipeline

- **Scope:** this-rebuild | **Domain:** Funding & eligibility
- **Gap:** Funding is a single free-text string (funding_reference) on respite_booking_requests only (validated nullable|string|max:255); respite_referrals has NO funding column at all and the intake form captures none, so the funder is unknown at triage. respite_bookings has no funding_verification column (the migration shows only an 'approvals' JSON — the design context's funding_verification/eligibility_checks/consent_records/medications_reconciled columns do NOT exist) and no service_agreement_id FK. There is no typed funding SOURCE, no funder-authorisation/prior-approval status, no link to the ServiceAgreement that already holds budget/days/expiry, and consumed nights are never decremented against hours_used/budget_used. So a bed is committed and staff are rostered against money that may never have been approved or may already be exhausted/expired.
- **NZ context:** NZ respite is paid by structurally different funders that gate eligibility differently — Whaikaha (disability, ex-MOH since 2022), Carer Support subsidy (day-based), NASC-allocated days, EGL/Individualised/Flexible Funding (capped budgets), ACC (injury), Te Whatu Ora (ex-DHB, disestablished 1 Jul 2022), MSD — most of which are FINITE allocations with review/expiry dates after which authority lapses. Delivering ahead of authorisation or beyond the NASC allocation is the single biggest cause of unrecoverable revenue for NZ providers.
- **Recommendation:** Add a typed funding_source enum captured at the REFERRAL step and carried onto request+booking (whaikaha|carer_support|contracted_respite|egl_ind_funding|nasc_allocated|acc|te_whatu_ora|msd|private|other), plus funder_reference and funding_notes. Add nullable service_agreement_id to respite_booking_requests and respite_bookings; let intake/approve pick the client's active ServiceAgreement and surface its budget_remaining/hours_remaining/ends_at on the request and readiness panel. Add funding_status (not_required|pending_approval|approved|declined|expired) + funding_approved_ref/at, make 'funding approved or not_required' an explicit readiness-ring segment, and warn (don't hard-block) on approve when still pending. On discharge, post consumed nights back to hours_used/budget_used.

#### Reuse the existing NZ funder enum (fin_funding_streams.funder_type) — and purge the Australian NDIS/DSS and stale DHB/MoH labels — instead of inventing a third picklist

- **Scope:** this-rebuild | **Domain:** Funding & eligibility
- **Gap:** Three divergent funder vocabularies already exist and respite uses none: (1) fin_funding_streams.funder_type is a real DB enum (whaikaha,acc,nasc,private_pay,moh,other) — confirmed in schema, but currently unseeded/unused; (2) the Add-client wizard FUNDING_OPTIONS = [NDIS,'Whaikaha (MoH)',ACC,'DHB / Te Whatu Ora',Private,Other]; (3) service_agreements.funding_body/funding_type are free strings — and the demo seeder literally seeds funding_body => ['NDIS','MSD','Private','DSS','ACC']. NDIS and DSS are AUSTRALIAN, 'Whaikaha (MoH)' is internally contradictory, and 'DHB' was disestablished in 2022. A fourth picklist would make funder reporting unjoinable.
- **NZ context:** Correct current NZ funders/regulators: Whaikaha – Ministry of Disabled People, Te Whatu Ora – Health New Zealand (DHBs gone 1 Jul 2022), ACC, MSD, NASC allocation agencies, plus EGL/Individualised Funding and Carer Support as funding MECHANISMS. NDIS/DSS belong to Australia; bare 'MoH' is legacy for disability support post-2022.
- **Recommendation:** Promote one shared enum: extend fin_funding_streams.funder_type to add carer_support, egl_ind_funding, te_whatu_ora, msd and deprecate bare 'moh'; seed it. Drive the respite funding_source, the Add-client wizard FUNDING_OPTIONS, and service_agreements.funding_type from that single list. Remove NDIS/DSS and the 'Whaikaha (MoH)'/'DHB' labels from the wizard AND from OperationsDemoSeeder/SystemClientsSeeder.

#### Admission is not a transfer of care: check-in does no medication reconciliation, surfaces no allergies/clinical/safeguarding alerts, and the columns to gate it don't exist

- **Scope:** this-rebuild | **Domain:** Clinical safety
- **Gap:** RespiteStayController::checkIn() is a bare status flip to 'active' with zero clinical pre-conditions, and the claimed booking.medications_reconciled column does not exist (the respite migration has no eligibility/consent/med fields on respite_bookings). No admission med-rec record (status/by/at/source/count/discrepancies/first-dose-due) is captured. Separately, the Client already carries MedicationAllergy up to 'life_threatening' with drug-class matching, ClientMedicationAlert, and polymorphic safeguarding_alerts (vulnerable_adult/risk_to_self/capacity_concerns) — but NONE of it is eager-loaded or rendered on the booking or stay screen (stays/show.tsx shows transport/notes/handovers/risk plans but no allergy banner). Relief staff unfamiliar with the resident get no anaphylaxis prompt at the bedside.
- **NZ context:** A respite admission is a transfer of care: the resident arrives with their own meds/MAR and the home must reconcile medicines, dose, allergies and 'last given' before administering. Nga Paerewa NZS 8134:2021 medicines-management criteria + HQSC/Health NZ medication reconciliation on transfer; Medsafe Medicines Care Guides apply to facility respite beds; HQSC 'alerts at point of care' for allergy/anaphylaxis.
- **Recommendation:** Add a real medication_reconciliation record to the stay (not a boolean): status (not_started/in_progress/completed), reconciled_by/at, source (own_meds/blister_pack/pharmacy_supply/GP_list), count_received, discrepancies_found(JSON), first-dose-due. Make check-in refuse to complete (or hard-warn with override+reason) until admission med-rec is completed for any client with active ClientMedication rows. Add a persistent high-contrast 'Critical alerts' banner at the top of the booking and stay pages eager-loading severe medicationAllergies, active ClientMedicationAlert and active safeguarding_alerts; for any life_threatening allergy require an anaphylaxis response (EpiPen location + escalation) acknowledged at check-in; include the alert summary in handover 'arrival' notes and the evidence pack.

#### No record of who may legally consent for the stay; the consent model is UK/GDPR-framed, not NZ PPPR

- **Scope:** this-rebuild | **Domain:** Clinical safety / Regulatory
- **Gap:** Whether the resident consents themselves or someone consents for them is the single most important medico-legal fact at a respite admission and it is missing: the booking has no consent verification and check-in does not establish who may consent. The existing ClientConsent model is framed for England — given_by_relationship lists 'deputy' (a Court of Protection role that does not exist in NZ) and the consent_types migration cites 'GDPR Article 6 basis' — and it is not referenced anywhere in respite. There is also no structured capture of activated EPOA (personal care & welfare), welfare guardian, or advance directive on the Client.
- **NZ context:** Protection of Personal and Property Rights Act 1988 (PPPR): only a welfare guardian or activated Enduring Power of Attorney (Personal Care & Welfare) can consent where the person lacks capacity — 'next of kin' alone cannot; HDC Code Right 7 (informed consent / presumption of competence). NZ has no 'deputy' and is not under GDPR.
- **Recommendation:** Add structured legal-status fields to the Client (has_activated_epoa_welfare + attorney name/contact, welfare_guardian_appointed + scope, advance_directive_on_file). In intake/readiness require selection of 'who is consenting for this stay' (self / activated EPOA / welfare guardian / parent of <18) and block confirmation if a client flagged lacks_capacity has no recorded substitute decision-maker. Relabel ClientConsent's 'deputy' to NZ terms (welfare_guardian, attorney_welfare) and replace the GDPR legal-basis text with HDC Code / Privacy Act 2020.

#### Restraint/seclusion during a stay bypasses the restraint register and its authorisation gate

- **Scope:** this-rebuild | **Domain:** Regulatory / Clinical safety
- **Gap:** RespiteRiskPlanActivation supports behaviour/safety/medical/mobility/communication plan types but has NO restraint/restrictive-practice type and NO relation to the existing RestraintEvent register; RespiteStay has no restraintEvents() relation and the Stays tab has no 'record restraint' action. A restraint, PRN sedation or seclusion used during a stay would be captured (if at all) only as a free-text daily note, never creating a restraint_events row with enduring-vs-PRN authorisation, within_support_plan/deviation_reason, authorised_by, post-incident support and review. Relief staff applying a restrictive intervention to an unfamiliar resident with no recorded authorisation is a major clinical-governance and human-rights exposure.
- **NZ context:** Nga Paerewa NZS 8134:2021 Section 5 (restraint & seclusion minimisation) mandates a restraint/seclusion register with approval/authorisation, timely post-event review and medical check; HealthCERT audits sample restraint records. The org already models this in RestraintEvent + BehaviourSupportPlan.restrictive_practice_type — respite simply bypasses it.
- **Recommendation:** Add a 'restraint / restrictive practice' action on the Stays tab that creates a RestraintEvent linked to the stay (add stay_id FK + RespiteStay::restraintEvents()), pre-filling site_id/client_id and surfacing the client's active BehaviourSupportPlan so within_support_plan (enduring) vs ad-hoc PRN is recorded with authorised_by. Require any 'behaviour' risk plan that includes restraint to reference an authorisation before activation. Surface an unreviewed-restraint count in day-N progress and the overview, and feed restraint events into the evidence pack.

#### Serious adverse events in a respite bed never reach the NotifiableIncident engine, and discharge has no escalation gate

- **Scope:** this-rebuild | **Domain:** Regulatory
- **Gap:** RespiteStay has no incidents() relation and no path to the mature NotifiableIncident model (which already encodes WorkSafe / Health NZ–Te Whatu Ora authorities, notification_deadline, site_preserved) or to ClientIncident's WorkSafe fields. A fall-with-fracture or death in a respite bed is recorded as a daily note with incident_occurred=true and stops there. RespiteStayController::discharge() requires only a free-text discharge_summary and does not check for open incidents, unreviewed restraint, or a triggered serious-adverse-event before allowing discharge.
- **NZ context:** National Adverse Events Policy (Health NZ / Te Whatu Ora) severity-assessment-code reporting, Nga Paerewa adverse-event management, and WorkSafe notifiable-event duties under HSWA 2015 (preserve scene, notify ASAP). These are time-bound statutory obligations auditors check for breach.
- **Recommendation:** On daily-note/handover with incident_occurred or sensitive_flag, prompt to create a ClientIncident and, where injury is serious/notifiable, spawn a NotifiableIncident with the deadline clock. Add a discharge readiness check that blocks (or hard-warns) when the stay has open incidents/unreviewed restraints/un-notified notifiable events. Add a 'compliance attention' lane to the overview: notifiable events past/near notification_deadline, restraint awaiting review/medical check, BSP activations awaiting acknowledgement, consent/rights missing — each deep-linking to the stay.

#### No NHI at referral/intake — only at full client onboarding — so triage and duplicate-detection run with no health identifier

- **Scope:** this-rebuild | **Domain:** Data, privacy & records
- **Gap:** respite_referrals.client_id is a NOT-NULL FK to clients and nhi_number lives only on clients (encrypted, validated ^[A-Z]{3}\d{4}$ via Client::nhiValidationRules). A referral/booking-request cannot record or verify an NHI until the person is already a full client, and the 4-step intake wizard and referrals/requests tabs have nowhere to capture or display it. So triage, bed allocation and duplicate-detection happen with no health identifier, and the same human can be referred twice under two new client shells.
- **NZ context:** The National Health Index (NHI) is the unique NZ patient identifier mandated by Te Whatu Ora for all health and disability records; correct NHI at intake is the basis for safe record-linking and for HIPC 2020 Rule 1 (collect only what's necessary) and Rule 8 (accuracy before use). Whaikaha/NASC-funded providers are expected to record NHI on the client health record.
- **Recommendation:** Add a nullable encrypted nhi_number to respite_referrals (carry on respite_booking_requests) reusing Client::nhiValidationRules(); surface an optional NHI field in intake step 1 with an inline 'NHI on file matches existing client' duplicate check (scopeByNhi). On onboard, prefill the Add-client wizard NHI from the referral so it is verified once, not re-keyed.

#### Health information flows referral->booking->roster->family portal with no recorded consent-to-share, and family-portal visibility isn't bound to (or revoked by) consent

- **Scope:** this-rebuild | **Domain:** Data, privacy & records
- **Gap:** Approve auto-creates a booking and syncs a staff Shift, and confirm projects a calendar event, with no check that the client (or PPPR substitute) consented to that disclosure; respite tables hold zero linkage to client_consents. Separately, FamilyPortalSetting.show_respite (and show_care_notes/show_incidents) is a staff-set boolean with no FK to a ClientConsent 'Information Sharing with Family' record and no enforcement it is active — so withdrawing consent (ClientConsent->withdrawn_at) does NOT flip show_respite off, and whanau keep seeing respite stays, daily notes and incident alerts after consent is revoked. The app already encodes the correct rules elsewhere (StandardConsentTypesSeeder; ConsentRequest::authorityToConsent under PPPR) — they're just not invoked in respite.
- **NZ context:** HIPC 2020 Rule 10 (use) and Rule 11 (disclosure) require a lawful basis to use/share health info; the Privacy Act 2020 treats withdrawal of consent as ending that basis. For disability clients only a welfare guardian / activated EPOA can authorise sharing where capacity is lacking.
- **Recommendation:** Add consent_status/consent_id on respite_booking_requests and a server-side guard in approve that blocks or warns-with-override (recording justification, mirroring break-glass) when no active ClientConsent of the Information-Sharing types exists; show a consent chip on the request card and overview. Bind each family-portal visibility flag to an active matching ClientConsent (refuse to enable show_respite without one) and add a consent-withdrawal observer that auto-disables the flags; display the authorising relationship on the portal settings screen.

#### Cultural identity is invisible at referral/triage and there are no iwi/hapu/marae or interpreter fields — Te Tiriti duties begin at first contact, not onboarding

- **Scope:** this-rebuild | **Domain:** Te Tiriti & cultural safety
- **Gap:** RespiteReferral and the intake form capture zero cultural data (only 'Family/Whanau' as a referrer_type); ethnicity, interpreter need and whanau contact don't surface until the much-later 8-step Add-client wizard. A crisis referral can be triaged, risk-rated and accepted with no visibility of whether the person is Maori or needs an interpreter. Worse, the wizard's Cultural step is titled 'Whakapapa, language & beliefs' and blurbed 'Te Tiriti–aligned' but only captures ethnicity/languages/religion — there are NO iwi, hapu, marae or waka columns anywhere (confirmed absent from schema), so whakapapa is promised but uncollectable. And interpreter need is not a typed, filterable flag (Client has languages[] only), so a coordinator confirming a booking gets no readiness prompt to arrange an NZSL/te reo/Pasifika interpreter for admission, consent and handover.
- **NZ context:** Te Tiriti o Waitangi + Pae Ora (Healthy Futures) Act 2022 place active-protection/equity duties from first contact; Nga Paerewa makes Te Tiriti and cultural safety a Pae Ora outcome across the whole service; HDC Right 1(3) requires culture taken into account from the outset and Right 5 guarantees effective communication including a competent interpreter; the NZ Sign Language Act 2006 makes NZSL official; iwi data follows HISO 10001/Iwi affiliation standards.
- **Recommendation:** Add a 'Cultural snapshot' panel to the referral + a step in the 4-step intake wizard: ethnicity (reuse client ETHNICITIES enum), is_maori (drives Te Tiriti workflows), interpreter_required + interpreter_language + interpreter_arranged, and a cultural_considerations note — pre-filled from the linked Client, badged on referral/booking cards, and with interpreter_arranged a HARD item in the readiness ring (can't hit 100% while outstanding). Add nullable iwi/hapu/marae(/waka) columns to clients rendered under an 'If Maori' disclosure in StepCultural and echoed read-only on the stay overview so workers know who to contact for cultural support / tangihanga.

#### No HDC Code-of-Rights / informed-consent / advocacy-offer capture, and no complaints (Right 10) register reachable from a stay

- **Scope:** this-rebuild | **Domain:** Regulatory
- **Gap:** Booking consent_records/eligibility_checks are untyped JSON (and in fact don't exist as columns); there is no structured field recording that the consumer/EPOA/whanau was given the HDC Code of Rights, that informed consent to respite was obtained, or that the right to an independent advocate was offered. Separately there is no Complaint model anywhere in the system and RespiteStay/Booking have no complaint capture — a complaint made during a stay cannot be logged, acknowledged, time-tracked, escalated to HDC, or appear in the evidence pack.
- **NZ context:** HDC Code of Health and Disability Services Consumers' Rights (esp. Right 5 communication, Right 6 information, Right 7 informed consent, Right 10 complaints/advocacy) — providers must evidence rights were upheld and have a complaints procedure; Nga Paerewa Section 1 (consumer rights). HealthCERT reviews complaints handling at audit.
- **Recommendation:** Add a typed consent/rights step to intake or the readiness ring: booleans + recorder + datetime for code_of_rights_provided, consent_to_respite (capacity basis: self/EPOA/welfare guardian), advocate_offered, and format provided (easy-read/interpreter/NZSL); block readiness from 100% until present and include in the evidence pack. Add a lightweight Complaint entity (source: consumer/whanau/advocate/staff; received_at; nature; acknowledged_at; resolution; escalated_to_hdc + reference) with a 'log complaint' action on the Stays tab and an open-complaints overview tile, reusing the SafeguardingExternalReport pattern (already enumerates hdc) for the HDC leg.

#### No carer/whanau record or carer-strain capture, and no emergency/crisis fast-path — the #1 driver of respite demand is invisible

- **Scope:** this-rebuild | **Domain:** Intake / NASC pipeline
- **Gap:** The referral captures a clinical referrer but has no concept of the primary carer as an entity and no carer-wellbeing/breakdown-risk capture, so a triager cannot record 'primary carer at risk of breakdown' or the carer's own contact/needs, and cannot distinguish a family/whanau booker from a clinical referrer. Relatedly, although urgency has a 'crisis' value and risk_level can be 'critical', the pipeline forces the same multi-stage path (referral->request->approval->booking) with no fast-path that creates an admitted stay in one action, no is_emergency/fast_tracked flag, and nothing pinning crisis items to the top of the overview with an SLA.
- **NZ context:** The NZ Carers' Strategy Action Plan and Whaikaha 'Carer Support and Respite' framing treat the carer as a client in their own right; respite is explicitly funded to prevent carer breakdown, and Whaikaha 'Respite explained' distinguishes planned from emergency/crisis respite (carer illness/breakdown/safety). Te Whatu Ora ARC emergency beds are allocated same-day; Nga Paerewa expects unplanned admissions to be managed safely.
- **Recommendation:** Add lightweight carer capture to the intake wizard (primary carer name/relationship/contact + carer_strain_level low/moderate/high/at_breakdown + carer_breakdown_flag) and an optional booker_type (clinical_referrer/family_whanau/self). Add an 'Emergency respite' action that creates referral+request+confirmed booking in one transaction marked is_emergency/fast_tracked so readiness can be completed post-admission, and pin urgency=crisis / carer_breakdown_flag rows to the top of 'needs your attention' with a visible SLA timer. (Carer entity = this-rebuild; full emergency one-action transaction can be staged.)

#### Discharge is a single free-text box: no medication reconciliation, return-of-meds count, or GP/pharmacy handover

- **Scope:** this-rebuild | **Domain:** Clinical safety
- **Gap:** RespiteStayController::discharge() requires only a free-text discharge_summary and the UI is one textarea. There is no return-of-own-medications check, no count/sign-out of medicines going home, no record of new/changed medicines started during the stay, no GP or community-pharmacy handover, and no carer/whanau medication briefing — a direct medication-safety gap at the point care transfers back to family or another provider.
- **NZ context:** Nga Paerewa NZS 8134:2021 medicines-management on transfer/discharge; HQSC medication reconciliation at discharge; Medsafe Medicines Care Guides (medicines returned and reconciled on leaving a facility).
- **Recommendation:** Replace the free-text-only discharge with a structured discharge med-rec block: medicines_returned_to (family/next_provider/pharmacy) + count + received_by, medicines_changed_during_stay(JSON), TTO/short-supply provided, GP/pharmacy_handover_sent (channel + timestamp), and whanau/carer medication briefing acknowledged. Gate 'discharged' on this being complete for any client with medications, mirroring the admission med-rec gate.

#### Pipeline models a fresh clinical referral, not a draw-down against a pre-allocated NASC entitlement; no Carer Support day burn-down

- **Scope:** this-rebuild | **Domain:** Intake / NASC pipeline
- **Gap:** The funnel starts at Referral (received->triaged->accepted) and implies every booking needs a fresh clinical triage, but the dominant NZ reality is that NASC has ALREADY assessed and allocated a Carer Support/respite entitlement in DAYS or an IF/Flexible budget, against which the family books. There is no field for the NASC allocation (assessor, agency, package ref, allocated days/nights remaining, funding stream) on the referral/request — even though the ServiceAgreement model carries exactly these (nasc_assessor_name, nasc_support_package_ref, whaikaha_reference, support_needs_level, allocated_hours_per_week) — and nothing tracks Carer Support days specifically (no days_allocated/days_used burn-down, no entitlement-year reset).
- **NZ context:** NASC under Whaikaha allocates Carer Support in DAYS treated as an annual budget plus IF/Flexible Funding budgets; Te Whatu Ora NASC does the equivalent for older people/ARC respite. Carer Support is reconciled day-by-day on the subsidy claim form, distinct from bed-night facility respite; providers and whanau routinely need 'days used / days remaining this entitlement year'.
- **Recommendation:** Link the pipeline to the existing ServiceAgreement (nullable service_agreement_id, surfaced in the intake wizard) and add a typed funding_stream enum + allocated_days_total/allocated_days_used (or read-through to the agreement) so the request form shows remaining funded nights; on approve, copy the NASC/Whaikaha reference onto the booking. When funding_source=carer_support, capture carer_support_days_allocated + entitlement-year start and derive days_used from completed stays with a days-remaining burn-down on the client/booking and a near-exhausted/near-reset tile on the overview. (ServiceAgreement link + funding_stream = this-rebuild; full Carer Support burn-down can follow.)

### P2

#### Booking/stay statuses lack the cancellation/no-show/hold vocabulary NZ respite reporting needs; waitlist is a dead-end with no capacity model

- **Scope:** this-rebuild | **Domain:** Intake / NASC pipeline
- **Gap:** Booking status is pending/confirmed/in_progress/completed/cancelled with a single free-text cancellation_reason — no no_show, no provider-vs-family-vs-illness cancellation source, no on_hold/pending_funding holding state, and no typed early/unplanned discharge_reason on the stay. Waitlisted is a valid request status but has no position/priority/expected-availability and no promotion workflow; and although sites.respite_capacity (+ respite_funding_types, respite_min/max_stay_days) exists, no per-date occupancy is computed against it, so 'home full' is unknowable, a freed bed can't be re-offered to a matching waitlist entry, and funded days may be wrongly consumed.
- **NZ context:** Whaikaha/Te Whatu Ora bed-night contracts run to a fixed number of contracted respite beds per home and their occupancy reporting distinguishes cancellations, DNAs and short-notice cancellations (they affect funding/utilisation and bed re-offer); Nga Paerewa requires safe occupancy/capacity management and equitable waitlist handling.
- **Recommendation:** Add no_show to the booking status enum + typed cancellation_source (provider/family_whanau/client/funder/illness) + cancellation_notice_hours, and an on_hold_pending_funding status (or hold flag); add a typed stay discharge_reason (planned/early_by_family/clinical/incident/transferred_to_hospital). Compute per-home/per-date occupancy against sites.respite_capacity so the calendar/overview can flag 'full' and gate confirmation on available capacity; add waitlist_position/priority + expected_availability_date and a 'Promote from waitlist' action that, on a freed bed, lists waitlisted requests whose dates+home match. Free the bed back to capacity/waitlist when status becomes cancelled/no_show.

#### No signed service/placement agreement gate in pre-stay readiness, despite ServiceAgreement carrying the signature fields

- **Scope:** this-rebuild | **Domain:** Intake / NASC pipeline / Regulatory
- **Gap:** The booking has consent_records/pre_arrival_checklist JSON and a readiness % but no explicit typed gate for the signed service/placement agreement that authorises this specific stay, its fees and conditions — so an admission can reach 'confirmed'/'ready' without a captured agreement signature. The ServiceAgreement model already has signed_at/signed_date/client_signatory/provider_signatory but is not linked to booking readiness.
- **NZ context:** Nga Paerewa NZS 8134:2021 and Whaikaha service specifications require a signed service/admission agreement (informed consent, fees, rights under the HDC Code) before/at admission for residential and facility respite.
- **Recommendation:** Add agreement_status to the booking readiness (not_required/pending_signature/signed) backed by a link to the ServiceAgreement (or a per-stay agreement record), make 'signed' a required tick in the readiness ring for non-emergency bookings, and add a 'Send agreement for signature' action on the booking card.

#### Daily-note wellbeing omits taha wairua and taha whanau (only 2 of Te Whare Tapa Wha's 4 walls), and there is no whanau-engagement/consent surface across the stay

- **Scope:** this-rebuild | **Domain:** Te Tiriti & cultural safety
- **Gap:** RespiteDailyNote scores mood/appetite/sleep/engagement/mobility — i.e. taha tinana and taha hinengaro only; there is no taha wairua (spiritual/cultural wellbeing) and no taha whanau (did whanau visit/phone) dimension, which for a stay away from home are the highest-risk dimensions. Separately, whanau involvement is reduced to NextOfKin portal booleans; nothing records who the cultural/whanau decision-maker is (vs legal EPOA), that whanau were engaged in the admission decision, or that they consented to the placement and to specific care.
- **NZ context:** Te Whare Tapa Wha (Mason Durie) is the foundational Maori health model named in Nga Paerewa and embedded in Whaikaha/Te Whatu Ora practice; for Pasifika the Fonofale model adds spiritual and family pillars. Whanau Ora/EGL require whanau-centred, mana-enhancing decision-making, and HDC Right 7 + PPPR require recording who can consent.
- **Recommendation:** Add two optional daily-note fields — wairua/cultural wellbeing (same very_low…excellent scale + note) and whanau_connection (visited/phoned/video/none + note) — prominent for residents flagged is_maori/Pasifika. On the booking/stay add a structured whanau_decision_maker (link to a NextOfKin) + decision_basis (self/supported/EPOA-welfare/welfare-guardian) and a whanau_consent_confirmed checkpoint in the readiness ring plus a discharge-time whanau debrief flag.

#### Cultural/religious dietary needs (halal/kosher/fasting/rongoa) aren't carried into the receiving home's catering readiness

- **Scope:** this-rebuild | **Domain:** Te Tiriti & cultural safety
- **Gap:** The client captures dietary_requirements free-text + meal IDDSI fields + mealDietaryTags, but the booking-request and pre-arrival readiness make no structured use of culturally/religiously-required diet, so a respite bed in a different home won't reliably know to provide halal/kosher, observe fasting, or accommodate rongoa Maori — nothing forces it into the pre-arrival checklist or onto the receiving home's Meal Planner for the stay dates.
- **NZ context:** HDC Right 1 (respect for culture, values and beliefs) and Nga Paerewa nutrition/cultural-safety outcomes require meeting cultural and religious dietary needs; the product already runs a per-Site Meal Planner, so the receiving home must be told. Halal/kosher and rongoa are cultural-safety, not mere preferences.
- **Recommendation:** Add a structured cultural/religious dietary flag (halal/kosher/vegetarian-for-faith/fasting-observance/rongoa-maori/other) to the client, default it into the booking request + a 'dietary needs confirmed with receiving home' readiness item, and pass the stay dates + dietary flags to the destination site's Meal Planner.

#### Seizure/epilepsy and other high-risk conditions don't force the matching support plan to be active and acknowledged before the stay, and there is no admission risk screen (falls/skin/IDDSI)

- **Scope:** this-rebuild | **Domain:** Clinical safety
- **Gap:** The Client stores seizure_duration_escalation_seconds and epilepsy is a first-class condition, and RespiteRiskPlanActivation supports a 'medical' plan with escalation_steps — but nothing in the admission flow detects a known seizure threshold and requires the seizure management + escalation plan to be activated and staff-acknowledged before the stay starts. More broadly there is no lightweight admission risk screen (falls, pressure-injury/skin, dysphagia) for the short stay, and the client's IDDSI food/fluid level (meal_iddsi_level) is not pulled into the stay/arrival handover or shown to kitchen staff — a choking/aspiration risk for a texture-modified resident.
- **NZ context:** Nga Paerewa NZS 8134:2021 (high/complex-needs service users have current support plans available to staff; nutrition, falls and pressure-injury criteria); Whaikaha/Health NZ epilepsy management-plan expectations; St John status-epilepticus escalation timing; IDDSI framework for texture-modified diets and thickened fluids; ACC falls prevention; HQSC pressure-injury programme.
- **Recommendation:** In booking readiness, if the client has condition 'epilepsy' or non-null seizure_duration_escalation_seconds, require a RespiteRiskPlanActivation of plan_type 'medical' (seizure) with escalation_steps populated and at least one staff acknowledgement before 'confirmed'/check-in, and show the threshold/steps in the critical-alerts banner. Add a short admission_risk_screen to the stay (falls low/med/high, pressure-injury risk + skin note, mobility/transfer needs) and a mandatory surfaced read-back of the IDDSI food and fluid level in the arrival handover and stay header, flagging if missing for a client with swallowing concerns.

#### Evidence pack contents are operator-discretionary, so audit export isn't assembled from the certification-relevant record set

- **Scope:** this-rebuild | **Domain:** Regulatory
- **Gap:** RespiteEvidencePack stores a free-text summary + untyped items JSON and seals it, but there is no defined contract that it pulls the standard-driven evidence: rights/consent acknowledgement, restraint events + reviews, adverse/notifiable events + notification refs, complaints, BSP activation acknowledgements, and medication reconciliation. The seal/export exists but the contents are at operator discretion.
- **NZ context:** HealthCERT certification & surveillance audits against Nga Paerewa NZS 8134:2021 trace a sampled consumer's stay end-to-end across rights, restraint, adverse events and complaints; an evidence pack missing these is not audit-defensible.
- **Recommendation:** Define a typed evidence-pack manifest that auto-includes, per stay: consent/rights record, all RestraintEvents (with review status), all ClientIncidents/NotifiableIncidents (with authority refs), complaints, BSP acknowledgements, and medications_reconciled. Show a completeness checklist on the stay before 'seal', greying out seal until mandatory categories are present or explicitly marked N/A.

#### Respite has no safeguarding quick-raise, and the safeguarding module is England-framed (CQC/safeguarding board) not NZ (HDC/Police/Whaikaha)

- **Scope:** this-rebuild | **Domain:** Clinical safety / Regulatory
- **Gap:** An adult-at-risk concern arising during a stay (unexplained injury, disclosure) has no fast path from the stay screen into the safeguarding workflow — staff must leave respite entirely. And the safeguarding tables are England-derived: safeguarding_external_reports.authority_type enumerates cqc/local_authority/safeguarding_board/coroner. CQC and statutory safeguarding boards do not exist in NZ; the correct external bodies are HDC, NZ Police and the funder/regulator (Health NZ/Whaikaha). Under-18 respite also triggers no Oranga Tamariki/Police pathway (no minor flag at triage).
- **NZ context:** NZ has no single safeguarding-adults statute — duty rests on provider policy plus referral to NZ Police and the HDC; serious harm in funded disability services is reportable to Whaikaha/Health NZ; Oranga Tamariki Act 1989 and Children's Act 2014 govern child protection and safety-checking. HDC Right 4 (freedom from abuse / appropriate standard).
- **Recommendation:** Add a 'Raise safeguarding concern' action on the stay that creates a safeguarding_concern pre-linked to client/stay/site, and surface any active safeguarding_alert in the critical-alerts banner. Update safeguarding_external_reports.authority_type to NZ values (police, hdc, health_nz, whaikaha, coroner, other). Add an is_minor (or DOB-derived) flag at triage that adds a child-protection/worker-safety-checking intake item and pre-selects oranga_tamariki/police as suggested authorities for a minor's safeguarding concern.

#### Onboarding prefill loses third-party-collection provenance; no consent-to-collect-from-a-third-party captured at referral

- **Scope:** this-rebuild | **Domain:** Data, privacy & records
- **Gap:** The 'Onboard client' flow prefills the 8-step wizard from referral+request (reason/risk/funding/dates/NHI), but nothing records that this health information was collected from a third party (DHB/NGO/GP/NASC/Family referrer) rather than the client, and no consent-to-collect-from-a-third-party is captured on the referral. Once prefilled and saved, the third-party origin is lost.
- **NZ context:** HIPC 2020 Rule 2 (collect from the individual where practicable) and Rule 3 (tell the individual what is collected, from whom and why) require the source and purpose of indirectly-collected health information to be documented and disclosed; NASC-originated referrals are a classic third-party collection under Whaikaha pathways.
- **Recommendation:** Persist referrer_type/referrer_name as the collection source on the referral and stamp a 'source: referral #, collected_from: <referrer_type>, basis: HIPC Rule 2 exception' note onto the created client/consent record during onboard; add a 'client/representative informed of collection (Rule 3)' checkbox to the intake wizard carried through to the client record.

#### Right-of-access / correction (DSR) export omits the entire respite domain

- **Scope:** future | **Domain:** Data, privacy & records
- **Gap:** DataSubjectRequestController::export assembles personal_information/support_plan/notes/assessments/incidents/medications/consent_records but pulls nothing from respite (respite_referrals, respite_bookings, respite_stays, respite_handover_notes, respite_communication_logs), so a client/whanau exercising access or correction receives an incomplete health record omitting their entire respite history.
- **NZ context:** HIPC 2020 Rule 6 (access) and Rule 7 (correction), plus the Privacy Act 2020 and HDC Right 6, give the individual access to ALL their health information held; the OPC treats a partial response as non-compliant. Respite stays are health information.
- **Recommendation:** Extend the DSR export builder to loadMissing the client's respite referrals/bookings/stays and emit titles/dates (matching the existing notes/incidents shape) plus handover and communication-log summaries in a 'respite_records' section; add respite fields to the correction surface so a Rule 7 correction can annotate them.

#### No retention/disposal policy targets the respite tables — declined referrals and short-stay records fall outside the 10-year minimum and any disposal schedule

- **Scope:** this-rebuild | **Domain:** Data, privacy & records
- **Gap:** A declined/never-converted referral still holds health info (referral_reason, risk_level, triage_notes) and only soft-deletes. The EnforceDataRetentionJob/DataRetentionPolicy engine exists but has no seeded policy row covering RespiteReferral/RespiteBookingRequest/RespiteStay, so respite health records fall outside both the mandatory 10-year minimum and any disposal schedule; the anonymiser also won't reach an encrypted nhi_number (pattern matches 'nhi' but the value is cast 'encrypted').
- **NZ context:** The Health (Retention of Health Information) Regulations 1996 require retention for a minimum 10 years from when the provider last provided services; HIPC 2020 Rule 9 forbids keeping it longer than needed thereafter. Declined-referral records about people who never became clients are exactly the transient records the Code expects to be dispositioned.
- **Recommendation:** Seed DataRetentionPolicy rows for the respite models: 10-year minimum retention for converted stays and a shorter defined disposal window for declined/never-converted referrals; verify the anonymiser nulls encrypted NHI. Config/data, cheap to fold in now while the tables are reworked.

#### No co-payment / client-contribution capture for respite

- **Scope:** future | **Domain:** Funding & eligibility
- **Gap:** The pipeline assumes respite is fully third-party funded: there is no field for a client co-payment, top-up or daily contribution on the booking/stay and no split between funder-paid and privately-paid portions, so the later invoice/funding claim cannot apportion funder vs private.
- **NZ context:** Residential/facility respite frequently carries a client contribution or daily co-payment, and where a NASC allocation or Carer Support entitlement is exhausted mid-stay the balance is commonly billed privately or part-funded; Te Whatu Ora/Whaikaha contracted rates may sit alongside a user co-contribution.
- **Recommendation:** Add co_payment_amount / co_payment_basis (per_night|fixed|none) and an optional private_pay_portion to the booking, defaulted from the chosen funding_source and the home's rate, and surface it on the readiness panel so the family is told the contribution before arrival.

#### referrer_type mislabels NZ referrers (DHB) and omits Whaikaha/EGL-connector pathways, and isn't correlated with funding

- **Scope:** this-rebuild | **Domain:** Funding & eligibility / Te Tiriti
- **Gap:** The referral referrer_type picklist is [Self, Family/Whanau, GP, Hospital, NASC, Social Worker, School, Community, Other] (and the design context's enumerated list still says DHB/NGO/GP/NASC/Family). 'DHB' is disestablished, neither list includes the Whaikaha or EGL connector / Local Area Coordination pathways that now drive much disability respite, the field is a loose string rather than a stored enum, and it's disconnected from funding_source even though in NZ the two strongly correlate.
- **NZ context:** Post-2022 disability referral pathways are NASC, Whaikaha, EGL connectors / Tuhono / Local Area Coordinators, Te Whatu Ora (replacing DHB), ACC case managers, GP/hospital and family/whanau; 'DHB' and the absence of Whaikaha/EGL connectors read as pre-2022.
- **Recommendation:** Replace 'DHB' with 'Te Whatu Ora', add 'Whaikaha', 'EGL connector / LAC' and 'ACC case manager', and convert referrer_type to a stored enum. When a referrer_type strongly implies a funder (NASC->nasc_allocated, ACC case manager->acc), pre-select funding_source in the intake wizard.

### P3

#### No cultural-placement check before booking confirm, and no tangihanga/cultural-leave bed-hold on an active stay

- **Scope:** future | **Domain:** Te Tiriti & cultural safety
- **Gap:** On approve->booking the system auto-creates a booking + syncs a Shift and confirm projects a calendar event, but nothing evaluates whether the chosen home/room is a culturally safe placement (te reo-speaking/kaupapa-Maori-aligned worker, tapu/noa considerations, proximity to marae/whanau) — the readiness ring measures logistics, not cultural fit. And RespiteStay statuses (admitted->active->extended->discharged) have no culturally-required temporary-absence sub-state, so a mid-stay tangihanga has no leave/return record or bed-hold semantic and misrepresents occupancy.
- **NZ context:** Whaikaha EGL 'mana enhancing' / 'no wrong door' principles and Nga Paerewa cultural-safety outcomes expect demonstrably culturally appropriate placement and staffing (incl. tikanga such as tapu/noa and whakawhanaungatanga); supporting tangihanga attendance is a recognised tikanga Maori obligation, and bed-hold/planned-leave intersects Whaikaha/NASC bed-night funding rules.
- **Recommendation:** Add an optional 'cultural placement check' acknowledgement to the booking confirm step (cultural needs reviewed / matched-worker considered / whanau proximity considered) recorded in eligibility_checks JSON and shown on the booking card. Add an 'on cultural/whanau leave' sub-state (or a typed stay_absence record with reason incl. tangihanga, expected_return, bed_held) that the calendar/occupancy and bed-count widgets respect.

#### Restrictive-practice / locked-environment context of the respite bed itself isn't captured at booking

- **Scope:** future | **Domain:** Regulatory
- **Gap:** Bookings capture location_id (site/room) but nothing records whether the placement involves environmental restrictive practices (locked unit, secure setting, movement monitoring) or whether the consumer's BehaviourSupportPlan authorises them in this setting, conflating an ordinary respite bed with a restrictive setting.
- **NZ context:** Nga Paerewa Section 5 environmental-restraint provisions require any restrictive practice to be the least-restrictive option, authorised and consented; relevant where providers offer secure/specialised disability respite.
- **Recommendation:** Add a typed setting_restriction enum on the booking/site (none/monitored/secure-locked) and, when not 'none', require the readiness ring to evidence BSP authorisation + consent for environmental restriction before the booking can be confirmed.

#### No recurring / repeat respite blocks — each request is a single date range despite planned respite being a standing booking

- **Scope:** future | **Domain:** Intake / NASC pipeline
- **Gap:** respite_booking_requests/respite_bookings carry a single requested_start/end; recurrence_rule exists ONLY on respite_calendar_events, so recurrence is a projection artefact, not a bookable entity. A family with an annual allocation who books a recurring block (second weekend monthly, every school holiday) can't express it as one request — the coordinator hand-creates N bookings and the funded-days draw-down isn't tracked across the series.
- **NZ context:** Carer Support days are an annual budget whanau deliberately spread across the year in recurring blocks; facility planned respite is routinely booked as repeating short stays against that entitlement.
- **Recommendation:** Add an optional recurrence_rule (RRULE) + series_id to respite_booking_requests/respite_bookings so one approval generates a linked series, each decrementing allocated days; surface the series on the calendar as a grouped block with cancel-one vs cancel-series.

#### Legacy GDPR-framed consent taxonomy and notice-period semantics risk surfacing in the respite consent picker

- **Scope:** future | **Domain:** Data, privacy & records
- **Gap:** ConsentTypesSeeder defines a 'Data Processing (GDPR)' type and applies withdrawal_notice_days (e.g. 7-day notice to stop sharing with family), while the newer StandardConsentTypesSeeder is correctly NZ-framed — so the rebuild risks surfacing GDPR consent types in its picker, and the notice-period concept implies sharing can lawfully continue for days after withdrawal.
- **NZ context:** NZ has no GDPR; the governing instruments are the Privacy Act 2020, HIPC 2020 and HDC Right 7, under which withdrawal ends the lawful basis to disclose promptly — a multi-day notice period before stopping family disclosure is not a NZ construct.
- **Recommendation:** Point the respite consent picker at the NZ-framed StandardConsentTypesSeeder set, deactivate the 'Data Processing (GDPR)' type, and treat withdrawal_notice_days as an operational wind-down only (stop new disclosures immediately; the window must not gate the cut-off of family-portal visibility).

#### No path from a respite privacy incident to the notifiable-breach (OPC) workflow

- **Scope:** future | **Domain:** Data, privacy & records
- **Gap:** DataBreachLog + requires_authority_notification exist as a standalone privacy module and respite has incident linkage (respite_linked_refs ref_type 'incident'), but there is no way to escalate a respite-side privacy incident (a sensitive_flag handover emailed to the wrong whanau contact, a mis-scoped family-portal disclosure) into a DataBreachLog assessment, and the overview has no breach signal.
- **NZ context:** Part 6 of the Privacy Act 2020 makes it mandatory to notify the OPC and affected individuals of a notifiable privacy breach (serious harm) as soon as practicable; health-information disclosure errors are a common trigger for disability/aged-residential providers.
- **Recommendation:** Add a 'Report as privacy breach' action on respite incidents/handovers that pre-fills a DataBreachLog (affected_data_categories, approximate_individuals_affected) and links back via respite_linked_refs; surface an open-breach count in the overview. Defer the full workflow but make the linkage now while incident plumbing is touched.

#### No funding-expiry / unverified-funding lane on the Overview 'needs your attention'

- **Scope:** future | **Domain:** Funding & eligibility
- **Gap:** The overview surfaces funnel/occupancy/arrivals but nothing funding-related — no 'arriving this week with funding unverified' or 'funding/allocation expiring before stay end' alert — even though ServiceAgreement already exposes ends_at/review_due_date + an expiringSoon scope and a CheckExpiringAgreementsJob already exists.
- **NZ context:** NASC allocations, IF/EGL budgets and Te Whatu Ora/Whaikaha contracts all have review/expiry dates and ACC authorisation can lapse; a stay crossing a funding expiry, or an imminent arrival with no verified funder, is the classic preventable NZ revenue loss.
- **Recommendation:** Add a funding lane to the overview: count of imminent arrivals where funding_status != approved/not_required, and stays whose linked ServiceAgreement ends_at/review_due_date falls before stay end — reusing the existing expiringSoon scope / CheckExpiringAgreementsJob rather than building new expiry logic.

## Codex close-out - 2026-06-06

The gaps in this analysis have been implemented through the localisation and respite implementation
plans. Source recommendations remain in this file for audit traceability, but the previously
future-scoped fast-follows are no longer open implementation work in the current codebase.

Closed items include:

- Funding source carry-through, service-agreement links, funding status, readiness gate and discharge
  consumption.
- Status vocabulary, per-home occupancy, waitlist promotion, carer crisis attention and emergency
  fast-path metadata.
- Admission as transfer of care: med-rec gates, critical alerts, risk screen and discharge compliance
  blocks.
- Compliance wiring: respite incidents, restraint events, notifiable incidents, complaints/HDC wiring,
  evidence manifest, NZ safeguarding authorities and OPC privacy-breach linkage.
- Consent and PPPR: who-consents fields, active family-information consent binding, and immediate
  shutdown of sensitive family-portal surfaces on withdrawal.
- Te Tiriti and cultural safety: referral/intake cultural snapshot, iwi/hapu/marae support, interpreter
  readiness, wairua/whānau daily note fields, cultural dietary context, cultural placement readiness,
  restrictive-setting evidence and cultural leave/bed-hold.
- Data/privacy records: NHI at referral with hash-based dedup support, respite records in privacy
  exports, retention-policy wiring and NZ privacy terminology.
- Fast-follows: co-payment/private-pay fields, recurring block metadata, funding-expiry attention lane
  and Carer Support day burn-down.

Verification evidence is captured in `docs/respite-nz-implementation-plan.md`.

