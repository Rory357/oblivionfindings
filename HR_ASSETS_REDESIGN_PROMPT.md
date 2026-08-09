# HR "Assets" (Equipment & Asset Management) Redesign — PROMPT (anchored on `/hr/assets`)

> Paste this whole file to Claude Code. It is an **implementation** prompt, not a discussion.
> You have full licence to do everything in the UI **and** the backend so the page is
> genuinely end-to-end. Where you find a gap you are not implementing this pass, you MUST
> append it to a handover doc (see §L) — nothing gets silently dropped.
>
> Verified facts about the current state (Chane has already audited with Claude):
> - `/hr/assets` today is **three thin views**: a flat list (`resources/js/pages/hr/assets/index.tsx`),
>   a **full-page** create form (`create.tsx`), and a detail page (`show.tsx`) whose Assign/Return are
>   single-column dialogs and whose **Maintenance / Return-to-service / Retire are browser `confirm()` boxes
>   that throw away the `notes` the backend already accepts**. There are **no tabs**, a **generic** `PageHero`
>   (not the golden band), **no right-click**, **no bulk actions**.
> - The hero stat tiles count `assets.data.length` — i.e. **only the 20 rows on the current page**, not tenant
>   totals. That is a live bug; fix it with server aggregates.
> - It runs on a **separate `hr_assets` table** (`HrAsset` / `HrAssetAssignment`) that **duplicates** the far
>   richer canonical **Fleet & Assets `assets` register** (`app/Models/Asset.php`) — which already has QR tags,
>   telemetry, inspections, maintenance logs, documents, geofences, and its own `AssetAssignment` / `AssetOwnership`.
>   The HR "vehicle" and "key" categories overlap the Fleet vehicle/key registers directly.

---

## 0. Mission

Turn `/hr/assets` from a flat CRUD list into a **premium, tabbed Equipment & Asset hub** that matches the
quality of the Leave (`/hr/leave`) and My-HR hubs: a golden command band, a real tab shell, full-workflow
wizard modals (the exact Add-Client pattern), right-click everywhere, and an end-to-end backend (maintenance
history, documents/photos, QR tagging, e-sign handover, reminders). It must **federate with the canonical
Fleet & Assets register so the same physical asset is never typed in twice.**

This is HR's lens on **staff-issued equipment** (laptops, phones, tablets, uniforms, access cards) plus a
**read-only window into Fleet-owned vehicles & keys** held by staff. Complete the loop: every asset has an
owner story, a condition story, a maintenance story, and a paper trail.

---

## 1. Non-negotiables

1. **NZ-only.** NZD currency, `en-NZ` dates, NZ spelling. Never "fix" to GBP/USD.
2. **Web app, desktop-first.** No phone frames, no "mobile app" treatments. (A dedicated mobile app comes later.)
3. **Design tokens only.** Every colour from semantic tokens per `docs/DESIGN_TOKENS.md` — never hardcoded hex.
   Tenant white-label theming (`--primary`) must keep propagating.
4. **Reuse the shared kit (§2). Do not fork new primitives.** If a primitive is missing, generalise the existing
   one (e.g. lift `useLeaveContextMenu` into a shared `useRowContextMenu`) rather than copy-pasting a variant.
5. **Standardised UI across modules.** The hero, tabs, modals, badges and context menus must be visually
   indistinguishable from Leave/Training/People. This page is currently the odd one out — bring it in line.
6. **No duplicated assets (Chane's decision: FEDERATE).** See §B. A vehicle/key must resolve to the Fleet
   register; never create a second record for the same physical thing.
7. **Every modal is a FULL workflow, not a thin form.** Stepper rail + per-step validation + completeness ring +
   review step + success pane + "Save & add another" where it makes sense. No `confirm()` boxes anywhere.
8. **End-to-end or it's a handover item.** If a button can't truthfully do the thing (no table, no endpoint),
   either build the backend this pass or write it into the handover doc (§L). No dead buttons.

---

## 2. The shared kit you MUST reuse (exact imports, verified)

```ts
// Page chrome
import { PageHero, PageLayout } from '@/components/page';

// Canonical HR tab strip + ?tab= URL sync (deep-linkable, refresh-safe)
import { HrTabs, useHrTab, type HrTabItem } from '@/components/hr/hr-tabs';

// Wizard chrome — the SAME kit the Add-Client + Leave-request modals use.
// `@/components/hr/wizard` is the single HR barrel: it re-exports the whole
// shell + every primitive, so import ALL wizard pieces from here (don't also
// pull the same primitive from `@/components/wizard/primitives` — one source).
import {
    useWizard, WizardShell, WizardStepPane, WizardSuccessPane, ReviewCard, ReviewRow,
    Field, FieldErr, SelectInput, StepHead, SubHead, InfoCard,
    Segmented, ChipMulti, TilePicker, Ring,
    type IconType, type WizardStep,
} from '@/components/hr/wizard';

// People selector for the Assign modal (avatars + search + role)
import { PeoplePicker, type PersonOption } from '@/components/hr/people-picker';

// Delight
import { fireConfetti } from '@/lib/confetti';
import { toast } from 'sonner';
```

**Benchmark these reference files before writing a line (open them, copy the chrome, not the content):**

| Need | Gold-standard file to mirror |
|---|---|
| Golden hero band, **NO clock** | `resources/js/components/hr/leave-hero.tsx` (+ wrapper `leave-hub-hero.tsx`) |
| Hero gradient/feel you liked on My-HR (drop the clock card) | `resources/js/components/hr/my-hr-hero.tsx` |
| Tab shell wrapper + per-tab routing | `resources/js/components/hr/leave-hub-tabs.tsx` (primitive: `hr-tabs.tsx` → `rostering/tab-strip.tsx`) |
| **Create wizard modal — THE gold standard** | `resources/js/components/clients/add-client-dialog.tsx` |
| Premium "New Request" modal (the one you pointed at) | `resources/js/components/hr/leave-request-dialog.tsx` |
| Wizard shell + review/success panes | `resources/js/components/wizard/shell.tsx`, `resources/js/components/wizard/primitives.tsx` |
| Right-click / ⋯ context menu | `resources/js/components/hr/leave-context-menu.tsx` |

---

## A. Audit & benchmark first (do this before building)

Open the current files and confirm/extend this gap list. **Append anything new you find to the handover doc (§L).**

Current files:
- `resources/js/pages/hr/assets/index.tsx` — flat list, generic hero, hero counts only current page (BUG).
- `resources/js/pages/hr/assets/create.tsx` — **full page** form (must become a modal; delete the page route).
- `resources/js/pages/hr/assets/show.tsx` — detail; Assign/Return = thin dialogs; **Maintenance/Retire = `confirm()`**.
- `app/Http/Controllers/Hr/AssetController.php` — index/create/store/show/assign/returnAsset/sendToMaintenance/
  returnFromMaintenance/retire.
- `app/Domain/Hr/Services/AssetService.php` — status-flip lifecycle only.
- `app/Domain/Hr/Models/HrAsset.php`, `HrAssetAssignment.php`.
- `database/migrations/2026_03_22_100013_create_hr_assets_tables.php`.
- `routes/hr.php` (assets block ~L936). Permissions: `hr.assets.view`, `hr.assets.manage`.

**Confirmed gaps (frontend):**
1. No tab shell; generic `PageHero category="hr"` instead of the golden band.
2. Hero stats count the current page only — must use tenant-wide server aggregates.
3. Create is a separate page, not a wizard modal.
4. Assign/Return are thin single-column dialogs (no avatars, no condition checklist, no photos, no e-sign, no review).
5. Maintenance / Return-from-maintenance / Retire are `confirm()` — they capture **nothing** (vendor, cost, dates,
   reason, evidence) even though some are accepted server-side. Replace with full modals.
6. No right-click context menu on rows; no bulk/multi-select.
7. Detail page is flat (one big card + one history table) — no detail tabs, no activity timeline.
8. `formatDate` imported but unused in `index.tsx` (lint nit) — clean up.

**Confirmed gaps (backend / data — needed for end-to-end):**
9. **No maintenance/repair history** — `maintenance` is a single status flag; no record of what/when/who/cost/vendor.
10. **No documents/photos** on HR assets (warranty, receipt, asset photo, signed handover form).
11. **No QR/barcode tag** + scan-to-open.
12. **No assignment acknowledgement / e-signature** — HR already has an e-sign system (`ESignatureController`,
    `hr.signatures.*`) to reuse.
13. **No due/return-by date** on an assignment; **no reminders** (warranty expiring, return overdue, offboarding return).
14. **Categories are hardcoded** in the controller (laptop/phone/tablet/vehicle/key/card/uniform/other) — and
    `vehicle`/`key` duplicate Fleet.
15. **No offboarding loop** — when a staff member is made a leaver (HR leavers / exit-interviews), their held
    assets are not flagged for return.
16. **Duplication** with the canonical `assets` register (see §B).

---

## B. Federation — where assets live (Chane's decision: FEDERATE, no duplicates)

Keep the HR **staff-equipment** register (`hr_assets`) for laptops/phones/tablets/uniforms/cards. But:

1. **Vehicles & keys defer to Fleet.** Remove `vehicle` and `key` as free HR categories. In the New-Asset wizard,
   if the user picks an asset **type** that is fleet-owned (vehicle, key), the wizard switches to a **"link to Fleet
   asset"** picker that searches the canonical `assets` register (`app/Models/Asset.php` via the FleetAssets
   `AssetController`) and stores a reference — it must be **impossible to hand-type a duplicate vehicle**.
2. **Unified read-only "All assets" lens.** The Inventory tab gets a segmented filter `HR equipment | Fleet (held by staff) | All`.
   The Fleet rows are read-through to the canonical register (link out to `/fleet-assets/assets/{id}`); HR never
   owns Fleet data, it only surfaces what staff currently hold.
3. **One assignment vocabulary.** Align HR assignment status/condition wording with the canonical
   `AssetAssignment` / `AssetOwnership` so a future merge is trivial.
4. **Write the full-unify plan to a handover doc** (`HR_ASSETS_FEDERATION_PLAN.md`): the path to collapsing
   `hr_assets` into the canonical `assets` table (column mapping, data migration, permission reconciliation
   `hr.assets.*` ↔ `assets.*`, and which controllers survive). Implement federation now; document full unification.

Reference (canonical register — read, do not rebuild): `app/Models/Asset.php`, `AssetAssignment.php`,
`AssetOwnership.php`, `AssetMaintenanceLog.php`, `AssetDocument.php`, `AssetQrTag.php`, `AssetScanEvent.php`,
`AssetCategory.php`; controllers under `app/Http/Controllers/FleetAssets/`; routes `routes/fleet-assets.php`,
`routes/assets.php`; permissions `assets.viewAny|create|update`, `fleet.viewAny|manage`.

---

## C. Hero rethink — the golden band (NO clock, fitted to assets)

Build `resources/js/components/hr/assets-hero.tsx` mirroring `leave-hero.tsx` (the brand-gradient command band):
**no clock card.** Layout:

- **Left:** asset-box icon tile, title **"Asset Management"**, one-line blurb ("Track staff equipment, assignments,
  maintenance and warranties — across {N} sites"), then a row of **glanceable stat link-buttons** computed from
  **server aggregates** (not the current page):
  - **Total assets** → Inventory tab
  - **Assigned** → Assignments tab
  - **Available** → Inventory filtered to available
  - **In maintenance** (amber when > 0) → Maintenance tab
- **Quick actions** (gradient chips): **New asset** (primary, opens wizard modal), **Assign**, **Open inventory**, **Export**.
- **"Needs you" chips** (amber dot, only when non-zero): `{n} warranties expiring ≤30d`, `{n} returns overdue`,
  `{n} in maintenance > {SLA}`, `{n} assets to recover from leavers`.
- **Right rail:** a small inline-SVG **status-mix donut** (available/assigned/maintenance/retired) with a toggle
  to a **total fleet value** ring (sum of `purchase_cost`), persisted to `localStorage` exactly like `leave-hero`'s
  mix/rate toggle.

Mount the same hero on every assets sub-surface so it reads as one band across tabs.

---

## D. Tabs — the hub shell (Overview · Inventory · Assignments · Maintenance & Documents)

Replace the flat page with a real `HrTabs` shell + `useHrTab('overview')` (`?tab=` synced). Build
`resources/js/components/hr/assets-hub-tabs.tsx` mirroring `leave-hub-tabs.tsx`:

| id | label | icon | tone | badge |
|---|---|---|---|---|
| `overview` | Overview | `LayoutDashboard` | primary | — |
| `inventory` | Inventory | `Boxes` | info | total |
| `assignments` | Assignments | `UserCheck` | violet | overdue returns |
| `maintenance` | Maintenance & Docs | `Wrench` | warning | open jobs |

All four render in-page on `/hr/assets?tab=…`. Keep the existing `/hr/assets/{id}` detail route.

---

## E. Overview tab

Command dashboard, all server-driven:
- KPI cards: total / assigned / available / maintenance / retired; total value (NZD); warranties expiring ≤30/≤90d.
- **Status-mix** donut + **category breakdown** bar.
- **Attention lists** (each row right-clickable, deep-links into the relevant tab/detail): warranties expiring,
  returns overdue, in maintenance, **assets held by leavers to recover**.
- **Recent activity** timeline (assigned / returned / sent to maintenance / retired / document added).

## F. Inventory tab (the register)

- The asset table, upgraded: sortable columns, sticky header, density that matches other hubs.
- **Filters:** search (tag/serial/name), category, status, site, **federation segment (HR / Fleet held / All)**, assignee.
- **Multi-select + bulk action bar:** bulk assign, bulk print QR labels, bulk category, bulk retire. Confirm via a
  modal, never `confirm()`.
- **Right-click context menu** on every row (§K). Row click → detail.
- Premium **empty state** (icon + "Add your first asset" CTA) and loading skeletons.

## G. Assignments tab

The "who has what" loop:
- Active assignments with assignee avatar, asset, since-date, **due/return-by** (red when overdue).
- Toggle to **history** (all past assignments) and a **by-employee rollup** ("Aroha holds 3 assets").
- Each row: Return / Transfer / Remind via right-click and row actions.
- **Offboarding hook:** if the assignee is a leaver, show a "Recover" flag and surface them in the hero "needs you".

## H. Maintenance & Documents tab

- **Maintenance queue:** open jobs (vendor, sent date, expected-back, cost), plus a **due schedule** (next-due dates).
  "Log repair" / "Return to service" open full modals (§J).
- **Documents library:** all asset documents (warranty, receipt, photo, signed handover form, invoice) with
  type filter, preview, download, upload — mirror the canonical `AssetDocument` categories.

---

## I. Asset detail redesign (`/hr/assets/{id}`)

Keep the route; make it a **tabbed detail** under a compact hero (asset name + tag badge + status pill +
context actions):
- **Overview:** specs, purchase/warranty, **QR tag** (show + print), current assignment card, depreciation/book-value note.
- **Assignments:** full history table (structured condition, who assigned, acknowledgement status).
- **Maintenance:** repair/service history (vendor, cost, dates, attachments) + next-due.
- **Documents:** per-asset document list + upload.
- **Activity:** audit timeline (the model already uses `AuditableChanges`).

All actions (Assign/Return/Maintenance/Retire) open the **full modals** from §J — no `confirm()`, no thin dialogs.

---

## J. Modals = exact Add-Client wizard pattern (FULL, not thin)

Every modal uses `WizardShell` + `useWizard` + the primitives in §2, with a **stepper rail, per-step validation,
a completeness `Ring`, a Review step, a `WizardSuccessPane`, and `fireConfetti()` + `toast` on success** — exactly
like `add-client-dialog.tsx` and `leave-request-dialog.tsx`. Map server validation errors back to the owning step
(see the `STEP_FOR_PREFIX` pattern in `add-client-dialog.tsx`).

1. **New / Edit Asset wizard** (replaces `create.tsx` page — delete the page + `create` route):
   - **Step Identity** — asset tag (with auto-suggest next tag), name, **type tiles** (`TilePicker`). If a Fleet
     type (vehicle/key) is chosen → switch to **"Link Fleet asset"** picker (§B), skip the HR-owned steps.
   - **Step Specifications** — make/manufacturer, model, serial number, condition-when-new.
   - **Step Purchase & Warranty** — purchase date, **purchase cost (NZD)**, supplier, warranty expiry,
     depreciation method/useful-life (book-value note).
   - **Step Tagging** — generate **QR/barcode** (preview + "print label").
   - **Step Documents** — drag-drop receipt / warranty / photo (optional).
   - **Step Review** — summary; **Create** / **Save & add another**.
2. **Assign Asset modal:**
   - **Employee** (`PeoplePicker` — avatars, search, role), **assigned date** + **due/return-by date**,
   - **Condition on assign** (structured `Segmented` good/fair/poor + notes + **photos**),
   - **Acknowledgement** — capture employee **e-signature** (reuse the HR e-sign flow) or "send for signature",
   - **Review** → Assign. Blocks if asset not `available`.
3. **Return Asset modal:** return date, **condition on return** (structured + photos), **damage/loss toggle**
   (→ offers to open Log-Repair or Retire-as-lost), notes, acknowledgement, Review.
4. **Log Maintenance / Repair modal** (replaces the `confirm()`): type (service/repair/cleaning/calibration),
   vendor, **cost (NZD)**, sent date, expected-back, next-due, notes, attach quote/invoice.
5. **Return from Maintenance modal:** outcome, final cost, next-due, condition → back to `available`.
6. **Retire / Dispose modal** (replaces the `confirm()`): reason (`end-of-life` / `lost` / `stolen` / `sold` /
   `damaged`), date, disposal value, evidence upload, write-off note.
7. **Bulk modals:** bulk assign / bulk retire / bulk label print, driven from the Inventory selection.

---

## K. Right-click everywhere (rows and tabs)

Generalise `useLeaveContextMenu` (`resources/js/components/hr/leave-context-menu.tsx`) into a shared
`useRowContextMenu` (or mirror it as `useAssetContextMenu`) and wire it to:
- **Inventory rows:** Open · Assign / Return · Log repair · Print QR label · Edit · Copy tag/serial · Retire (critical tone).
- **Assignment rows:** Open asset · Return · Transfer · Remind assignee · View employee.
- **Maintenance rows:** Return to service · Edit job · Open asset.
- **Tabs themselves:** quick "open in new view" / jump affordances (as Leave does on its tab strip).
Context-menu actions open the **same full modals** from §J — never a reduced variant.

---

## L. Backend handoff for Claude Code (append to this as you design)

Build the backend so the above is real. New/extended tables (HR domain, tenant-scoped, `AuditableChanges`):
- `hr_asset_maintenance_logs` — asset_id, type, vendor, cost, sent_at, expected_back_at, completed_at, next_due_at,
  notes, performed_by, attachments. (Mirror canonical `asset_maintenance_logs`.)
- `hr_asset_documents` — asset_id, title, category (manual|certificate|photo|invoice|handover), file storage
  fields, effective/expiry dates, uploaded_by. (Mirror canonical `asset_documents`.)
- `hr_asset_assignments` — **add** `due_at`, `acknowledged_at`, `signature_id` (FK to the HR e-sign record),
  structured `condition_on_assign/return`, photo refs.
- `hr_assets` — **add** `qr_token` (unique), `supplier`, depreciation fields, `fleet_asset_id` (nullable FK into
  canonical `assets` for federated vehicles/keys), and **drop** `vehicle`/`key` from the free category list.
- A **categories** source (table or config) so categories aren't hardcoded in the controller.

Endpoints / services to add or extend (`Hr\AssetController` + `AssetService`):
- maintenance log CRUD; documents upload/list/download; QR generate + **scan-to-open** (reuse `AssetScanEvent`
  pattern); assignment **due-date + e-sign** issue/return; **edit asset**; **bulk actions**; **export**.
- **Aggregates endpoint** for the hero/overview (tenant-wide counts, status mix, value, expiring warranties,
  overdue returns, leaver-held) — fixes the current page-only count bug.
- **Reminders/jobs:** warranty-expiry, return-overdue, maintenance-due → HR notifications + hero "needs you".
- **Offboarding loop:** when an employee becomes a leaver, flag their open assignments for recovery (hook into
  the existing leavers / exit-interview flow).

Docs to write:
- `HR_ASSETS_FEDERATION_PLAN.md` — the full path to unifying `hr_assets` into the canonical `assets` register (§B).
- `HR_ASSETS_HANDOVER.md` — anything audited but deferred this pass (with file refs + why).

Permissions: keep `hr.assets.view` / `hr.assets.manage`; map any Fleet read-through to `assets.viewAny`/`fleet.viewAny`.

---

## M. Premium polish & delight

- Confetti + toast on create/assign/return success; optimistic UI where safe.
- Skeleton loaders; premium empty states; keyboard nav in menus (already in the context-menu primitive).
- Consistent status pills/badges with the rest of HR (reuse `StatusBadge`).
- Respect `motion-reduce`. Full a11y: labelled controls, focus traps in modals, `aria` on the hero buttons.
- Print-friendly QR labels.

---

## Definition of done

- `/hr/assets` is a golden-band, four-tab hub (Overview · Inventory · Assignments · Maintenance & Docs); hero
  counts are tenant-wide and correct.
- The full-page create is gone; **all** create/assign/return/maintenance/retire/bulk flows are full wizard modals
  — **zero `confirm()` boxes**, zero thin dialogs.
- Right-click works on inventory, assignment, maintenance rows and the tab strip; bulk actions work.
- Maintenance history, documents/photos, QR tag+scan, e-sign handover and reminders are real (backend + UI).
- Vehicles/keys are **linked to Fleet, never duplicated**; the Inventory "All" lens reads Fleet through read-only.
- Asset detail is tabbed with an activity timeline.
- `HR_ASSETS_FEDERATION_PLAN.md` and `HR_ASSETS_HANDOVER.md` exist and capture every deferred gap.
- UI is visually identical in chrome to Leave/Training/People. NZ formatting throughout. Tokens only.
