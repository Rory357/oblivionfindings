# Self-Administration (/emar/self-admin) — Cross-Module Parity Gap Analysis

Single source of truth for the `/emar/self-admin` parity `/loop`. Tick `[x]` only when
typecheck + lint + build pass for the touched files. Mirror the shared idioms from
`/emar/prn` (`PrnRecords.tsx`, `prn-detail-dialog.tsx`) and `/emar/controlled`
(`ControlledDrugs.tsx`); reuse components, never hand-roll.

**Files in scope:** `resources/js/pages/emar/SelfAdmin.tsx`,
`resources/js/pages/emar/_self-admin-dialogs.tsx`,
`app/Http/Controllers/Emar/EmarController.php → selfAdmin()/serializeSelfAdmin()`.

**Note:** The design-drop prototype (`.design-drops/emar-redesign/Self_Administration_Page/…`)
is not present in this worktree. Per the loop brief, the cross-module standard wins for
row interactions / menus / client-jump regardless, so PRN + Controlled are the authority.

---

## A. Right-click context menu (everywhere)

- [x] **A1** Add `onContextMenu → ShiftContextMenu` (copy PRN's `openRowCtx`) to the
  assessments table rows, the reassess cards, the agreements list rows, and the per-med
  scope headers. Items: View assessment (primary) · Reassess · Sign agreement (when
  unsigned) · Set medication scope (when self-managing) · sep · View client · Open on MAR
  chart · sep · Print assessment. Header tag = outcome category pill; meta = client · NHI ·
  reassessment date. — *Done: `catCtxTag()` + `openRowCtx()` in `SelfAdmin.tsx`; wired to
  all four list surfaces; `<ShiftContextMenu>` rendered at root.*

## B. Detail modal — Options bar + "View client"

- [x] **B2** Replace `ViewSelfAdminDialog`'s lone Close button with the standard Options
  action bar (Reassess · Sign agreement · Set scope · View client + Close). Actions open
  the existing wizards in place; View client → `/operations/clients/{id}/care`. — *Done:
  `ViewSelfAdminDialog` now takes `onReassess/onSignAgreement/onSetScope`; footer = Close
  (left) + Options bar (right); SelfAdmin wires the callbacks to swap `modal`.*
- [x] **B3** Surface the full assessment in the detail modal: capacity sub-scores (/25), the
  6 capability checks, consent/wishes, support & storage adjustments, agreement status, and
  per-med scope. Payload already carries every field (`serializeSelfAdmin`), so no backend
  change. — *Done: enriched read-only body — sub-score grid, capability check grid,
  consent/people, support+storage, agreement banner, per-med scope list.*

## C. "View client" jump + click-to-open parity

- [x] **C4** Wire View client in both the context menu (A1) and the detail modal footer
  (B2). — *Done: `router.visit('/operations/clients/${id}/care')` in both.*
- [x] **C5** Make the reassess cards, agreements list rows, and per-med scope headers
  click-to-open the detail modal (cursor-pointer + hover + keyboard-focusable, with
  `stopPropagation` on inline buttons) — every list behaves like the assessments table. —
  *Done: `role="button"` + `tabIndex` + `onKeyDown` + `onContextMenu` on each surface;
  inline action buttons stop propagation.*

## D. Stacked alert strip

- [x] **D6** Replace the single reassessment banner with a stacked, dismissible alert strip
  (mirror `/emar/controlled`): N due for reassessment (`kpis.due_now`, warning → Reassess
  tab) and N agreements awaiting signature (`kpis.unsigned`, critical → Agreements tab).
  Each: icon + count + message + Review button that sets `activeTab`, plus per-session
  dismiss. — *Done: `SaAlert` + sessionStorage dismiss helpers + `AlertRow` (copied from
  ControlledDrugs); strip rendered between hero and TabStrip.*

## E. Client filter + search-pill polish

- [x] **E7** Add a Client `EntityFilter` (allLabel="All clients", onDark) beside Site in the
  hero footer, built from the `clients` prop (client-side faceting). Align the hand-rolled
  search pill to the shared meds/today / PRN pill (clear-✕ + matching classes). No
  day-stepper — the reassessment-due tab/banner is the correct time dimension. — *Done:
  Client EntityFilter folds into `visible`; search pill rebuilt to the PRN pill (absolute
  icon, `h-8 rounded-full`, clear-✕).*

## Backend (selfAdmin() — minimal)

- [x] **BE1** Detail enrichment (B3): confirm capacity sub-scores, capability checks,
  support/storage notes, agreement status and per-med scope are returned. — *Confirmed:
  `serializeSelfAdmin()` already returns all of them; no change required.*
- [x] **BE2** Client filter (E7): client-side faceting chosen, so no `client_id` round-trip
  added (kept the controller minimal). — *No change required.*

## Verify / Exit

- [x] `npm run types` clean (exit 0) · `npm run lint` clean (touched files, exit 0) ·
  `npm run build` succeeds (exit 0, 3m19s). (First-run worktree setup: copied generated
  `routes`/`actions`/`wayfinder` codegen + `vendor` + `.env` from the parent so the
  wayfinder vite plugin can regenerate at build.)
- [x] Assessment/agreement/scope wizards + capacity-scoring logic untouched (no regression):
  `MedicationSelfAdminAssessment`/`storeSelfAdmin`/`updateSelfAdmin` and the wizard
  components are unchanged; `SelfAdminTest` 7/7 green (consent-first, recompute-on-update,
  supersede, soft-delete all still pass).
- [x] Coverage added:
  - `resources/js/pages/emar/_self-admin-dialogs.test.tsx` (vitest/RTL, 4 tests) — the
    **detail Options bar** fires Reassess/Sign/Set-scope in place, **View client** jumps to
    `/operations/clients/{id}/care`, the enriched body surfaces capacity sub-scores +
    capability checks + per-med scope, and the signed banner replaces Sign-agreement.
  - `SelfAdminTest::test_payload_carries_detail_enrichment_fields` (feature) — asserts the
    serializer returns the full enrichment contract the detail modal reads.
  - The **context menu** + **click-to-open rows** reuse the shared `ShiftContextMenu` /
    `rowInteract` idioms (identical to PRN/Controlled, both of which carry the live coverage)
    and are validated by typecheck + build; a page-level interaction test would need a full
    `AppLayout` render harness that this codebase exercises via Playwright e2e only (no
    self-admin e2e spec exists — a live-on-.com check is the user/browser step).

### Remaining TODO(Gx)
- None. All A–E + backend boxes closed. Live pixel-parity-on-.com verification is a
  browser/user step outside this headless loop.
