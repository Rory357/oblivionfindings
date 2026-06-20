# PPE & Equipment register — CURRENT STATE audit

Audit of the off-pattern implementation at `/health-safety/ppe` so we know what to **preserve** vs **replace** when rebuilding to the H&S gold standard. Cross-referenced against the design handoff (`docs/ppe-redesign/_design_reference/HANDOFF.md`) and the hi-fi prototype (`docs/ppe-redesign/_design_reference/PPE Register.dc.html`).

**Files audited (in full):**
- `resources/js/pages/health-safety/ppe/index.tsx` (1610 lines — the current page)
- `app/Http/Controllers/HealthSafety/PpeController.php` (265 lines)
- `app/Models/PpeType.php`, `PpeInventory.php`, `PpeAllocation.php`, `PpeInspection.php`
- `database/migrations/2026_03_28_200005_create_ppe_tables.php`
- `routes/health-safety.php` (PPE group, lines 310–332)

---

## 1. Props the page consumes (from `PpeController@index`)

The page component signature (`index.tsx:194-203`) destructures: `types, inventory, allocations, stats, sites, staff, filters, can_manage`.

| Prop | Shape (as returned by controller) | Notes |
|---|---|---|
| `types` | `PpeType[]` — **flat array, not paginated**. Each: `{id, name, category, description, hazards_addressed, standards_reference, inspection_frequency, typical_lifespan_months}` (TS type at `index.tsx:50-59`; note TS type **omits `is_active`** even though the model returns it). Controller filters `where('is_active', true)` so **retired types never reach the client**. | `PpeController.php:25-28` |
| `inventory` | Laravel paginator: `{ data: InventoryItem[], links: [{label,url,active}] }`. Page reads only `data` + `links` (TS type at `index.tsx:91-98` omits `meta`/`current_page`/etc.). Each item eager-loads `ppeType:id,name,category` + `site:id,name`. `InventoryItem` (`index.tsx:61-75`): `{id, ppe_type, site, brand, model, serial_number, purchase_date, expiry_date, quantity, location, condition, status, next_inspection_due}`. | `PpeController.php:31-38` — `paginate(25)->withQueryString()` |
| `allocations` | Paginator on page name `allocations_page`. **Active only** (`whereNull('returned_at')`), `orderByDesc('allocated_at')`. Each row is **reshaped via `->through()`** (`PpeController.php:50-57`): `toArray()` plus injected `inventory_item` (alias of `ppe_inventory`), `ppe_type_name`, `allocated_date` (alias of `allocated_at`); `ppe_inventory` key unset. `Allocation` TS type (`index.tsx:77-87`): `{id, user, inventory_item, ppe_type_name, allocated_date, fit_test_completed, training_completed, acknowledged, returned_at}`. | `PpeController.php:41-57` |
| `stats` | `{total_items, allocated, inspections_due, condemned}` — **four numbers only**. `total_items` = SUM(quantity) excluding condemned/disposed; `allocated` = active allocation count; `inspections_due` = items with `next_inspection_due <= today`; `condemned` = items with status condemned. | `PpeController.php:60-80` |
| `sites` | `Site[]` `{id, name}` — active sites, ordered by name. | `PpeController.php:81` |
| `staff` | `User[]` `{id, name}` — **ALL users**, ordered by name (no role/active filter). | `PpeController.php:82` |
| `filters` | `{site_id, ppe_type_id, condition, status}` (TS at `index.tsx:108-113`). **MISMATCH:** controller actually reads `$request->only(['site_id', 'category', 'status', 'ppe_type_id'])` — it accepts `category` but **NOT `condition`**, and the page's filter UI sends `condition`. So the page exposes a Condition filter that the controller silently drops, and the controller supports a Category filter the page never offers. | `PpeController.php:22` vs `index.tsx:108-113` |
| `can_manage` | `boolean` — `$request->user()?->canDo('hazards.manage')`. | `PpeController.php:84` |

**Gate:** index route requires `hazards.view`; all writes require `hazards.manage` (`routes/health-safety.php:313-331`). The handoff says the page is "gated by `hazards.manage`" but the **index is actually `hazards.view`** — view-only roles can load the page; only mutations need `manage`. (Matches the sidebar gate `can?.hazards?.view || can?.compliance?.view` at `app-sidebar.tsx:1255`.)

---

## 2. Components used today (all OFF-PATTERN)

| Used now | File / import | Gold-standard replacement |
|---|---|---|
| `PageHero` (generic, 4 flat stats) | `@/components/page` (`index.tsx:1, 332-345`) | `HeroShell` + hs-hero-kit (`HeroMedallion, HeroStatusPill, HeroCluster, HeroClusterTile, HeroComplianceBadges, HeroSegmented, HeroSummaryStrip`) + `WorkflowRibbon` |
| Plain `Tabs/TabsList/TabsTrigger/TabsContent` (3 client-side tabs, **no counts**, `defaultValue` only — **tab state is NOT in the URL**) | `@/components/ui/tabs` (`index.tsx:22-27, 348-355`) | `TabStrip` + `RosterTabItem[]` from `@/components/rostering`, with server `tabCounts` badges and `router.get(...{preserveScroll})` |
| `Card/CardHeader/CardTitle/CardContent` filter panel + table wrappers | `@/components/ui/card` (`index.tsx:3`) | Hero `footer={}` filter bar + `register-row-kit` table card |
| Plain `Dialog` ×4 (Add Item, Add Type, Allocate, Inspect) — single-screen forms, `max-w-lg`/`max-w-md` | `@/components/ui/dialog` (`index.tsx:6-12`) | Add-Client wizard shell (`@/components/wizard/primitives` + `add-client-dialog.tsx` pattern) for multi-step; `@/components/wizard/shell` `WizardShell` for single-step actions |
| Hand-rolled `<table>` ×2 (inventory, allocations) + card-grid for types | inline (`index.tsx:513-672, 721-787, 791-914`) | `RegisterTableHeader` + `register-row-kit` (`TONE_BG, TONE_DOT, FlagBadge, titleCase, initials, entityTone`) |
| Inline `Select/SelectTrigger/...`, `Input`, `Textarea`, `Checkbox`, `Label`, `Badge`, `Button` | `@/components/ui/*` | Wizard primitives `SelectInput, Segmented, ChipMulti, TilePicker, Field, FieldErr, SubHead, StepHead, InfoCard` |
| **No right-click anywhere** | — | `ShiftContextMenu` + `ShiftCtxItem`/`ShiftCtxState` on every row + on the hero |
| **No detail-as-modal** (rows are inert; only inline action buttons) | — | `HsDetailDialog` / `EventDetailDialog` pattern, Add-Client shell with 248px rail + sections |
| **No `EntityFilter`** (uses raw `Select` for site) | — | `EntityFilter` (site) in hero footer |

**Layout violations of repo conventions:** page is wrapped in `p-6` + lives inside `Tabs` with `Card`-bounded content; the gold standard is full-width hero + tab strip + single table card. There is **no `max-w` cap** (good) but the whole page is column-stacked cards, not the hero-led command-centre layout.

---

## 3. Workflows / modals that exist today

All four are **plain single-screen `Dialog`s** with footer Cancel/Submit, `useForm` POST, `onSuccess` closes + resets. No steppers, no validation gating, no success pane, no "save & add another".

### 3a. Add PPE Item — `Dialog` (`index.tsx:956-1137`)
- Opens from: "Add Item" button in the inventory filter card header (`index.tsx:367-375`).
- Form (`addItemForm`, `index.tsx:220-230`): `ppe_type_id, site_id, brand, model, serial_number, purchase_date, expiry_date, quantity (default '1'), location`. **No `condition`** field (defaults server-side to `available` status / DB default `good` condition), **no `next_inspection_due`** field.
- POST `/health-safety/ppe/inventory` (`submitAddItem`, `index.tsx:269-276`).
- Errors shown for `ppe_type_id`, `site_id` only.

### 3b. Add PPE Type — `Dialog` (`index.tsx:1140-1314`)
- Opens from: "Add Type" button in the Types tab (`index.tsx:709-718`).
- Form (`addTypeForm`, `index.tsx:232-240`): `name, category, description, hazards_addressed, standards_reference, inspection_frequency, typical_lifespan_months`.
- Category options in UI: head, eye, ear, respiratory, hand, foot, body, fall_protection, high_visibility, other.
- **Inspection-frequency MISMATCH:** UI offers `before_each_use, weekly, monthly, quarterly, six_monthly, annually` (`index.tsx:1266-1272`) but the **controller `storeType` validation only allows `daily, weekly, monthly, quarterly, annually`** (`PpeController.php:99`). So `before_each_use` and `six_monthly` will **fail validation** (422) — a live bug today.
- POST `/health-safety/ppe/types` (`submitAddType`, `index.tsx:278-285`).

### 3c. Allocate PPE — `Dialog` (`index.tsx:1317-1481`)
- Opens from: per-row "Allocate" button (inventory rows where `status === 'available'`, `index.tsx:619-637`). Sets `allocateItemId` to that inventory id.
- Form (`allocateForm`, `index.tsx:242-249`): `user_id, fit_test_completed, fit_test_date, fit_test_result, training_completed, training_date`. Conditional date/result sub-fields appear when the toggle is checked.
- **No `acknowledged` field** — acknowledgement is never set on allocate (the column exists but is unreachable from the UI). Fit-test is **not** gated on respiratory type (no RPE awareness at all).
- POST `/health-safety/ppe/inventory/{allocateItemId}/allocate` (`submitAllocate`, `index.tsx:287-298`).

### 3d. Record Inspection — `Dialog` (`index.tsx:1484-1607`)
- Opens from: per-row "Inspect" button (inventory rows where status is not condemned/retired, `index.tsx:638-658`). Sets `inspectItemId`.
- Form (`inspectForm`, `index.tsx:251-257`): `result (pass/fail/needs_repair/condemned), condition_after, findings, action_taken, next_inspection_due`.
- POST `/health-safety/ppe/inventory/{inspectItemId}/inspections` (`submitInspection`, `index.tsx:300-311`).

### 3e. Return PPE — NO modal (bare POST)
- Per-row "Return" button in the Allocations tab (active allocations only, `index.tsx:877-892`).
- `submitReturn` (`index.tsx:313-319`) does `router.post(.../allocations/{id}/return, {}, {preserveScroll})` with **empty body** — never collects returned condition or notes, even though `returnPpe` accepts `condition` + `notes` (`PpeController.php:194-197`). The handoff wants a single-step `WizardShell` with a returned-condition Segmented.

### Pagination
Inventory + allocations both render Laravel `links` as a row of `Button`s with `dangerouslySetInnerHTML` (`index.tsx:675-704, 917-946`). Server-side pagination is real and **must be retained**.

---

## 4. Data flow

- **Filters:** `onFilter` (`index.tsx:260-266`) merges into `currentFilters` and `router.get('/health-safety/ppe', {...}, {preserveState, preserveScroll})`. Keys sent: `site_id, ppe_type_id, condition, status` — but see the `condition`/`category` mismatch in §1. **Filters drive a full controller re-render** (no partial reload / `only:`).
- **Tabs:** purely client-side (`Tabs defaultValue="inventory"`); switching tabs does **not** hit the server and is **lost on refresh**. The gold standard moves tab into the URL and drives server counts.
- **Create/edit:** Inertia `useForm.post(...)`, `onSuccess` closes the dialog + `form.reset()`. Controllers all `redirect()->back()->with('success', ...)`, so a full reload follows each mutation.
- **No `?item=` / `?allocation=` deep-link / detail loading** exists — rows do nothing on click.

---

## 5. Reusable client-side logic worth keeping (port to tokens / kit)

The current file has small tone/format helpers. They already use **semantic tokens** (good), but the gold-standard kit (`register-row-kit` `TONE_BG`/`TONE_DOT`, `FlagBadge`, `fmt`) supersedes them. Keep the **mapping logic**, not the markup:

- `fmtDate` (`index.tsx:122-129`) — **uses `en-GB`**; the prototype + NZ rule require **`en-NZ`** (`PPE Register.dc.html:237`). Replace with the kit `fmt`/`en-NZ`.
- `conditionColor` (`index.tsx:131-146`) — new→success, good→info, fair/poor→warning, condemned→critical. Matches prototype `condTone` (`PPE Register.dc.html:314`). **Keep the mapping.**
- `statusColor` (`index.tsx:148-163`) — available→success, allocated→info, in_repair→warning, condemned→critical, retired→neutral. Matches prototype `statusTone`/`statusLabel`. **Keep.** (Note: prototype uses `in_repair`; DB/controller use `maintenance` for the same idea — see §7 enum drift.)
- `categoryColor` (`index.tsx:165-188`) — present but **diverges** from the prototype's richer `CAT_TONE` (`PPE Register.dc.html:233`: respiratory→info, head→warning, eye→success, ear→neutral, hand→info, foot→warning, high_visibility→success, fall_protection→critical). Prefer the prototype mapping.
- `ANY = '__any__'` sentinel for "all" select option (`index.tsx:120`) — a useful pattern for the new `SelectInput` filters.
- The inline overdue-date red styling (`index.tsx:597-616`) is the seed of the `FlagBadge` Overdue/Due-soon logic; replace with `FlagBadge` + prototype `dateCell` thresholds (inspection ≤30d warn / <0 overdue; expiry ≤60d warn / <0 expired — `PPE Register.dc.html:278-281, 625-628`).

**Everything else (markup, dialog scaffolding, table JSX) is replaced.**

---

## 6. Stubs / half-wired / dead today

- **Rows are inert** — no click-to-open-detail, no right-click. Only the inline Allocate/Inspect/Return buttons do anything.
- **Return collects nothing** — empty POST body (§3e); `condition`/`notes` the endpoint supports are never sent.
- **Acknowledgement is unreachable** — `acknowledged`/`acknowledged_at` columns exist (`migration:70-71`, fillable in model) but **no UI sets them and no `acknowledge` endpoint exists**. The Allocations tab shows an "Acknowledged" Yes/No badge that can only ever be "No".
- **Add-Type frequency bug** — UI sends `before_each_use`/`six_monthly`, controller rejects them → 422 (§3b).
- **Filter `condition` is dead** — page sends it, controller ignores it (§1).
- **Filter `category` is invisible** — controller supports it, page never offers it (§1).
- **Retired types are invisible** — `is_active=false` types are filtered out server-side; there is **no Catalogue Active/Retired view** and **no activate/deactivate action** (catalogue is create-only; no `updateType`).
- **`action_taken` collected but not surfaced** — inspection form captures it; stored fine, never displayed (no inspection history view exists at all).
- **No condemn/dispose as first-class actions** — condemning only happens as a side-effect of an inspection `result=condemned`; there is no "Condemn" or "Dispose" action and no reason/audit capture.
- **No CSV export, no "go to analytics", no copy-link** — none of the quick actions in the prototype exist.

---

## 7. Backend: what exists vs what the new page needs

### Endpoints that EXIST (reuse — do not replace)
`routes/health-safety.php:311-332`, all under `prefix('ppe')->name('ppe.')`:
| Route | Method | Controller |
|---|---|---|
| `/health-safety/ppe` | GET, `hazards.view` | `index` |
| `/health-safety/ppe/types` | POST | `storeType` |
| `/health-safety/ppe/inventory` | POST | `storeInventory` |
| `/health-safety/ppe/inventory/{inventory}` | PUT | `updateInventory` *(exists but the page never calls it — no Edit UI today)* |
| `/health-safety/ppe/inventory/{inventory}/allocate` | POST | `allocate` |
| `/health-safety/ppe/allocations/{allocation}/return` | POST | `returnPpe` |
| `/health-safety/ppe/inventory/{inventory}/inspections` | POST | `storeInspection` |

### Controller data the new page NEEDS but `index` does NOT yet return
1. **`tabCounts`** — for all 9 TabStrip tabs. Today only the 4-number `stats`. Need: `inv_all, inv_available, inv_allocated, inv_inspection (≤30d), inv_expiring (≤60d/expired), inv_condemned, alloc_active, alloc_unack, types`. (Prototype `counts()` at `PPE Register.dc.html:368-381` is the canonical definition; note inspection-due in the hero uses ≤30d here, but the existing `stats.inspections_due` uses `<= today` — reconcile.)
2. **`hero` block** — the two clusters + NZ compliance counts/booleans: total / allocated / available / inspections-due-30d / **inspections-overdue (<0)** / expiring(≤60d) / condemned / unacknowledged-allocations; plus compliance booleans (RPE fit-test due, inspections overdue, items expiring, condemned awaiting disposal, hi-vis & footwear coverage). RPE-fit-test-due and hi-vis/footwear coverage have **no current computation** — net-new.
3. **`detail`** prop — loaded only when `?item=`/`?allocation=` present, eager-loading type, site, allocations(+user), inspections(+inspector), createdBy/updatedBy. **Nothing like this exists** — the detail-as-modal is entirely net-new server-side. Needs a partial-reload contract (`only: ['detail']`).
4. **`can: { manage }`** — return alongside (or instead of) the existing `can_manage` so the page can use the kit's `can.manage` convention.
5. **Filter reconciliation** — accept BOTH `category` and `condition` (and `q` search — the prototype searches type/brand/model/serial/site/location, `PPE Register.dc.html:340`). Today: `category` accepted/unused-by-page, `condition` sent-by-page/ignored, **no search at all**.
6. **Allocations beyond active** — the gold standard's "Allocations" + "Unacknowledged" tabs need active allocations; a per-worker history may also be wanted in the detail modal. Today `index` only returns active allocations (one paginator).
7. **Types should include `is_active`** and **retired types** (for the Catalogue Active/Retired status column) — today filtered to active-only and the TS type omits the flag.

### New endpoints REQUIRED (per handoff §Backend + prototype context menus)
| New route | Purpose | DB readiness |
|---|---|---|
| `PUT /health-safety/ppe/types/{type}` → `updateType` | Edit a catalogue type (currently create-only). | columns exist; just add method |
| Activate/Deactivate type (toggle `is_active`) — could be part of `updateType` or a dedicated route | Catalogue retire/restore. | `is_active` column exists (`migration:20`) |
| `POST /health-safety/ppe/allocations/{allocation}/acknowledge` → `acknowledge` | Set `acknowledged=true` + `acknowledged_at` (+ optional `acknowledged_by` — **column does NOT exist**, would need a migration if attribution wanted). | `acknowledged`/`acknowledged_at` exist (`migration:70-71`); **`acknowledged_by` does NOT** |
| `POST /health-safety/ppe/inventory/{inventory}/condemn` → `condemn` | First-class condemn with a reason/audit; set status+condition=condemned. **No `condemn_reason`/`condemned_at`/`condemned_by` columns exist** — needs a migration if the reason is to persist (prototype captures `reason` + `disposal: quarantine|dispose`). | needs migration for reason/audit |
| `POST /health-safety/ppe/inventory/{inventory}/dispose` → `dispose` | Move condemned → disposed; capture disposal. **No disposal columns exist.** | needs migration |

### Schema notes / enum drift to resolve
- **Status enum drift:** migration default `available`; `updateInventory` validates `available,allocated,maintenance,condemned,disposed` (`PpeController.php:149`) — note **`maintenance`**, but the page UI + prototype use **`in_repair`** for the same concept (`index.tsx:498`, `PPE Register.dc.html:316`). The current `index` page's status filter even offers `retired` which the controller's update enum does **not** include. Pick one vocabulary (recommend the controller's `maintenance`/`disposed`, map labels in the kit).
- **Condition enum:** `new,good,fair,poor,condemned` consistently (migration default `good`; validations at `PpeController.php:121,146,196,229`). Inspection `condition_after` validation **omits `new`** (`PpeController.php:229`: `good,fair,poor,condemned`) but the prototype inspection modal offers New (`PPE Register.dc.html:1007`) — reconcile.
- **`storeInventory` lacks `condition`/`next_inspection_due` capture in the current UI** even though the endpoint accepts them — the new wizard's "Condition & dates" step should send them.
- All four models use `SoftDeletes` + `AuditableChanges` and `created_by`/`updated_by`; the detail modal's History/audit timeline can draw on `AuditableChanges`.
- All four tables have sensible indexes; no schema change is needed for the **read** path (tabCounts/hero/detail are all derivable from existing columns). Schema changes are only needed if we want to **persist** condemn reasons, disposal records, or acknowledged-by attribution.

---

## 8. Touch-point parity (confirmed)

PPE is referenced in exactly these places (grep across `*.php|*.tsx|*.ts` for `health-safety/ppe`, `PpeController`, `PpeType/Inventory/Allocation/Inspection`):
- The register page itself (`resources/js/pages/health-safety/ppe/index.tsx`).
- **Sidebar nav** — `resources/js/components/app-sidebar.tsx:1255-1260`: a single "PPE Management" link → `/health-safety/ppe`, icon `HardHat`, gated `hazards.view || compliance.view`. Title should likely become "PPE & Equipment" to match the new hero/breadcrumb.
- **Browser test** — `tests/Browser/HealthSafety/HealthSafetyTest.php:86-93`: loads `/health-safety/ppe`, asserts the text "PPE". (Will still pass after redesign provided "PPE" remains in the hero.)
- The four models, the controller, the migration, and the routes group.

**No "My PPE" worker view, no Site-profile PPE tab, no H&S dashboard PPE tile, no analytics PPE surface exist.** The handoff's suspicion is confirmed: the register is the **only** PPE surface. There is nothing else to re-chrome or deep-link — but it also means a worker-facing "my allocations" entry point and any dashboard/analytics tiles are net-new if desired (out of scope for this register rebuild unless explicitly added).

---

## 9. Concrete change list to reach the gold standard

**Frontend (rewrite `index.tsx` against the prototype):**
1. Replace `PageHero` → `HeroShell` + hs-hero-kit: workflow ribbon (Catalogue→Stock→Issue→Inspect→Retire), medallion `ShieldCheck`, status pill, "Add to register" popover (Add type / Add inventory / Allocate), two clusters (Live·register / Needs attention), `HeroComplianceBadges`, hero-footer filter bar (Site `EntityFilter` + Category + Status selects + search + Clear), and hero right-click quick-actions.
2. Replace plain `Tabs` → `TabStrip` with 9 server-counted tabs; move tab into URL; `router.get(...{preserveScroll})`.
3. Replace both hand-rolled tables + the type card-grid → `register-row-kit` tables with the prototype's exact columns (inventory: Type/Site·location/Identification/Condition/Status/Next inspection/Expiry/Flags; allocation: Worker/Item/Allocated/Fit-test/Training/Acknowledged/Flags; type: Type/Category/Standard/Inspection/Lifespan/Status). Every row: left-click→detail, right-click→`ShiftContextMenu`, keyboard-openable, `FlagBadge`s.
4. Build the **detail-as-modal** (Add-Client shell, 248px rail, sections Overview/Allocation/Inspections/History, sticky footer Allocate·Inspect·Condemn) loaded via `?item=`/`?allocation=` partial reload.
5. Convert all workflows to wizards on the Add-Client pattern: Add inventory (4 steps, save-&-add-another), Allocate (4 steps, RPE fit-test gating per AS/NZS 1715), Add type (4 steps, save-&-add-another), Edit inventory (reuse Add-inventory), Edit type. Single-step `WizardShell`: Return (returned-condition + notes), Record inspection (result/condition-after/findings/next-due), Condemn (reason + quarantine/dispose). Per-step `validateStep` mirroring server rules; jump-to-first-failure; success pane.
6. Right-click menus per the prototype (`PPE Register.dc.html:384-431`): inventory (View/Edit/Allocate/Record inspection/Condemn/Dispose/Copy link), allocation (View/Mark acknowledged/Return/Record inspection/Copy link), type (Edit/Activate-Deactivate/Add inventory of this type), hero (Add type/Add inventory/Allocate/Export CSV/Go to analytics).
7. Port tone/format helpers to the kit + **switch `en-GB`→`en-NZ`**; use prototype `CAT_TONE`/flag thresholds; semantic tokens only (zero raw hex/oklch/`border-l-*`).
8. Fix the dead filters (`condition`), expose `category`, add search; keep server-side pagination.

**Backend (`PpeController` + routes + maybe 1 migration):**
9. Extend `index` to also return `tabCounts`, `hero`, `detail` (partial-reload, eager-loaded), `can.manage`; include `is_active` + retired types; accept `category`+`condition`+`q`.
10. Add `updateType` (+ activate/deactivate), `acknowledge`, `condemn`, `dispose` endpoints under `hazards.manage`.
11. Reconcile enum drift: status `in_repair` vs `maintenance`, `retired` (page) vs `disposed` (controller); inspection `condition_after` missing `new`; Add-Type `inspection_frequency` (`before_each_use`/`six_monthly` rejected today).
12. **Migration only if persisting:** condemn reason/`condemned_at`/`condemned_by`, disposal record, allocation `acknowledged_by`. (Read-path features need no schema change.)

**Tests/nav:**
13. Update sidebar label to "PPE & Equipment" if desired; the existing Browser test still passes if "PPE" stays in the hero.

---

## 10. Risks / gotchas

- **Live 422 bug** in Add-Type frequency (must not be carried forward) and the **silently-dropped `condition` filter** — both are real defects to fix, not just cosmetic.
- **`allocations` reshaping via `->through()`** (snake aliases `inventory_item`/`ppe_type_name`/`allocated_date`) is bespoke; the new page should standardise the allocation serialization (ideally an API resource) rather than copy the `toArray()` munging.
- **Enum vocabulary is inconsistent across migration / `updateInventory` / page / prototype** — decide canonical values before wiring tabs, or counts/filters will silently miss rows (`in_repair` vs `maintenance` especially).
- **`staff` = ALL users** with no active/role filter — the Allocate wizard worker picker may need scoping.
- **Detail modal History/audit** relies on `AuditableChanges`; confirm what that trait actually records for these models before promising a real audit timeline (prototype's History is seeded/faked).
- **`hazards.manage` permission seeding** — per repo memory, permissions are seeded not migrated and deploys skip seeders; the four new write endpoints reuse the existing `hazards.manage` gate, so **no new permission is introduced** (good — avoids the 403-on-deploy trap).
- Keep the sanctioned `eslint-disable` for on-dark hero buttons (copy from the kit / Add-Client header) to stay `no-restricted-syntax`-clean.
