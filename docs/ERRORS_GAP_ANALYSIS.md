# /emar/errors — Cross-module Parity Gap Analysis

Single source of truth for the Medication Errors (`/emar/errors`) parity loop. Goal: bring the
already-strong Report → Triage → Review → Resolve → Close-out workflow up to full cross-module
parity with `/emar/prn` and the app's shared idioms, and deepen the error↔incident workflow.
**Scope is ONLY `/emar/errors`, its tabs and its modals.** The incident jump only navigates to
`/incidents/...`; the incidents module itself is not modified.

Touched files:
- `resources/js/pages/emar/MedicationErrors.tsx` — page (hero, footer, table, context menu, alerts)
- `resources/js/pages/emar/_error-dialogs.tsx` — TriageDialog (Options bar), review/resolve/close wizards
- `app/Http/Controllers/Emar/MedicationErrorController.php` — serializer (`mar_url`), `linkIncident()`
- `routes/emar.php` — `emar.errors.link_incident` route

Idioms mirrored (copied, not invented):
- Right-click menu → `@/components/rostering/shift-context-menu` (`ShiftContextMenu`, `ShiftCtxItem`,
  `ShiftCtxState`); copy of PRN's `openRowCtx` (`PrnRecords.tsx`).
- Detail Options bar → `resources/js/components/emar/prn-detail-dialog.tsx` footer pattern.
- Jumps → client `/operations/clients/{id}/care` (`operations.clients.care`); incident `/incidents/{id}`
  (`incidents.show`); MAR `/emar/mar?client_id={id}` (`EmarUrl::mar`).
- Filter pills → `@/components/rostering` `EntityFilter`.
- Alert strip → `/emar/controlled` stacked banner (`AlertRow` + per-session dismiss).

> Note: `.design-drops/emar-redesign/Emar_Errors_Page/` is not vendored into this worktree, so the
> §2 spec in the loop prompt + the cross-module standard are the working reference. The cross-module
> standard wins for row interactions/menus/jumps regardless.

## Checklist

### A. Right-click context menu + "View client"
- [x] A1. `onContextMenu` on error rows → `ShiftContextMenu` (copy PRN `openRowCtx`). Items: View /
  triage (primary), Review (when reported), Resolve (when reported/investigating), Close out (when
  resolved), sep, View client (→ care page), Open on MAR chart, View linked incident **or** Create &
  link incident (C), sep, Escalate (critical). Header tag = severity/status pill; meta = ref · client ·
  medication.

### B. Triage dialog — standard Options bar
- [x] B1. Give `TriageDialog` the standard Options action bar (like `prn-detail-dialog.tsx`):
  status-appropriate Review / Resolve / Close · View client · View / create incident (C) · Open on
  MAR (+ Close). Keep the rich read-only detail (type, severity, harm band, attachments, timeline).
  Action buttons open the existing review/resolve/close wizards in place.

### C. Incident jump + create-and-link
- [x] C1. Backend: add `linkIncident()` to `MedicationErrorController` + `emar.errors.link_incident`
  route. Reuses the existing `store()` incident-creation logic; creates a `ClientIncident` and links it
  to the error (sets `client_incident_id`). Does NOT modify the incidents module.
- [x] C2. Serialize `mar_url` (`EmarUrl::mar`) onto each error row for "Open on MAR chart".
- [x] C3. Front-end: when `error.incident` exists → "View incident" → `router.visit('/incidents/{id}')`;
  when it doesn't → "Create & link incident" → POST link route then navigate. Surface in the triage
  dialog (B) and the context menu (A).

### D. Alert strip
- [x] D1. Stacked, dismissible strip (mirror `/emar/controlled`) from already-computed data: N critical
  errors open (critical → Critical tab), N awaiting review (warning → Open tab), N resolved-not-closed
  (info → Resolved tab). Each: icon + count + message + "Review" button that sets `activeTab`.

### E. Consolidate search + Client into the hero footer
- [x] E1. Move search + Client filter from the in-panel bar up into the hero footer (one control row
  beside the month-stepper + Site). Keep Severity / Type / Reporter as error-specific panel facets.
  Align the search pill to the shared meds/today pill. Keep the month-stepper (do NOT add a day-stepper).

### F. Polish
- [x] F1. Error rows keyboard-focusable (Enter/Space opens triage); empty states use the standard
  icon + message pattern; semantic tokens only (no raw `oklch()`).

### Backend
- [x] (covered by C1/C2) No new index params, no migrations. `linkIncident()` is the only new endpoint.

## Verification log
- A1 — `openRowCtx` + `ShiftContextMenu` on every error row (right-click): View/triage, status-aware
  Review/Resolve/Close, sep, View client → `/operations/clients/{id}/care`, Open on MAR, and the
  incident action (escalates with critical tone for open critical/major). `MedicationErrors.tsx`.
- B1 — `TriageDialog` footer is now the standard Options bar (Close · Review/Resolve/Close · Client ·
  Incident/Create · MAR); rich read-only body kept. `_error-dialogs.tsx`.
- C1 — `linkIncident()` controller + `emar.errors.link_incident` POST route (idempotent; reuses
  `store()` incident shape; redirects to `incidents.show`). `MedicationErrorController.php`, `routes/emar.php`.
- C2 — `mar_url` (`EmarUrl::mar`) added to `serializeError()` + `ErrorRow` type. `MedicationErrorController.php`, `_error-dialogs.tsx`.
- C3 — incident jump (`/incidents/{id}`) when linked, else create-and-link POST; in both the context
  menu and the triage Options bar; fixed the triage banner "Open" link (was `/clients/{id}`).
  `MedicationErrors.tsx`, `_error-dialogs.tsx`.
- D1 — `AlertRow` strip (critical/warning/info) from loaded register; per-session `sessionStorage`
  dismiss; "Review" sets `activeTab`. `MedicationErrors.tsx`.
- E1 — search pill (shared meds/today style) + Client filter moved into the hero footer beside the
  month-stepper + Site; Severity/Type/Reporter remain panel facets. `MedicationErrors.tsx`.
- F1 — rows `tabIndex={0}` + Enter/Space → triage + focus ring; empty state = icon + message;
  semantic tokens only. `MedicationErrors.tsx`.
- Verified: `npm run types` clean · `eslint` clean (touched files) · `npm run build` succeeds ·
  `php -l` clean · `wayfinder:generate` OK. Browser pixel/interaction check pending (loop §5/§6).
