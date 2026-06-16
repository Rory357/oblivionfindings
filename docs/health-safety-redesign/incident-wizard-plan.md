# Health & Safety Redesign — Workstream Plan: Report launcher + incident wizard (WS6)

> Plan per `HEALTH_SAFETY_REDESIGN_LOOP_PROMPT.md` §4/§5. Spec = `PROTOTYPE_DIGEST.md` §4.1. NZ-only.

## 0. Goal
The ＋Report launcher + the fully-built reference wizard (Report incident / near-miss) incl. the WorkSafe notifiable-event check, posting to the existing `/incidents` endpoint, in place.

## 1. Backend
- **`IncidentController@store`** (method-injected `NotifiableEventClassifier`): accept `site_preserved` + `worksafe_reference`; **enforce notifiable server-side (G2, escalate-only)** — `medical_treatment_type ∈ {hospital, ambulance}` or `injury_classification = notifiable` forces `is_notifiable = true` regardless of the client value (never downgrades); persist `site_preserved` + `worksafe_reference` + `worksafe_notification_status = pending`. New `stay` flag → `back()` so the wizard stays on the dashboard and refreshes props.
- **Dashboard controller**: add `clients` (id, name) to the payload for the wizard's required client picker.

## 2. Frontend
- **`components/report-incident-dialog.tsx`** — 6 steps on the `WizardShell` chrome + `wizard/primitives` (StepHead/Field/TilePicker/ChipMulti/Segmented/InfoCard): (1) Type & people, (2) Client + site + when + description, (3) Severity & harm, (4) Immediate actions + create-CA toggle, (5) **WorkSafe check** (auto-determined `harm ∈ {hospitalisation,death} || severity = Critical` — red panel w/ required Site-preserved + notifier, or green not-notifiable panel), (6) Review. Completeness meter (8 checks); Continue disabled until the step validates; stepper clickable ≤ current. Submit maps wizard vocab → DB (`SEVERITY_MAP`, `HARM_TO_TREATMENT`, `injury_classification`), `router.post('/incidents', { …, stay:true })` → `WizardSuccessPane` (Record another / Done).
- **`components/report-launcher.tsx`** — 9-workflow chooser; incident tile → the dialog; the other 8 navigate to their register/create pages (interim — WS7 converts to in-place wizards).
- **`command-centre-hero.tsx`** — `onReport` prop renders the white-pill `＋ Report` action (deferred from WS2).
- **`dashboard.tsx`** — launcher + dialog state; `onReport` opens the launcher.

## 3. Deviations / notes
- DB requires `client_id`, so **client is required** in the wizard (prototype made it optional) — site is informational (no `site_id` on `client_incidents`), appended to the description.
- `severity` maps 4→3 (Critical→high + `injury_classification = notifiable`). `notifyWho` has no model column → appended to the description.

## 4. Verify
- php -l (both controllers) clean; `npm run types` H&S-clean; `npx eslint` clean incl. raw-colour guard (launcher/wizard custom selectors carry documented `no-restricted-syntax` disables).
- **Deferred (post-merge):** an HTTP store-enforcement feature test (assert a hospitalisation/ambulance incident is forced notifiable) — unrunnable in this vendorless worktree and auth/ClientPolicy-fragile; the rule itself is covered by `NotifiableEventClassifierTest`. Browser parity (drive the wizard to the WorkSafe panel + success) → post-merge.
