# /emar/medications — cross-module consistency gap analysis

Single source of truth for the `/loop` bringing the **Medications register** (`/emar/medications`)
up to the shared eMAR standard already used by `/emar/prn`, `/meds/today` and the rebuilt
`/emar/controlled`. The page itself (row-click → detail modal; Add/Edit/Detail/Discontinue/
Reject/Import/Interactions on `MedsWizardDialog`) is well-made — this loop is about **filter,
menu and navigation consistency**, not rebuilding the modals.

Where the design handoff (`.design-drops/emar-redesign/Medications_Page/`) conflicts with the
cross-module standard, **the cross-module standard wins** for filters / menus / client-jump.

Status legend: `[ ]` open · `[x]` done (with one-line note + files touched).

## Reference implementations (copy, don't invent)
- Hero footer control row — `resources/js/pages/emar/PrnRecords.tsx` (~L339) · `resources/js/pages/meds/today/index.tsx` heroFooter
- Filter pills — `EntityFilter` from `@/components/rostering` (onDark)
- Right-click menu — `ShiftContextMenu` / `ShiftCtxItem` / `ShiftCtxState` from `@/components/rostering/shift-context-menu`; template = PRN `openRowCtx` (PrnRecords.tsx L193)
- Client jump — `router.visit('/operations/clients/${clientId}/care')` (route `operations.clients.care` ✓ confirmed)
- Detail Options bar — `resources/js/components/emar/prn-detail-dialog.tsx` footer
- Alert strip — `/emar/controlled` strip (`ControlledDrugs.tsx` L129-137)
- Wizard chrome — `MedsWizardDialog` / `SummaryRow` from `@/components/meds/wizard-shell` (keep as-is)

---

## A. Hero footer — standardise the control row (the consistency centrepiece)
- [x] **A1** Rebuilt hero footer as one onDark control row: white pill search ("Search medication, brand or client…", clear-✕) + Site `EntityFilter` + Client `EntityFilter` (replaces raw `Select`). Mirrors PRN footer right-side group. _(Medications.tsx footer prop)_
- [x] **A2** Removed search + client `Select` from the in-panel toolbar; kept **Sort** + **Interactions** + the "N of M" count (table-specific). Toolbar now leads with a "Medication register" label. _(Medications.tsx)_
- [x] **A3** No day-stepper — Medications is the order register (no date metaphor); footer matches via search+Site+Client only. Optional future `TODO(G-asat)` left in TODO section. _(by design)_
- [x] **A4** Search/client stay **client-side**; Site round-trips via `router.get` with `preserveState`/`preserveScroll`. `clientFilter` state changed `string→number|null` to fit `EntityFilter`. _(Medications.tsx)_

## B. Right-click context menu on every row
- [x] **B5** Added `onContextMenu` → `ShiftContextMenu` via new `openRowCtx` (copied PRN template). Items: View details (primary) · Edit order · Verify/Reject (when `pending_verification` & `can.verify_orders`) · Open on MAR · sep · View client · View stock · sep · Discontinue (critical, when `state==='active'`). Tag reflects approval/state status; meta = client · med · dose. Inline Edit/Discontinue buttons folded into the menu (PRN-consistent); rows are now also keyboard-activatable. _(Medications.tsx)_

## C. "View client" everywhere
- [~] **C6** Context-menu "View client" wired (`router.visit('/operations/clients/{id}/care')`). Detail-modal Options-bar "View client" lands with **E8**. In-page actions stay modal; only View client / Open on MAR navigate. _(Medications.tsx — menu half done)_

## D. Alert banner strip
- [x] **D7** Dismissible alert strip under the hero (new `AlertStripRow` helper mirroring `/emar/controlled`): **Awaiting** (warning) → "Review" sets `activeTab='awaiting'`; **Low/out of stock** (critical) → "Review" sets a new `lowStockOnly` client-side filter + `sort='stock'` + `activeTab='all'`. Each row has a ✕ that hides it per session (`alertDismissed` state). Added a clearable "Low / out of stock ✕" chip in the table toolbar so the filter is removable. _(Medications.tsx)_

## E. Detail modal parity + enrichment
- [ ] **E8** Align `MedicationDetailDialog` footer to the standard Options action bar (like `prn-detail-dialog.tsx`): Edit · Verify/Reject (when pending) · View client · Open on MAR · Print. Keep existing recent-administrations / order summary.
- [ ] **E9** Confirm the modal surfaces: full order detail (dose/route/frequency/instructions ✓), flags (PRN/CD/high-risk/witness), verification/audit trail, stock history, interaction detail (link to Interactions for this client). Payload gaps → `TODO(Gx)` here (don't invent tables).

## F. Empty state + polish consistency
- [~] **F10** Rows are now keyboard-focusable (`tabIndex=0` + Enter/Space opens detail + focus ring) — done with B5. **Remaining:** replace the grey empty state (Medications.tsx) with icon + message + "Add medication" CTA. Semantic tokens only.

## Backend (medications() — minimal; NO speculative migrations)
- [ ] **BK** Only touch `EmarController@medications` (L1554) to enrich the detail payload for E9 if the modal needs data it doesn't already receive (stock history, interaction detail, verification audit). Schema gaps → `// TODO(Gx)`. Search/client stay client-side; no new params.

---

## TODO(Gx) — deferred / payload gaps
- **TODO(G-asat)** Optional "as-at date" `DayPickerChip` in the hero footer that re-queries the register as-of a historical date (would need a `medications(?as_at=)` param + a point-in-time query). Not built — register is served whole/current by design.

## Pass log
- **Pass 1** (Group A + B + menu-half of C, plus F keyboard-focus): hero footer standardised to the shared eMAR control row; raw client `Select` replaced by Client `EntityFilter`; right-click `ShiftContextMenu` on every row; inline action buttons folded into the menu; rows keyboard-activatable. Gates: `tsc` clean (whole project), `eslint` clean, full vite bundle build ✓. Files: `resources/js/pages/emar/Medications.tsx`, `docs/MEDICATIONS_GAP_ANALYSIS.md`.
- **Pass 2** (Group D): dismissible hero alert strip (`AlertStripRow`) for awaiting-verification (→ Awaiting tab) and low/out-of-stock (→ `lowStockOnly` filter + stock sort), per-session dismiss ✕, plus a clearable low-stock chip in the toolbar. Gates: `tsc` clean, `eslint` clean, full vite bundle build ✓. Files: `resources/js/pages/emar/Medications.tsx`.
