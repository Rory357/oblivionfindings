# Resident Tracking — Live Findings, Bug Fixes, and Client-Surface UX Plan

> **For agentic workers:** Use `superpowers:subagent-driven-development` or `superpowers:executing-plans` to implement this plan task-by-task. Steps use checkbox syntax for tracking. This plan extends [resident-tracking-feature-complete-plan.md](resident-tracking-feature-complete-plan.md) with three live-server findings and a UX cleanup pass for the History page and Client navigation.

**Goal:** Fix three confirmed bugs that block Amelia's GL30MEU from rendering correctly, and ship a focused UX cleanup of the History page and the way clients navigate through their profile + tracking surfaces.

**Live environment:** Production at `oblivionfindings.com`, app at `/var/www/oblivionfindings`, currently on commit `3fbe97f5` (Unify resident tracking with client profile location tab).

---

## 1. Confirmed Live Bugs

### 1.1 Queclink parser reads field 4 as `battery` for every command — wrong for `GTFRI` on GL30MEU

**Symptom:** Amelia's pendant is healthy and reporting from IP `3.112.185.7`, but the UI shows "Low battery 0 %" and `battery_status = low`. Every `fleet_telemetry_events` row for the device has `battery_pct = 0`.

**Diagnosis:** The actual `+RESP:GTFRI` payload from the live device is:

```
+RESP:GTFRI,970204,867963069916998,,0,0,1,0,4.3,145,-105.0,175.241197,-37.723363,
20260518224343,0530,0001,A310,0017E102,24,0,4191,100,1,,,20260518233812,085F$
```

The fields after `cell_id` are:

| Idx | Value | Meaning (per protocol) |
| --- | ----- | ---------------------- |
| 18  | `24`     | `position_append_mask` (hex) — declares which optional fields follow |
| 19  | `0`      | satellites in use |
| 20  | `4191`   | **battery voltage in mV** (4.19 V) |
| 21  | `100`    | **battery percentage** |
| 22  | `1`     | charging status |
| 23  | `` (empty) | reserved |
| 24  | `` (empty) | reserved |
| 25  | `20260518233812` | send time |
| 26  | `085F` | count number |

[`AtTrackProtocolParser::normalisePayload()`](app/Services/Queclink/AtTrackProtocolParser.php:168) currently sets `payload['battery'] = $fields[4]` for every command. For `GTFRI` on GL30MEU, field 4 is the **report ID** (always `0`), not the battery. The real value at field 21 (`100`) is never read.

The same bug affects `GTSOS`, `GTLBC`, `GTMAN`, `GTSPD`, `GTHBM`, `GTGEO`, `GTGIN`, `GTGOT`, `GTHBD`, `GTPNA`, `GTPFA` — anywhere the device sends a location-style payload with a `position_append_mask`. `GTBPL` (battery-low alert) is the only command that puts the battery percentage at field 4, and that handler at [line 234](app/Services/Queclink/AtTrackProtocolParser.php:234) is correct.

**Why my "battery 0 % fix" didn't catch this:** [`FleetTelemetryIngestService`](app/Services/Fleet/FleetTelemetryIngestService.php:127) is correct — it only writes `battery_level` when `battery_pct !== null`. But the parser feeds it `0` (not null), so the ingest faithfully stores `0`. The UI then renders "Low battery 0 %" correctly for that data. The bug is upstream of the ingest.

### 1.2 Site geofence editor hardcodes `scope = 'vehicle'` — no geofences ever flow through to residents

**Symptom:** The user creates a geofence at `/sites/{id}` for Harbour Respite. It exists, it's active, it's on the right coordinates — but it never renders for residents anywhere.

**Diagnosis:** [`SiteGeofenceController::geofenceAttributes()`](app/Http/Controllers/Sites/SiteGeofenceController.php:92) hardcodes `'scope' => 'vehicle'`. Every geofence created from the Sites module is born with the wrong scope.

```php
private function geofenceAttributes(array $data, Site $site): array
{
    return [
        'asset_id' => null,
        'site_id' => $site->id,
        'name' => $data['name'],
        'type' => $data['type'],
        'scope' => 'vehicle',  // ← bug
        ...
    ];
}
```

Confirmed live: the only geofence in the database is `id=1, scope='vehicle'` for site `9004` (Harbour Respite, `type=house`).

**Knock-on effect on Amelia:**

1. `clients.house_geofence_id = NULL` for Amelia. The backfill seeder in [`BackfillHouseGeofenceSeeder`](database/seeders/BackfillHouseGeofenceSeeder.php) only matches geofences with `scope IN ('house', 'resident')` — so it skips the `scope='vehicle'` row.
2. The Fleet dashboard still renders the geofence (it now loads *all active* geofences, per `1.1` of the previous plan).
3. The Client Profile renders no geofence (it looks for `client.houseGeofence`, then falls back to `scope IN ('house','resident')`).
4. "In Zone" pill on the profile shows "Zone Unknown" because no applicable geofence was found.

### 1.3 GL30 device `model` not populated; defaults break command-family detection

**Symptom:** `devices.model = ''` for Amelia's pendant on the live DB, even though we know it's a GL30MEU. The Queclink frame includes `device_name = ''` for this unit (the GL30 is shipped with a blank name slot until set).

**Effect:** [`LocateNowService::familyForDevice`](app/Services/Queclink/LocateNowService.php) defaults to GL30M family when category is `personal_tracker` — so Locate Now still works — but downstream UI shows "Model: —" in Device Details, and any future logic that branches on model name (e.g., `Resident safety profile`) needs an explicit hint.

---

## 2. Bug Fixes (Phase A)

### Task A1 — Parser: read battery from the position-append-mask region for `GTFRI` and other location-style commands

**Files:**
- Modify: `app/Services/Queclink/AtTrackProtocolParser.php`
- Modify: `app/Services/Fleet/Telemetry/QueclinkAdapter.php` (only if we surface `battery_voltage_mv` from the new fields)
- Add: `tests/Unit/Services/Queclink/AtTrackProtocolParserGtfriTest.php`

- [ ] Remove the generic `'battery' => $fields[4]` from line 168. Set it to `null` by default; only specific handlers set it.
- [ ] For commands that share the `position_append_mask` layout (`GTFRI`, `GTSOS`, `GTLBC`, `GTMAN`, `GTSPD`, `GTHBM`, `GTGEO`, `GTGIN`, `GTGOT`, `GTHBD`, `GTPNA`, `GTPFA`, `GTRTL`, `GTDOG`, `GTVGL`, `GTTOW`), parse the trailing fields. Identify them by walking from `fields[18]` (the mask):
  - `mask_decimal = hexdec($fields[18])`
  - Build the order of trailing fields by examining mask bits (per the GL30MEUR01 @Track Air Interface Protocol). For the GL30, the relevant bit map is:
    - bit 0 = satellites in use
    - bit 1 = mileage
    - bit 2 = MCC reserved / hour meter
    - bit 3 = battery voltage (mV)
    - bit 4 = battery percentage
    - bit 5 = charging status
  - Walk the bits in ascending order, popping the next field for each set bit.
  - Without the PDF in hand, **add an empirical fallback** for the GL30 family: after the mask, scan the next 4 fields for the first value in `[0, 100]` (battery %) and the first value in `[2_500, 5_500]` (voltage mV). Persist as `battery` and `battery_voltage_mv`.
- [ ] Set `charging_status` based on the trailing charging field: `0 → 'unknown'`, `1 → 'not_charging'`, `2 → 'charging'`, `3 → 'stopped'`.
- [ ] Tests:
  - GTFRI with mask 0x24, 5 trailing fields → battery_pct = 100, voltage = 4191, charging = `not_charging`.
  - GTFRI with no trailing battery fields → battery_pct stays `null`.
  - GTBPL still reads field 4 as battery (no regression).
  - GTSOS with the same trailing layout → battery_pct populated AND sos_flag still true.

### Task A2 — Fix `SiteGeofenceController` scope, and migrate live data

**Files:**
- Modify: `app/Http/Controllers/Sites/SiteGeofenceController.php`
- Modify: `tests/Feature/Sites/SiteGeofenceTest.php` (or add)
- Create one-off command: `app/Console/Commands/FixSiteGeofenceScopes.php`

- [ ] Replace the hardcoded `'scope' => 'vehicle'` with logic that derives scope from the site:
  - `sites.type = 'house'` (and `'residential'`) → `scope = 'house'`
  - `sites.type = 'facility'` → `scope = 'asset'`
  - Anything else / null → `scope = 'site'` (or leave null)
- [ ] Tests:
  - Creating a geofence on a `type=house` site sets `scope='house'`.
  - Creating on a `type=facility` site sets `scope='asset'`.
- [ ] Console command `php artisan tracking:fix-site-geofence-scopes` that walks every `AssetGeofence` with `site_id IS NOT NULL`, infers the right scope from `sites.type`, and updates it. Idempotent — safe to re-run.
- [ ] After running A2 on the live server, also run `php artisan db:seed --class=BackfillHouseGeofenceSeeder` so Amelia (and every other client) gets a `house_geofence_id`.

### Task A3 — Show device model from raw frames + IMEI prefix when name is blank

**Files:**
- Modify: `app/Services/Fleet/FleetTelemetryIngestService.php` (or wherever device upsert happens for Queclink)
- Modify: `app/Services/Queclink/AtTrackProtocolParser.php`

- [ ] When a Queclink frame parses with `device_name` non-empty, persist it as `devices.model` (if not already set) and `devices.name` (if blank).
- [ ] If `device_name` is blank but the IMEI matches a known TAC range (first 8 digits), infer model — e.g. `86796306` → `GL30MEU` (configurable in `config/queclink.php`).
- [ ] Surface "Model unknown — set in Device Console" badge in `ResidentSidebar` when `model` is null.

### Task A4 — Backfill panic_active and last_safety_event for already-running devices

The new ingest sets `panic_active = false` only on the *next* frame from each device. Devices that haven't reported since the deploy still have neither `panic_active` nor `last_safety_event_at` in their `meta`, so the UI falls back to "No panic events recorded" — which is right, but the meta JSON is incomplete and may cause edge cases when a real panic arrives.

- [ ] Add a one-off command `php artisan tracking:backfill-device-meta-defaults` that:
  - Sets `meta.panic_active = false` for any tracking device that lacks the key.
  - Leaves `last_safety_event` and `last_safety_event_at` alone (only the ingest should set those).

---

## 3. History Page UX Cleanup (Phase B)

Live URL: <https://oblivionfindings.com/fleet-assets/resident-tracking/history/9012>

### Current state

- Hero strip with `Back to Tracking` and `Export CSV`.
- **Two large cards** at the top: resident photo + tracker info (purple + blue tinted).
- A separate **Date range filter card** with seven controls: From, To, Filter, Reset, View all points / Live point only, "Showing live point" badge, "N location points" badge.
- **Four KPI cards** (Live point / History starts / Points in view / Latest event).
- Map (~520 px) with a Leaflet popup that shows raw HTML.
- **Timeline list** on the right.

### Problems

1. **Too much chrome above the map.** Three rows of cards (two info cards, filter row, four KPI cards) push the map below the fold. Operators in the field need the map first, controls second.
2. **The KPI cards repeat what's already on the map.** "Live point — 2h ago" duplicates the map marker. "History starts — 19 May 2026" duplicates the timeline. "Points in view" duplicates the filter badge. They are noise.
3. **Filter row is wide and uneven.** Two date inputs + a button + reset + a toggle + two badges in one row. On a 1280 px screen they wrap; on a 1440 px screen they spread.
4. **No quick presets.** Today / Last 24h / Last 7 days / Custom is the operator's mental model, but the only option is a `<input type="date">`.
5. **No event filters.** A real GL30MEU emits `GTFRI`, `GTHBD`, `GTBPL`, `GTSOS`, `GTGEO`, etc. The timeline mixes them all without a way to filter by event type.
6. **Marker popups use raw HTML.** They lose the design system styling and are hard to read on small screens.
7. **No way to jump to a specific point** in the timeline from the map (and vice versa).

### Target UX

```
┌───────────────────────────────────────────────────────────────────────────────────┐
│ ← Resident Tracking                                                                │
│                                                                                    │
│ ┌─ Amelia · Harbour Respite ─────────────────────────┐  ┌─ Pendant · GL30MEU ────┐ │
│ │ [photo] In Zone · Last seen just now               │  │ Online · 100% · 4.19V  │ │
│ └────────────────────────────────────────────────────┘  └────────────────────────┘ │
│                                                                                    │
│ [Today] [24h] [7d] [30d] [Custom from–to]   [All events ▾]   [Export CSV]          │
│                                                                                    │
│ ┌─────────────────────────────────────────────────┐ ┌──────────────────────────┐  │
│ │                  MAP (3fr)                      │ │ TIMELINE (2fr)           │  │
│ │  ◯ live    ⋯⋯ polyline                          │ │ ●  10:42  GTFRI          │  │
│ │                                                 │ │ │   Borman Village        │  │
│ │  Click marker → highlight timeline row          │ │ │   4.3 km/h · 100%       │  │
│ │  Click timeline row → pan map                   │ │ ●  10:24  GTHBD          │  │
│ │                                                 │ │ │   heartbeat             │  │
│ │  Updated 12s ago                                │ │ ●  09:58  GTBPL  ⚠       │  │
│ │  Show: ☑ Live  ☑ Trail  ☐ Heatmap               │ │     battery low 18%      │  │
│ └─────────────────────────────────────────────────┘ └──────────────────────────┘  │
└───────────────────────────────────────────────────────────────────────────────────┘
```

### Task B1 — Header strip, not stacked cards

**Files:**
- Modify: `resources/js/pages/fleet-assets/resident-tracking/history.tsx`

- [ ] Replace the two-card hero with a single horizontal strip: resident chip + tracker chip + In Zone pill + battery pill + Last seen relative time. All on one row.
- [ ] Move `Export CSV` into the filter row (it sits awkwardly in the hero today).

### Task B2 — Replace the KPI cards with a one-line summary

**Files:**
- Modify: `resources/js/pages/fleet-assets/resident-tracking/history.tsx`

- [ ] Drop the four KPI cards entirely. Replace with a thin summary bar:

```
N points · from 18 May 06:24 to 19 May 10:42 · Live point 2h ago · Latest event location_report
```

### Task B3 — Quick-range filter pills + smart custom range

**Files:**
- Modify: `resources/js/pages/fleet-assets/resident-tracking/history.tsx`

- [ ] Replace the filter card with a pill group: `Today` (default) / `24h` / `7d` / `30d` / `Custom`.
- [ ] `Custom` reveals the existing date inputs inline.
- [ ] Selecting a pill triggers an Inertia `router.get` with `preserveState: true`.
- [ ] Show URL-driven default (e.g. `?range=7d`) and persist the choice in `localStorage` per user.

### Task B4 — Event-type filter

**Files:**
- Modify: `app/Services/Integration/IntegrationEventHistoryService.php` (already supports filter)
- Modify: `app/Http/Controllers/FleetAssets/ResidentTrackingController.php::history`
- Modify: `resources/js/pages/fleet-assets/resident-tracking/history.tsx`

- [ ] Controller accepts `?event_types=location_report,heartbeat,...` and passes through to the service.
- [ ] UI: multi-select dropdown with the distinct event types observed in the time range, plus a `Safety only` shortcut (filters to sos / man_down / battery_low / tamper).
- [ ] Each event type gets a coloured dot (lucide `Circle` for normal, `ShieldAlert` for safety, `Battery` for battery, `Plug` for power).

### Task B5 — Linked map ↔ timeline interaction

**Files:**
- Modify: `resources/js/pages/fleet-assets/resident-tracking/history.tsx`
- Modify: `resources/js/components/leaflet-map.tsx` (expose a controlled `activeMarkerId` prop)

- [ ] Add hover state to timeline rows. On hover, highlight the matching marker (size + glow). On click, pan the map to that point.
- [ ] On marker click, scroll the matching timeline row into view + highlight for 2 s.
- [ ] Replace raw HTML popups with a React-rendered popup matching the resident sidebar style: badge + relative time + lat/lng + battery + event-type icon.

### Task B6 — Map height + responsive split

**Files:**
- Modify: `resources/js/pages/fleet-assets/resident-tracking/history.tsx`

- [ ] Use the `[3fr_2fr]` grid (same as Fleet + Profile). Map left, timeline right.
- [ ] Map height: 600 px on desktop, full-screen toggle for field operators (`Expand`/`Restore` in top-right corner).
- [ ] Below `lg`, stack map above timeline.

### Task B7 — Heatmap / cluster visualisation toggle (optional)

**Files:**
- Modify: `resources/js/components/leaflet-map.tsx`

- [ ] Add a `mode` prop: `markers` (default), `cluster`, `heatmap`. Use `leaflet.heat` plugin for heatmap when toggled.
- [ ] Useful when a resident has 500+ points and operators want to see where they spend time.

### Task B8 — Loading & empty states

**Files:**
- Modify: `resources/js/pages/fleet-assets/resident-tracking/history.tsx`

- [ ] Skeleton state while history loads (currently it pops in).
- [ ] Empty state when zero points match the filter: illustration + "No events for this range" + "Reset filter" button.
- [ ] Error state if the API returns 5xx: actionable retry.

---

## 4. Client-Surface Navigation Rework (Phase C)

The profile show page ([resources/js/pages/operations/clients/show.tsx](resources/js/pages/operations/clients/show.tsx:566)) defines **22 flat tabs**:

`Overview · Onboarding · Medical · MAR · Food & Meal · Observations · Care Plans · Calendar · Progress Notes · Agreements · Assessments · Timeline · Documents · Photos · Personal Assets · Transport · Consents · Consent Requests · Location · Family Portal · Family Notes · Respite · Workers`

On a 1440 px screen they overflow into a horizontal scroll bar; on a tablet they wrap to 3 lines. There is no semantic grouping, no defaults per role, and no way for a support worker to find "the things that change every shift" vs "background context".

### Problems with the status quo

1. **Cognitive load.** 22 buttons, all the same size, all the same colour. The pendant safety surface (Location) is no more discoverable than the static Personal Assets tab.
2. **Discovery fatigue.** Frontline staff hunt through tabs for the one or two they actually use.
3. **Status-blind.** None of the tabs surface "this needs attention now" without clicking in.
4. **Cross-linking is one-way.** Going Location → Fleet Dashboard works (we built it in Phase 3). Going Fleet Dashboard → Profile is one click. But Medical → Observations → Timeline traversal is a maze.
5. **Mobile is unusable.** The same 22 tabs in a horizontally-scrollable strip; nothing collapses.

### Target structure

Group the 22 into **5 task-oriented sections** rendered as a vertical sidebar nav (already standard pattern in the app's main sidebar). Each section has a count/health badge and a default starting tab.

```
┌──────────────────────────────────┐
│  ← Clients · Amelia Wilson       │
│                                  │
│  ● Live status                   │  ← top section, always expanded
│    ◆ Location & Safety   In Zone │
│    ◆ Today's shift       3 tasks │
│    ◆ Alerts              0 open  │
│                                  │
│  ▸ Care delivery             5   │  ← expandable, shows badge total
│      Medical, MAR, Observations, │
│      Care Plans, Progress Notes  │
│                                  │
│  ▸ Records                   4   │
│      Assessments, Documents,     │
│      Photos, Timeline            │
│                                  │
│  ▸ Logistics                 4   │
│      Calendar, Transport,        │
│      Personal Assets, Respite    │
│                                  │
│  ▸ Compliance & relationships  6 │
│      Consents, Consent Requests, │
│      Agreements, Family Portal,  │
│      Family Notes, Workers       │
│                                  │
└──────────────────────────────────┘
```

A 6th section, "Onboarding", is shown above "Live status" only while `client.status === 'onboarding'`.

### Task C1 — Group definitions in code

**Files:**
- Modify: `resources/js/pages/operations/clients/show.tsx`
- Add: `resources/js/pages/operations/clients/tabs/_groups.ts`

- [ ] Extract the 22-tab array into the new `_groups.ts` with shape:

```ts
type Group = { key: string; label: string; icon: LucideIcon; defaultOpen?: boolean; tabs: ClientTab[] };
```

- [ ] Each tab keeps its `count` and `show` predicates; the group accumulates the count.
- [ ] Default open groups: `live`, plus whichever section contains the URL's `?tab=`.

### Task C2 — Vertical group sidebar on desktop, horizontal pill row on mobile

**Files:**
- Modify: `resources/js/pages/operations/clients/show.tsx`
- Add: `resources/js/components/client-profile/client-nav.tsx`

- [ ] At `lg+`: vertical sidebar with collapsible groups. Each tab is a row with icon + label + count.
- [ ] Below `lg`: top-of-page horizontal scrollable pill row, grouped with separators (visually communicate the groups even when stacked).

### Task C3 — Health surfacing per group

**Files:**
- Modify: `app/Http/Controllers/ClientController.php` (extend the `show()` payload)
- Modify: `resources/js/components/client-profile/client-nav.tsx`

- [ ] Each group gets a small status pill:
  - `Live status` → red dot if active panic / outside zone / open critical alert.
  - `Care delivery` → orange dot if any MAR dose is overdue or any progress note is unread for >24 h.
  - `Records` → no badge (purely document).
  - `Logistics` → orange dot if there's a calendar event in the next 4 h with no assigned worker.
  - `Compliance & relationships` → red dot if any consent has expired or is withdrawn; orange if expiring in <14 days.
- [ ] The controller's `show()` already computes most of this (look at the `next_shift`, `pendingConsentRequestsCount`, etc.). Group the existing flags into a `nav_health` map.

### Task C4 — Tab persistence and deep linking

**Files:**
- Modify: `resources/js/pages/operations/clients/show.tsx`

- [ ] Tab choice persisted in `localStorage` per user — when returning to a client profile, start on the last-opened tab.
- [ ] `?tab=` query param overrides storage (deep links win).
- [ ] When a tab loads, update `history.replaceState` so refresh stays put.

### Task C5 — Top-level breadcrumbs + recent clients

**Files:**
- Modify: `resources/js/pages/operations/clients/show.tsx`
- Add: `resources/js/components/client-profile/recent-clients-strip.tsx`

- [ ] Just above the nav sidebar: avatars of the operator's 5 most-recently-viewed clients. One click jumps to that client's profile, opening on the same tab the operator is currently on (e.g. they're on Location for Amelia → click the next avatar → land on Location for Ben).
- [ ] Track recents via `localStorage` for now; we can later persist server-side.

### Task C6 — Cross-link from any tab to the Fleet Resident Tracking page

**Files:**
- Modify: `resources/js/components/client-profile/client-nav.tsx`

- [ ] When the resident has an active tracker, render a small `View in Fleet Dashboard` link in the `Live status` group header (deep-links with `?focus=<client_id>`, scrolling that resident into view on the dashboard). Already wired server-side; this just makes the link discoverable.

### Task C7 — Frontline-friendly mobile profile (optional follow-up)

The current page is desktop-first. Field workers need a stripped-down version:

- [ ] At `sm`: collapse everything except the active section, hide secondary metadata, big buttons for `Locate Now`, `Log progress note`, `Open MAR`.
- [ ] Tracked as a follow-up because it's a separate page-level rewrite.

---

## 5. Sites Module — Make geofences visible everywhere they apply (Phase D)

The Sites module is where geofences are authored, but the rest of the app didn't know how to look them up. With the SiteGeofenceController fix in A2, plus the existing canonical relationships, geofences now flow correctly. We can also surface them more clearly.

### Task D1 — Show the geofence preview on the Site detail page

**Files:**
- Modify: `resources/js/pages/sites/show.tsx` (or wherever the Site detail lives)

- [ ] If the site has an `AssetGeofence` row, render a 200×200 thumbnail map with the geofence shape outlined. One-click into the editor.
- [ ] Show counts: "12 residents in this geofence · 3 currently outside zone".

### Task D2 — Show the linked geofence on the Site edit form

**Files:**
- Modify: the geofence editor component used by [`SiteGeofenceController`](app/Http/Controllers/Sites/SiteGeofenceController.php)

- [ ] When the form loads, fetch the resolved scope label ("House zone", "Facility perimeter") and display it as a read-only chip — operators learn what scope means.
- [ ] Add a `Scope` selector (dropdown) for advanced users: House / Facility / Asset / Custom. Default derived from `sites.type` but overridable.

### Task D3 — Show geofences in the Site dashboard list

**Files:**
- Modify: `resources/js/pages/sites/index.tsx`

- [ ] Add a `Geofence` column showing a tiny coloured dot (green if active, grey if disabled, red if missing). Click → open the editor.
- [ ] Highlights sites that *should* have a geofence (i.e. `type=house` with tracked residents) but don't.

### Task D4 — Per-asset geofences also surface

The model already supports per-asset geofences (`AssetGeofence.asset_id`). The Sites module only edits site-level; the Asset module should edit asset-level. Cross-check:

**Files:**
- Modify: `resources/js/pages/fleet-assets/assets/show.tsx`

- [ ] Show the resolved geofence (site-level or asset-specific override) in the asset detail page, with a clear label.

---

## 6. Quick-fix one-liners we can run immediately on the live server

Even before A1 ships, these unblock Amelia today:

```bash
# 1. Fix the existing geofence's scope so the backfill seeder picks it up
php artisan tinker --execute="\App\Models\AssetGeofence::where('id', 1)->update(['scope' => 'house']);"

# 2. Backfill house_geofence_id for every client
php artisan db:seed --class=BackfillHouseGeofenceSeeder

# 3. Manually set Amelia's house_geofence_id (only if step 2 doesn't match — it should)
php artisan tinker --execute="\App\Models\Client::where('id', 9012)->update(['house_geofence_id' => 1]);"

# 4. Reset the stale battery_level=0 so the UI shows "Battery not reported" until the next frame
php artisan tinker --execute="
\App\Domain\SecurityDevices\Models\Device::where('id', 2)->update([
    'battery_level' => null,
]);
\$d = \App\Domain\SecurityDevices\Models\Device::find(2);
\$m = \$d->meta;
unset(\$m['battery'], \$m['battery_level']);
\$m['battery_status'] = 'unknown';
\$d->forceFill(['meta' => \$m])->save();
"
```

After A1 ships, the next GL30MEU frame will overwrite these with the real values.

---

## 7. Acceptance Criteria

### Phase A
- The next `+RESP:GTFRI` frame from IMEI `867963069916998` populates `battery_level = 100`, `battery_voltage_mv = 4191`, `charging_status = 'not_charging'`, `battery_status = 'normal'`.
- Amelia's Client Profile Location tab shows "100 %" and "Voltage 4.19 V" in the Battery & Power card.
- Creating a geofence on a `type=house` site via the Sites editor stores `scope='house'`.
- Running `php artisan tracking:fix-site-geofence-scopes` updates every legacy `scope='vehicle'` row sitting on a `type=house` site to `scope='house'`. Idempotent.
- The backfill seeder then assigns Amelia (and every other house-resident) a `house_geofence_id`.
- The In-Zone pill on the profile reflects the real haversine result.

### Phase B
- The History page renders the map within the first viewport at 1280 × 800.
- Quick-range pills (`Today / 24h / 7d / 30d / Custom`) drive the query without re-typing dates.
- Event-type filter pill row works; `Safety only` filters to SOS / man-down / battery-low / tamper.
- Hovering a timeline row highlights its marker; clicking a marker scrolls the timeline row into view.
- Marker popups are React components, not raw HTML.

### Phase C
- The 22-tab strip is replaced with 5 task-oriented groups.
- Each group has a live health badge driven by the controller's existing data.
- Tab choice persists per operator across page loads and per-client.
- Recent-clients avatar strip lets a worker hop between residents while staying on the same sub-tab.

### Phase D
- The Site detail page shows the geofence preview thumbnail.
- The Site list page shows a geofence dot per site (and flags sites that should have one but don't).
- The geofence editor includes a `Scope` selector with a sensible default per `sites.type`.

---

## 8. File Manifest

| Purpose | Path |
| ------- | ---- |
| Queclink parser | [app/Services/Queclink/AtTrackProtocolParser.php](app/Services/Queclink/AtTrackProtocolParser.php) |
| Queclink adapter | [app/Services/Fleet/Telemetry/QueclinkAdapter.php](app/Services/Fleet/Telemetry/QueclinkAdapter.php) |
| Telemetry ingest | [app/Services/Fleet/FleetTelemetryIngestService.php](app/Services/Fleet/FleetTelemetryIngestService.php) |
| Site geofence editor | [app/Http/Controllers/Sites/SiteGeofenceController.php](app/Http/Controllers/Sites/SiteGeofenceController.php) |
| Backfill seeder | [database/seeders/BackfillHouseGeofenceSeeder.php](database/seeders/BackfillHouseGeofenceSeeder.php) |
| Resident tracking controller | [app/Http/Controllers/FleetAssets/ResidentTrackingController.php](app/Http/Controllers/FleetAssets/ResidentTrackingController.php) |
| Client controller | [app/Http/Controllers/ClientController.php](app/Http/Controllers/ClientController.php) |
| History page | [resources/js/pages/fleet-assets/resident-tracking/history.tsx](resources/js/pages/fleet-assets/resident-tracking/history.tsx) |
| Client show page | [resources/js/pages/operations/clients/show.tsx](resources/js/pages/operations/clients/show.tsx) |
| Site detail page | [resources/js/pages/sites/show.tsx](resources/js/pages/sites/show.tsx) (verify path) |
| Resident sidebar (existing) | [resources/js/components/resident-tracking/resident-sidebar.tsx](resources/js/components/resident-tracking/resident-sidebar.tsx) |
| Client nav (new) | `resources/js/components/client-profile/client-nav.tsx` |
| Recent clients (new) | `resources/js/components/client-profile/recent-clients-strip.tsx` |
| Fix-scope command (new) | `app/Console/Commands/FixSiteGeofenceScopes.php` |
| Backfill defaults command (new) | `app/Console/Commands/BackfillDeviceMetaDefaults.php` |
| Tab groups data (new) | `resources/js/pages/operations/clients/tabs/_groups.ts` |

---

## 9. Recommended order of work

1. **Phase A first**, because it unblocks live data for Amelia and any future GL30 residents.
2. **Quick-fix one-liners** (Section 6) immediately after deploy of A2 — Amelia gets her zone today.
3. **Phase B (History)** next — it's a contained, single-page improvement and gives operators an immediate quality-of-life win.
4. **Phase C (Nav rework)** — the biggest change in this plan; do it after A and B are stable so we don't compound risk.
5. **Phase D (Sites surfacing)** — small, additive, ship whenever.

Each phase is independently deployable and tested.
