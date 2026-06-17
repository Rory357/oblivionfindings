# Safeguarding Redesign — Step Plan: 06 — Raise-concern wizard

## 0. Identity
- **Step:** 6 — `WizardShell` 6-step raise wizard (modal) + retire `create.tsx`/`edit.tsx`
- **New component:** `resources/js/components/safeguarding/raise-wizard.tsx`
- **Controller:** `@store` → `back()` + flashes `created_concern_id` (wizard success pane); `@create` → redirect `/safeguarding?raise=1`; `@edit` → redirect to the concern; `@index` += `staff`, `raise`
- **Shared-file (additive):** `HandleInertiaRequests` flash += `created_concern_id`
- **Drop refs:** Safeguarding.dc.html raise wizard (~660+, HANDOFF §4); incidents `incident-report-dialog.tsx` (§7 template); store contract = `SafeguardingConcernController::normalizeConcernInput`/validate.

## 1. Steps (WizardShell, Add-Client contract)
① Subject & concern type — subject_type Segmented (Client/Staff/Other) → SelectInput (clients|staff) or other_subject_name; concern-type TilePicker (Abuse/Neglect/Self-neglect/Exploitation/Discrimination/Organisational).
② What happened — description Textarea (required) + optional witnesses + optional expandable alleged person (perpetrator_type + id/name + relationship). **Evidence upload deferred to Step 7** → honest InfoCard ("evidence can be attached from the concern once raised"), NOT a dropzone.
③ Severity & abuse category — severity TilePicker (low/med/high/critical) + abuse_category TilePicker (12 NZ categories).
④ Immediate response & subject-informed — immediate_action_description Textarea + subject_informed Segmented (Yes / Not yet / Not appropriate).
⑤ External-referral check — auto-suggest InfoCard (sexual/physical → likely NZ Police) + requires_external_referral Segmented (Refer / No referral needed); the authority + report are logged after raising (Step 4b/8 panes).
⑥ Review & raise — ReviewCards (Edit jumps) → submit.

## 2. Submit → store contract
`form.transform` → POST `/safeguarding`: subject_type, subject_id, other_subject_name, concern_type, abuse_category, severity, description, occurred_at?, location?, witnesses?, alleged_perpetrator_type?, alleged_perpetrator_id?, other_perpetrator_name?, perpetrator_relationship?, immediate_action_taken(bool)+immediate_action_description, subject_informed(bool), requires_external_referral(bool), site_id?. (store already sets status=reported; observer fires HsEvent + Control Room alert + NotifiableIncident-if-critical.)
- `preserveState:true` keeps the wizard mounted → success pane; `flash.created_concern_id` powers "Open concern".

## 3. Need-to-know
- Wizard only shown when `can.create`. No redaction concerns (creating, not viewing). A raise can mark the concern sensitive later (is_sensitive at raise = future nicety; not required by W-list — keep minimal).

## 4. Incidents-consistency (§7)
- Same `IncidentReportDialog` structure: WizardShell + steps + completeness `Ring` + Back/Next/Submit footer + `WizardSuccessPane`; `useForm`+transform+`preserveState`+flash-id. Shared primitives (TilePicker/Segmented/SelectInput/Field/StepHead/InfoCard/Ring). Deltas: safeguarding fields/steps; blame-free protective copy.

## 5. Retire → redirect (no 404)
- `create.tsx` (full-page form) deleted; `@create` → `redirect()->route('safeguarding.index', ['raise'=>1])` (opens the wizard).
- `edit.tsx` deleted; `@edit` → `redirect()->route('safeguarding.show', $concern)` (the detail). `update()` endpoint kept (no UI caller now).
- index "Raise concern" button → opens the wizard modal (was `router.visit('/safeguarding/create')`).

## 6. Backend tests
- store still creates + redirects (existing tests pass with back()). Add: store flashes created_concern_id; create redirects to ?raise=1; edit redirects to the concern.

## 7. Verify
- types/lint/pint/build; safeguarding suite green; retired routes redirect (not 404); commit + tick PROGRESS + flag the shared-file edit.

## 8. Notes
- Shared-file edit (`HandleInertiaRequests`) is +1 flash key, additive — flagged in PROGRESS §shared-file + INCIDENTS_CONSISTENCY.
- Evidence capture (W8) + the authority report creation belong to Step 7/existing referral pane — wizard only sets the requires_external_referral flag.
