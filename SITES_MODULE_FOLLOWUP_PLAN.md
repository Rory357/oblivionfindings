# Sites & Locations Module — Follow-up Plan (for GPT‑5.5)

**Target:** Oblivion Findings Laravel/Inertia/React app at `C:\Users\steph\Herd\oblivionfindings`.
**Scope:** Tidy-up items surfaced during the audit of [SITES_MODULE_PLAN.md](SITES_MODULE_PLAN.md). Sites & Locations only. Do **not** touch mobile. Preserve the dark polished look/feel.

---

## Ground rules (same as before)

1. **Module URL is `/sites`**. NZ context — NZD, NZ English (`organisation`), Ministry of Health/NASC framing, no NDIS.
2. **Theme tokens only.** No hard-coded purple/indigo/`#XXXXXX` in JSX/TSX. Use `primary`, `primary-foreground`, `muted`, `border`, `card`, `accent`, `status-success`, `status-warning`, `status-critical`, `status-info`.
3. **Sites module UI pattern** (from memory): rounded card grid + dialog-driven CRUD; Send-Kudos-style 3-column tile pickers. Canonical example: `resources/js/pages/sites/contacts/_dialogs.tsx` `ContactTypePicker`.
4. **Dark theme.** Verify in both themes.
5. **Memory directive:** "fix errors/warnings found during verification, don't dismiss as pre-existing." That applies to everything in this plan.

Run after each task:

```powershell
php artisan test --filter=Sites
npx tsc --noEmit
npm run build
```

The build currently warns:

```
(!) Some chunks are larger than 500 kB after minification.
public/build/assets/_dialogs-BpFQy2PN.js  1,269.33 kB │ gzip: 580.15 kB
```

Task 3 addresses that.

---

## Task 1 — Clarify "Capacity" semantics on house sites

**Symptom.** The plan asked for `houseRooms as rooms_total` with no filter. The implementation filters by `is_assignable = true`, so only bedrooms count toward capacity — kitchens, lounges, bathrooms are excluded.

The implementation choice is semantically defensible (capacity = beds), but the user-facing card on `/sites/{id}` says **"Total rooms"** which implies all rooms. That's the mismatch worth fixing — pick one definition and label it correctly everywhere.

**Decision to apply (recommended):** keep the `is_assignable = true` filter — capacity for supported living = beds. Then **rename the labels** so the meaning is clear.

### Changes

#### 1a. [app/Http/Controllers/SiteController.php](app/Http/Controllers/SiteController.php)

Update `occupancyPayload()` (around line 1623–1676) so the labels read as bedrooms, not rooms:

```php
if (in_array($site->type, ['house', 'residential'], true)) {
    // ... existing filter logic ...
    return [
        'label' => 'Bedroom occupancy',
        'noun' => 'bedrooms',
        'rooms_total' => $total,
        'rooms_occupied' => $occupied,
        'vacancies' => max(0, $total - $occupied),
        'percent' => $total > 0 ? (int) round(($occupied / $total) * 100) : 0,
    ];
}
```

Leave the head-office (`Resources`) and facility (`Zones`) branches as they are — those nouns already read correctly.

#### 1b. [resources/js/pages/sites/show.tsx](resources/js/pages/sites/show.tsx) (around line 1663–1677)

The card currently renders `Total ${occupancy.noun}`. After the controller change above, this becomes "Total bedrooms" for houses, which reads correctly. No JSX change needed — just verify the result.

#### 1c. [resources/js/pages/sites/index.tsx](resources/js/pages/sites/index.tsx)

Find the Capacity column header (added by the original plan's Task 2c) and confirm it reads `Capacity (beds)` for the houses view. If it currently just says `Capacity`, add a small subtitle or `title` attribute clarifying that the count is bedrooms only.

#### 1d. Tests

Update [tests/Feature/Sites/SiteOperationalReadinessTest.php](tests/Feature/Sites/SiteOperationalReadinessTest.php) — if any assertion checks `noun === 'rooms'` for a house site, change it to `'bedrooms'`. Add one assertion that a non-assignable room (kitchen) doesn't move the capacity counter.

### Acceptance
- A house with 3 bedrooms + 1 kitchen shows `Capacity 3` on the index, not `4`.
- The Overview card says "Bedroom occupancy" + "Total bedrooms".
- Test suite still green.

---

## Task 2 — Convert remaining `text-white/*` hero gradients to `text-primary-foreground/*`

**Symptom.** `show.tsx`'s hero was converted to `text-primary-foreground/XX` per Extra B, but two other hero gradients still use raw white opacities:

- [resources/js/pages/sites/rooms/index.tsx](resources/js/pages/sites/rooms/index.tsx) lines 592, 599, 607, 610, 623, 660, 671, 712
- [resources/js/pages/sites/checklists/runs/[id].tsx](resources/js/pages/sites/checklists/runs/[id].tsx) lines 480, 488, 496, 533, 548, 557, 567

Both heroes sit on `bg-gradient-to-br from-primary/90 via-primary to-primary/80`, so `text-primary-foreground` is the correct theme-token equivalent of `text-white`.

The `rooms/_dialogs.tsx` (lines 479, 494) and the rose/emerald pass/fail badges in `checklists/runs/[id].tsx` (lines 154–155, 868–870, 986) sit on coloured backgrounds rather than the primary hero — for those, `text-white` is acceptable because there's no theme token that follows the rose/emerald hue. Skip those; only convert tokens on primary-coloured surfaces.

### Replacements (run a careful find/replace, do **not** use blanket replace-all)

| Old | New | Where |
|---|---|---|
| `text-white` (on primary hero) | `text-primary-foreground` | rooms/index hero, checklists/runs hero, rooms/_dialogs dialog header |
| `text-white/90` | `text-primary-foreground/90` | same |
| `text-white/80` | `text-primary-foreground/80` | same |
| `text-white/70` | `text-primary-foreground/70` | same |
| `text-white/60` | `text-primary-foreground/60` | same |
| `border-white/20` | `border-primary-foreground/20` | same |
| `bg-white/10` | `bg-primary-foreground/10` | same |
| `bg-white/20` | `bg-primary-foreground/20` | same |
| `hover:bg-white/20` | `hover:bg-primary-foreground/20` | same |
| `hover:text-white` | `hover:text-primary-foreground` | same |

**Do not touch:**
- `bg-rose-500 text-white` / `bg-emerald-500 text-white` in `checklists/runs/[id].tsx` (semantic pass/fail, theme-independent)
- `border-emerald-500 bg-emerald-500 text-white` patterns

### Acceptance
- The rooms hero and checklist-run hero render correctly in both light and dark themes, with the same contrast they currently have.
- No `text-white` references remain on `from-primary` gradients.

---

## Task 3 — Code-split the 1.27 MB `_dialogs` bundle

**Symptom.** `public/build/assets/_dialogs-BpFQy2PN.js` is `1,269.33 kB` minified (`580.15 kB` gzipped) and Vite warns at every build.

Inspection of `show.tsx` shows the page eagerly imports dialogs for every tab:

```
116: vendors/_dialogs
124: credentials/_dialogs
132: contacts/_dialogs
142: clients/_dialogs
153: rooms/_dialogs
```

Each of those dialog modules is hundreds of KB. Users open one tab at a time — eagerly bundling all five is wasteful and is the root cause of the warning.

### Fix

#### 3a. Lazy-load the per-tab dialog modules in [show.tsx](resources/js/pages/sites/show.tsx)

Switch each top-level dialog import to a `React.lazy()` + `Suspense` wrapper. Pattern:

```tsx
import { lazy, Suspense } from 'react';

const VendorsDialogs = lazy(() => import('./vendors/_dialogs').then(m => ({
    default: {
        Add: m.AddVendorDialog,
        Edit: m.EditVendorDialog,
        Show: m.ShowVendorDialog,
        Delete: m.DeleteVendorDialog,
    } as any,
})));
```

That's awkward — a cleaner pattern is to **wrap each per-tab block in `<Suspense>`** and import the dialog module from inside a small `<VendorsTab>` sub-component that the parent lazy-renders only when the tab is mounted.

Concretely: extract each tab's body into its own file (`./tabs/VendorsTab.tsx`, `./tabs/CredentialsTab.tsx`, etc.), import those at the top of `show.tsx` with `lazy()`, and render them as:

```tsx
<TabsContent value="vendors">
    <Suspense fallback={<TabSkeleton />}>
        <VendorsTab site={site} vendors={vendors} can_edit={can_edit} />
    </Suspense>
</TabsContent>
```

Each tab file imports its own dialogs — Vite then ships them in per-tab chunks instead of the monolithic `_dialogs` chunk.

#### 3b. Verify after change

```powershell
npm run build
```

The Vite warning should be gone, and you should see new chunks like `VendorsTab-*.js`, `CredentialsTab-*.js`, each well under 500 kB.

If a single tab's bundle is still over 500 kB, the next split is the dialog itself — e.g., move `ShowCredentialDialog`'s TOTP enrolment subtree into its own lazy module.

### Acceptance
- `_dialogs-*.js` is no longer the biggest chunk and is well under 500 kB.
- No regression on the Sites show page — every tab still opens and every dialog still functions.
- TSC + tests still green.

---

## Task 4 — Replace `window.alert()` in credentials index with toasts

**Symptom.** [resources/js/pages/sites/credentials/index.tsx](resources/js/pages/sites/credentials/index.tsx) lines 134, 144, 154 still use `window.alert()`. The dark theme makes these ugly OS dialogs, and the rest of the codebase (edit.tsx, portal pages, emar, ledger-panel) uses `sonner` toasts.

### Fix

At the top of `credentials/index.tsx`:

```tsx
import { toast } from 'sonner';
```

Replace the three call sites:

| Line | Old | New |
|---|---|---|
| 134 | `alert(response.status === 403 ? 'Incorrect password.' : 'Failed to reveal credential.');` | `toast.error(response.status === 403 ? 'Incorrect password.' : 'Failed to reveal credential.');` |
| 144 | `alert('Failed to reveal credential. Please check your connection and try again.');` | `toast.error('Failed to reveal credential. Please check your connection and try again.');` |
| 154 | `alert('Failed to copy to clipboard.');` | `toast.error('Failed to copy to clipboard.');` |

Then ripgrep the rest of `resources/js/pages/sites/` for any remaining `alert(` / `confirm(` and convert:

```powershell
# Should return zero matches when you're done
Get-ChildItem -Recurse resources\js\pages\sites -Filter *.tsx | Select-String "\balert\(|\bconfirm\(" | Where-Object { $_.Line -notmatch "role=\"alert\"|aria-live|setUploadError" }
```

(If `confirm()` is used for destructive actions that warrant a dialog, replace those with the existing `AlertDialog` primitive in `@/components/ui/alert-dialog` instead of a toast.)

### Acceptance
- No `window.alert` / `window.confirm` calls remain inside `resources/js/pages/sites/`.
- The credential reveal/copy failure paths surface via toast in both themes.
- Test suite still green.

---

## Task 5 — Thread `SiteRecommendedDocuments` from backend through to the empty states

**Symptom.** Three empty-state suggestion lists are currently hardcoded in the React pages:

- [resources/js/pages/sites/documents.tsx](resources/js/pages/sites/documents.tsx) — `suggestedDocumentCategory()` (lines 87–105) maps keys to categories inline
- [resources/js/pages/sites/hazards/index.tsx](resources/js/pages/sites/hazards/index.tsx) — `HAZARD_EMPTY_STATE_SUGGESTIONS` (lines 121–167) is hardcoded
- [resources/js/pages/sites/checklists/index.tsx](resources/js/pages/sites/checklists/index.tsx) — `CHECKLIST_EMPTY_STATE_SUGGESTIONS` (lines 76–119) is hardcoded

The plan (Task 11d) called for [app/Support/SiteRecommendedDocuments.php](app/Support/SiteRecommendedDocuments.php) to be the single source of truth — but only the documents page consumes it via the controller. Hazards and checklists each duplicate the list in TS.

That violates DRY and means the readiness service (Task 3 in the original plan) and the empty states could drift out of sync.

### 5a. Extend the support class

Rename and broaden `SiteRecommendedDocuments` → `SiteRecommendedSetup` (or add sibling classes `SiteRecommendedHazards`, `SiteRecommendedChecklists`). Pick one of these two shapes:

**Option A (recommended): three sibling classes, one shared shape**

```
app/Support/SiteRecommendedDocuments.php      // already exists, keep as-is
app/Support/SiteRecommendedHazards.php        // new
app/Support/SiteRecommendedChecklists.php     // new
```

Each exposes `public static function forType(?string $type): array` returning the same `{ key, label, hint }` shape so the frontend can share an `EmptyStateSuggestion` component.

Move the contents of `HAZARD_EMPTY_STATE_SUGGESTIONS` into `SiteRecommendedHazards::house()` / `headOffice()` / `facility()`. Same for checklists. Keep the existing key strings (`slip_trip`, `fire_electrical`, etc.) so the create routes don't change.

**Option B: one umbrella class**

```php
SiteRecommendedSetup::documents($type) -> array
SiteRecommendedSetup::hazards($type) -> array
SiteRecommendedSetup::checklists($type) -> array
```

Less filesystem noise but a heavier class. Either is fine — Option A is more in keeping with the existing project shape.

### 5b. Pass from controllers

- `SiteHazardController::index($site)` — add `'recommendedHazards' => SiteRecommendedHazards::forType($site->type)` to the Inertia payload.
- `SiteChecklistController::indexForSite($site)` (whatever the matching controller method is — check `routes/sites.php`) — add `'recommendedChecklists' => SiteRecommendedChecklists::forType($site->type)`.
- `SiteDocumentController::index` — already passes `recommendedDocuments`; no change.

### 5c. Replace hardcoded lists with the prop

- `hazards/index.tsx`: delete `HAZARD_EMPTY_STATE_SUGGESTIONS`, accept `recommendedHazards` from `usePage<PageProps>()`, render it in the empty state.
- `checklists/index.tsx`: same for `CHECKLIST_EMPTY_STATE_SUGGESTIONS`.

While you're in `documents.tsx`, move `suggestedDocumentCategory()` to the backend too — make `SiteRecommendedDocuments::item()` return `category` alongside `key/label/hint`, and have the controller pass it through. Then drop the inline JS mapping.

### 5d. Tests

Add a quick feature test asserting the three lists are passed:

```php
test('site hazards index passes recommended hazards', function () {
    // ... setup ...
    $this->get("/sites/{$site->id}/hazards")
        ->assertInertia(fn ($page) =>
            $page->has('recommendedHazards', fn ($list) =>
                $list->where('0.key', 'slip_trip')
            )
        );
});
```

### Acceptance
- `HAZARD_EMPTY_STATE_SUGGESTIONS` and `CHECKLIST_EMPTY_STATE_SUGGESTIONS` are deleted from the React pages.
- Hazards and checklists empty states render the same content as today, but driven by the controller prop.
- Adding a new recommended hazard requires only a PHP edit, not a TS edit.

---

## Task 6 — Switch `Site::firstOrCreate` to `updateOrCreate` in named-site seeders

**Symptom.** [database/seeders/SystemCatalogSeeder.php](database/seeders/SystemCatalogSeeder.php) lines 18–42 use `Site::firstOrCreate(['name' => ...], [...])`. If a Kauri House or Harbour Respite record already exists from an older seed run, the attributes in the second array — including `region` — are **not** applied. The backfill migration covered region specifically, but the pattern is brittle for future fields.

### Fix

Convert both blocks to `updateOrCreate`:

```php
$siteA = Site::updateOrCreate(
    ['name' => 'Kauri House'],
    [
        'address_line_1' => '12 Kauri Street',
        'suburb' => 'Grey Lynn',
        'city' => 'Auckland',
        'region' => 'Auckland',
        'postcode' => '1021',
        'country' => 'New Zealand',
        'is_active' => true,
    ]
);
```

Same for `Site::updateOrCreate(['name' => 'Harbour Respite'], ...)`.

**Caveat:** `updateOrCreate` will overwrite any manual changes a tester made to those records. For seed data that's the intent — seeders should be reproducible. Make sure the rest of the test suite doesn't depend on stale field values.

While you're in there, audit the other seeders that create sites by `firstOrCreate`:

- `database/seeders/DuskDatabaseSeeder.php` (QA Main Site)
- `database/seeders/FleetDemoSeeder.php` (Demo Site)
- `database/seeders/FleetManagementSeeder.php` (Main Site)

Same conversion — `firstOrCreate` → `updateOrCreate` — so seeded sites stay current with the seeder definition.

`RosteringProductionDemoSeeder.php`'s `site()` helper already uses `forceFill()->save()`, which is effectively the same thing. Leave it.

### Acceptance
- Re-running `php artisan db:seed --class=SystemCatalogSeeder` on an existing database now refreshes the address/region of the canonical houses, rather than silently keeping stale data.
- `php artisan test --filter=Sites` still green.

---

## Task 7 — Document the `SiteDamage` tenant_id auto-fill (no code change)

The audit flagged that [app/Models/SiteDamage.php](app/Models/SiteDamage.php) gained a `booted()` hook in this work that wasn't in the plan:

```php
protected static function booted(): void
{
    static::creating(function (self $damage): void {
        if ($damage->tenant_id === null && $damage->site_id !== null) {
            $damage->tenant_id = Site::query()->whereKey($damage->site_id)->value('tenant_id');
        }
    });
}
```

This is an **incidental fix** consistent with the project memory "fix errors/warnings found during verification, don't dismiss as pre-existing." Either:

- **Keep it (recommended).** Add a one-line PHPDoc above the hook explaining why ("Multi-tenant safety: damages were occasionally created with null tenant_id when the site-id was supplied on its own.") and leave a passing test that asserts the auto-fill works.
- **Revert it** if you want this work strictly scoped to the original plan.

Pick the first option unless instructed otherwise. Add a feature test in [tests/Feature/Sites/SiteDamageTest.php](tests/Feature/Sites/SiteDamageTest.php) along the lines of:

```php
test('creating a damage report inherits tenant_id from the site when omitted', function () {
    $site = Site::factory()->create(['tenant_id' => 99]);
    $damage = SiteDamage::create([
        'site_id' => $site->id,
        'description' => 'Cracked window',
    ]);
    expect($damage->tenant_id)->toBe(99);
});
```

### Acceptance
- The booted hook has a brief PHPDoc.
- A feature test covers the auto-fill behaviour.

---

## Suggested implementation order

1. **Task 1** (rename labels) — single-file change, immediately reduces user confusion.
2. **Task 4** (credentials toasts) — three line changes, no risk.
3. **Task 6** (`updateOrCreate` seeders) — small, isolated.
4. **Task 7** (SiteDamage doc + test) — quick.
5. **Task 2** (`text-white` → `text-primary-foreground`) — search/replace with care.
6. **Task 5** (Recommended* support classes) — touches three pages + three controllers, do as one unit.
7. **Task 3** (code-split `_dialogs`) — biggest change, lands last. Run `npm run build` before and after; record the bundle sizes.

## Final verification

```powershell
php artisan test --filter=Sites
npx tsc --noEmit
npm run build
```

Manual smoke:

- `/sites/{house-id}` shows `Bedroom occupancy` + `Total bedrooms` (Task 1).
- Rooms hero on `/sites/{id}/rooms` and a running checklist on `/sites/{id}/checklists/runs/{run}` render identically to today (Task 2).
- `/sites/{id}/credentials` triggers a toast (not an OS dialog) when reveal fails (Task 4).
- `/sites/{id}/hazards` and `/sites/{id}/checklists` empty states still render their NZ suggestion list, but no longer have hardcoded data in the page file (Task 5).
- `npm run build` no longer warns about `_dialogs-*.js > 500 kB` (Task 3).
