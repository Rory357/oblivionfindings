# Safeguarding Redesign — Step Plan: 08 (FINAL) — Cross-module + NZ authority currency

## 0. Identity
- **Step:** 8 — X1 reverse incident relation · X2 Control Room quick-actions (concern side) · X3 state-sync · NZ authority currency
- **Edits:** `ClientIncident` (+relation), `IncidentController::buildIncidentDetail` (+surface), `incident-detail-dialog.tsx` LinkedSection (+link) [cross-module, additive], `SafeguardingConcernObserver` (X3), `SafeguardingExternalReportController` (authority enum), `concern-dialog.tsx` ReportPane AUTHORITIES
- **Drop refs:** SAFEGUARDING_LIFECYCLE_PLAN §X1–X3 + currency note; HANDOFF §5/§7.7-8.

## 1. X1 — reverse incident → concern
- `ClientIncident::safeguardingConcerns(): HasMany(SafeguardingConcern, 'related_incident_id')`.
- `buildIncidentDetail`: load `safeguardingConcerns`; serialise `safeguarding_concerns` = [{id, reference_number, status, severity, can_view}] (`can_view = $user->can('view', $concern)` — gates the click-through; need-to-know).
- Incident detail dialog: add spawned-concern link(s) to LinkedSection (link to `/safeguarding/{id}` when can_view, else a non-link "restricted" row).
- Keep the existing abuse/neglect→concern auto-escalation (IncidentController ~L553) untouched.

## 2. X2 — Control Room safeguarding quick-actions (concern side)
- **Concern side already provided:** assign (`/safeguarding/{c}/assign`) + triage (`/safeguarding/{c}/triage`) are policy-protected and callable from anywhere; the jump target `/safeguarding/{id}` is the redaction-aware thin shell (restricted → locked). So an operator card can wire ack(CR alert)/assign/triage + jump using existing endpoints.
- The **operator alert-card UI belongs to the Control Room redesign drop** (separate) — per the lifecycle plan, Safeguarding provides the concern side only. **No speculative Control Room UI built here** (would stub a page being redesigned elsewhere). Documented in PROGRESS.

## 3. X3 — state-sync (concern → linked records coherent)
- Extend `SafeguardingConcernObserver::updated`: when `wasChanged('status')` && status ∈ {closed, no_action_required} → `syncTerminalState`: set the linked `HsEvent` (idempotency key) → `STATUS_CLOSED`; find the linked Control Room alert (`hsEvent->control_room_alert_id`, null-safe) → `STATUS_RESOLVED` + `resolved_at`. try/catch (best-effort, never block the concern update). NotifiableIncident left as-is (independent regulator obligation).

## 4. NZ authority currency
- `SafeguardingExternalReportController@store` validate `authority_type` `in:` → add `msd_dss` + `coroner` (keep police/health_nz/whaikaha/worksafe/privacy_commissioner/hdc/oranga_tamariki/other).
- `concern-dialog.tsx` ReportPane `AUTHORITIES`: add `{msd_dss: 'MSD – Disability Support Services'}` + `{coroner: 'Coroner'}`, relabel `whaikaha` → 'Whaikaha (monitoring)'. (DSS moved Whaikaha→MSD Sept 2024.)

## 5. Need-to-know
- X1 surfaces concerns gated by `can('view')` (no click-through leak). X3 is backend coherence (no UI). Authority list is non-sensitive.

## 6. Incidents-consistency (§7)
- X1 incident-side edits are additive (new relation + a LinkedSection row), matching the existing LinkedRow idiom — flagged as cross-module/shared. No Incidents behaviour changed.

## 7. Tests
- X1: incident detail (`/incidents?incident={id}`) surfaces a spawned concern with can_view. (+ keep escalation test green.)
- X3: closing a concern with a linked HsEvent sets the HsEvent to closed (+ alert resolved if linked).
- Authority: external report store accepts `msd_dss`.

## 8. Verify + finish
- migrate (none expected) / pint / tsc / eslint / build / full safeguarding suite + incident tests green. Commit. Tick PROGRESS. **Write the §10 final summary into PROGRESS.md, post it, and STOP the loop (no wakeup).**
