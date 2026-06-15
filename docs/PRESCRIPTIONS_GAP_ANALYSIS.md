# Prescriptions & Orders (`/emar/prescriptions`) — Gap Analysis & Loop Tracker

Single source of truth for the `/loop` bringing **`/emar/prescriptions`** to
feature-complete, standardised parity with `/emar/prn`, `/meds/today` and the
app's shared idioms. **Cross-module consistency + ease-of-use is the priority.**
Scope is ONLY `/emar/prescriptions`, its tabs, and its modals. Do NOT regress the
working wizards or the countersign-deadline logic.

Status legend: `[ ]` open · `[x]` done. Tick an item ONLY when its
typecheck/lint/build is green.

> **STATUS: all boxes `[x]` — loop exit reached (see §6 at the bottom).** Verified
> 2026-06-16: `npx tsc --noEmit` 0 errors (whole project, after Wayfinder gen),
> eslint clean on touched files, `npm run build` ✓, `pint` passed,
> `PrescriptionsPageTest` 3/3 (33 assertions).

## Reference idioms (copy, don't hand-roll)
- Hero footer (search + Site + Client): `resources/js/pages/emar/PrnRecords.tsx`
  footer (~L339) and `resources/js/pages/meds/today/index.tsx` heroFooter.
- Filter pills: `@/components/rostering` → `EntityFilter` (`onDark`).
- Right-click menu: `@/components/rostering` → `ShiftContextMenu`, `ShiftCtxItem`,
  `ShiftCtxState`. Template = PrnRecords `openRowCtx` (~L193).
- Read-only detail modal + Options bar: `resources/js/components/emar/prn-detail-dialog.tsx`
  (built on `@/components/wizard/shell` → `WizardShell`/`ReviewCard`/`ReviewRow`).
- Client jump: `router.visit(`/operations/clients/${clientId}/care`)`.
- Alert strip: mirror `/emar/controlled` stacked banner (`ControlledDrugs.tsx` ~L129).
- Wizard chrome (keep): `@/components/meds/wizard-shell` → `MedsWizardDialog`, `SummaryRow`.

> NOTE: the `.design-drops/emar-redesign/Prescription_Page/` prototype folder is
> NOT present in this worktree (design drops are not committed). The cross-module
> standard (PRN / meds-today / controlled) is authoritative here per the prompt —
> "the cross-module standard WINS for row interactions/menus/client-jump".
> Build-plan reference: `docs/emar-redesign/prescriptions-plan.md`.

## Backend data model (verified)
`MedicationPrescriberOrder` carries **one** inline countersign + **one** inline
dispense event (no separate history tables) — `countersigned_at`/`_by_name`/
`countersign_method`, `dispensed_at`/`_by_name`/`pharmacy_name`/`batch_number`/
`batch_expiry`. Linked MAR = `client_medication_id` → `medication` relation.
Covert auths are a separate list keyed by client+medication (cross-reference
client-side). The detail modal is fully buildable from existing payload + a small
`client_room`/`client_site` enrichment. **No new tables/columns/migrations.**

---

## A. Hero footer — standardise the control row
- [x] **A1** White pill search ("Search client, medication or prescriber…",
  clear-✕) + Site `EntityFilter` + new Client `EntityFilter`
  (`allLabel="All clients"`, `onDark`) all in the hero footer; search removed
  from the in-panel toolbar. The Client filter feeds `clientItems` (mapped from
  the `clients` prop, `description = site_name`). Search + Client narrow **every
  tab's** rows via `matchesFooter`; counts/badges stay unfiltered (true totals).
  _Files: `resources/js/pages/emar/Prescriptions.tsx`._
- [x] **A2** Raw shadcn status `Select` → status **chips** (`STATUS_FILTERS`,
  PRN register-chip idiom, secondary/ghost), kept local to the Orders tab.
  _Files: `Prescriptions.tsx`._
- [x] **A3** Day-picker: orders register has no date param → NO daily stepper
  (done-by-design). Standardised via search + Site + Client. Order-date range
  filter remains a future `TODO(Gx)` — not built.

## B. Order detail / "view" modal + clickable rows
- [x] **B4** New read-only **`OrderDetailDialog`** (prn-detail-dialog idiom, on
  `WizardShell`): 2 sections — Order (resident avatar/room/site, medication +
  dose/route/freq/indication/instructions, prescriber + source written/verbal/
  telephone, order/effective/expiry dates) and Countersign & dispensing
  (countersign status + hours-left/overdue + method/signed-by/read-back,
  dispensing detail, linked MAR entry, active covert authorisation). Footer
  Options bar: Countersign (when awaiting) · Dispense (when confirmed) · Link to
  MAR (pending/confirmed) · Client · MAR. _Files:
  `resources/js/components/emar/prescriptions/order-detail-dialog.tsx` (new)._
- [x] **B5** Order rows clickable (cursor-pointer + hover + `tabIndex`/Enter-Space
  + focus ring) → detail modal; inline action buttons wrapped with
  `stopPropagation`. Same row-click + detail on Awaiting-Countersign cards,
  Dispensing list/table rows, and Activity rows. _Files: `Prescriptions.tsx`._

## C. Right-click context menu
- [x] **C6** `onContextMenu` → `ShiftContextMenu` (`openRowCtx`, copied from PRN)
  on order rows across Orders/Countersign/Dispensing/Activity. Items: View
  details (primary), Countersign (when awaiting), Confirm / Dispense
  (status-appropriate), Link to MAR, sep, View client, Open on MAR, sep, Cancel
  order (critical, pending/confirmed only). Header tag = status/deadline pill
  (`ctxTag`); meta = client · medication · prescriber. Covert cards get a lighter
  `openCovertCtx` (View client · Open on MAR · Revoke). _Files: `Prescriptions.tsx`._

## D. "View client" jump
- [x] **D7** View client (`/operations/clients/{id}/care`) wired in the context
  menu (C) and the detail modal footer (B); Open on MAR navigates to the real
  per-client chart (`/clients/{id}/mar`, `ClientMarController@show`). In-page
  actions stay modal/inline. _Files: `Prescriptions.tsx`, `order-detail-dialog.tsx`._

## E. Stacked alert strip
- [x] **E8** Single overdue banner → stacked, **dismissible** alert strip
  (`visibleAlerts`, mirrors `/emar/controlled`): overdue countersign (critical →
  Awaiting), awaiting countersign (warning → Awaiting, excludes the overdue rows
  so the two counts don't double-count), ready to dispense (info → Dispensing),
  covert active (warning → Covert). Each: icon + count + message + "Review" →
  `setActiveTab` + ✕ dismiss. _Files: `Prescriptions.tsx`._

## F. Polish for parity
- [x] **F9** Rows keyboard-focusable (`tabIndex={0}` + Enter/Space + focus-visible
  ring + `aria-label`); grey empty states → standard `EmptyState` (icon + message
  + optional CTA) on Countersign/Covert tabs; semantic tokens only (Avatar hue is
  the established per-resident `oklch()` convention shared with PRN/meds-today —
  no raw `oklch()` introduced in row/state markup). _Files: `Prescriptions.tsx`._

## Backend (minimal)
- [x] **BK** `prescriptions()` payload: added `client_room` (`client.room.name`)
  + `client_site` (`client.site.name`) to each order (eager-loaded
  `client.site:id,name` / `client.room:id,name`); `clients` now carries
  `site_name` for the Client `EntityFilter` description. `preserveState`/
  `preserveScroll` kept; no date param; no migrations. `PrescriptionsPageTest`
  3/3 still green. _Files: `app/Http/Controllers/Emar/EmarController.php`,
  `resources/js/components/emar/prescriptions/types.ts`._

---

## Pass log
- **Pass 1 (2026-06-16)** — created tracker; audited live page vs §2 +
  cross-module standard. Implemented **all** gaps in one coherent commit (A–F +
  BK are tightly coupled — same component + one new dialog; row interactions feed
  the detail modal, context menu and alert strip). Verified: tsc 0 / eslint clean
  / build ✓ / pint ✓ / PrescriptionsPageTest 3/3. Remaining: optional `TODO(Gx)`
  order-date range filter (explicitly deferred); browser pixel-verify on `.com`
  (user/browser, out of scope for the headless loop).

## §6 Loop exit — REACHED
Every box `[x]`; tsc/lint/build all pass; hero footer = white pill search + Site +
Client `EntityFilter` (`onDark`); order rows have row-click detail modal + a
right-click menu with View client; stacked dismissible alert strip present; all
prescription actions happen in-page via modals with Inertia partial reloads
(`preserveScroll`). Only deferred item is the optional `TODO(Gx)` order-date range
filter and live browser parity-check on oblivionfindings.com.
