# Destructions Register — Gap Analysis & Parity Loop

Single source of truth for bringing `/emar/destructions` to the standardised eMAR
parity established by `/emar/prn` and `/emar/controlled`. Checklist with `[ ]`/`[x]`;
one gap (or tight group) per pass.

- **Live page:** `resources/js/pages/emar/Destructions.tsx`
- **Shared dialogs:** `resources/js/pages/emar/_cd-dialogs.tsx` → `RecordDestructionDialog` (shared with `/emar/controlled`), `VoidDestructionDialog`, `CdPill`
- **Controller:** `app/Http/Controllers/Emar/EmarController.php` → `destructions()` (`:2574`); void endpoint `emar.destructions.void`
- **Tests:** `tests/Feature/Emar/DestructionsTest.php`
- **Idioms mirrored:** `PrnRecords.tsx` (footer search + Client filter, `openRowCtx`), `prn-detail-dialog.tsx` (read-only detail + Options bar), `ControlledDrugs.tsx` (alert strip, `rowProps`/`interactive`)

## §0 Decision — KEEP standalone `/emar/destructions` as canonical

There is duplication: a standalone `/emar/destructions` page **and** a "Destructions"
tab on `/emar/controlled` (they share `RecordDestructionDialog`). The original
Controlled redesign suggested retiring the standalone page, but the standalone page is
**richer** (all-medications disposal log + CD-only tab + Reports & export, vs. a single
CD-only table on Controlled).

**Decision: keep `/emar/destructions` as the canonical disposal register.** The
Controlled "Destructions" tab becomes a count + summary + deep-link into the canonical
register (Gap G), with `RecordDestructionDialog` kept shared so "Record destruction"
behaves identically from both entry points. No 301 redirect; no porting of the 3-tab
richness into Controlled.

## Compliance invariant (do not violate)

The register is **append-only** (MoD Regs 1977). Records are **VOIDED, never edited or
deleted**. No edit/delete affordances anywhere. "Void" is the only correction path.
Voided records stay visible, struck-through, with reason. The void endpoint and the
record/void wizards must not regress, and immutability must not weaken.

## Checklist

### A. Hero footer — search + Client filter
- [x] **A1** Added a white pill search ("Search client, medication, batch or witness…", clear-✕) + a Client `EntityFilter` (`allLabel="All clients"`, `onDark`) to the hero footer next to the Site filter — one control row; client/search faceting is client-side (`matches`), Site round-trips. No day-stepper. `TODO(G-date)` deferred. _(Destructions.tsx)_

### B. Destruction detail / "view" modal + clickable rows
- [x] **B1** New `components/emar/destruction-detail-dialog.tsx` (WizardShell idiom, mirrors `prn-detail-dialog.tsx`): Disposal pane (med + client, qty + unit, reason, method + denaturing line, batch/expiry, destroyed_at) + Witness & audit pane (witness 1 & 2 + authoriser, audit trail) + a voided InfoCard. Options bar: View client · Export record · Void (Void only when live). _(destruction-detail-dialog.tsx, Destructions.tsx)_
- [x] **B2** Log + CD rows are clickable (cursor-pointer + hover + keyboard-focusable via `rowProps`) → open the detail modal; inline Void buttons `stopPropagation`. _(Destructions.tsx)_

### C. Right-click context menu (read-only + void only)
- [x] **C1** `onContextMenu` → `ShiftContextMenu` on log + CD rows (`openRowCtx`). Immutable-safe items only: View details (primary), View client, Export this record, sep, Void (critical, only when not voided). **No edit/delete.** Header tag = CD/MED/VOIDED pill; meta = client · medication · destroyed date. "Open on MAR chart" omitted — no MAR target on a destruction record (`TODO(G-mar)`). _(Destructions.tsx)_

### D. "View client" jump
- [x] **D1** View client → `/operations/clients/{id}/care` wired in the context menu (C) and the detail modal footer (B); shown only when `client_id` is present. _(Destructions.tsx, destruction-detail-dialog.tsx)_

### E. Search-pill / empty-state polish
- [x] **E1** Shared search-pill idiom (copied from PRN footer); empty states use the standard Trash2 icon + message pattern with a "no records match these filters" variant. Semantic tokens only. _(Destructions.tsx)_

### F. Alert strip (optional, low priority)
- [x] **F1** Dismissible stacked strip (mirrors `/emar/controlled` `AlertRow` + sessionStorage): N CD destructions in last 30d (info → Controlled tab) + N records voided — review (warning → Log tab). _(Destructions.tsx)_

### G. Cross-module dedupe (the workflow win)
- [x] **G1** Controlled page's "Destructions" tab is now a banner: count + "Open destructions register" deep-link (`router.visit('/emar/destructions')`) + read-only embedded summary table. `RecordDestructionDialog` kept shared so "Record destruction" behaves identically from both entry points (button moved into the banner). _(ControlledDrugs.tsx)_

### Backend (front-end first — NO edit/delete, NO migrations)
- [x] **BK1** Confirmed `destructions()` payload (`:2574`) carries everything Gap B needs (witnesses/authoriser/void-reason/labels) — no change needed. Denaturing-kit is derived client-side from `disposal_method` (`TODO(G-denaturing)` to persist explicit kit confirmation — needs migration, out of scope). No new params, no migrations, no edit/delete endpoints. Covered by `DestructionsTest::test_payload_carries_detail_fields_for_voided_cd_record`. _(DestructionsTest.php)_

## Audit notes (verified against code, first run 2026-06-16)

- Payload (`destructions()` `:2574`) already carries: `witness_1_name`, `witness_2_name`,
  `authorised_by_name`, `destroyed_by_name`, `voided_at`/`void_reason`/`voided_by_name`/`is_voided`,
  `batch_number`, `expiry_date`, `reason_label`, `disposal_method_label`, `controlled_drug_class`,
  `client_id`/`client_name`/`site_id`/`site_name`. **`clients`** prop = `getClientsList()` →
  `{id, first_name, last_name}` (map to `{id, name}` for the EntityFilter).
- **Denaturing-kit:** the wizard collects `denaturing_confirmed` but it is **not persisted**
  (`MedicationDestruction::$fillable` has no such column). Gap B derives the denaturing line
  from `disposal_method === 'denaturing'`; explicit kit-confirmation persistence is
  `TODO(G-denaturing)` (would need a migration — out of scope, append-only/no-migration rule).
- No Playwright/Dusk specs exist for eMAR pages; coverage is the PHPUnit Feature test.
- Void endpoint `emar.destructions.void` exists and is tested (void marks voided, not deleted;
  requires reason). The hard-delete `destroy` path was already retired in the redesign.

## Remaining TODO(Gx)
- `TODO(G-date)`: optional date-range filter on the register (like Reports).
- `TODO(G-denaturing)`: persist explicit denaturing-kit confirmation (needs migration).
- `TODO(G-mar)`: "Open on MAR chart" — only shown when a client MAR jump is meaningful.
