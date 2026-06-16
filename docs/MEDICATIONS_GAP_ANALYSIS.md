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
- [x] **C6** "View client" wired in both the row context menu and the detail-modal Options bar (`router.visit('/operations/clients/{id}/care')`). In-page actions (edit/verify/reject/discontinue) stay modal; only View client / Open on MAR navigate. _(Medications.tsx + _dialogs.tsx)_

## D. Alert banner strip
- [x] **D7** Dismissible alert strip under the hero (new `AlertStripRow` helper mirroring `/emar/controlled`): **Awaiting** (warning) → "Review" sets `activeTab='awaiting'`; **Low/out of stock** (critical) → "Review" sets a new `lowStockOnly` client-side filter + `sort='stock'` + `activeTab='all'`. Each row has a ✕ that hides it per session (`alertDismissed` state). Added a clearable "Low / out of stock ✕" chip in the table toolbar so the filter is removable. _(Medications.tsx)_

## E. Detail modal parity + enrichment
- [x] **E8** `MedicationDetailDialog` footer rebuilt as the standard Options action bar (Close left; right group = Verify/Reject when pending & canVerify · Edit · Discontinue when active · View client · MAR · Print). Pending banner simplified to informational (actions live in the footer now); redundant body "Open on MAR" link removed. _(_dialogs.tsx)_
- [x] **E9** Surfaces audited & enriched. **Flags** row (PRN / Controlled / High-risk / Witness / Interaction:severity, critical-toned for CD/high-risk). Full order detail ✓. **Verification/audit trail** now surfaced via a dedicated "Audit trail" section (Charted by · Verification status · Review due · Ceased) — wired in **BK** from pre-existing columns. **Stock history** + **per-client interaction detail** remain genuine payload gaps → `TODO(G-stockhist)` / `TODO(G-interact)` (deferred, no invented tables). _(_dialogs.tsx)_

## F. Empty state + polish consistency
- [x] **F10** Rows keyboard-focusable (`tabIndex=0` + Enter/Space + focus ring, done in B5). Empty state replaced with the standard pattern: tokenised icon chip + context-aware message (distinguishes "register empty" vs "filtered to none") + an "Add medication" CTA that opens the add modal. Semantic tokens only. _(Medications.tsx)_

## Backend (medications() — minimal; NO speculative migrations)
- [x] **BK** Enriched the `medications()` detail payload with the verification/lifecycle **audit trail** using pre-existing columns (no migration): added a `verifiedByUser` relation to `ClientMedication`; eager-loaded `createdByUser`/`verifiedByUser`/`ceasedByUser`; mapped `created_by_name`, `created_at`, `verified_by_name`, `verified_at`, `ceased_by_name`, `ceased_at`, `ceased_reason`, `review_date` (null-safe, formatted). Added the fields to `MedRow` and an "Audit trail" section to the detail modal. No new query params; search/client stay client-side. Stock-history + interaction-detail left as `TODO(Gx)`. _(ClientMedication.php, EmarController.php, types.ts, _dialogs.tsx)_

---

## TODO(Gx) — deferred / payload gaps
- **TODO(G-asat)** Optional "as-at date" `DayPickerChip` in the hero footer that re-queries the register as-of a historical date (would need a `medications(?as_at=)` param + a point-in-time query). Not built — register is served whole/current by design.
- **TODO(G-stockhist)** Detail-modal **stock history** — `medications()` only serves the current stock snapshot (`on_hand`/`unit`/`low`). A movements/adjustments timeline would need a stock-ledger source (no obvious general stock-movement table; CD movements live in `ClientControlledDrugEntry`). Not built — would need a confirmed data source, not a new table.
- **TODO(G-interact)** Detail-modal **per-client interaction detail** — payload carries only `interaction_severity` (a string), surfaced in the Flags row. Richer detail (the paired drug, mechanism, guidance) would need joining `MedicationInteraction` rows per client into the detail payload. The register-wide `InteractionsDialog` remains the reference view. Not built pending a decision on the per-client interaction shape.

## Pass log
- **Pass 1** (Group A + B + menu-half of C, plus F keyboard-focus): hero footer standardised to the shared eMAR control row; raw client `Select` replaced by Client `EntityFilter`; right-click `ShiftContextMenu` on every row; inline action buttons folded into the menu; rows keyboard-activatable. Gates: `tsc` clean (whole project), `eslint` clean, full vite bundle build ✓. Files: `resources/js/pages/emar/Medications.tsx`, `docs/MEDICATIONS_GAP_ANALYSIS.md`.
- **Pass 2** (Group D): dismissible hero alert strip (`AlertStripRow`) for awaiting-verification (→ Awaiting tab) and low/out-of-stock (→ `lowStockOnly` filter + stock sort), per-session dismiss ✕, plus a clearable low-stock chip in the toolbar. Gates: `tsc` clean, `eslint` clean, full vite bundle build ✓. Files: `resources/js/pages/emar/Medications.tsx`.
- **Pass 3** (Group E8 + E9-flags, completes C6): detail-modal footer rebuilt as the standard Options bar (Verify/Reject/Edit/Discontinue/View client/MAR/Print); pending banner de-duplicated; Flags row added; recorded audit-trail (→ BK) + stock-history/interaction `TODO(Gx)`. Gates: `tsc` clean, `eslint` clean, full vite bundle build ✓. Files: `resources/js/pages/emar/_dialogs.tsx`, `docs/MEDICATIONS_GAP_ANALYSIS.md`.
- **Pass 4** (Group F10 empty state): grey empty block replaced with tokenised icon chip + context-aware message + "Add medication" CTA. Gates: `tsc` clean, `eslint` clean, full vite bundle build ✓. Files: `resources/js/pages/emar/Medications.tsx`.
- **Pass 5** (Backend BK + finalises E9): verification/lifecycle audit-trail payload enrichment (no migration) + "Audit trail" modal section. Gates: `tsc` clean, `eslint` clean, full vite bundle build ✓, `php -l` clean on both PHP files. (⚠️ Pint/Pest not runnable here — worktree has no `vendor/`; PHP additions hand-matched to surrounding style + null-safe.) Files: `app/Models/ClientMedication.php`, `app/Http/Controllers/Emar/EmarController.php`, `resources/js/components/emar/medications/types.ts`, `resources/js/pages/emar/_dialogs.tsx`.

## Loop status — ✅ COMPLETE (steady state)
All checklist items A–F + Backend are `[x]`. Only the two explicitly-deferred payload gaps remain — `TODO(G-stockhist)` (stock-movement history) and `TODO(G-interact)` (per-client interaction detail) — both need a confirmed data source, not a new table, so they are out of scope for this consistency loop. §6 exit conditions met: hero footer matches PRN/meds-today (pill search + Site + Client `EntityFilter`, onDark); every row has a right-click menu + View client; awaiting/low-stock alert strip present; detail modal has the standard Options bar + audit trail; all medication actions happen in-page via modals with Inertia partial reloads. ⚠️ Remaining for the user (browser-only, out of headless scope): live pixel/parity check vs `/emar/prn` on the dev/.com site + axe a11y sweep. The `@/routes`,`@/actions`,`@/wayfinder` dirs were copied into this worktree from the parent for tsc/build (git-ignored; not committed).
