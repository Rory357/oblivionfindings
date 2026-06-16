# eMAR Handovers — Gap Analysis & Consistency Loop

Single source of truth for the `/loop` bringing **/emar/handovers** to standardised
parity + deeper rostering/medication integration. One gap (or tight group) per pass,
priority **A → H**. Tick `[x]` only when typecheck + lint + build are green for the
touched files and (for shared-component changes) **/operations/handovers** is verified
un-regressed.

## Scope & architecture (read before coding)

- **/emar/handovers** is a *medication-focused lens* over the unified shift-handover
  workflow. It **reuses** the Operations handover components and writes to the shared
  `ShiftHandover` model (FK'd to rostering shifts + staff).
- Page: `resources/js/pages/emar/Handovers.tsx` (hero + week-stepper + 7-tab TabStrip +
  reused shared components, `medicationFocus` wizard).
- **Shared components** (edits here also hit **/operations/handovers** —
  `resources/js/pages/operations/handovers/Index.tsx`):
  `resources/js/pages/operations/handovers/components/` →
  `cards-view.tsx`, `list-view.tsx`, `board-view.tsx`, `handover-rail.tsx`,
  `handover-detail-dialog.tsx`, `handover-wizard.tsx`, `shared.tsx`.
- Backend: `app/Models/ShiftHandover.php`, `app/Services/ShiftHandoverService.php`,
  `app/Services/Operations/HandoverPresenter.php`,
  `app/Http/Controllers/Emar/EmarController.php` →
  `handovers()` (2754), `storeHandover()` (3731), `updateHandover()`,
  `submitHandover()` (3782), `acknowledgeHandover()` (3799).
- Routes (`routes/emar.php`): `emar.handovers[.store|.update|.submit|.acknowledge|.destroy]`.
  Operations uses PATCH `/operations/handovers/{id}/{submit,acknowledge}`; eMAR uses POST.
- Rostering coupling on `ShiftHandover`: `outgoing_shift_id` / `incoming_shift_id` (→ shifts),
  `outgoing_staff_id` / `incoming_staff_id` (→ users), `client_id`, `medications_due` (JSON),
  `observations_summary` (JSON).
- Jumps: client → `/operations/clients/{id}/care`; shift → `/operations/shifts/{id}`;
  staff → `/staff/{id}`; MAR chart → `/emar/mar?client_id={id}`; concern → `/clients/{id}/incidents`.
- Idioms to reuse: `@/components/rostering/shift-context-menu`
  (`ShiftContextMenu`/`ShiftCtxItem`/`ShiftCtxState`); PRN `openRowCtx`
  (`PrnRecords.tsx:193`); detail Options bar (`components/emar/prn-detail-dialog.tsx`);
  `EntityFilter`/`TabStrip` (`@/components/rostering`); alert strip mirrors `/emar/controlled`.

> Note: `HANDOVERS_AUDIT.md` and the design-drop prototype referenced in the loop prompt
> are **not present** in this worktree — this analysis is derived from the live code + the
> loop prompt's §2/§3.

---

## A. Right-click context menu on cards + rail  *(shared → also Operations)*
- [x] **A1** Add `onContextMenu` → `ShiftContextMenu` to handover **cards** (`cards-view.tsx`)
  and the **rail's** awaiting list (`handover-rail.tsx`). Items: View handover (primary),
  Acknowledge (needs-ack), Submit (draft), Edit (when `can_edit`), sep, View client, View
  shift, View outgoing/incoming staff, Open on MAR chart, sep, Raise concern. Header tag =
  status pill; meta = client · shift · staff. Actions guarded by the `can_*` flags already on
  the payload. — *Done: new shared `handover-context-menu.tsx` (`useHandoverContextMenu`
  hook) used by both `cards-view.tsx` + `handover-rail.tsx`; `onEdit` threaded as an optional
  handler from both `emar/Handovers.tsx` and `operations/handovers/Index.tsx`; `CardHandlers`
  gained optional `onEdit?`. Operations un-regressed (same handlers, jumps are global routes).*

## B. Cross-entity jumps in the detail dialog  *(shared → also Operations)*
- [x] **B1** Make the shift labels/times + staff names in `handover-detail-dialog.tsx` link to
  their entities, and add a footer **Options** action bar (mirror `prn-detail-dialog`): View
  client, View shift, View staff, Open on MAR chart — alongside the existing Acknowledge/Edit/
  Submit. Reuse the same jump targets as A. — *Done: `handover-detail-dialog.tsx` — header
  outgoing-shift label + outgoing/incoming staff names are now Inertia `<Link>`s (new `EntityLink`
  helper); footer restructured to `flex-col` with an Options bar (`OptionLink` ghost-buttons) =
  View client (`/operations/clients/{id}/care`) · View shift (`/operations/shifts/{id}`) · outgoing
  & incoming staff (`/staff/{id}`) · Open on MAR chart (`/emar/mar?client_id={id}`), above the
  existing Close/Submit/Acknowledge/Edit row. Shared component → Operations gets the same links
  with no prop changes (un-regressed). `<Link>`-based, so no new raw-button lint.*

## C. Hero footer filters (eMAR only)
- [x] **C1** Add a **Client** `EntityFilter` and a **Staff** filter (outgoing/incoming) to the
  eMAR hero footer beside Site (`emar/Handovers.tsx`); align the hand-rolled search pill to the
  shared meds/today pill. **Keep the week-stepper** (Wk · {range} + prev/next) — do **not** add
  a day-stepper. Client/Staff can filter client-side over the loaded week (mirror Operations'
  `baseFiltered`). — *Done: `emar/Handovers.tsx` — `clientFilter`/`staffFilter` state + derived
  `clientItems`/`staffItems` (unique clients/staff present in the week); `searched` memo now applies
  both (staff matches outgoing/incoming/acknowledger). Two `EntityFilter onDark` pills added beside
  Site (Staff uses `pluralLabel="staff"`). Search pill re-aligned to the PRN/meds-today convention
  (relative + absolute Search icon + h-8 `rounded-full` white pill + inline clear `X`). Week-stepper
  unchanged. Site stays server-side; Client/Staff are client-side. eMAR-only — no shared component.*

## D. Alert strip (eMAR only)
- [x] **D1** Replace the single needs-ack banner with a **stacked, dismissible** strip (mirror
  `/emar/controlled`): *N awaiting your acknowledgement* (warning → Needs-ack tab), *N open
  incoming shifts — needs cover* (critical → Open-incoming tab), and (after F) *N CD counts
  unverified at handover* (critical → Open the CD register / Needs-ack). — *Done: `emar/Handovers.tsx`
  — `AlertRow` component + per-session `sessionStorage` dismiss (`handover-dismissed-alerts`,
  `read/persistDismissedAlerts`) copied from ControlledDrugs; `alerts[]` built from `counts`
  (needs-ack → warning → `needs_ack` tab; open-incoming → critical → `open_incoming` tab). The CD
  banner is stubbed `// TODO(F):` and wired when Gap F lands. eMAR-only.*

## E. Auto-surface the shift's medication state  *(the real win — expand the module)*
- [x] **E1a** Backend snapshot + **wizard** "Medications this shift" panel. — *Done: new
  `app/Services/Emar/ShiftMedicationSnapshotService::forShift(Shift)` reuses
  `EnhancedMarService::build()` (the MAR/board pipeline) and `MarOmissionService`, narrowing to the
  outgoing shift's `[starts_at, ends_at]` window → counts (due/given/missed/refused/cd_due/prn_given/
  reviews_outstanding/omissions), a `due[]` pre-fill list, and stock/attention `alerts[]`. New
  on-demand endpoint `EmarController@shiftMedicationSnapshot` + route `emar.handovers.shift_medications`
  (GET `/emar/handovers/shift-medications?shift_id=`, `permission:medications.view`, site-access-gated)
  — computed one shift at a time (build() is heavy → no index-time N+1). The shared wizard
  (`handover-wizard.tsx`, **`medicationFocus`-gated** so Operations stays inert) fetches it via axios
  on outgoing-shift select, shows a read-only stat panel (`ShiftMedPanel`), and pre-fills the meds
  list once when empty (never clobbering an edit). Frontend tsc/eslint green; **PHP feature-tests
  environmentally blocked** in the bare worktree (no php/vendor/.env — like `npm run build`).*
- [x] **E1b** Detail-dialog **read-only** "Medications this shift" summary. — *Done: extracted the
  snapshot type + read-only panel into shared `shift-med-snapshot.tsx` (`ShiftMedSnapshot` +
  `ShiftMedSummary`, with optional `note`/`noShiftHint`); refactored the wizard to consume it (DRY,
  one implementation). `handover-detail-dialog.tsx` gained a `medicationSnapshotUrl?` prop + hooks
  (axios-fetch the snapshot on open for the handover's outgoing shift) and renders `ShiftMedSummary`
  above the manual "Medications due" list. eMAR `Handovers.tsx` passes
  `medicationSnapshotUrl="/emar/handovers/shift-medications"`; Operations leaves it unset → section
  hidden + no request (un-regressed; tsc green for both callers). Gap E complete.*

## F. Controlled-drug count at handover  *(domain + compliance)*
- [x] **F1a** CD count verification capture + storage. — *Done: new **column** `cd_verification`
  (JSON) on `shift_handovers` (migration `2026_06_16_000200…`, mirrors `observations_summary`) +
  `ShiftHandover` fillable/cast. The shared **wizard** (`medicationFocus`-gated) gained a
  `CdVerificationSection` in the meds step: two-person check (result = verified/discrepancy, witness
  select excluding the outgoing worker, notes) + `cd_due`-this-shift chip (from the E snapshot) +
  deep-link to `/emar/controlled` (new tab). The eMAR `storeHandover`/`updateHandover` validate
  `cd_result`/`cd_witness_id`/`cd_notes` and pass `cd_verification_input`; `ShiftHandoverService::
  normalizeCdVerification()` stamps `verified_at`/`verified_by`/witness+actor names. `HandoverPresenter`
  exposes `cd_verification`; shared `Handover` type gained it. Operations inert (gated). Frontend
  tsc/eslint green; **PHP feature-tests environmentally blocked** (no php/vendor/.env).*
- [x] **F1b** Surface CD verification. — *Done: `handover-detail-dialog.tsx` shows a read-only CD
  block (verified/discrepancy tone, witness, checked-by + time, notes, CD-register link) — data-gated
  by `h.cd_verification` (null on Operations → hidden). `emar/Handovers.tsx` alert strip wires the CD
  banner: counts submitted/acknowledged handovers whose `medications_due` mentions a CD (the wizard's
  `(CD)` pre-fill tag) but carry no `cd_verification` → critical, deep-links `/emar/controlled`
  (`HandoverAlert` gained `href?`; `AlertRow` does href-or-tab nav). **Gap F complete.** Frontend
  tsc/eslint green.*

## G. Clarify roles + edit-locking (dedupe the 3 write paths)
- [x] **G1** Optimistic-concurrency / edit-locking. — *Done: new `version` column (migration
  `2026_06_16_000300…`, unsignedInteger default 0) + `ShiftHandover` fillable/cast.
  `ShiftHandoverService::save()` now re-reads a reused draft under `lockForUpdate()` and, when the
  caller passes a stale `expected_version`, throws a "changed by {name} after you opened it" conflict
  instead of silently overwriting; `version` increments on every draft save. eMAR `storeHandover`/
  `updateHandover` validate `version` + pass `expected_version`; the wizard sends `version:
  editing.version` on the edit PUT and its `onError` now surfaces the server's `handover` conflict
  message. `HandoverPresenter` exposes `version`; shared `Handover` type gained it. The eMAR detail
  dialog shows a "Same record as Operations handovers" cross-link (gated by `medicationSnapshotUrl`).
  Submitted/acknowledged handovers were already blocked by `save()` status guards + the 7-day
  `editPermission` window. Frontend tsc/eslint green; PHP feature-tests environmentally blocked.
  TODO(Gx): live presence-based "who holds it right now" pessimistic locking (disable-on-open) is
  deferred — the optimistic block-on-save is the robust standard guard; `applyEdit()` (Operations
  submitted-edit path) keeps its in-txn `lockForUpdate` and doesn't bump `version` (drafts only
  mutate via `save()`).

## H. Remove dead `MedicationHandover` code
- [x] **H1** Remove dead `MedicationHandover`. — *Done: re-audited — the model was referenced only by
  `EmarComprehensiveSeeder` (import + one `firstOrCreate`); `MedicationHandoversTest` is
  `ShiftHandover`-backed (coincidental name); no factory. Deleted `app/Models/MedicationHandover.php`,
  removed the seeder's section-8 block + import, and dropped the `medication_handovers` table via a new
  **reversible** migration `2026_06_16_000400…` (down() recreates the full original structure — the
  historical create/checklist migrations are untouched). Zero code references remain (only the
  migration docblock + seeder note mention the name). tsc still 0 (PHP-only change).*

---

## Verify each pass (§5)
- `npm run types` + `npm run lint` clean for touched files; `npm run build` succeeds.
- **Regression:** load `/operations/handovers` after any shared-component change (mandatory for
  A, B). Compare against the loop prompt's expectations.
- Exercise: right-click cards/rail; detail-dialog View client/shift/staff land on the right
  pages; meds panel auto-populates from the shift window; CD verification records + links to the
  register; edit-locking blocks a 2nd editor; alert strip jumps to the right tab; submit/
  acknowledge still work end-to-end. Run any handover Dusk/Playwright/feature specs.

## Loop exit (§6)
Every box `[x]`; types/lint/build pass; `/operations/handovers` un-regressed; cards/rail have a
right-click menu; detail dialog links to client + shift + staff; the eMAR lens auto-surfaces the
shift med-state + a CD count at handover; concurrent edits are locked; dead `MedicationHandover`
removed (or documented); all actions in-page via Inertia partial reloads.

### Remaining `TODO(Gx)` markers
- `emar/Handovers.tsx` `counts.cdUnverified` — the CD-unverified alert derives "this handover involved a
  controlled drug" from the `(CD)`/"controlled" tag in the stored `medications_due` list (the wizard's
  snapshot pre-fill). A live per-handover CD-due query would be exact but is the index-time N+1 the
  on-demand snapshot deliberately avoids. `TODO(Gx)` if exactness is later required.
- `ShiftMedicationSnapshotService` — PRN given/reviews-outstanding use a focused administration query;
  omissions clamp `to` to now (future shifts → 0). Refused = `administration.status === 'refused'`.
- Gap G — live presence-based "who holds it right now" pessimistic lock (disable-on-open) is deferred;
  the optimistic block-on-save (`version`) is the implemented guard. `applyEdit()` (Operations
  submitted-edit path) keeps its in-txn `lockForUpdate` and doesn't bump `version` (drafts only mutate
  via `save()`).

---

## ✅ Loop complete — 2026-06-16

Every box **A–H** is `[x]` (10 passes, one reviewable commit per gap). Final state:

- **A** right-click context menu (cards + rail) · **B** detail-dialog cross-entity links + Options bar ·
  **C** Client/Staff hero filters + aligned search pill · **D** stacked dismissible alert strip ·
  **E** live "Medications this shift" snapshot (service + on-demand endpoint, wizard pre-fill + detail
  summary) · **F** controlled-drug count verification (two-person check, capture + detail + alert banner)
  · **G** optimistic-concurrency `version` edit-lock + "same as Operations" cross-link · **H** dead
  `MedicationHandover` model/seeder/table removed.
- **Reuse-first throughout:** `ShiftContextMenu`, `EntityFilter`, `EnhancedMarService`/`MarOmissionService`,
  `/emar/controlled` AlertRow idiom, the shared wizard/detail/rail/cards. New shared bits:
  `handover-context-menu.tsx`, `shift-med-snapshot.tsx`, `ShiftMedicationSnapshotService`.
- **Operations un-regressed:** every shared-component addition is either additive or gated
  (`medicationFocus` / `medicationSnapshotUrl` / data-gated by a null field). Full-project `tsc` is
  green for **both** entry pages after every pass.

### ✅ Verification — all complete (merged to main + LIVE)
Merged into `origin/main` (merge `80e1ff06`, integrated concurrent loops conflict-free; pushed
`a0f56421`) and verified in the **parent** repo (which has `vendor`/`node_modules`):
- `npm run types` **0 errors** + `npm run lint` clean (every pass).
- Full **`npm run build`** succeeds (wayfinder route-gen included; needs PHP `memory_limit=1024M`).
- **PHP feature tests** — the existing **54 handover tests pass** (incl. `ShiftHandoverWorkflowTest`
  exercising the `save()` path the `version`/`cd_verification` changes touch) AND **new
  `HandoverMedicationLensTest` (4 tests)** covering the snapshot endpoint (422 + window-scoped shape +
  PRN counts), CD persistence, and the version-conflict block. RefreshDatabase applies the **3 new
  migrations** cleanly. (Pushed in `3d010b21` → main.)
- **Frontend tests** — new `handover-context-menu.test.tsx` + `shift-med-snapshot.test.tsx`
  (**10 vitest tests**) cover the context-menu permission guards + the `ShiftMedSummary` panel.
  (Pushed `184682d7` → main.)
- **Live on oblivionfindings.com (Chrome):** new route `/emar/handovers/shift-medications` returns 422
  (old code 404 → deploy confirmed); the wizard's "Medications this shift" panel + the CD two-person
  check render and toggle correctly; both `/emar/handovers` + `/operations/handovers` render un-regressed.
- ⚠️ Note: `vendor/bin/pint` is **not enforced** in CI (`lint.yml` runs `pint` with the auto-commit step
  commented out; the codebase is broadly non-conformant). New files were kept Pint-clean; pre-existing
  violations in shared files were left untouched (fixing them would reformat unrelated code).
