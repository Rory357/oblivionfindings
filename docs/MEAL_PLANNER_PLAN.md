# Meal Planner Tab for Sites

## Context

Add a "Meal Planner" tab to each Site detail page. Houses (resident care) plan resident meals with dietary/allergen safety; offices and facilities use it to track kitchen consumables (milk, coffee, snacks). Same tab, site-type-aware defaults — no duplication.

v1 ships the full feature: weekly menu calendar, recipe library, per-site inventory with movements/stocktake, auto-generated shopping list, costed rollup. Countdown/Woolworths NZ integration is **deferred** (no public ordering API) but the data model and a `DeliveryProvider` adapter slot are designed for a future drop-in. v1 ships with a `NullDeliveryProvider` only.

Difficulty estimate: **14–17 working days** total, shippable in 4–5 slices.

---

## Scope decisions (confirmed with user)

- Dual use case: houses (resident meals) + offices/facilities (staff kitchen inventory). One tab, site-type-aware defaults.
- Full v1: recipes + dietary tags + costed shopping list + **inventory tracker**.
- Countdown integration: design for it (adapter + `external_refs` on products), don't build yet.

## Open decisions to confirm before S1

1. **Allergen warnings at houses** — recommend ON in v1: the plan-entry dialog cross-checks recipe allergen tags against linked residents' `Client.allergies`. Safety feature, not polish.
2. **Auto-decrement inventory when a meal is served** — recommend **manual** "Mark served" button in v1 to avoid noisy/wrong deductions. Movement is written on confirm.
3. **Currency** — recommend single tenant-wide NZD via existing `AppSetting`; keep `currency` column on products reserved but unused in v1.
4. **Recipe scaling** — v1 assumes linear scaling only (baked-goods `scales_linearly` flag deferred).
5. **Multi-site shopping lists** — v1 is per-site only.

---

## Data model

Two layers: **global library** (tenant-wide, reusable) and **per-site state**. All models live in `app/Models/` (Sites is a core model, not a domain module per existing convention). All use the `AuditableChanges` trait and respect `tenant_id`.

### Global library

| Table | Key columns | Purpose |
|---|---|---|
| `meal_dietary_tags` | `tenant_id`, `key`, `label`, `kind` enum(`dietary`,`allergen`), `severity` enum(`info`,`warn`,`critical`), `color` | Lookup table — chosen over JSON so allergens are joinable, renameable, and drive safety filters. |
| `meal_products` | `tenant_id`, `name`, `category`, `default_unit`, `pack_size`, `cost_per_unit_cents`, `currency`, `is_active`, `external_refs` JSON, `barcode`, `notes` | Master catalogue. `external_refs` reserved for future Countdown SKU mapping. |
| `meal_product_tag` | `product_id`, `tag_id` | So a product (e.g. almond milk) carries allergens that propagate to recipes. |
| `meal_recipes` | `tenant_id`, `name`, `slug`, `description`, `serves_default`, `prep_minutes`, `cook_minutes`, `instructions`, `image_path`, `is_active`, `created_by` | Reusable recipe; serving size is the scaling base. |
| `meal_recipe_ingredients` | `recipe_id`, `product_id` (nullable), `free_text_name` (nullable), `quantity`, `unit`, `notes`, `sort_order` | Product FK or free-text — quick entry before cataloguing. |
| `meal_recipe_tag` | `recipe_id`, `tag_id` | Pivot. |

### Per-site state

| Table | Key columns | Purpose |
|---|---|---|
| `site_meal_plan_entries` | `tenant_id`, `site_id`, `plan_date`, `meal_slot` enum(`breakfast`,`morning_tea`,`lunch`,`afternoon_tea`,`dinner`,`supper`), `recipe_id` (nullable), `ad_hoc_name` (nullable), `servings`, `notes`, `client_ids` JSON | Calendar cell. `client_ids` enables resident-allergen cross-checks at houses. |
| `site_meal_inventory_items` | `tenant_id`, `site_id`, `product_id`, `current_qty`, `unit`, `par_level`, `reorder_level`, `location_label`, `last_counted_at` | One row per (site, product). `current_qty` is materialised. |
| `site_meal_inventory_movements` | `tenant_id`, `site_id`, `product_id`, `delta` signed, `unit`, `reason` enum(`stocktake`,`delivery`,`consumption`,`waste`,`adjustment`,`plan_consumption`), `reference_type`+`reference_id`, `note`, `performed_by`, `performed_at` | Append-only audit log. Every `current_qty` change goes through one. |
| `site_meal_shopping_lists` | `tenant_id`, `site_id`, `status` enum(`draft`,`ordered`,`received`,`cancelled`), `covers_from`, `covers_to`, `generated_at`, `generated_by`, `provider_key`, `provider_order_ref` | Draft lists regenerate; ordered lists freeze. |
| `site_meal_shopping_list_items` | `list_id`, `product_id` (nullable), `free_text_name`, `needed_qty`, `unit`, `source` enum(`meal_plan`,`restock_to_par`,`manual`), `source_meta` JSON, `received_qty` | `source=manual` items survive regeneration. |

Migration order: tags → products → product↔tag → recipes → recipe ingredients → recipe↔tag → inventory items → movements → plan entries → shopping lists → list items. Soft-deletes on recipes, products, inventory items (referenced by historical entries).

---

## Backend

### Site-scoped controllers — `app/Http/Controllers/Sites/`
- `SiteMealPlanController` — `index` (week JSON), `store`, `update`, `destroy`, `weekSummary` (cost + allergen rollup).
- `SiteMealInventoryController` — `index`, `adjust` (single movement), `stocktake` (bulk set), `lowStock`.
- `SiteMealShoppingListController` — `index`, `generate`, `update` (status), `addItem`, `removeItem`, `markReceived`.

Routes nested in the existing `Route::prefix('sites/{site}')->middleware('permission:sites.viewAny')` group in `routes/sites.php`, with per-action permission middleware in the same style as Calendar/Hazards.

### Global library — new `routes/catering.php` registered in `bootstrap/app.php`
Controllers under `app/Http/Controllers/Catering/`:
- `RecipeController` — resourceful CRUD.
- `ProductController` — resourceful CRUD + future CSV import.
- `DietaryTagController` — admin CRUD.

### Service layer — `app/Services/Catering/`
- `MealCostCalculator` — recipe + servings → cost from ingredients × unit cost, with unit conversion. Cacheable per recipe revision.
- `ShoppingListGenerator` — walks plan entries, expands recipes, subtracts inventory, adds `(par − current)` deltas, aggregates by `(product, unit)`. Preserves `source=manual` items across regenerations.
- `InventoryMovementRecorder` — single write-path: append movement row + update `current_qty` in a DB transaction. Stocktakes, deliveries, "mark served" all flow through here.
- `UnitConverter` — g↔kg, ml↔L, each via `pack_size`.

### DeliveryProvider adapter — `app/Services/Catering/DeliveryProviders/`
- `DeliveryProviderContract` interface — methods: `key()`, `searchProducts()`, `matchProduct()`, `priceQuote()`, `submitOrder()`, `orderStatus()`.
- `NullDeliveryProvider` — default; returns empty collections, throws `UnsupportedOperationException` on submit.
- `DeliveryProviderManager` — resolves by key from `config/catering.php`. Bound in `AppServiceProvider`.

v1 registers Null only. Shopping list UI exposes a `provider_key` selector locked to "Manual."

---

## Frontend

**Single top-level Site tab "Meal Planner" with internal sub-tabs.** Splitting across multiple top-level Site tabs would bloat an already crowded tab bar (10+ tabs already).

Sub-tabs inside the new `<TabsContent value="meal-planner">` block in `resources/js/pages/sites/show.tsx`:

1. **Calendar** — week grid (rows = meal slots, cols = days). Click cell → plan entry dialog.
2. **Recipes** — searchable global recipe list, "add to today" quick action.
3. **Inventory** — site product table with current qty, par, low-stock badges, inline +/− adjusts, stocktake mode.
4. **Shopping List** — current draft + history, regenerate button.

### Site-type-aware defaults (driven by `site.type` already in payload)
- `house` → default **Calendar**; tab order: Calendar, Inventory, Shopping, Recipes.
- `office` / `facility` → default **Inventory**; tab order: Inventory, Shopping, Calendar, Recipes.
- All four always accessible — nothing hidden by type.

### File layout — mirrors the Contacts pattern under `resources/js/pages/sites/meal-plans/`
- `_dialogs.tsx` — `PlanEntryDialog`, `RecipePickerDialog`, `StocktakeDialog`, `ShoppingListGenerateDialog`, `AdjustInventoryDialog`. Lazy-loaded from `show.tsx`.
- `_helpers.ts` — meal slot ordering, unit/cost formatters, allergen badge colour map.
- `_calendar-grid.tsx` — week grid sub-component.
- `_inventory-table.tsx` — inline-adjust table.
- `_shopping-list-panel.tsx` — list + regenerate flow.

`SiteController::show` Inertia payload extends with `mealPlanEntries`, `inventory`, `shoppingList`, `recipesIndex` (slimmed).

---

## Permissions

New `database/seeders/CateringPermissionsSeeder.php` (mirrors existing module seeders), called from `RbacSeeder`:

- `catering.recipes.view` / `catering.recipes.manage`
- `catering.products.view` / `catering.products.manage`
- `sites.meals.view` (effectively granted with `sites.viewAny`)
- `sites.meals.plan`
- `sites.meals.inventory.adjust`
- `sites.meals.shopping.manage`

Default role mapping: care workers → view + plan + inventory.adjust at rostered houses; house leads → all `sites.meals.*`; ops manager → everything including global `catering.*`; read-only roles → view only.

---

## Seeding

New `database/seeders/CateringSeeder.php`, idempotent via `firstOrCreate`:
- ~25 dietary/allergen tags (vegetarian, vegan, GF, dairy-free, halal, kosher, low-sodium, diabetic, soft-diet, pureed, thickened-fluids; allergens: peanuts, tree-nuts, shellfish, fish, eggs, dairy, soy, gluten, sesame, sulphites).
- ~40 starter products (milk 2L, bread loaf, eggs dozen, coffee 250g, tea, sugar, butter, common pantry). No default costs.
- ~5 example recipes (`is_active=false` by default).

Wired into `DatabaseSeeder` after `SitesModuleSeeder`.

---

## Shippable slices

| Slice | Scope | Days |
|---|---|---|
| **S1 — Foundations** | Tags, products, recipes (global library), admin CRUD pages, permissions seeder. | 3–4 |
| **S2 — Meal Plan Calendar** | Plan entries + controller + Calendar sub-tab. Site-type defaults. Resident linking + allergen warnings for houses. | 3 |
| **S3 — Inventory** | Inventory items + movements + Inventory sub-tab. Quick adjusts, stocktake mode, audit log view. | 2–3 |
| **S4 — Shopping List + Cost Rollup** | `ShoppingListGenerator`, `MealCostCalculator`, shopping sub-tab, regenerate flow, mark-received writes movements. | 3–4 |
| **S5 — Adapter Scaffold** | `DeliveryProviderContract` + `Null` impl + manager + config + `external_refs` plumbing. | 1 |
| **Polish & tests** | Pest feature tests for controllers + services; Vitest for helpers. | 2 |

Ship S1+S2 in week 1 to get houses immediate value; S3+S4 week 2; S5 whenever.

---

## Critical files

**Read for context (existing patterns to follow):**
- `resources/js/pages/sites/show.tsx` — tab insertion point + lazy dialog pattern.
- `routes/sites.php` — site-prefixed route group.
- `resources/js/pages/sites/contacts/_dialogs.tsx` — dialog template.
- `database/seeders/SitesModuleSeeder.php` — seeder conventions.
- `app/Models/SiteContact.php` — site-attached model + audit trait.

**New (created by this plan):**
- 11 migrations (tables listed above).
- 11 Eloquent models in `app/Models/`.
- 6 controllers (3 site-scoped, 3 global library).
- 4 service classes + DeliveryProvider contract + Null impl + Manager.
- `routes/catering.php` (registered in `bootstrap/app.php`).
- `config/catering.php`.
- 2 seeders (`CateringPermissionsSeeder`, `CateringSeeder`).
- Frontend: `resources/js/pages/sites/meal-plans/` (5 files) + Catering admin pages under `resources/js/pages/catering/`.

---

## Verification

After each slice:

1. **Migrate + seed locally**: `php artisan migrate --seed` (using `C:\Users\chane\.config\herd\bin\php.bat`). Confirm seeded tags, products, recipes appear.
2. **Pest feature tests**: cover controller happy-paths + permission denials. Service-level tests for `MealCostCalculator`, `ShoppingListGenerator` (with manual-item preservation), `InventoryMovementRecorder` (movement → qty sync in transaction), `UnitConverter`.
3. **Manual end-to-end via Inertia**:
   - **House site** → Meal Planner → Calendar opens by default → add lunch with a recipe + 6 residents → save → allergen warning fires for a resident with a tagged allergy.
   - **Office site** → Meal Planner → Inventory opens by default → stocktake mode → adjust milk to 0 → low-stock badge appears.
   - Generate shopping list for week → manual item added → regenerate → manual item still present, meal-plan items recomputed.
   - Mark shopping list as received → inventory `current_qty` increases via `delivery` movements.
4. **Vitest** for `_helpers.ts` formatters and slot ordering.
5. **Permissions smoke**: log in as a read-only role → confirm sub-tabs render but mutation actions are disabled/hidden.
