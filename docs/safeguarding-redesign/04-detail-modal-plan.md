# Safeguarding Redesign — Step Plan: 04 — Detail modal (SafeguardingConcernDialog)

> Split (mirrors the Incidents loop's 4a/4b): **4a** read-only modal + detail-over-list + retire show.tsx;
> **4b** action panes (assign / investigation / external report / risk / action / mark-informed) in the
> gated Options bar. **Step 5** owns the prototype-faithful Triage decision screen + gated Close checklist.

## 0. Identity
- **Step:** 4a — read-only `SafeguardingConcernDialog` on WizardShell, opens over the list
- **Routes:** `/safeguarding` (index gains `detail` prop on `?concern={id}`); `/safeguarding/{id}` show → redirect to `/safeguarding?concern={id}` (thin shell)
- **New component:** `resources/js/components/safeguarding/concern-dialog.tsx`
- **Controller:** `@index` (+`detail`), new `buildConcernDetail()`, `@show` → redirect
- **Drop refs:** Safeguarding.dc.html detail modal (rail sections + lifecycle tracker 323–348, stages 870, stageIndex 1052, timeline 1069–1077); HANDOFF §2; incidents `incident-detail-dialog.tsx` (§7 template); `WizardShell`/primitives.

## 1. Section map (rail) — read-only this step
| Section | Source |
|---|---|
| Overview (lifecycle **stage tracker** + active-CR-alert banner + triage-now note + People + Classification + immediate response) | concern + triage/closure fields |
| Timeline | derived events (raised → triaged → investigation opened → report logged → closed) |
| Risk | `riskAssessments` |
| Investigation (+ H&S event read-only + "Open in Health & Safety") | `investigations` + linked `HsEvent` |
| External reports | `externalReports` |
| Action plan | `actionPlans` (overdue highlighted) |
| Linked records | related incident · H&S event · subject alert · (Control Room alert → Step 8) |
- **Evidence** section → **Step 7** (SafeguardingAttachment); omitted now (no stub).
- Lifecycle stage tracker: Reported→Triaged→Investigating→Action plan→Monitoring→Closed; `referred_external` shown at idx 2, `no_action_required` at idx 1 (branch chips).

## 2. Lifecycle gates surfaced
- `detail.lifecycle.stage_index` drives the tracker. `detail.lifecycle.gates` (can_triage/investigate/refer/close + reasons) + `detail.can` are serialized now so **4b** Options bar consumes them. No writes this step.

## 3. Need-to-know / redaction (§3b)
- `@index` only serializes `detail` when the viewer `can('view', $concern)`; a **restricted** concern (sensitive + no viewSensitive + not assignee/reporter) serializes a **redacted** detail (`restricted:true`, subject/description/reporter/perpetrator nulled) and the dialog renders a locked state.
- Audit cue: rail footer + header "Your access to this concern is recorded." (the "viewing is logged" cue). Actual read-audit logging = lightweight here (cue only); deeper audit deferred (G2 note).

## 4. Modal map (§4)
- `SafeguardingConcernDialog` = the ONE detail surface, on WizardShell read-only chrome. Replaces `show.tsx`. No raw Dialog/Sheet. 4b/5 add panes onto the SAME shell (action state, like IncidentDetailDialog's EditPane/ActionPane).

## 5. Backend
| Change | Migration? | Test |
|---|---|---|
| `@index` serialize `detail` on `?concern={id}` (authorized + redaction) via `buildConcernDetail` | no | index?concern returns detail; restricted detail redacted |
| `@show` → `redirect()->route('safeguarding.index', ['concern'=>id])` (thin shell) | no | show redirects; unauthorised still 403 |
| resolve linked `HsEvent` (idempotency key) read-only | no | (covered by detail test) |

## 6. Incidents-consistency (§7)
- Same `WizardShell` + `ReviewCard`/`ReviewRow`/`InfoCard` chrome, same rail-section-switcher + footer pills + "Open full page" idiom, same detail-over-list mechanism (`router.get` `only:['detail']`, close drops the param). Adopt as-is. Safeguarding deltas: 7 sections incl. lifecycle tracker + Risk/External-reports (vs incidents' photos/followups), need-to-know restricted state, "viewing is logged" cue.

## 7. Cross-module
- Subject → `/operations/clients/{id}/care`; related incident → `/incidents/{id}`; H&S event → `/health-safety/events/{id}`. Control Room alert link → Step 8.

## 8. Retire → redirect
- `safeguarding/show.tsx` → delete the Inertia page; `@show` redirects to the list with `?concern=`. `/safeguarding/{id}` stays a valid deep link (no 404). `create.tsx`/`edit.tsx` → Step 6.

## 9. Execution checklist (4a)
- [ ] `buildConcernDetail()` + `@index` detail prop (redaction-aware) + `@show` redirect
- [ ] `concern-dialog.tsx` (read-only sections + lifecycle tracker + restricted state + audit cue)
- [ ] `index.tsx`: openDetail/closeDetail (`only:['detail']`), row click → openDetail, render dialog, `detail` prop
- [ ] delete `show.tsx`; update show tests → redirect; move show-serialization test → index?concern detail
- [ ] types/lint/pint/build; safeguarding tests green
- [ ] commit + tick PROGRESS

## 10. Notes
- 4b: Options bar wires assign/investigation/external-report/risk/action/mark-informed panes (endpoints exist). Step 5: Triage decision screen + gated Close checklist (prototype-faithful).
- Keep `detail.lifecycle.gates` shape stable so 4b consumes without re-serialising.
