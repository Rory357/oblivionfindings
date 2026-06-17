# Safeguarding Redesign — Step Plan: 05 — Triage decision screen + gated Close

## 0. Identity
- **Step:** 5 — add Triage (W4) + gated Close (W7) panes to `SafeguardingConcernDialog`'s Options bar
- **Component:** `resources/js/components/safeguarding/concern-dialog.tsx` (add `'triage'`/`'close'` ActionKeys + 2 panes + buttons + Overview "Triage now" CTA)
- **Controller:** `@close` extended so the soft-block matches the design (open work **or** unlogged referral); endpoints `/triage` + `/close` already exist (Step 2)
- **Drop refs:** Safeguarding.dc.html triage modal (553–601: trSubstantiate/trRisk/trLeads/trPath + path notes) + gated close modal (607–658: closeChecks 1240–1250, blocked = inv/act/ref) ; HANDOFF §3
- **Goal:** the lifecycle is fully drivable from the modal — triage a reported concern down its path, and close with the gated checklist.

## 1. Triage pane (W4)
- 4 steps in one pane: ① substantiate (TilePicker: Substantiated / Needs enquiry / Not substantiated → keys `substantiated`/`needs_enquiry`/`not_substantiated`) · ② initial risk (Segmented low/med/high/critical) · ③ assign lead (SelectInput, assignable_staff) · ④ decide path (TilePicker: Investigate / Refer externally / No further action).
- Path notes: refer → crit InfoCard ("requires a report… log it next"); investigate → info InfoCard ("an investigation opens automatically"); no_action → required rationale Textarea (`notes`).
- Submit label adapts to path. POST `/safeguarding/{id}/triage` {substantiation, initial_risk, lead_user_id, path, notes}. (Backend Step 2 already: investigate→opens investigation+investigating; refer→requires_referral+triaged; no_action→no_action_required.)

## 2. Close pane (W7)
- 4 checks computed from `detail`: inv complete (no open investigation) · action plan complete (no incomplete actions) · subject informed/N-A (**warn only**) · external referral logged if required.
- `blocked = !invOk || !actOk || !refOk` (matches the extended backend). When blocked → override-reason input (required) + warning banner. `closure_summary` required; `lessons` optional. Subject-not-informed → non-blocking warning.
- Submit disabled unless summary && (!blocked || override). POST `/safeguarding/{id}/close` {closure_summary, lessons_learned, override_reason}.

## 3. Options bar gating (added)
- **Triage**: show when `can.update && status==='reported'` (the first gate). Also an Overview "Triage now" CTA.
- **Close**: show when `can.update && !terminal && status!=='reported'` (close-from-reported is server-blocked → triage first).

## 4. Backend (this step)
| Change | Migration? | Test |
|---|---|---|
| `@close` soft-block also when referral indicated + unlogged (match design gating) | no | new lifecycle test (added) |

## 5. Need-to-know
- Restricted concerns never reach these panes (dialog renders the locked state; restricted shell omits `can`). No new redaction.

## 6. Incidents-consistency (§7)
- Same WizardShell pane idiom (ActionPane), `useForm` + back()+flash-guard, shared primitives (TilePicker/Segmented/SelectInput/Field/StepHead/InfoCard). Triage tiles + close checklist are Safeguarding-specific surfaces with no Incidents equivalent — built on the same primitives. No shared primitives modified.

## 7. Verify
- types/lint/pint/build; gating tests (triage paths already covered Step 2; close referral-block new); full safeguarding suite green; commit + tick PROGRESS.

## 8. Notes
- Triage/close endpoints + lifecycle transitions were built+tested in Step 2; Step 5 is the UI + the one close-gate refinement. Step 6 = raise wizard.
