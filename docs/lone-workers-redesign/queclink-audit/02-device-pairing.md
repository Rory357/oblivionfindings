# Queclink Audit 02 — Device Pairing / Person Assignment

**Question:** How does a Queclink tracker pair/assign to a PERSON, and is STAFF assignment supported?

**Verdict:** ✅ **STAFF pairing is FULLY SUPPORTED END-TO-END TODAY.** Backend, DB, route, frontend, and a staff-tracker lookup helper all already exist. There is **no schema or pairing work required** to assign a Queclink tracker to a staff (lone-worker) `User`. The lone-worker feature can consume the existing pairing as-is.

---

## 1. The two device models

### `QueclinkDevice` — `app/Models/Queclink/QueclinkDevice.php`
Raw intake/handshake row keyed by IMEI (table `queclink_devices`).
- **Status constants** (lines 16–18): `STATUS_PENDING = 'pending'`, `STATUS_PAIRED = 'paired'`, `STATUS_REJECTED = 'rejected'`.
- **Pairing-type constants** (lines 20–22): `PAIRING_VEHICLE = 'vehicle'`, **`PAIRING_STAFF = 'staff'`**, `PAIRING_CLIENT = 'client'`.
- Column `pending_pairing_type` (fillable line 35) — operator's intended target while a device sits in the pending tray. Migration enum (`2026_05_11_120000_create_queclink_devices_table.php` line 22): `enum('pending_pairing_type', ['vehicle', 'staff', 'client'])->nullable()` — **staff is a first-class enum value.**
- `isPaired(): bool` (line 87) → `status === STATUS_PAIRED`; `isPending()` (line 92).
- `device_id` FK → canonical `Device` (relation `device()`, line 52). Status enum (migration line 21): `['pending','paired','rejected']`.

### `Device` — `app/Domain/SecurityDevices/Models/Device.php`
Canonical hardware registry (table `devices`). One row per physical device.
- `assignments(): HasMany` (line 126) → `DeviceAssignment`.
- `activeAssignment(): ?DeviceAssignment` (line 131) → `assignments()->whereNull('released_at')->first()`.
- Geo/telemetry columns relevant to "last-known location": `latitude` (decimal:8), `longitude` (decimal:8), `last_seen_at`, `last_signal_at`, `battery_level`, `location_description` (fillable 72–74, 62–63; migration 49–52, 71–73).
- `domain` = `'tracking'`, `category` = `'personal_tracker'` for staff trackers (see §3).

---

## 2. DeviceAssignment IS polymorphic — and already points at a User/staff

`app/Domain/SecurityDevices/Models/DeviceAssignment.php` (table `device_assignments`):
- **It is polymorphic via `assignable_type` (string) + `assignable_id` (unsignedBigInteger)** — NOT `assigned_user_id`/`client_id`. Fillable (lines 22–34): `device_id, assignable_type, assignable_id, assignment_type, assigned_at, expected_return_at, released_at, assigned_by_user_id, released_by_user_id, consent_id, notes`.
- **Target constants (lines 45–49):** `TARGET_SITE='site'`, `TARGET_ROOM='room'`, `TARGET_VEHICLE='vehicle'`, **`TARGET_STAFF='staff'`**, `TARGET_CLIENT='client'`. All five in `VALID_TARGETS` (51–57).
- **`assignable(): ?Model` (lines 84–94)** resolves the target. The staff arm: `self::TARGET_STAFF => User::find($this->assignable_id)` (line 90). So an assignment row CAN and DOES point at a staff `User`.
- `requiresConsent()` (line 136) → only `TARGET_CLIENT` requires `consent_id`. **Staff and vehicle do not require consent** (privacy gate is client-only).
- Scopes: `active()` (`whereNull('released_at')`, line 98), `forTarget($type,$id)` (line 108).

**DB schema** (`2026_04_14_000001_create_security_devices_tables.php`, `device_assignments`, lines 107–140):
- `assignable_type` string (line 113, comment lists `site, room, vehicle, staff, client`), `assignable_id` unsignedBigInteger (114).
- `consent_id` nullable FK → `client_consents`, nullOnDelete (126) — nullable, so staff rows leave it null.
- Index `dev_assign_target_active_idx` on `(assignable_type, assignable_id, released_at)` (136) — the "find this person's active device" lookup is indexed.
- At most one active assignment per device (`released_at IS NULL`).

---

## 3. The pairing flow — `claimDevice` already branches on staff

**Controller:** `app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php`
**Method:** `claimDevice(Request $request, QueclinkDevice $queclinkDevice)` — **lines 169–248**. This is THE place where `QueclinkDevice.status` becomes `'paired'` and a `DeviceAssignment` is created.

Flow (inside `DB::transaction`):
1. Guards: `userCanManage` (perm `securityDevices.integrations.manage`, line 1312) + `$queclinkDevice->isPending()` (line 172).
2. **Validation (lines 174–179):** `'pairing_type' => ['required', 'in:vehicle,staff,client']` — **staff explicitly allowed**; `target_id` required integer; `consent_id` nullable; `create_personal_tracker_asset` nullable bool.
3. **Asset resolution (lines 192–204):** `match($pairingType)` → `'staff' => $this->ensurePersonalTrackerAsset(type:'staff', targetId:$targetId, tenantId:$tenantId)` (line 194).
4. **Canonical device (line 206):** `ensureCanonicalDevice($queclinkDevice,$tenantId,$pairingType)` — for staff sets `name = "Lone-worker tracker {imei}"` (line 1250), `domain='tracking'`, `category='personal_tracker'` (1254–1257), `provider='queclink'`.
5. **THE ASSIGNMENT (lines 208–216):**
   ```php
   DeviceAssignment::create([
       'device_id' => $device->id,
       'assignable_type' => $pairingType,      // 'staff'
       'assignable_id' => $targetId,           // User id
       'assignment_type' => AssignmentType::Permanent->value,
       'assigned_at' => now(),
       'assigned_by_user_id' => $request->user()->id,
       'consent_id' => $consentId,             // null for staff
   ]);
   ```
6. Mirrors to legacy `AssetTracker` (218–230, `vendor='queclink'`, `status='paired'`).
7. **Flips status to paired (lines 232–237):** `$queclinkDevice->update(['status'=>STATUS_PAIRED,'pending_pairing_type'=>null,'device_id'=>$device->id,'tenant_id'=>$tenantId])`.
8. Audit log via `QueclinkAuditEvent` (239–244).

**Consent gate** (`resolveClientTrackingConsentId`, 250–275): runs **only when `pairingType==='client'`** (line 185, `$client` is null otherwise → `$consentId` null). Staff pairing bypasses consent entirely.

**Release/unpair:** `releaseDevice` (294–325) sets `released_at` on the active assignment + flips QueclinkDevice back to pending.

**Staff personal-tracker asset:** `ensurePersonalTrackerAsset` (1195–1219) reuses/creates an `Asset` with `category='personal_tracker'`, `primary_driver_user_id=$targetId` for staff (confirmed `Asset` has `primary_driver_user_id` fillable line 44 + `belongsTo(User)` line 247).

**Route:** `routes/security-devices.php` line 254 — `POST /security-devices/integrations/queclink/devices/{queclinkDevice}/claim` → `claimDevice`, name `security-devices.integrations.queclink.claim`, middleware `permission:securityDevices.integrations.manage` (group, line 243).

---

## 4. Frontend pairing UI already offers "staff"

`resources/js/pages/security-devices/integrations/queclink-hub.tsx`:
- `type PairingType = 'vehicle' | 'staff' | 'client';` (line 86).
- Pending-tray "claim" dialog form has `pairing_type` + `target_id` (984–989); when `pairing_type==='staff'` it pulls the staff option list (996–999) and posts to `.../devices/${id}/claim` (1019).
- `index()` already supplies a **`targets.staff`** option list (controller lines 116–122): all `User`s with `whereNotNull('approved_at')`, labelled `name <email>`.

---

## 5. Ready-made staff-tracker LOOKUP (reuse for the lone-worker page)

`app/Domain/SecurityDevices/Services/DeviceRegistryService.php`:
- **`forStaff(int $tenantId, int $userId): Builder`** (lines 73–80) → devices with an active assignment to that staff user (`assignments()->active()->forTarget(DeviceAssignment::TARGET_STAFF, $userId)`).

This returns `Device` rows (with `latitude/longitude/last_seen_at/battery_level`) for a given lone-worker `User->id` — the exact query to surface "last-known location" on `/health-safety/lone-workers`. (Sibling helper `forClient`, lines 50–57, is what the resident side uses.)

---

## REUSE vs BUILD

**REUSE (no changes):**
- `DeviceAssignment` polymorphic model + `TARGET_STAFF` + `assignable()`→`User` resolution.
- `device_assignments` table (columns + indexes already support staff).
- `QueclinkDevice::PAIRING_STAFF` + `pending_pairing_type` enum.
- `QueclinkHubController::claimDevice` staff branch + `/claim` route + queclink-hub.tsx staff option.
- `ensurePersonalTrackerAsset('staff', …)`, `ensureCanonicalDevice` ("Lone-worker tracker …").
- **`DeviceRegistryService::forStaff($tenantId, $userId)`** — the lookup for the lone-worker page.

**BUILD (lone-worker feature, NOT pairing):**
- On the lone-worker session detail: call `forStaff()` for the session's worker `User`, render last-known lat/lng + `last_seen_at`/battery, and wire a "Locate now" action (queue the GL30 `requestLocation` command — `sendCommand`/`CommandBuilder::requestLocation`, controller line 344 — mirroring the resident path; this is covered in the locate/panic audit docs, not here).
- Optional convenience: a "pair this worker to a tracker" deep-link/affordance from the lone-worker UI into the existing queclink-hub claim flow (no new pairing backend).

**Bottom line:** a Queclink device can be assigned to a staff `User` **today** via the pending-tray claim flow (`pairing_type='staff'`) — the assignment is a polymorphic `device_assignments` row (`assignable_type='staff'`, `assignable_id=<user id>`). Minimal addition for the lone-worker feature is purely **read/surface + Locate-now wiring**, reusing `DeviceRegistryService::forStaff`.
