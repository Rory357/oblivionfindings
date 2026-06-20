# PPE Register redesign — Backend gap list + work plan

Audit target: `/health-safety/ppe` rebuild to the **H&S gold standard** (near-twin of Fleet Incidents / Incidents / Safeguarding controllers).
Scope of **this** document: backend only — controller (`index` additions + new endpoints), routes, migrations, FormRequests, observer/notification note, and the test/factory/seeder plan.

**Source files audited**
- Controller: `app/Http/Controllers/HealthSafety/PpeController.php` (266 lines; `index`, `storeType`, `storeInventory`, `updateInventory`, `allocate`, `returnPpe`, `storeInspection`).
- Routes: `routes/health-safety.php` — PPE group **lines 310–332** (`prefix('ppe')->name('ppe.')`, `index` gated `hazards.view`, all mutations gated `hazards.manage`).
- Migration: `database/migrations/2026_03_28_200005_create_ppe_tables.php` (4 tables).
- Models: `app/Models/PpeType.php`, `PpeInventory.php`, `PpeAllocation.php`, `PpeInspection.php` (all `use AuditableChanges, SoftDeletes, HasFactory`).
- Permissions: `database/seeders/RbacSeeder.php` lines 446 (`hazards.view`), 450 (`hazards.manage`).
- Reference (gold-standard `index` shape): `app/Http/Controllers/FleetAssets/IncidentController.php` lines 41–90 (closure props), 526–534 (`tabCounts`), 554+ (`buildDetailPayload`).
- Hero contract: `resources/js/pages/health-safety/components/hs-hero-kit.tsx` — `HeroComplianceBadges` line 181, accepts `items?: HeroComplianceBadge[]` (line 212) so PPE supplies its own NZ badge set; `HeroComplianceBadge = { icon, tone, label }` (line 166).
- Design semantics: `docs/ppe-redesign/_design_reference/PPE Register.dc.html` (prototype) — flag thresholds lines 278–282 & 301–302, tab scopes lines 345–349 & 356–357, counts 368–381, hero clusters 522–530, compliance badges 541–545.

**Confirmed baseline facts**
- ✅ Permissions `hazards.view` / `hazards.manage` already seeded and granted to Site Manager (RbacSeeder ~769), H&S Officer (~794), etc. **No new permission needed** — and none must be added (deploys skip seeders → would 403; see `reference_deploy_seeders.md`).
- ✅ Columns `acknowledged` + `acknowledged_at` **already exist** on `ppe_allocations` (migration lines 70–71). `acknowledge` endpoint just needs to set them.
- ✅ Models already expose every relation the detail needs: `PpeInventory` → `ppeType, site, allocations, inspections, createdBy, updatedBy`; `PpeAllocation` → `ppeInventory, user, createdBy, updatedBy`; `PpeInspection` → `ppeInventory, inspector, createdBy, updatedBy`. **No model edits required** except adding `acknowledged_by` + condemn/dispose columns to `$fillable` (and a `condemnedBy`/`disposedBy`/`acknowledgedBy` relation if desired).
- ❌ **No `ppe_attachments` table exists** (grep `ppe_attachments|PpeAttachment` → no files). The handoff says this table is **owned by the attachments audit** — out of scope here. The detail "History" tab must therefore be sourced from `AuditableChanges` audit rows + the inspections/allocations timeline, **not** from attachments. Do not build attachments in this loop.
- ❌ No `database/factories/Ppe*` (glob: none). No PPE seeder. No `tests/**/*Ppe*` (glob: none) — **the entire PPE backend is currently untested.**

---

## 0. Gating (unchanged — keep exactly)

| Surface | Middleware |
|---|---|
| `index` (read) | `permission:hazards.view` |
| every mutation (`storeType`, `updateType`, type activate/deactivate, `storeInventory`, `updateInventory`, `condemn`, `dispose`, `allocate`, `acknowledge`, `returnPpe`, `storeInspection`) | `permission:hazards.manage` |

Do **not** add `hazards.create` to PPE mutations (the prototype is manage-only; other registers split create/manage but PPE has always been manage-gated — keep it). `index` returns `can: { manage }` so the React layer can hide affordances.

---

## 1. `index()` additions

Refactor `index` to the **closure-prop** shape (so a `?item=`/`?allocation=` partial reload that only re-requests `detail` does **not** recompute the list, counts and hero). Mirror `FleetAssets/IncidentController::index` lines 70–89.

Keep server-side pagination for `inventory` (page `page`) and `allocations` (page `allocations_page`). Build **one filtered base** per dataset (site/category/status/search/ppe_type_id) **excluding** the tab, so hero + tab counts reflect the filter scope but not the active tab. The active `tab` only narrows the visible list.

### 1a. Props returned by `index`

```php
return Inertia::render('health-safety/ppe/index', [
    'tab'         => $tab,                                  // string, default 'inv_all'
    'filters'     => $request->only(['site_id','category','status','ppe_type_id','search']),
    'inventory'   => fn () => $this->buildInventoryPayload((clone $invBase), $tab),   // paginated
    'allocations' => fn () => $this->buildAllocationPayload((clone $allocBase), $tab), // paginated (allocations_page)
    'types'       => fn () => $this->buildTypesPayload($request),                      // catalogue tab (all, incl. inactive)
    'tabCounts'   => fn () => $this->tabCounts((clone $invBase), (clone $allocBase)),
    'hero'        => fn () => $this->hero((clone $invBase), (clone $allocBase)),
    'sites'       => fn () => Site::select('id','name')->where('is_active', true)->orderBy('name')->get(),
    'staff'       => fn () => User::select('id','name')->orderBy('name')->get(),
    'detail'      => fn () => $this->detail($request),       // null unless ?item=/?allocation=
    'can'         => ['manage' => $request->user()?->canDo('hazards.manage') ?? false],
    // BC shim during migration only — delete once index.tsx no longer reads them:
    'can_manage'  => $request->user()?->canDo('hazards.manage') ?? false,
]);
```

> Keep the legacy `stats` + `can_manage` keys **only** until `index.tsx` is cut over, then delete. Cleaner to drop `stats` immediately and have the hero tiles read `tabCounts`/`hero` — see §1c.

### 1b. `tabCounts` — exact query logic

Tabs (order per handoff §2 / prototype lines 372–379). All counts run on the **filtered base** (clone per count). Thresholds taken verbatim from the prototype.

| Tab key | Badge tone | Count query (on filtered base) |
|---|---|---|
| `inv_all` | primary | `(clone $invBase)->count()` |
| `inv_available` | success | `->where('status','available')->count()` |
| `inv_allocated` | info | `->where('status','allocated')->count()` |
| `inv_inspection` | warning | `->whereNotNull('next_inspection_due')->whereDate('next_inspection_due','<=', now()->addDays(30)->toDateString())->count()` — **due within 30 days OR overdue** (prototype `insp<=30`, incl. negatives) |
| `inv_expiring` | critical | `->whereNotNull('expiry_date')->whereDate('expiry_date','<=', now()->addDays(60)->toDateString())->count()` — **≤60 days or expired** (prototype `exp<=60`) |
| `inv_condemned` | critical | `->where('status','condemned')->count()` |
| `alloc_active` | info | `(clone $allocBase)->whereNull('returned_at')->count()` *(label "Allocations")* |
| `alloc_unack` | warning | `(clone $allocBase)->whereNull('returned_at')->where('acknowledged', false)->count()` |
| `types` | primary | `PpeType::count()` *(catalogue — count **all** incl. inactive; not filtered by site/status)* |

```php
private function tabCounts($invBase, $allocBase): array
{
    $today = now()->toDateString();
    return [
        'inv_all'        => (clone $invBase)->count(),
        'inv_available'  => (clone $invBase)->where('status', 'available')->count(),
        'inv_allocated'  => (clone $invBase)->where('status', 'allocated')->count(),
        'inv_inspection' => (clone $invBase)->whereNotNull('next_inspection_due')
                                ->whereDate('next_inspection_due', '<=', now()->addDays(30)->toDateString())->count(),
        'inv_expiring'   => (clone $invBase)->whereNotNull('expiry_date')
                                ->whereDate('expiry_date', '<=', now()->addDays(60)->toDateString())->count(),
        'inv_condemned'  => (clone $invBase)->where('status', 'condemned')->count(),
        'alloc_active'   => (clone $allocBase)->whereNull('returned_at')->count(),
        'alloc_unack'    => (clone $allocBase)->whereNull('returned_at')->where('acknowledged', false)->count(),
        'types'          => PpeType::count(),
    ];
}
```

> Note on `inv_all`: current code `sum('quantity')` of non-condemned/disposed for the "total items" stat. Decide one of: (a) tab badge = **row count** (recommended — matches prototype `inv.length` and how every other tab counts rows), (b) keep a separate `hero.total_items = sum(quantity)` for the hero tile. Use **(a)** for the tab badge; expose the quantity sum **only** in the hero "Total items" tile if you want the unit count.

### 1c. `hero` block — two clusters + NZ compliance counts/booleans

Return **raw counts/booleans only** — never pre-formatted strings (`HeroComplianceBadges` formats labels itself, but PPE passes a custom `items` array built **client-side** from these numbers; keep server output as primitives so the React side owns wording). Cluster tile values map 1:1 to `tabCounts`.

```php
private function hero($invBase, $allocBase): array
{
    $today = now()->toDateString();
    $in30  = now()->addDays(30)->toDateString();
    $in60  = now()->addDays(60)->toDateString();

    // Cluster 1 — "Live · register"
    $totalItems    = (clone $invBase)->count();                                   // row count (see §1b note)
    $totalUnits    = (int) (clone $invBase)->whereNotIn('status', ['condemned','disposed'])->sum('quantity');
    $allocated     = (clone $invBase)->where('status', 'allocated')->count();
    $available     = (clone $invBase)->where('status', 'available')->count();
    $inspectionDue = (clone $invBase)->whereNotNull('next_inspection_due')
                        ->whereDate('next_inspection_due', '<=', $in30)->count();

    // Cluster 2 — "Needs attention"
    $inspectionOverdue = (clone $invBase)->whereNotNull('next_inspection_due')
                        ->whereDate('next_inspection_due', '<', $today)->count();
    $expiring   = (clone $invBase)->whereNotNull('expiry_date')->whereDate('expiry_date', '<=', $in60)->count();
    $condemned  = (clone $invBase)->where('status', 'condemned')->count();
    $unack      = (clone $allocBase)->whereNull('returned_at')->where('acknowledged', false)->count();

    // NZ compliance signals (booleans + counts the badge row consumes)
    // RPE fit-test due: active allocation of a respiratory-category item where fit-test not completed (AS/NZS 1715).
    $rpeFitTestDue = PpeAllocation::whereNull('returned_at')
        ->where('fit_test_completed', false)
        ->whereHas('ppeInventory.ppeType', fn ($q) => $q->where('category', 'respiratory'))
        ->count();

    // Hi-vis & footwear coverage: at least one in-date, non-condemned item exists in each category (AS/NZS 4602 / 2210).
    $hiVisCovered = PpeInventory::whereHas('ppeType', fn ($q) => $q->where('category', 'high_visibility'))
        ->whereNotIn('status', ['condemned','disposed'])
        ->where(fn ($q) => $q->whereNull('expiry_date')->orWhereDate('expiry_date', '>=', $today))
        ->exists();
    $footwearCovered = PpeInventory::whereHas('ppeType', fn ($q) => $q->where('category', 'foot'))
        ->whereNotIn('status', ['condemned','disposed'])
        ->where(fn ($q) => $q->whereNull('expiry_date')->orWhereDate('expiry_date', '>=', $today))
        ->exists();

    return [
        'clusters' => [
            'live'   => ['total' => $totalItems, 'units' => $totalUnits, 'allocated' => $allocated, 'available' => $available, 'inspections_due' => $inspectionDue],
            'attention' => ['inspection_overdue' => $inspectionOverdue, 'expiring' => $expiring, 'condemned' => $condemned, 'unacknowledged' => $unack],
        ],
        'compliance' => [
            'rpe_fit_test_due'   => $rpeFitTestDue,    // int  → badge "RPE fit-test · N due"
            'inspections_overdue'=> $inspectionOverdue,// int  → badge
            'items_expiring'     => $expiring,         // int  → badge
            'condemned_awaiting' => $condemned,        // int  → badge "Condemned · N awaiting disposal"
            'hi_vis_covered'     => $hiVisCovered,      // bool ┐ combined → "Hi-vis & footwear · Covered/Gaps"
            'footwear_covered'   => $footwearCovered,   // bool ┘
        ],
    ];
}
```

> **`rpeFitTestDue` is global (not site-filtered)** in the snippet for simplicity. If the hero should respect the site filter, thread the active `site_id` into the `whereHas('ppeInventory', …)` (PpeAllocation has no `site_id`; join via inventory). Recommend: respect the site filter on **all** hero numbers for consistency — clone from `$allocBase`/`$invBase` which already carry the filter, and for the global category coverage checks apply the same `site_id` when present.

### 1d. `detail` prop — loaded only when `?item=` / `?allocation=`

```php
private function detail(Request $request): ?array
{
    if ($request->filled('item')) {
        $item = PpeInventory::with([
            'ppeType',
            'site:id,name',
            'allocations' => fn ($q) => $q->with('user:id,name', 'createdBy:id,name')->latest('allocated_at'),
            'inspections' => fn ($q) => $q->with('inspector:id,name')->latest('inspected_at'),
            'createdBy:id,name',
            'updatedBy:id,name',
        ])->find((int) $request->input('item'));

        return $item ? ['kind' => 'item', 'item' => $this->itemDetailPayload($item)] : null;
    }

    if ($request->filled('allocation')) {
        $alloc = PpeAllocation::with([
            'ppeInventory.ppeType',
            'ppeInventory.site:id,name',
            'user:id,name',
            'createdBy:id,name',
            'updatedBy:id,name',
        ])->find((int) $request->input('allocation'));

        return $alloc ? ['kind' => 'allocation', 'allocation' => $this->allocationDetailPayload($alloc)] : null;
    }

    return null;
}
```

- The detail payload's **History** section = `AuditableChanges` audit rows for the model (the trait writes audit entries) **plus** the inspections/allocations timeline. **Not** attachments (no table).
- Closing the modal drops `?item`/`?allocation` → `detail` returns `null`. Front-end opens via `router.get(url, { only: ['detail'], preserveState, preserveScroll })`.

---

## 2. New endpoints

All under the existing `hazards.manage` group in `routes/health-safety.php` (insert inside the block at lines 317–331). All return `redirect()->back()->with('success'|'error', …)` to keep the modal-refresh-in-place pattern (matches every existing PPE method).

### 2a. `updateType` — `PUT /health-safety/ppe/types/{type}`  · name `ppe.types.update`

```php
public function updateType(Request $request, PpeType $type): RedirectResponse
{
    $validated = $request->validate([
        'name'                    => ['required', 'string', 'max:255'],
        'category'                => ['required', 'string', 'in:head,eye,ear,respiratory,hand,foot,body,fall_protection,high_visibility,other'],
        'description'             => ['nullable', 'string', 'max:2000'],
        'hazards_addressed'       => ['nullable', 'string', 'max:2000'],
        'standards_reference'     => ['nullable', 'string', 'max:255'],
        'inspection_frequency'    => ['nullable', 'string', 'in:daily,weekly,monthly,quarterly,annually'],
        'typical_lifespan_months' => ['nullable', 'integer', 'min:1', 'max:600'],
    ]);
    $type->update($validated);
    return redirect()->back()->with('success', 'PPE type updated.');
}
```
*(Validation mirrors `storeType` exactly — extract a shared FormRequest, §4.)*

### 2b. Type activate / deactivate — `is_active` toggle

Two routes (clearest for the right-click "Activate"/"Deactivate" items) OR one `setActive`:

```php
// PATCH /health-safety/ppe/types/{type}/activate    name ppe.types.activate
public function activateType(PpeType $type): RedirectResponse
{
    $type->update(['is_active' => true]);
    return redirect()->back()->with('success', 'PPE type reactivated.');
}
// PATCH /health-safety/ppe/types/{type}/deactivate  name ppe.types.deactivate
public function deactivateType(PpeType $type): RedirectResponse
{
    $type->update(['is_active' => false]);
    return redirect()->back()->with('success', 'PPE type retired.');
}
```
> ⚠️ `index` currently lists **only** active types (`PpeType::where('is_active', true)`). The catalogue tab must list **all** types (incl. retired, with an Active/Retired status pill) — change the types query for the catalogue payload to `PpeType::orderBy('category')->orderBy('name')->get()` (drop the `is_active` filter), but keep the **inventory `Add type` wizard's** type dropdown filtered to active only.

### 2c. `acknowledge` — `POST /health-safety/ppe/allocations/{allocation}/acknowledge` · name `ppe.allocations.acknowledge`

```php
public function acknowledge(Request $request, PpeAllocation $allocation): RedirectResponse
{
    $allocation->update([
        'acknowledged'    => true,
        'acknowledged_at' => now(),
        'acknowledged_by' => $request->user()->id,   // NEW column — migration §3
        'updated_by'      => $request->user()->id,
    ]);
    return redirect()->back()->with('success', 'Allocation acknowledged.');
}
```
- No request body. Idempotent (re-acknowledging just refreshes `acknowledged_at`).
- `acknowledged`/`acknowledged_at` exist already; **add `acknowledged_by`** (§3) for attribution and add to `PpeAllocation::$fillable`.

### 2d. `condemn` — `POST /health-safety/ppe/inventory/{inventory}/condemn` · name `ppe.inventory.condemn`

```php
public function condemn(Request $request, PpeInventory $inventory): RedirectResponse
{
    $validated = $request->validate([
        'reason' => ['required', 'string', 'max:2000'],
    ]);
    $inventory->update([
        'status'            => 'condemned',
        'condition'         => 'condemned',
        'condemned_at'      => now(),          // NEW
        'condemned_by'      => $request->user()->id,  // NEW
        'condemned_reason'  => $validated['reason'],  // NEW
        'updated_by'        => $request->user()->id,
    ]);
    return redirect()->back()->with('success', 'Item condemned and removed from service.');
}
```
- Side-effects: status→`condemned`, condition→`condemned`, write audit columns. **Guard:** reject if the item has an **active allocation** (`allocations()->whereNull('returned_at')->exists()`) — must be returned first; return `back()->with('error', …)`. (Prototype shows Condemn on allocated items, but the safe order is return-then-condemn; surface as a validation error.)

### 2e. `dispose` — `POST /health-safety/ppe/inventory/{inventory}/dispose` · name `ppe.inventory.dispose`

```php
public function dispose(Request $request, PpeInventory $inventory): RedirectResponse
{
    $validated = $request->validate([
        'disposal_method' => ['required', 'string', 'max:255'],
        'reason'          => ['nullable', 'string', 'max:2000'],
    ]);
    $inventory->update([
        'status'          => 'disposed',
        'disposed_at'     => now(),                      // NEW
        'disposed_by'     => $request->user()->id,       // NEW
        'disposal_method' => $validated['disposal_method'], // NEW
        'condemned_reason'=> $validated['reason'] ?? $inventory->condemned_reason,
        'updated_by'      => $request->user()->id,
    ]);
    return redirect()->back()->with('success', 'Item disposed and archived.');
}
```
- Typically follows `condemn` (status `condemned` → `disposed`). **Guard:** only allow from `condemned` (or `condemned`/`poor`) status; otherwise `back()->with('error', 'Condemn the item before disposal.')`.

### 2f. Routes block to append (lines 317–331)

```php
Route::middleware('permission:hazards.manage')->group(function () {
    // PPE Types
    Route::post('/types', [PpeController::class, 'storeType'])->name('types.store');
    Route::put('/types/{type}', [PpeController::class, 'updateType'])->name('types.update');                 // NEW
    Route::patch('/types/{type}/activate', [PpeController::class, 'activateType'])->name('types.activate');   // NEW
    Route::patch('/types/{type}/deactivate', [PpeController::class, 'deactivateType'])->name('types.deactivate'); // NEW

    // PPE Inventory
    Route::post('/inventory', [PpeController::class, 'storeInventory'])->name('inventory.store');
    Route::put('/inventory/{inventory}', [PpeController::class, 'updateInventory'])->name('inventory.update');
    Route::post('/inventory/{inventory}/condemn', [PpeController::class, 'condemn'])->name('inventory.condemn'); // NEW
    Route::post('/inventory/{inventory}/dispose', [PpeController::class, 'dispose'])->name('inventory.dispose'); // NEW

    // Allocations
    Route::post('/inventory/{inventory}/allocate', [PpeController::class, 'allocate'])->name('inventory.allocate');
    Route::post('/allocations/{allocation}/acknowledge', [PpeController::class, 'acknowledge'])->name('allocations.acknowledge'); // NEW
    Route::post('/allocations/{allocation}/return', [PpeController::class, 'returnPpe'])->name('allocations.return');

    // Inspections
    Route::post('/inventory/{inventory}/inspections', [PpeController::class, 'storeInspection'])->name('inventory.inspections.store');
});
```

> `{type}` route-model binding: `PpeType` (default `id`). `{inventory}`: `PpeInventory`. `{allocation}`: `PpeAllocation`. All already work via implicit binding.

### 2g. One pre-existing bug to fix while here

`allocate()` (controller line 163) **never sets `acknowledged`** and there is no acknowledgement step — the prototype's allocate wizard has a "Training & acknowledgement" step with an acknowledgement toggle (handoff §6.2). Extend `allocate`'s validation to accept `acknowledged` (boolean) and, when true, set `acknowledged` + `acknowledged_at` (+ `acknowledged_by`) at creation time, so a worker who acknowledges at issue doesn't show as "Unacknowledged". (Otherwise every new allocation lands in the `alloc_unack` tab until a second action.)

---

## 3. Migrations

**Single new migration** (additive, nullable columns — safe; no destructive change). Name e.g. `2026_06_2x_000000_add_ppe_lifecycle_audit_columns.php`.

### 3a. `ppe_allocations` — attribution for acknowledge

| Column | Type | Notes |
|---|---|---|
| `acknowledged_by` | `unsignedBigInteger` nullable, FK→`users.id` `nullOnDelete` | who recorded the acknowledgement |

### 3b. `ppe_inventory` — condemn/dispose audit

| Column | Type | Notes |
|---|---|---|
| `condemned_at` | `dateTime` nullable | |
| `condemned_by` | `unsignedBigInteger` nullable, FK→`users.id` `nullOnDelete` | |
| `condemned_reason` | `text` nullable | reason captured in condemn modal |
| `disposed_at` | `dateTime` nullable | |
| `disposed_by` | `unsignedBigInteger` nullable, FK→`users.id` `nullOnDelete` | |
| `disposal_method` | `string` nullable | e.g. "Hazardous waste contractor", "General waste" |

```php
public function up(): void
{
    Schema::table('ppe_allocations', function (Blueprint $t) {
        $t->unsignedBigInteger('acknowledged_by')->nullable()->after('acknowledged_at');
        $t->foreign('acknowledged_by')->references('id')->on('users')->nullOnDelete();
    });
    Schema::table('ppe_inventory', function (Blueprint $t) {
        $t->dateTime('condemned_at')->nullable()->after('next_inspection_due');
        $t->unsignedBigInteger('condemned_by')->nullable()->after('condemned_at');
        $t->text('condemned_reason')->nullable()->after('condemned_by');
        $t->dateTime('disposed_at')->nullable()->after('condemned_reason');
        $t->unsignedBigInteger('disposed_by')->nullable()->after('disposed_at');
        $t->string('disposal_method')->nullable()->after('disposed_by');
        $t->foreign('condemned_by')->references('id')->on('users')->nullOnDelete();
        $t->foreign('disposed_by')->references('id')->on('users')->nullOnDelete();
    });
}
public function down(): void
{
    Schema::table('ppe_allocations', function (Blueprint $t) {
        $t->dropForeign(['acknowledged_by']);
        $t->dropColumn('acknowledged_by');
    });
    Schema::table('ppe_inventory', function (Blueprint $t) {
        $t->dropForeign(['condemned_by']);
        $t->dropForeign(['disposed_by']);
        $t->dropColumn(['condemned_at','condemned_by','condemned_reason','disposed_at','disposed_by','disposal_method']);
    });
}
```

**Model `$fillable` additions** (required — these are mass-assigned in the new methods):
- `PpeAllocation::$fillable` += `'acknowledged_by'` (+ cast none needed; add `belongsTo` `acknowledgedBy` relation if surfacing in detail).
- `PpeInventory::$fillable` += `'condemned_at','condemned_by','condemned_reason','disposed_at','disposed_by','disposal_method'`; add casts `'condemned_at' => 'datetime', 'disposed_at' => 'datetime'`; optional `condemnedBy()`/`disposedBy()` `belongsTo(User::class, …)` relations for the detail "Status/History" section.

### 3c. `ppe_attachments` — **NOT in this loop**

The handoff explicitly states this table is **owned by the attachments audit** (a separate cross-module workstream). Do **not** create it here. The detail "History" tab sources from `AuditableChanges` audit + inspection/allocation timelines. Note it in the cross-module audit and leave a `// TODO(attachments-audit): evidence uploads` marker where the detail evidence section would mount.

### Migration policy
Run the migration **locally/autonomously** (additive, reversible). On deploy it runs automatically. No data backfill needed (all nullable).

---

## 4. FormRequests worth extracting vs inline `validate()`

The codebase mixes both; existing PPE methods use inline `validate()`. **Extract only where rules are shared across two methods** (DRY + a single `validateStep` mirror for the wizard). Keep one-off action rules inline.

| Extract → FormRequest | Used by | Why |
|---|---|---|
| `StorePpeTypeRequest` | `storeType` **and** `updateType` | identical 7-field ruleset — extract to avoid drift (§2a). |
| `StorePpeInventoryRequest` | `storeInventory` **and** `updateInventory` | mostly-shared; `updateInventory` allows `quantity:min:0` + `status` while store forces defaults. Either one request with a `prepareForValidation`/method-aware rules, or keep `updateInventory` inline and extract only the store. **Recommend:** `StorePpeInventoryRequest` + keep `updateInventory` inline (its rules legitimately differ). |
| Keep inline | `allocate`, `returnPpe`, `storeInspection`, `acknowledge`, `condemn`, `dispose` | single-caller, short, action-specific — no benefit to extracting. |

Place under `app/Http/Requests/HealthSafety/`. Each `authorize()` returns `true` (route middleware already enforces `hazards.manage`). Keep the `in:` enum lists identical to the column domains.

---

## 5. Observer / Notification — note + defer

**Warranted but NOT owned by this loop.** Three time-based signals would benefit from proactive notification rather than only surfacing on the register:
1. **Inspection due/overdue** — `next_inspection_due <= today` → notify the site H&S lead.
2. **RPE fit-test expiry** — annual re-fit per AS/NZS 1715 (a `fit_test_date` older than ~12 months on an active respiratory allocation).
3. **Unacknowledged allocation** — active allocation, `acknowledged = false`, older than N days → nudge the worker / coordinator.
4. (Bonus) **Item expiry** — `expiry_date` within 60 days.

**Recommendation:** do **not** build an observer/notification in the PPE redesign loop. Pattern precedent exists (`SafeguardingReviewReminders` command + `database`-channel notification, per memory). **Defer ownership to the cross-module H&S audit / a scheduled `PpeComplianceReminders` console command** so notification routing (who, channel, digest cadence) is decided once across H&S. Leave a one-line note in the cross-module audit doc. No `AppServiceProvider` observer registration in this loop (keeps the diff additive and avoids cross-module churn — see the Safeguarding loop's shared-edit caveats).

---

## 6. Test + factory + seeder plan

> ⚠️ **Worktree-vendor caveat** (`reference_worktree_junction_tests_load_parent_app.md`): junctioned-vendor worktrees autoload the **parent's** `app/` via absolute composer paths, so **unmerged controller edits are NOT exercised by `php artisan test` in the worktree**. Migrations + frontend *do* use the worktree. **Backend Pest tests must ultimately run in the PARENT repo after merge.** Write the tests now; treat a green run only as authoritative post-merge in the parent. Run non-parallel (`reference_testing.md` — `--parallel` gives false failures here). Use `php84\php.exe artisan test` directly (`reference_herd_php_bat.md`).

### 6a. Factories to add (`database/factories/`)

All four are missing. Models already `use HasFactory`.

| Factory | Key definition notes |
|---|---|
| `PpeTypeFactory` | `name` fake; `category` → `fake()->randomElement(['head','eye','ear','respiratory','hand','foot','high_visibility','fall_protection'])`; `standards_reference` (e.g. 'AS/NZS 1715'); `inspection_frequency` randomElement(daily…annually); `typical_lifespan_months` 12–60; `is_active` true. States: `respiratory()` (category respiratory + 'AS/NZS 1715 & 1716'), `inactive()`. |
| `PpeInventoryFactory` | `ppe_type_id` → `PpeType::factory()`; `site_id` → `Site::factory()`; brand/model/serial fakes; `condition` 'good'; `quantity` 1; `status` 'available'; `purchase_date` past; `expiry_date` future; `next_inspection_due` future. States: `allocated()`, `condemned()` (status+condition condemned), `inspectionDue()` (`next_inspection_due` = `now()->subDay()`), `expiring()` (`expiry_date` = `now()->addDays(30)`), `expired()`. |
| `PpeAllocationFactory` | `ppe_inventory_id` → `PpeInventory::factory()`; `user_id` → `User::factory()`; `allocated_at` now; `returned_at` null; booleans false; `acknowledged` false. States: `acknowledged()`, `returned()`, `fitTested()`, `unacknowledged()` (explicit). |
| `PpeInspectionFactory` | `ppe_inventory_id` → factory; `inspected_by` → `User::factory()`; `inspected_at` now; `result` 'pass'; `condition_after` 'good'; `next_inspection_due` future. States: `failed()`, `condemned()`. |

### 6b. Seeder for demo data

Add `PpeSeeder` (call from `DatabaseSeeder`, after sites/users exist; **not** required on deploy — local/demo only). Mirror the prototype seed (`PPE Register.dc.html` lines 243–294): ~8 types across the NZ AS/NZS categories (half-face respirator P2 'AS/NZS 1715 & 1716' monthly, hard hat 'AS/NZS 1801', safety glasses 'AS/NZS 1337.1', hi-vis vest 'AS/NZS 4602.1' annually, safety boots 'AS/NZS 2210.3', cut gloves 'AS/NZS 2161', full-body harness 'AS/NZS 1891.1', disposable P2 masks); ~14 inventory rows across 3 sites with a mix of statuses incl. one `condemned`, one inspection-overdue, one expiring; ~6 active allocations incl. one unacknowledged respiratory (no fit-test) to light up every hero badge/tab. Idempotent (`firstOrCreate` on name/serial).

### 6c. Pest test plan — `tests/Feature/HealthSafety/PpeRegisterTest.php`

Cover **every endpoint + the index contract**. Auth: a user with `hazards.manage` for mutations; a `hazards.view`-only user for the 403 matrix; a no-perm user for index 403.

**Index / read (`hazards.view`)**
1. `index` renders `health-safety/ppe/index` with props `tab, filters, inventory, allocations, types, tabCounts, hero, sites, staff, can`.
2. `tabCounts` correctness: seed fixtures so each tab has a known count (available/allocated/inspection-due `≤30`/expiring `≤60`/condemned/active/unacknowledged/types) and assert exact integers. Include boundary cases: `next_inspection_due` exactly today (counts in inspection-due), `expiry_date` exactly +60 (counts), +61 (does not).
3. `hero.compliance`: assert `rpe_fit_test_due` counts an active respiratory allocation with `fit_test_completed=false`; `hi_vis_covered`/`footwear_covered` booleans flip when the only in-date item is condemned/expired; `inspections_overdue`/`expiring`/`condemned_awaiting` integers.
4. `detail` is `null` without params; with `?item={id}` returns `kind=item` + eager-loaded type/site/allocations/inspections; with `?allocation={id}` returns `kind=allocation`; with a non-existent id returns `null` (no 404).
5. Filters: `site_id`/`category`/`status`/`search`/`ppe_type_id` narrow the list **and** `tabCounts`/`hero` (scope respected), but the active `tab` narrows only the list.
6. Catalogue tab lists **inactive** types too (regression guard for the `is_active` filter removal).

**Mutations (`hazards.manage`)**
7. `storeType` creates a type (valid) / 422 on bad `category` enum.
8. `updateType` updates; 422 on missing `name`.
9. `activateType` / `deactivateType` flip `is_active`.
10. `storeInventory` creates with `status=available`, `created_by`/`updated_by` set.
11. `updateInventory` updates allowed fields.
12. `allocate` creates an allocation, sets inventory `status=allocated`; when `acknowledged=true` passed, sets `acknowledged`+`acknowledged_at`+`acknowledged_by` (the §2g fix); respiratory item without fit-test still allocates (fit-test non-blocking server-side, surfaced in UI).
13. `acknowledge` sets `acknowledged=true, acknowledged_at, acknowledged_by`; idempotent.
14. `returnPpe` sets `returned_at`, returns inventory to `available` (or `condemned` when returned condition condemned).
15. `storeInspection` creates an inspection, updates `last_inspected_at`/`next_inspection_due`/`condition`; `result=condemned` flips inventory to `condemned`.
16. `condemn` sets status+condition `condemned` + `condemned_at`/`by`/`reason`; **rejected** (back with error, no change) when an active allocation exists.
17. `dispose` sets status `disposed` + `disposed_at`/`by`/`disposal_method`; 422 on missing `disposal_method`; rejected when status not `condemned`.

**Authorization matrix**
18. `hazards.view`-only user: `GET index` 200; every mutation (storeType/updateType/activate/deactivate/storeInventory/updateInventory/allocate/acknowledge/return/inspect/condemn/dispose) → **403**.
19. No-permission user: `GET index` → 403.

**Regression**
20. A `detail`-only partial reload (`?item={id}` with `X-Inertia-Partial-Data: detail`) does not error and returns the detail prop (smoke test of the closure-prop refactor).

Target ~20 test cases. Keep them in one feature file; use the factories from §6a and a small inline arrange block per assertion rather than the full demo seeder.

---

## 7. Summary checklist (backend only)

- [ ] Refactor `PpeController@index` to closure props + filtered bases; add `tab`, `tabCounts`, `hero`, `detail`, `can:{manage}` (§1). Keep `hazards.view` gate.
- [ ] Catalogue query lists **all** types (drop `is_active` filter); keep wizard type-dropdown active-only (§2b note).
- [ ] Add `updateType` (PUT), `activateType`/`deactivateType` (PATCH), `acknowledge` (POST), `condemn` (POST), `dispose` (POST) — all under `hazards.manage` (§2).
- [ ] Fix `allocate` to honour an `acknowledged` flag at issue (§2g).
- [ ] One additive migration: `acknowledged_by` on `ppe_allocations`; `condemned_at/by/reason` + `disposed_at/by` + `disposal_method` on `ppe_inventory` (§3). Update `$fillable`/casts.
- [ ] Do **not** create `ppe_attachments` (attachments audit owns it); History tab = audit + timelines (§3c).
- [ ] Extract `StorePpeTypeRequest` (+ optional `StorePpeInventoryRequest`); keep action rules inline (§4).
- [ ] Note observer/notification need; **defer** to cross-module audit / scheduled command — no observer in this loop (§5).
- [ ] Add 4 factories + `PpeSeeder` + `PpeRegisterTest` (~20 cases). **Run backend tests in the PARENT repo post-merge**, non-parallel, via `php84\php.exe` (§6).
- [ ] No new permissions (would 403 on deploy — seeders skipped). `hazards.view`/`hazards.manage` already cover it.
