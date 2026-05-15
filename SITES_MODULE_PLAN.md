# Sites & Locations Module — Implementation Plan (for GPT‑5.5)

**Target:** Oblivion Findings Laravel/Inertia/React app at `C:\Users\steph\Herd\oblivionfindings`.
**Scope:** Web **Sites & Locations** module only (`/sites/*`). Do **not** redesign the rest of the app, do **not** touch mobile, and **preserve** the current dark polished look/feel.

---

## Ground rules (read first, apply everywhere)

1. **Module URL is `/sites`**, not `/operations/sites`. All deep links use `/sites/{id}/...`.
2. **NZ context.** This is a Supported Living CRM for New Zealand — use NZD, NZ English (`organisation`), Ministry of Health/NASC framing, NZ phone format, dd/mm/yyyy. **Never** use NDIS terminology.
3. **Theme tokens only.** Use the existing CSS variables: `primary`, `primary-foreground`, `muted`, `muted-foreground`, `border`, `card`, `accent`, `status-success`, `status-success-bg`, `status-warning`, `status-warning-bg`, `status-critical`, `status-critical-bg`, `status-info`, `status-info-bg`. **No hard-coded purple/indigo/`#XXXXXX`** in JSX/TSX. (Hex in `SitesModuleSeeder.php` event-type colours is fine — that data is consumed by the calendar, not styling.)
4. **Sites module UI pattern** (from memory): rounded card grid + dialog‑driven CRUD; Send‑Kudos‑style 3‑column tile grid for type/category pickers. Canonical example: `resources/js/pages/sites/contacts/_dialogs.tsx` `ContactTypePicker`.
5. **Dark theme.** Everything must look right in dark mode. Test with both themes.
6. **Acceptance gates:** all the URLs below must still return 200 and the existing Sites feature tests must still pass.

Verified-good URLs that **must remain 200** after your changes:
`/sites`, `/sites?type=head_office`, `/sites?type=house`, `/sites?type=facility`, `/calendar`, `/checklists`, `/sites/inspections`, `/sites/reports`, `/vendors`; site detail subroutes `/sites/{id}/{documents,checklists,hazards,calendar,hardware,credentials,rooms,inspections,integrations}`.

Run after each task:

```bash
php artisan test --filter=Sites
npx tsc --noEmit
npm run build
```

---

## Architectural cheat‑sheet (so you don't have to re‑map the codebase)

| Concern | File |
|---|---|
| Sites index page | [resources/js/pages/sites/index.tsx](resources/js/pages/sites/index.tsx) |
| Site detail (show) | [resources/js/pages/sites/show.tsx](resources/js/pages/sites/show.tsx) |
| Wizard shared steps | [resources/js/pages/sites/_wizard.tsx](resources/js/pages/sites/_wizard.tsx) |
| Wizard host (create) | [resources/js/pages/sites/create.tsx](resources/js/pages/sites/create.tsx) |
| Wizard host (edit) | [resources/js/pages/sites/edit.tsx](resources/js/pages/sites/edit.tsx) |
| Wizard stepper component | [resources/js/components/wizard-stepper.tsx](resources/js/components/wizard-stepper.tsx) |
| Sites controller (index/show) | [app/Http/Controllers/SiteController.php](app/Http/Controllers/SiteController.php) |
| Rooms page | [resources/js/pages/sites/rooms/index.tsx](resources/js/pages/sites/rooms/index.tsx) |
| Documents page | [resources/js/pages/sites/documents.tsx](resources/js/pages/sites/documents.tsx) |
| Hazards index | [resources/js/pages/sites/hazards/index.tsx](resources/js/pages/sites/hazards/index.tsx) |
| Checklists index | [resources/js/pages/sites/checklists/index.tsx](resources/js/pages/sites/checklists/index.tsx) |
| Site model | [app/Models/Site.php](app/Models/Site.php) |
| Site factory | [database/factories/SiteFactory.php](database/factories/SiteFactory.php) |
| Sites module seeder | [database/seeders/SitesModuleSeeder.php](database/seeders/SitesModuleSeeder.php) |
| Seeders that create named sites | `SystemCatalogSeeder.php`, `DuskDatabaseSeeder.php`, `RosteringProductionDemoSeeder.php`, `FleetDemoSeeder.php`, `FleetManagementSeeder.php` |
| Sites feature tests | `tests/Feature/Sites/*` |

---

## Task 1 — Sites index: fill/derive missing NZ region values

**Symptom.** Only Harbour Respite shows Region = Waikato. Kauri House, QA Main Site, and Rostering E2E houses show `—` despite having Auckland/Wellington addresses. Region filter is unusable because backed by `Site::region` and most rows are null.

**Root cause.** Seeders create sites with `city` but never set `region`. The index pulls `region` raw from the DB ([SiteController.php:71,85](app/Http/Controllers/SiteController.php#L71-L85)).

### Fix

**1a. Add a single source of truth: NZ city → region map.**

Create `app/Support/NzRegions.php`:

```php
<?php

namespace App\Support;

class NzRegions
{
    /** Canonical NZ regions (display labels). */
    public const REGIONS = [
        'Northland', 'Auckland', 'Waikato', 'Bay of Plenty', 'Gisborne',
        "Hawke's Bay", 'Taranaki', 'Manawatū-Whanganui', 'Wellington',
        'Tasman', 'Nelson', 'Marlborough', 'West Coast', 'Canterbury',
        'Otago', 'Southland',
    ];

    /**
     * Heuristic: derive a region from a NZ city/suburb string.
     * Returns null when no confident match.
     */
    public static function fromCity(?string $city): ?string
    {
        if (! $city) return null;
        $needle = mb_strtolower(trim($city));

        $cityToRegion = [
            // Auckland
            'auckland' => 'Auckland', 'manukau' => 'Auckland', 'north shore' => 'Auckland',
            'waitakere' => 'Auckland', 'papakura' => 'Auckland', 'devonport' => 'Auckland',
            'grey lynn' => 'Auckland', 'ponsonby' => 'Auckland', 'mt eden' => 'Auckland',
            'henderson' => 'Auckland', 'takapuna' => 'Auckland', 'albany' => 'Auckland',
            // Waikato
            'hamilton' => 'Waikato', 'cambridge' => 'Waikato', 'te awamutu' => 'Waikato',
            'huntly' => 'Waikato', 'thames' => 'Waikato', 'tokoroa' => 'Waikato',
            // Bay of Plenty
            'tauranga' => 'Bay of Plenty', 'rotorua' => 'Bay of Plenty',
            'whakatane' => 'Bay of Plenty', 'mount maunganui' => 'Bay of Plenty',
            // Wellington
            'wellington' => 'Wellington', 'lower hutt' => 'Wellington', 'porirua' => 'Wellington',
            'upper hutt' => 'Wellington', 'kapiti' => 'Wellington', 'te aro' => 'Wellington',
            // Canterbury
            'christchurch' => 'Canterbury', 'rangiora' => 'Canterbury', 'ashburton' => 'Canterbury',
            'timaru' => 'Canterbury',
            // Otago / Southland
            'dunedin' => 'Otago', 'queenstown' => 'Otago', 'oamaru' => 'Otago',
            'invercargill' => 'Southland', 'gore' => 'Southland',
            // Northland
            'whangarei' => 'Northland', 'kerikeri' => 'Northland', 'kaitaia' => 'Northland',
            // Others
            'gisborne' => 'Gisborne', 'napier' => "Hawke's Bay", 'hastings' => "Hawke's Bay",
            'new plymouth' => 'Taranaki', 'palmerston north' => 'Manawatū-Whanganui',
            'whanganui' => 'Manawatū-Whanganui', 'nelson' => 'Nelson', 'blenheim' => 'Marlborough',
            'greymouth' => 'West Coast', 'westport' => 'West Coast',
        ];

        if (isset($cityToRegion[$needle])) {
            return $cityToRegion[$needle];
        }
        foreach ($cityToRegion as $key => $region) {
            if (str_contains($needle, $key)) return $region;
        }
        return null;
    }
}
```

**1b. Site model: derive region when the column is null.**

In [app/Models/Site.php](app/Models/Site.php), add an accessor named so it doesn't collide with the existing `region` column:

```php
use App\Support\NzRegions;

public function getResolvedRegionAttribute(): ?string
{
    return $this->region ?: NzRegions::fromCity($this->city ?: $this->suburb);
}
```

Append `'resolved_region'` to `$appends` so it's available to Inertia payloads, **and** update [SiteController::index](app/Http/Controllers/SiteController.php) to read it.

In `SiteController::index`:

- Replace the `->where('region', $region)` filter with one that matches **either** the stored column or the derived value. Cleanest path: only filter on the stored column server‑side (after the backfill in 1c, this is fine), but **also** compute the `regions` filter list from the union of stored + derived values:

```php
$visibleSites = (clone $visibleSitesQuery)->get(['region', 'city', 'suburb']);
$regions = $visibleSites
    ->map(fn ($s) => $s->region ?: \App\Support\NzRegions::fromCity($s->city ?: $s->suburb))
    ->filter()
    ->unique()
    ->sort()
    ->values();
```

- In the `$sites` payload, set `'region' => $site->region ?: NzRegions::fromCity(...)` so the index always shows a region when one is derivable.

**1c. Add a one‑shot data backfill migration.**

Create `database/migrations/2026_05_14_000001_backfill_sites_region.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use App\Support\NzRegions;

return new class extends Migration {
    public function up(): void
    {
        DB::table('sites')
            ->whereNull('region')
            ->orWhere('region', '')
            ->orderBy('id')
            ->chunkById(200, function ($rows): void {
                foreach ($rows as $row) {
                    $derived = NzRegions::fromCity($row->city ?: $row->suburb);
                    if ($derived) {
                        DB::table('sites')->where('id', $row->id)->update(['region' => $derived]);
                    }
                }
            });
    }

    public function down(): void
    {
        // no-op: don't unset region data
    }
};
```

**1d. Update seeders to set region directly.**

In each of the following, add `'region' => '<derived>'` to the site array:

- [database/seeders/SystemCatalogSeeder.php](database/seeders/SystemCatalogSeeder.php) — Kauri House (`Auckland`), Harbour Respite (`Auckland`). Harbour Respite is Devonport, not Waikato; if a test asserts Waikato somewhere, update the assertion.
- [database/seeders/DuskDatabaseSeeder.php](database/seeders/DuskDatabaseSeeder.php) — QA Main Site (`Auckland`).
- [database/seeders/RosteringProductionDemoSeeder.php](database/seeders/RosteringProductionDemoSeeder.php) — the `site()` helper builds Wellington sites; add `'region' => 'Wellington'`.
- [database/factories/SiteFactory.php](database/factories/SiteFactory.php) — set `'region' => fake()->randomElement(\App\Support\NzRegions::REGIONS)`.

**1e. Wizard Address step.** In [_wizard.tsx StepAddress](resources/js/pages/sites/_wizard.tsx), change the Region field from free‑text input to a **`<Select>`** of `NzRegions::REGIONS`. Pass the list from the controller's `index/edit/create` props as `regionOptions`. Keep the value optional. When the user picks a city that maps to a region, **pre‑fill** the Region select.

### Acceptance
- Every Active site on `/sites` shows a Region (either stored or derived).
- The Region filter dropdown lists ≥2 NZ regions and filters correctly.
- `php artisan migrate` + `php artisan db:seed` produces sites with regions populated.

---

## Task 2 — Sites index: surface operational signals

**Goal.** Move `/sites` from "static list of names" to "operations triage view." The current columns are Site / Type / Region / Risk / Status / Actions ([index.tsx:271–278](resources/js/pages/sites/index.tsx#L271-L278)).

### 2a. Backend: enrich the `$sites` payload

In `SiteController::index`, withCount and select counts so the index doesn't N+1:

```php
$sites = (clone $visibleSitesQuery)
    ->withCount([
        'clients as clients_count' => fn ($q) => $q->where('status', 'active'),
        'contacts as contacts_count',
        'documents as documents_count',
        'houseRooms as rooms_total',
        'houseRooms as rooms_occupied' => fn ($q) => $q->whereNotNull('assigned_client_id')->active(),
        'hazards as open_hazards_count' => fn ($q) => $q->whereIn('status', ['open', 'in_progress']),
        // checklists overdue: assignments with last_run older than frequency window
    ])
    // ... existing selects + relations
    ->get([...existing columns]);
```

Compute `vacancies = rooms_total - rooms_occupied`. Add a simple readiness score per site (re‑use Task 3's helper — put it on the model so both index and show use it):

```php
// Site::operationalReadiness(): array { critical_total, critical_done, recommended_total, recommended_done, missing_critical: [keys], score: 0..100 }
```

Surface this in the index payload as `readiness`.

### 2b. Frontend: add summary chips + saved views above the table

Above the existing filter row in [index.tsx](resources/js/pages/sites/index.tsx), add a horizontally scrollable row of **filter chips** ("saved views") that drive the existing URL query state:

| Chip | Filter applied |
|---|---|
| High risk | `?risk=high_risk` |
| Audit overdue | `?audit=overdue` |
| Open hazards | `?hazards=open` |
| Open maintenance | `?maintenance=open` |
| Active but incomplete | `?readiness=incomplete` |
| Respite locations | `?service=respite` |
| Inactive | `?status=inactive` |

Add the matching server-side `when()` clauses in `SiteController::index`. Each chip shows the matching count from the unfiltered visible set so users see the volume before clicking.

### 2c. Frontend: extend the table columns

Replace the table head with: **Site** • Type • Region • **Capacity** (`{occupied}/{total} · {vacancies} vac.`) • **Clients** • Site Lead • **Open hazards** (red badge if >0) • **Overdue** (red badge for `overdue_checklists + overdue_maintenance`) • Risk • **Readiness** (donut/dot with tooltip listing missing items) • Status • Actions.

Hide low‑priority columns at smaller breakpoints exactly as the current code does (`hidden md:table-cell`, etc.). Keep Site / Type / Status / Actions on all sizes.

### 2d. Card on hero row: counts of triage states

Reuse `<FleetHero>` and replace the existing stats with: Total, Active, **Active but incomplete**, **Open hazards (sum)**, **Audit overdue (sum)**.

### Acceptance
- The table shows occupancy and open hazard counts without N+1 queries.
- Clicking each filter chip narrows the table and round‑trips through the URL.
- "Active but incomplete" surfaces sites that have `is_active = true` but `missing_critical` non‑empty.

---

## Task 3 — Site overview: operational readiness panel

**Symptom.** Harbour Respite shows Active but has Phone, Email, Site lead, Manager phone, After hours, Emergency plan, Medication storage all "Not specified", 0 Contacts/Documents/Clients/Assets, no geofence, no notes — yet there's no warning anywhere on the overview.

**Foundation already exists.** [SiteController::show](app/Http/Controllers/SiteController.php#L195-L247) already builds a `$checklist` array — extend it, don't duplicate.

### 3a. Refactor readiness into a service

Create `app/Services/Sites/SiteReadinessService.php`:

```php
namespace App\Services\Sites;

use App\Models\Site;

class SiteReadinessService
{
    public function evaluate(Site $site): array
    {
        $critical = [
            $this->item('contact_phone', 'Site phone',                 'Sites', filled($site->phone), 'add_phone'),
            $this->item('contact_email', 'Site email',                 'Sites', filled($site->email), 'add_email'),
            $this->item('site_lead',     'Site lead / manager',        'Sites', filled($site->primary_contact_user_id) || filled($site->manager_name), 'assign_lead'),
            $this->item('after_hours',   'After-hours / on-call line', 'Sites', filled($site->after_hours_phone), 'add_after_hours'),
            $this->item('emergency_plan','Emergency plan & assembly point', 'Sites', filled($site->emergency_plan_location), 'add_emergency_plan'),
            $this->item('med_storage',   'Medication storage details', 'Sites', filled($site->medication_storage_location), 'add_med_storage'),
            $this->item('emergency_contact', 'At least one emergency / maintenance contact', 'Sites', $site->contacts()->whereIn('type', ['emergency','maintenance','manager'])->exists(), 'add_contact'),
        ];

        $recommended = [
            $this->item('required_docs',  'Required documents uploaded (evacuation map, fire safety, etc.)', 'Sites', $site->documents()->count() > 0, 'upload_doc'),
            $this->item('rooms_configured','Capacity / rooms configured', 'Sites', $this->roomsConfigured($site), 'configure_rooms'),
            $this->item('hazards_reviewed','Hazards reviewed in last 90 days', 'Sites', $site->hazards()->where('updated_at', '>=', now()->subDays(90))->exists() || $site->hazards()->doesntExist() === false, 'review_hazards'),
            $this->item('checklists_scheduled', 'At least one checklist scheduled', 'Sites', $site->checklistAssignments()->exists(), 'schedule_checklist'),
            $this->item('geofence',       'Geofence configured', 'Sites', $site->geofences()->where('is_active', true)->exists(), 'configure_geofence'),
        ];

        $criticalDone = collect($critical)->where('done', true)->count();
        $recommendedDone = collect($recommended)->where('done', true)->count();
        $criticalTotal = count($critical);
        $recommendedTotal = count($recommended);

        return [
            'critical' => $critical,
            'recommended' => $recommended,
            'critical_done' => $criticalDone,
            'critical_total' => $criticalTotal,
            'recommended_done' => $recommendedDone,
            'recommended_total' => $recommendedTotal,
            'missing_critical' => collect($critical)->where('done', false)->pluck('key')->values()->all(),
            'score' => (int) round(
                ($criticalDone * 2 + $recommendedDone) /
                max(1, ($criticalTotal * 2 + $recommendedTotal)) * 100
            ),
            'is_active' => (bool) $site->is_active,
            'is_active_but_incomplete' => $site->is_active && $criticalDone < $criticalTotal,
        ];
    }

    private function item(string $key, string $label, string $area, bool $done, string $action): array
    {
        return compact('key', 'label', 'area', 'done', 'action');
    }

    private function roomsConfigured(Site $site): bool
    {
        return match ($site->type) {
            'house', 'residential' => $site->houseRooms()->active()->exists(),
            'head_office'          => $site->hoResources()->active()->exists(),
            'facility'             => $site->facilityZones()->active()->exists(),
            default                => true,
        };
    }
}
```

Replace the inline `$checklist` builder in `SiteController::show` with a call to this service. Pass `'readiness' => $service->evaluate($site)` to Inertia. Also expose a slim version from `index()` for Task 2's `missing_critical`/score.

### 3b. Frontend readiness panel

Create `resources/js/pages/sites/_readiness-panel.tsx`. Render it **above the existing Overview tab cards** and **also** as a yellow chip in the hero when `is_active_but_incomplete`.

```tsx
// shape only — wire the actions to existing edit dialogs in show.tsx
type ReadinessItem = { key: string; label: string; done: boolean; action: string };
type Props = {
  readiness: {
    critical: ReadinessItem[]; critical_done: number; critical_total: number;
    recommended: ReadinessItem[]; recommended_done: number; recommended_total: number;
    score: number; is_active: boolean; is_active_but_incomplete: boolean;
  };
  onAction: (action: string) => void;
};
```

Layout:

```
┌─ Site readiness ─────────────────────────────────┐
│  ◷ 60/100   "Active but incomplete" (warning)    │
│                                                  │
│  Critical (3/7 done) — must fix                  │
│   ✓ Site phone                                   │
│   ✓ Site email                                   │
│   ⚠ Site lead / manager   [Assign lead]          │
│   ⚠ After-hours / on-call line   [Add number]    │
│   …                                              │
│                                                  │
│  Recommended (1/5)                               │
│   ⚠ Required documents uploaded   [Upload]       │
│   …                                              │
└──────────────────────────────────────────────────┘
```

- Critical items not done: `border-status-critical/30 bg-status-critical-bg`. Recommended: `border-status-warning/30 bg-status-warning-bg`. Done: muted with check.
- Score chip: donut/ring using `--status-success` (≥90), `--status-warning` (50–89), `--status-critical` (<50).
- The "Active but incomplete" hero chip is `border-status-warning/40 bg-status-warning-bg text-status-warning` and clicking it scrolls to the readiness panel.

### 3c. Wire `onAction` to existing dialogs

Map keys to handlers already in `show.tsx`:

| `action` | Handler |
|---|---|
| `add_phone`, `add_email`, `add_after_hours` | `setContactInfoOpen(true)` |
| `assign_lead` | `setContactInfoOpen(true)` (the dialog exposes the manager picker) |
| `add_emergency_plan`, `add_med_storage` | `setSafetyOpen(true)` |
| `add_contact` | open existing `AddContactDialog` |
| `upload_doc` | navigate to `/sites/{id}/documents?action=upload` (Task 11 adds the trigger) |
| `configure_rooms` | navigate to `/sites/{id}/rooms?action=add` |
| `configure_geofence` | `setLocationOpen(true)` (the location dialog already manages geofences) |
| `review_hazards` | navigate to `/sites/{id}/hazards` |
| `schedule_checklist` | open existing assign-checklist dialog (already on `checklists/index.tsx`) |

### Acceptance
- Visiting Harbour Respite shows the readiness panel with all 7 critical items flagged.
- The hero shows a yellow "Active but incomplete" chip.
- Clicking each missing item opens the correct dialog or navigates to the correct page.

---

## Task 4 — Inline fix actions next to missing fields

**Goal.** "Not specified" should never be a dead end on an Active site. Convert the existing `ContactRow` and the safety/location "Not specified" placeholders into actionable buttons.

### Change

In [show.tsx ContactRow](resources/js/pages/sites/show.tsx#L527-L564) (and the inline blocks at lines 1576–1580, 1670–1674, 1687–1691), replace:

```tsx
<span className="font-normal text-muted-foreground italic">Not specified</span>
```

with:

```tsx
<MissingFieldButton onClick={onFix} label={`Add ${label.toLowerCase()}`} />
```

Create `resources/js/components/missing-field-button.tsx`:

```tsx
import { Plus } from 'lucide-react';

export function MissingFieldButton({ onClick, label, disabled }: {
    onClick?: () => void; label: string; disabled?: boolean;
}) {
    if (!onClick || disabled) {
        return <span className="font-normal italic text-muted-foreground">Not specified</span>;
    }
    return (
        <button
            type="button"
            onClick={onClick}
            className="inline-flex items-center gap-1 rounded-md border border-dashed border-status-warning/40 bg-status-warning-bg/40 px-2 py-0.5 text-xs font-medium text-status-warning hover:bg-status-warning-bg"
        >
            <Plus className="h-3 w-3" />
            {label}
        </button>
    );
}
```

Pass `onFix` from the page based on the field (Phone/Email/Manager phone/After hours → `setContactInfoOpen`, Emergency plan/Med storage → `setSafetyOpen`). When the user lacks `can_edit`, fall back to the plain italic placeholder.

### Acceptance
- Every "Not specified" row on Overview is either a clickable amber button (edit allowed) or a muted italic label (view-only).
- Buttons open the correct existing dialog.

---

## Task 5 — Site detail tab bar: overflow / responsive

**Symptom.** The TabsList ([show.tsx:1299](resources/js/pages/sites/show.tsx#L1299)) uses `overflow-x-auto` but at normal desktop widths the right‑side tabs (Services, Shift Coverage, Staff Requirements, Rooms…) run past the visible area without indication.

### Fix

1. Wrap the `TabsList` in a relative container with **fade gradients** on both edges to hint scrollability:

```tsx
<div className="relative -mx-1 px-1">
  <div className="pointer-events-none absolute inset-y-0 left-0 w-6 bg-gradient-to-r from-background to-transparent z-10" />
  <div className="pointer-events-none absolute inset-y-0 right-0 w-6 bg-gradient-to-l from-background to-transparent z-10" />
  <TabsList className="scrollbar-pretty flex h-auto w-full justify-start gap-1 overflow-x-auto rounded-none border-b bg-transparent p-0 pb-1">
    ...
  </TabsList>
</div>
```

Hide the fades using IntersectionObserver / `useRef` when scrolled to the relevant edge (use `getBoundingClientRect` on a sentinel inside the scroll container).

2. Add a **"More" overflow dropdown** on screens where there is not enough room. Use the existing `DropdownMenu` primitive. Move the last N tabs into it when `containerWidth < contentWidth`. Tabs collapsed into "More" should still receive `data-state=active` styling when their `value` matches the active tab.

3. Ensure each `TabsTrigger` keeps `shrink-0` (already present), `whitespace-nowrap` to prevent truncated labels, and that keyboard focus rings (`focus-visible:ring-2 focus-visible:ring-ring`) remain visible.

### Acceptance
- At 1280px viewport, no tab label is clipped; either the scroll fade is visible or a "More" dropdown holds overflowing tabs.
- Tab → arrow key navigation still works; the active tab in the "More" menu is highlighted.

---

## Task 6 — Wizard: label all 8 steps

**Root cause.** [wizard-stepper.tsx](resources/js/components/wizard-stepper.tsx#L14) sets `const compact = steps.length > 4`, then `const showLabel = compact ? isCurrent : true`. Because the wizard has 8 steps, **all non‑current labels are hidden** — that's why only "Basics" was visible.

### Fix

Replace the stepper component so all 8 step labels render even in compact mode. Pattern:

- **Desktop (≥sm):** show every label, truncated, beneath each circle (vertical stack), with the connector line above. Use `text-[11px]` for the labels and `min-w-0 truncate`. This eliminates the "only Basics is labelled" problem on a normal desktop.
- **Mobile (<sm):** keep only the active step's label visible (current behaviour is fine here because of width constraints).

```tsx
// resources/js/components/wizard-stepper.tsx
import { Check } from 'lucide-react';

export type WizardStep = { key: string; label: string };

export default function WizardStepper({ steps, current }: { steps: WizardStep[]; current: number }) {
  return (
    <ol className="flex w-full min-w-0 items-start gap-1" aria-label="Progress">
      {steps.map((step, i) => {
        const isComplete = i < current;
        const isCurrent = i === current;
        return (
          <li key={step.key} className="flex min-w-0 flex-1 flex-col items-center gap-1.5">
            <div className="flex w-full items-center gap-1.5">
              <span
                className={`flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-xs font-semibold ${
                  isComplete ? 'bg-status-success text-white'
                  : isCurrent ? 'bg-primary text-primary-foreground'
                  : 'bg-muted text-muted-foreground'
                }`}
                aria-current={isCurrent ? 'step' : undefined}
              >
                {isComplete ? <Check className="h-4 w-4" /> : i + 1}
              </span>
              {i < steps.length - 1 && (
                <span className={`h-0.5 flex-1 rounded-full ${isComplete ? 'bg-status-success' : 'bg-muted'}`} />
              )}
            </div>
            <span
              className={`hidden w-full truncate text-center text-[11px] font-medium sm:block ${
                isCurrent ? 'text-foreground' : 'text-muted-foreground'
              }`}
              title={step.label}
            >
              {step.label}
            </span>
          </li>
        );
      })}
    </ol>
  );
}
```

The current `STEPS` array in [_wizard.tsx:223](resources/js/pages/sites/_wizard.tsx#L223) already has decent labels; **update them** to the canonical names below so they line up with the recommended set:

```ts
export const STEPS: WizardStep[] = [
    { key: 'basics',     label: 'Basics' },
    { key: 'address',    label: 'Address' },
    { key: 'rooms',      label: 'Rooms / Resources' },
    { key: 'contacts',   label: 'Contacts' },
    { key: 'assets',     label: 'Assets' },
    { key: 'documents',  label: 'Documents' },
    { key: 'checklists', label: 'Checklists' },
    { key: 'safety',     label: 'Safety & Review' },
];
```

### Acceptance
- On `/sites/create` at desktop width, all 8 step labels are visible under the circles.
- At <640px, only the active step's label is visible.
- Existing test `SiteNavigationRoutesTest.php` still passes.

---

## Task 7 — Wizard: clarify saving and navigation

**Symptom.** Edit page says "Documents save instantly; everything else saves on submit" while the visible button only says "Next".

### Fix

In both [create.tsx](resources/js/pages/sites/create.tsx) and [edit.tsx](resources/js/pages/sites/edit.tsx):

1. **Rename the Next button dynamically** to advertise the next step:

   ```tsx
   {step < STEPS.length - 1 ? (
       <Button size="lg" onClick={goNext} ...>
           Next: {STEPS[step + 1].label}
           <ArrowRight className="ml-1.5 h-4 w-4" />
       </Button>
   ) : ( ...Save changes / Create site... )}
   ```

2. **Helper text under the button strip**, edit page only:

   ```tsx
   <p className="text-xs text-muted-foreground">
     Documents save instantly. Everything else is saved when you finish the wizard.
   </p>
   ```

3. **Cancel guard.** Replace the bare `<Link>` with a `Button` that opens a confirm dialog **only when the form is dirty**. For `useForm` (edit page), use the built‑in `isDirty`; for the local `useState` (create page), compare to `initialData` using a JSON.stringify equality check.

   ```tsx
   const [confirmCancel, setConfirmCancel] = useState(false);
   const onCancel = () => (isDirty ? setConfirmCancel(true) : router.visit(cancelHref));
   ```

   Dialog body: "Unsaved changes will be lost. Continue cancelling?" Buttons: "Keep editing" / "Discard changes" (the latter `variant="destructive"`).

### Acceptance
- Next button reads "Next: Address →", "Next: Rooms / Resources →", etc.
- Cancelling a dirty wizard prompts before navigating away.

---

## Task 8 — Wizard: validation feedback

**Symptom.** Clicking Next on Step 1 with Site name blank sets an error in state but doesn't move focus or announce it.

### Fix

In [create.tsx goNext](resources/js/pages/sites/create.tsx#L115-L124) and [edit.tsx goNext](resources/js/pages/sites/edit.tsx#L229-L238):

1. Add `useRef`s for the inputs (or query them by `id` from the document).
2. When validation fails, **focus the first invalid field** and `scrollIntoView({ behavior: 'smooth', block: 'center' })`.
3. Add an `aria-live="assertive"` region that briefly reads the error text:

   ```tsx
   <p role="alert" aria-live="assertive" className="sr-only">{errors.name ?? ''}</p>
   ```

4. On the `Input` itself, set `aria-invalid={!!errors.name}` and `aria-describedby="name-error"`. Make the error message element have `id="name-error"`.
5. Add visible red ring on invalid inputs: `aria-invalid:border-status-critical aria-invalid:ring-1 aria-invalid:ring-status-critical/40` (Tailwind v4 supports `aria-invalid:` variant — verify in this repo's Tailwind setup).
6. Audit all Step components in `_wizard.tsx` and apply the same pattern (`StepAddress`, `StepRoomsOrResources`, `StepContacts`, etc.).

### Acceptance
- With Site name blank, clicking Next focuses the Site name input, scrolls to it, shows a red border + error text, and an aria‑live announcement fires once.
- The error clears when the user types.

---

## Task 9 — Wizard: required/optional indicators

### Fix

1. Define a shared `<FieldLabel>` helper in `resources/js/components/field-label.tsx`:

   ```tsx
   export function FieldLabel({ htmlFor, required, optional, recommended, children }: {
       htmlFor?: string; required?: boolean; optional?: boolean; recommended?: boolean;
       children: React.ReactNode;
   }) {
       return (
           <label htmlFor={htmlFor} className="mb-1 block text-sm font-medium">
               {children}
               {required && <span className="ml-1 text-status-critical" aria-label="required">*</span>}
               {recommended && !required && (
                   <span className="ml-1 text-xs font-normal text-status-warning">recommended</span>
               )}
               {optional && !required && !recommended && (
                   <span className="ml-1 text-xs font-normal text-muted-foreground">optional</span>
               )}
           </label>
       );
   }
   ```

2. In [_wizard.tsx](resources/js/pages/sites/_wizard.tsx), apply:
   - **Required (`*`):** `name`, `type`.
   - **Recommended:** `primary_contact_user_id` (Site Lead / Manager), `phone`, `after_hours_phone`, `emergency_plan_location`, `medication_storage_location` for `type='house'`.
   - **Optional:** every other field with explicit "optional" label.

3. Replace the inline `<Label htmlFor=...>Site name <span className="text-status-critical">*</span></Label>` with `<FieldLabel htmlFor="name" required>Site name</FieldLabel>` everywhere in the wizard.

### Acceptance
- Required fields show `*`, recommended fields show "recommended" in warning colour, others show "optional".
- "Site Lead / Manager" carries the "recommended" label.

---

## Task 10 — Rooms/occupancy: meaningful seed data + surfacing

**Symptom.** Harbour Respite has one empty bedroom called "yuikri". The default seeder ([SitesModuleSeeder::seedHouseRooms](database/seeders/SitesModuleSeeder.php#L174-L198)) **already produces meaningful names** ("Bedroom 1", "Kitchen", etc.), but only when `houseRooms()->count() === 0`. The junk room is user-created and should not be wiped.

### Fix

**10a. Strengthen wizard room validation.**

In `_wizard.tsx StepRoomsOrResources`, when `data.type === 'house'`:

- Reject names matching `/^[a-z]{4,8}$/i` that aren't in a whitelist (`Bedroom`, `Kitchen`, `Lounge`, `Bathroom`, `Laundry`, `Hallway`, `Garage`, `Garden`, `Office`, `Dining`, `Living`, `Ensuite`).
- Show a friendly inline warning: "This doesn't look like a real room name. Examples: Bedroom 1, Master bedroom, Lounge, Ensuite."
- Don't block submit, only warn — users may have legitimate names.

**10b. Re-run room seeding for sites that ask for it.**

Add a button on the Rooms page header (visible when `summary.total < 3` and `can_edit`): **"Add standard rooms"**. Clicking it `POST /sites/{id}/rooms/seed-defaults`. The endpoint creates the missing standard rooms from `seedHouseRooms`'s default list using `firstOrCreate(['site_id', 'name'])`. Add the route in `routes/sites.php` and a method in `SiteRoomController`.

**10c. Surface capacity on site overview AND index.**

On the **show page Overview tab**, add a new card to the existing grid:

```
┌─ Capacity & Occupancy ─────────────────┐
│  3 occupied / 5 total · 2 vacancies    │
│  ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ 60%     │
│                                        │
│  [View rooms]                          │
└────────────────────────────────────────┘
```

Use a horizontal progress bar with `bg-status-success` segment for occupied. Compute server-side in `show()` and pass as `occupancy: { rooms_total, rooms_occupied, vacancies, percent }`. For non-house types, swap to the resource/zone equivalent and label accordingly.

On the **index table**, add the Capacity column from Task 2c.

### Acceptance
- A house site with 1 short, lowercase room name shows the inline wizard warning when re-edited.
- Clicking "Add standard rooms" creates the missing rooms (idempotent).
- The Overview tab shows the new Capacity & Occupancy card.

---

## Task 11 — Empty states: guided supported-living setup

**Goal.** Convert clean-but-passive empty states into NZ supported‑living guidance.

### 11a. Documents empty state ([documents.tsx:387–415](resources/js/pages/sites/documents.tsx#L387-L415))

Replace the "No Documents" panel with a checklist of recommended documents for the site type. Render each as a row with a faded check + label + an "Upload" button that opens the upload dialog with the title pre-filled.

Recommended documents (`type === 'house'` or `residential`):

- Evacuation plan & assembly point map
- Fire safety / smoke alarm log
- Medication storage policy / locked cabinet audit
- House rules / resident handbook
- Emergency contacts sheet
- Hazard register (current)
- Cleaning & food safety schedule
- Site induction checklist
- Most recent inspection report

For `type === 'head_office'`, swap to: Health & Safety policy, Office evacuation plan, Building WOF, Insurance certificate, etc.

For `type === 'facility'`, swap to: Equipment maintenance log, PPE register, Safe Work Method Statements, Emergency stop check log.

Store this list once in `app/Support/SiteRecommendedDocuments.php` so the empty state and the readiness check stay in sync.

### 11b. Hazards empty state ([hazards/index.tsx:541–551](resources/js/pages/sites/hazards/index.tsx#L541-L551))

Replace with the same pattern: list common hazards to check, each with a "Log this hazard" button that opens the hazard form pre-filled with the hazard type:

- Slip / trip hazards (loose mats, wet floors)
- Hot water temperature (>50°C scald risk)
- Medication storage access
- Fire / electrical (overloaded sockets, expired alarms)
- Manual handling (transfers, lifting)
- Behavioural / security
- Outdoor / gardening hazards
- Cleaning chemicals storage
- Bathroom safety (grab rails, non-slip)

Each row links to `/sites/{id}/hazards/create?type={key}`.

### 11c. Checklists empty state ([checklists/index.tsx:238–250](resources/js/pages/sites/checklists/index.tsx#L238-L250))

Replace with suggested templates as cards, each with "Schedule this":

- Site induction (one-off)
- Fire drill (quarterly)
- Medication storage audit (monthly)
- Cleaning & food safety (weekly)
- Emergency readiness check (monthly)
- Quality Home Checklist (monthly — already seeded)

Cards link to `/sites/{id}/checklists?action=assign&template={key}`. Below the suggestions, keep the existing "Manage templates" link.

### 11d. Visual style

All three empty states share the same skeleton:

```tsx
<Card className="border-dashed">
  <CardContent className="space-y-4 p-6">
    <div className="flex items-center gap-3">
      <span className="flex h-10 w-10 items-center justify-center rounded-lg bg-primary/10 text-primary">
        <Icon className="h-5 w-5" />
      </span>
      <div>
        <h3 className="font-semibold">No {thing} yet</h3>
        <p className="text-sm text-muted-foreground">
          Recommended for supported-living sites — set these up now to keep this site audit-ready.
        </p>
      </div>
    </div>
    <ul className="grid gap-2 sm:grid-cols-2">
      {suggestions.map(s => (
        <li key={s.key} className="flex items-center justify-between rounded-lg border bg-card/40 px-3 py-2">
          <div className="min-w-0">
            <p className="truncate text-sm font-medium">{s.label}</p>
            <p className="truncate text-xs text-muted-foreground">{s.hint}</p>
          </div>
          <Button size="sm" variant="outline" onClick={() => s.action()}>
            {s.cta}
          </Button>
        </li>
      ))}
    </ul>
  </CardContent>
</Card>
```

### Acceptance
- Each of Documents / Hazards / Checklists empty states renders a list of NZ-relevant suggestions with one-click CTAs that pre-fill the create form.
- The recommended-documents list is the **same** data driving the Task 3 readiness service.

---

## Extra issues spotted during code read (added to scope)

### A. Region filter dropdown can be empty
After Task 1's backfill this resolves automatically — but verify by deleting `region` on every site and reloading: filter dropdown should still list ≥1 region derived from `city`.

### B. Hard-coded white text on hero
[show.tsx:1192–1295](resources/js/pages/sites/show.tsx#L1192) uses `bg-gradient-to-br from-primary/90 via-primary to-primary/80 text-white`. That's tied to `--primary`, which is fine, but the `text-white/60`, `text-white/50` opacity-on-white reads poorly in both themes. Replace with `text-primary-foreground/80`, `text-primary-foreground/60` so it's theme-aware.

### C. SitesIndex prop shadowing
[index.tsx:155](resources/js/pages/sites/index.tsx#L155) destructures `{ sites }` from props in the function signature but then re-reads everything via `usePage<PageProps>()`. Remove the function-arg destructure and use only `usePage()` to avoid the typed mismatch (the page-arg has only `Site[]`, while the actual props include `filters`, `filterOptions`, `auth`).

### D. `Site::manager_name` vs `Site::primary_contact_user_id` ambiguity
The wizard StepBasics sets `primary_contact_user_id` via a User picker, but show.tsx ContactRow falls back to `manager_name` (a free-text field). Decide one source of truth — recommendation: `primary_contact_user_id` is canonical; `manager_name` only when there is no linked user. Task 3's readiness check already handles this with an `||`. No code change needed here unless you also want to remove the duplicate from the wizard — for now, **leave it**, just document the precedence in a code comment in `_overview-dialogs.tsx EditContactInfoDialog`.

### E. `confirm()` and `alert()` usage
[edit.tsx:284–311](resources/js/pages/sites/edit.tsx#L284-L311) uses `window.alert()` and `window.confirm()` for document upload/delete failures. These are ugly in the dark theme. Replace with the existing `sonner` toast (already imported elsewhere — verify) or a small Dialog. Out of scope to convert every callsite, but at least update these four.

### F. `SitesModuleSeeder::seedHouseRooms` skip-if-any-rooms behaviour
Currently `if ($site->houseRooms()->count() > 0) continue;` — meaning a site with one junk room ("yuikri") **never** gets the standard rooms seeded. Task 10b's "Add standard rooms" button is the user-facing escape hatch. Don't change the seeder behaviour silently.

---

## Out of scope (do **not** do)

- Re-styling other modules (Calendar, Vendors global, Checklists global) beyond what's required to keep them rendering after these changes.
- Mobile-specific layouts.
- Schema changes other than the region backfill migration in Task 1c.
- Replacing the `ContactRow` italic placeholders on screens *outside* the Overview tab — those can stay if they're not visible on Active sites with missing data.
- Touching `_overview-dialogs.tsx` markup beyond passing in the existing open/close props.

---

## Suggested implementation order

1. **Task 1** (region) — small, isolated, unblocks Task 2's filter chips.
2. **Task 6** (wizard step labels) — single component change, immediately visible improvement.
3. **Task 3 + Task 4** — together; the readiness panel reuses `MissingFieldButton`.
4. **Task 2** (index signals) — depends on the model's `operationalReadiness()` method added in Task 3.
5. **Task 5** (tab overflow) — independent, do anytime.
6. **Task 7, 8, 9** (wizard polish) — together, all touch `create.tsx` / `edit.tsx` / `_wizard.tsx`.
7. **Task 10** (rooms).
8. **Task 11** (empty states).
9. Extras A–E as cleanup.

## Final verification (run before declaring done)

```bash
php artisan migrate --pretend          # confirm 1c migration is the only pending one
php artisan migrate
php artisan db:seed --class=SystemCatalogSeeder
php artisan db:seed --class=SitesModuleSeeder
php artisan test --filter=Sites
php artisan test --filter=SiteNavigationRoutesTest
npx tsc --noEmit
npm run build
```

Manual smoke:
- `/sites` → every Active site has a Region, the filter chips work, the Capacity column shows numbers.
- `/sites/{harbour-respite-id}` → readiness panel lists 7 critical items, hero shows "Active but incomplete" chip, clicking "Assign lead" opens the contact-info dialog with the Manager picker focused.
- `/sites/create` → all 8 step labels visible at desktop width; clicking Next on step 1 with empty name focuses the input and shows an error.
- `/sites/{id}/documents` empty → renders 9 recommended-document suggestions with Upload buttons.
- Toggle dark/light theme → no white-on-white or invisible borders anywhere.
