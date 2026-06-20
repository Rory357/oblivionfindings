# 05 — Placement & Permissions: Staff GPS-Tracker (Lone Worker)

Audit scope: WHERE the staff-tracking UX lives + which permissions gate it, mirroring the
existing RESIDENT tracking feature (Queclink GL30 pendant → ControlRoom). Read-only audit.

The headline finding: **the heavy plumbing already exists**. The Queclink TCP intake already
supports `pairing_type: 'staff'`, `DeviceRegistryService::forStaff()` already exists as the exact
mirror of `forClient()`, `LocateNowService::queueForDevice(Device, User)` is client-agnostic, and
`DeviceAssignment::TARGET_STAFF = 'staff'` is a first-class assignable target. The work is a thin
**presenter + 2–3 routes + UI wiring**, not new infrastructure.

---

## 1. The 3 placement questions

### Q1 — WHERE to PAIR a tracker to a staff member

**Decision: REUSE the existing Queclink Hub pairing flow. Do NOT build a new pairing surface.**

The pairing surface already exists and already handles staff:
- Page: `resources/js/pages/security-devices/integrations/queclink-hub.tsx`
- Controller: `app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php`
- Route: `POST /security-devices/integrations/queclink/devices/{queclinkDevice}/claim`
  → `security-devices.integrations.queclink.claim` (routes/security-devices.php:254)
- Method: `QueclinkHubController::claimDevice()` (line 169) — validates
  `'pairing_type' => ['required', 'in:vehicle,staff,client']` (line 175). The `'staff'` branch
  (lines 194–198) calls `ensurePersonalTrackerAsset(type:'staff', …)`, creates a
  `DeviceAssignment` with `assignable_type='staff'`, and `ensureCanonicalDevice()` already names it
  `"Lone-worker tracker {imei}"` with `category='personal_tracker'` (lines 1248–1257).
- The Hub already loads a `staff` picker target (lines 116–122: `User::whereNotNull('approved_at')`).

So a tracker is paired to staff **today** via the Hub's pending-tray. No new pairing route is
needed. (Optional polish, deferred: a "+ Pair tracker" affordance on the staff profile that
deep-links to the Hub pre-filtered to that staff member — not required for v1.)

Permission for pairing: `securityDevices.integrations.manage` (the whole `/integrations/queclink`
group is gated by it — routes/security-devices.php:243; also enforced in-controller via
`userCanManage()` → `canDo('securityDevices.integrations.manage')`, line 1310). **Keep this gate.**
Pairing/provisioning hardware is an IT/admin act, distinct from H&S coordination.

### Q2 — WHERE "Locate now" + last-GPS live (the SESSION DETAIL)

**Decision: surface last-GPS + "Locate now" + "Acknowledge panic" on the lone-worker SESSION DETAIL
modal**, specifically in the existing **"Last-known location" card** of the Overview section.

File: `resources/js/components/health-safety/lone-worker-detail-dialog.tsx`
- `SessionOverview()` (lines 174–237) already renders a **"Last-known location"** card (the
  `<SectionLabel icon={MapPin}>Last-known location</SectionLabel>` block, lines 202–228). Today it
  reads `d.location_lat / d.location_lng` (the session's manually-entered/shift-derived coords) and
  shows an "Open map" link.
- **Build:** when the session's worker has a paired tracker, enrich this card with the **live
  tracker** last-GPS (lat/lng/address/battery/last_seen), a **"Locate now"** button, and (if a panic
  is active) an **"Acknowledge panic"** action + a critical banner. This is a 1:1 visual mirror of
  the resident card built by `ResidentTrackingController::buildResidentPayload()`.

This is the right home because the session detail is the coordinator's per-worker focus view, and it
already owns the "Last-known location" affordance. It also keeps the 3-actor model intact (see §3).

### Q3 — A new control on the lone-worker page / a staff-profile tab?

- **Lone-worker register page (`/health-safety/lone-workers`):** optional, deferred. A small
  "tracked" pill on session rows (`register-row-kit`) is nice-to-have but not required for v1. The
  detail modal is the canonical home.
- **Staff profile (`resources/js/pages/staff/show.tsx`):** this page is a card-based `PageHero`
  layout (no `TabStrip` today — it shows Fleet eligibility + recent trips cards). A "Devices /
  Tracker" card here is a reasonable **future** placement (mirrors the resident `?tab=location`
  surface) but is **out of scope for the lone-worker feature** — defer to the SecurityDevices/Fleet
  owners. For v1, the session-detail card is sufficient and avoids cross-module churn.

---

## 2. The resident MIRROR to copy (this is the template)

Controller: `app/Http/Controllers/FleetAssets/ResidentTrackingController.php` — copy these
method-for-method into the lone-worker controller (operating on a `User` instead of a `Client`):

| Resident method | Line | Staff equivalent to build |
|---|---|---|
| `locateNow(Request, Client, LocateNowService)` | 404 | `locateNow(Request, User)` → `LocateNowService::queueForDevice($device, $user)` |
| `acknowledgePanic(Request, Client)` | 426 | `acknowledgePanic(Request, User)` (clears `device.meta['panic_active']` + acks CR alert) |
| `buildResidentPayload(Device, Client, …)` | 598 | `buildWorkerTrackerPayload(Device, User)` — last-GPS/battery/panic/`last_seen_at` shape |
| `getAuthorizedClientIds()` authz pattern | 465 | staff-scope authz (here: `hazards.manage` already gates the write routes) |

Key reusable services (NO client coupling — directly usable for staff):
- `App\Services\Queclink\LocateNowService::queueForDevice(Device $device, User $user): QueclinkPendingCommand`
  (LocateNowService.php:18). Family-detects GL30; `personal_tracker`/`lone_worker_tracker`
  categories already map to `FAMILY_GL30M` (line 85).
- `App\Domain\SecurityDevices\Services\DeviceRegistryService::forStaff(int $tenantId, int $userId): Builder`
  (DeviceRegistryService.php:73) — **already exists**, exact mirror of `forClient()` (line 50),
  filters `assignments()->active()->forTarget(TARGET_STAFF, $userId)`. Use this to resolve a worker's
  paired tracker in the lone-worker controller.

Device → staff link model: `App\Domain\SecurityDevices\Models\DeviceAssignment`
- `TARGET_STAFF = 'staff'` (line 48); `assignable()` resolves `User::find()` for staff (line 90).
- Last-GPS fields live on `Device` (`latitude`, `longitude`, `battery_level`, `last_seen_at`,
  `meta['panic_active' | 'speed' | 'address' | …]`) — same source the resident payload reads.

Inbound PANIC / MAN-DOWN → emergency pipeline:
- The lone-worker emergency entrypoint is
  `App\Services\HealthSafety\LoneWorkerSignalService::emitEmergency(LoneWorkerSession $session, ?string $notes = null): void`
  (LoneWorkerSignalService.php:35) → `SignalProcessingService` → `ControlRoomAlert`
  (source=`lone_worker`). It requires a **`LoneWorkerSession`** (reads `$session->site_id`,
  `client_id`, `user`). A tracker PANIC frame from a paired staff device must be mapped to the
  worker's **active** `LoneWorkerSession` (resolve via `forStaff` → assignment → `user_id` →
  active session) before calling `emitEmergency()`. (Cross-module wiring detail — covered by the
  signal-routing audit, not this placement doc; flagged here so the panic action and the inbound
  frame converge on the same `emitEmergency` seam.)

---

## 3. Permissions — recommended gates (H&S gold standard + 3-actor model)

The lone-worker module gates on `hazards.*`, NOT `securityDevices.*`
(routes/health-safety.php:368–398, confirmed in `LoneWorkerController::index` `can` block, lines
153–157):
- `hazards.view`  → read the lone-worker page (`permission:hazards.view`, line 371).
- `hazards.manage` → all write/lifecycle actions (start/extend/end/emergency/ack/resolve), line 388.
- Worker self check-in is **auth-only** with ownership enforced in-controller
  (`checkIn()` lines 243–248) so frontline support workers (who hold **no** `hazards.*`) can self-act.

**Recommended permission gates for the new staff-tracker actions:**

| New action | Route name (proposed) | Permission gate | Rationale |
|---|---|---|---|
| Locate now (coordinator) | `health-safety.lone-workers.sessions.locate` | `permission:hazards.manage` | A coordinator watch-tower write action; sits in the existing `hazards.manage` group alongside `sessions.emergency`. |
| Acknowledge panic | `health-safety.lone-workers.sessions.acknowledge-panic` | `permission:hazards.manage` | Operational response to a distress signal; coordinator act. |
| Read last-GPS in detail payload | (no new route — part of `index`/`sessionDetail`) | `hazards.view` (already gates the page) | Last-known location is already shown to `hazards.view`; tracker enrichment inherits the same gate. |
| Pair tracker → staff | `security-devices.integrations.queclink.claim` (EXISTING) | `securityDevices.integrations.manage` (EXISTING) | Hardware provisioning stays an IT/admin act — do not weaken to H&S. |

This split keeps the 3-actor model clean:
- **Coordinator / H&S lead** (`hazards.manage`) → Locate now + Acknowledge panic on the session
  detail (the watch-tower). Mirrors how resident Locate/ack require `fleet.manage`.
- **The lone worker** → never operates the tracker UI; their device just reports. Their only action
  is the My Day self check-in (auth-only). A tracker PANIC/MAN-DOWN is an *inbound* signal, not a UI
  action.
- **The client** → never touches lone-worker safety (unchanged).

Do **not** introduce a new permission key. `hazards.view` / `hazards.manage` already model the
read/manage split for this module and are already seeded to the right roles
(`RbacSeeder`). Adding the new routes into the existing
`permission:hazards.manage` group means **zero new RBAC migration** for the lone-worker side.

Note on `controlRoom.*`: the detail modal already conditionally renders an "Open in Control Room"
link gated by `controlRoom.viewAny` (`can.view_control_room`, LoneWorkerController.php:156;
seeded keys: `controlRoom.viewAny`, `controlRoom.alerts.{view,manage,assign,escalate,create}`,
`controlRoom.reports.view` — RbacSeeder.php:193–199). Reuse that exact gate for any "view panic alert
in CR" deep-link from the tracker card. No change needed.

---

## 4. New route names (under `health-safety.lone-workers.*`)

Add inside the existing `permission:hazards.manage` group (routes/health-safety.php:388–397):

```php
// Tracker actions on a session's worker (coordinator watch-tower).
Route::post('/sessions/{session}/locate', [LoneWorkerController::class, 'locateNow'])
    ->name('sessions.locate');
Route::post('/sessions/{session}/acknowledge-panic', [LoneWorkerController::class, 'acknowledgePanic'])
    ->name('sessions.acknowledge-panic');
```

Binding by `{session}` (not `{user}`) is preferred: the detail modal is session-scoped, the session
already carries `user_id`, and it keeps the URL consistent with the sibling
`sessions.emergency` / `sessions.end` routes. Resolve the worker's tracker inside the action via
`DeviceRegistryService::forStaff($tenantId, $session->user_id)->where('domain','tracking')->first()`,
exactly as the resident controller does `forClient(...)->where('domain','tracking')->first()`
(ResidentTrackingController.php:410–413).

If a tracker is paired to a worker who has **no active session**, prefer `{user}`-bound variants
under the same `hazards.manage` group instead (`lone-workers.staff.{locate,acknowledge-panic}`); for
v1 the session-bound form is sufficient because Locate/ack are only surfaced from the session detail.

---

## 5. Frontend wiring (types + component)

- `resources/js/components/health-safety/lone-worker-types.ts` — extend `SessionDetail`
  (line 90) with an optional `tracker` block:
  ```ts
  tracker?: {
      device_id: number; device_uid: string; name: string;
      lat: number | null; lng: number | null; address: string | null;
      battery: number | null; battery_status: string | null;
      last_seen_at: string | null; panic_active: boolean;
      locate_url: string; acknowledge_panic_url: string;
  } | null;
  ```
- `lone-worker-detail-dialog.tsx` `SessionOverview()` (lines 174–237) — inside the existing
  "Last-known location" card, when `d.tracker` is present, render tracker coords/battery/last-seen,
  a "Locate now" button (`router.post(d.tracker.locate_url)`), and (if `panic_active`) a critical
  banner + "Acknowledge panic" button. Use the existing `InfoCard tone="crit"` pattern already used
  for `emergency_notes` (lines 229–233).
- `LoneWorkerController::sessionDetail()` (line 535) — add the `tracker` block to the payload via a
  new `buildWorkerTrackerPayload(Device, User)` private helper copied from
  `ResidentTrackingController::buildResidentPayload()`.

---

## 6. Taxonomy & config notes

- `DeviceTaxonomy` (app/Domain/SecurityDevices/Config/DeviceTaxonomy.php) — under
  `DeviceDomain::Tracking`, the category is **`personal_tracker`** (lines 85–91) with subcategories
  including `lone_worker => 'Lone Worker Device'` (line 89) and `sos_device`, `pendant`,
  `wearable_gps`. **There is NO separate `lone_worker_tracker` category** in the taxonomy — staff
  trackers are `personal_tracker` (same category residents use), differentiated by the
  `DeviceAssignment.assignable_type` (`staff` vs `client`). NB: the strings
  `'lone_worker_tracker'` / `'client_tracker'` DO appear as defensive family-detection hints in
  `QueclinkHubController::guessFamily()` (line 1279) and `LocateNowService::familyFor()` (line 85),
  but they are not canonical taxonomy categories — don't rely on them as the category value.
- `CategoryPageConfig` (CategoryPageConfig.php) — the `tracking-devices` page
  (`/security-devices/category/tracking-devices`, lines 52–61) lists ALL tracking devices
  (`'categories' => null`), so paired staff trackers already appear in the SecurityDevices admin
  inventory with no config change.

---

## 7. What to REUSE vs BUILD (summary table)

| Concern | REUSE (exists) | BUILD (new) |
|---|---|---|
| Pair tracker → staff | `QueclinkHubController::claimDevice()` + `claim` route (staff branch already there) | nothing (optional deep-link from staff profile, deferred) |
| Resolve worker's tracker | `DeviceRegistryService::forStaff()` | nothing |
| Queue "Locate now" | `LocateNowService::queueForDevice(Device, User)` | thin `locateNow(session)` controller action + route |
| Acknowledge panic | pattern from `ResidentTrackingController::acknowledgePanic()` | thin `acknowledgePanic(session)` action + route |
| Last-GPS payload | shape from `ResidentTrackingController::buildResidentPayload()` | `buildWorkerTrackerPayload(Device, User)` helper in `LoneWorkerController` |
| Emergency → CR | `LoneWorkerSignalService::emitEmergency(session)` | inbound PANIC frame → active-session mapping (signal-routing audit) |
| UI home | "Last-known location" card in `lone-worker-detail-dialog.tsx` | tracker enrichment + Locate/ack buttons |
| Perms | `hazards.view` (read) / `hazards.manage` (write); `securityDevices.integrations.manage` (pairing); `controlRoom.viewAny` (CR deep-link) | **no new permission keys** |

**Net new build is small and additive**: 2 controller methods + 2 routes (in the existing
`hazards.manage` group) + 1 payload helper + 1 detail-card enrichment + 1 type extension.
No new migration, no new permission, no new pairing UI.
