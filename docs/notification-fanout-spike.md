# Spike: control-room notification fanout

**Status:** Open spike — DESIGN, not implementation. Output is a recommendation + small benchmark, NOT a PR.
**Origin:** frontline-ops audit, item O-3. After fixing the per-user query path, `admin@demo.test` still had 2,589 unread. Confirmed not a query bug — the count is real.

---

## Why this is a problem

A single demo admin accumulated 2,589 unread notifications. That's not an isolated case — any user whose role matches `managers_core` gets a row for every control-room alert plus every SLA-breach escalation. At production scale this:

- Hides actionable items behind noise (the inbox becomes a stream you scroll past).
- Bloats the `notifications` table — every alert × every admin × every escalation level.
- Burns email/push budgets on the channels that fan out from the same notify call.
- Skews any "engagement" metric we attach to read-rates.

Reducing the **count** isn't the goal — reducing **unnecessary copies of the same signal** is.

---

## Verified diagnosis

```php
// app/Services/NotificationService.php:35-38
const MANAGERS_CORE_ROLES = ['admin', 'provider_manager'];
```

```php
// app/Services/ControlRoom/ControlRoomNotificationService.php:107
$user->notify(new ControlRoomAlertNotification($alert, $extraContext));
```

`resolveUsersByRoles('managers_core')` returns **every** admin + provider_manager user. The loop notifies each one. So:

- 1 alert with `notify_roles: ['managers_core']` × N managers_core users = N notifications.
- Each SLA escalation re-fires the same fan-out (see [`CheckControlRoomSlaBreaches:75`](../app/Jobs/CheckControlRoomSlaBreaches.php)).

### Where it originates

26+ SignalRule seeds with `notify_roles: ['managers_core']` across these migrations:
- `database/migrations/2026_04_10_230000_seed_integration_signal_rules.php`
- `database/migrations/2026_04_10_240000_seed_medication_signal_types_and_rules.php`
- `database/migrations/2026_04_10_250000_seed_lone_worker_signal_types_and_rules.php`

### Where it surfaces

| Read site | File:line |
|---|---|
| Top-nav bell unread count | [`HandleInertiaRequests.php:203`](../app/Http/Middleware/HandleInertiaRequests.php) (deferred prop) |
| My Day stat tile | [`MyTasksController.php:96`](../app/Http/Controllers/MyTasksController.php) |
| Inbox dropdown items | `HandleInertiaRequests.php:204-216` |
| Portal | [`Portal/PortalNotificationController.php:20,36,56`](../app/Http/Controllers/Portal/PortalNotificationController.php) |

Both use the default Laravel `Notifiable` trait — per-user, no global scope.

---

## Quick benchmark (run before designing)

Numbers first. Don't pick a strategy without them.

```php
// Total rows per type, top 10
\DB::table('notifications')
    ->selectRaw('type, count(*) as n')
    ->groupBy('type')
    ->orderByDesc('n')
    ->limit(10)
    ->get();

// Worst-affected users
\DB::table('notifications')
    ->selectRaw('notifiable_id, count(*) as n, max(created_at) as latest')
    ->groupBy('notifiable_id')
    ->orderByDesc('n')
    ->limit(10)
    ->get();

// Fan-out factor: notifications per distinct source event
\DB::table('notifications')
    ->whereNotNull('data->control_room_alert_id')
    ->selectRaw("data->>'control_room_alert_id' as alert_id, count(*) as recipients")
    ->groupBy('alert_id')
    ->orderByDesc('recipients')
    ->limit(20)
    ->get();

// Time series — has the rate increased?
\DB::table('notifications')
    ->selectRaw('date(created_at) as day, count(*) as n')
    ->where('created_at', '>=', now()->subDays(30))
    ->groupBy('day')
    ->orderBy('day')
    ->get();
```

**Decision gate:** if `recipients` per alert is consistently ≥ 3 and the count grows >100/day on a typical week, fan-out is the cause. Anything else (e.g., one user receives 90% of rows) is a different problem and this spike is the wrong answer.

---

## Three candidate strategies

### Strategy A — Acknowledge model (cheapest)

**Idea:** Stop creating multiple DB rows per event. Create ONE row per event, store the recipient list in `data`, and let users mark "acknowledged" against the single record.

**Schema sketch:**
```sql
ALTER TABLE notifications ADD COLUMN broadcast_role VARCHAR(50) NULL;
CREATE TABLE notification_acknowledgements (
    notification_id BIGINT,
    user_id BIGINT,
    acknowledged_at TIMESTAMP,
    PRIMARY KEY (notification_id, user_id)
);
```

Unread count = "notifications where I'm an intended recipient AND I haven't acknowledged."

**Pros:**
- DB grows linearly with events, not events × recipients.
- "Mark as read" semantics already exist; "Acknowledge" mirrors them.
- One source of truth for the alert payload — edits propagate to all viewers.

**Cons:**
- Diverges from Laravel's `Notifiable` trait → custom unread query everywhere it's read.
- Needs schema migration + back-population.
- Email/push channels still need per-recipient deliveries (this only fixes the inbox).

### Strategy B — Targeted fan-out (most surgical)

**Idea:** Replace `managers_core` with finer-grained role targeting tied to the alert's `organization_id` + `site_id` + on-call rotation. Don't notify all admins — only the one(s) responsible right now.

**Concretely:**
- Add `on_call_for` lookup (existing? check `staff_on_call` / shift rosters).
- `ControlRoomNotificationService::resolveUsersByRoles` becomes `resolveResponders($alert)` — returns 1–3 users max.
- For audit, write a single archival row to a separate `notification_audits` table — visible to org admins on demand, not in the bell.

**Pros:**
- Inbox stays Laravel-native — no schema changes for `notifications`.
- Reduces the noise dramatically (1 row per alert instead of N).
- Aligns with how real on-call workflows operate.

**Cons:**
- "Who's responsible" is a product decision — needs sign-off from operations on the rotation source of truth (shifts? a separate on-call table? site contacts?).
- Loss of "everyone admin sees everything" affordance — may surprise users.
- If no responder is online, alert needs a fallback target.

### Strategy C — Squelch + retention (band-aid)

**Idea:** Keep fan-out. Aggressively expire old notifications. Add per-user mute on noisy types.

**Mechanics:**
- Nightly job: `delete from notifications where read_at < now() - interval '30 days'`.
- User setting: `muted_notification_types JSONB`. Read-side filter.
- "Mark all read" is the daily-driver UX.

**Pros:**
- No schema change, no service change, no product decision.
- Ships in a day.

**Cons:**
- Doesn't fix the root cause — write volume stays the same.
- Loses audit trail (the deletes).
- Encourages users to ignore the bell entirely.

---

## Recommendation

**B for new alerts. A only if the benchmark shows we already have a multi-GB notifications table and disk pressure matters. C as a stop-gap if neither can ship in time.**

Logic:
- B aligns with operational reality (on-call rotation is the right abstraction).
- A is more invasive (custom unread queries everywhere) — pay that cost only if the row volume itself is the problem, not just inbox UX.
- C is what you do this week so B has time to land properly.

---

## Out of scope for the spike

- The Microsoft Graph / FCM channels — they're per-recipient by API design.
- Notification UI redesign (the inbox dropdown).
- Audit-log policy for who-saw-what-when.
- Cross-tenant notification routing (this is single-tenant for now).

---

## What "done" looks like for the spike

1. Benchmark numbers committed to `docs/notification-fanout-benchmark.md`.
2. A 1-page decision memo: which strategy + why, signed off by the operations lead.
3. A scoped follow-up brief (B + C if both, A only if needed) ready for an implementation PR.

**Not** a PR. The implementation PR is downstream.

---

## File index for the implementor

| File | Purpose |
|---|---|
| `app/Services/NotificationService.php` | `MANAGERS_CORE_ROLES`, `resolveRoleGroupUserIds` |
| `app/Services/ControlRoom/ControlRoomNotificationService.php` | Fan-out site (line 107), SLA escalation (line 45) |
| `app/Jobs/CheckControlRoomSlaBreaches.php:75` | SLA-driven re-emit |
| `database/migrations/2026_04_10_*_signal_*.php` | The 26+ signal rules with `managers_core` |
| `database/migrations/2026_01_24_000010_create_notifications_table.php` | Current schema (no org scope) |
| `app/Http/Controllers/MyTasksController.php:96` | Read site (My Day) |
| `app/Http/Middleware/HandleInertiaRequests.php:201-217` | Read site (top-nav bell) |
| `app/Http/Controllers/NotificationInboxController.php` | `markRead` / `markAllRead` |
