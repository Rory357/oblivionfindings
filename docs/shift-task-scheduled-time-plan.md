# Timed shift tasks — audit & implementation plan

**Status:** Plan / not yet started. Authored 2026-06-01.

> **Build spec:** the diff-level, Codex-ready implementation spec lives in [shift-task-scheduled-time-spec.md](shift-task-scheduled-time-spec.md). Where the two disagree on a file path/line, the spec wins (it was verified against the code). This doc is the rationale/overview.

## Goal

Each shift task gets an optional **"Is this task for a specific time?"** toggle. When ticked, the
scheduler picks a time-of-day. That time then:

1. **Surfaces, in time order, in My Day → "What's next"** (the priority).
2. Shows on the **shift detail** page and the **roster** detail sheet.
3. Shows in the **team calendar** and **client calendar** event details.
4. **Notifies the assigned worker** when the task becomes due.

Untimed tasks behave exactly as today (flat checklist, no time, no reminder). The feature is
purely additive and backward-compatible.

## Decisions locked (2026-06-01)

| Decision | Choice |
| --- | --- |
| Calendar reach | List timed tasks in **team + client calendar event details** (plus My Day, shift detail, roster). Site/respite calendars do **not** show shifts and are out of scope. |
| Reminders | **Notify when due** — in-app notification via a scheduled job. (App has no push/SMS; baseline is the notification inbox, optional snoozable My Day alert.) |
| Overnight | **Handle cross-midnight** — store time-of-day, roll to the next day when the task time falls before the shift's start. |

## Current state (audited)

- `shift_tasks` columns today: `id, shift_id, label, is_completed, completed_at, completed_by, sort_order, timestamps` — **no time column**.
  See [migration](../database/migrations/2026_01_23_000009_create_shift_tasks_table.php), [model](../app/Models/ShiftTask.php).
- Tasks are created/edited inline in [create-shift-dialog.tsx](../resources/js/pages/operations/shifts/components/create-shift-dialog.tsx) and flow through `ShiftController` store/update/sync, `ShiftSeriesController` (repeat weekly), the editable payload, and the duplicate path.
- **My Day stream is already time-aware.** `inferTaskTime()` in [stream-grouping.ts:93](../resources/js/pages/my-day/lib/stream-grouping.ts) reads `task.at` or `task.scheduled_for` (ISO, via `isoToHourMinute`). Medications already use `scheduled_for`. So once the backend emits `scheduled_for` per task, the "What's next" rail orders it with **no stream changes**.
- Calendars that render shifts: **team** ([CalendarController](../app/Http/Controllers/CalendarController.php) + [calendar/index.tsx](../resources/js/pages/calendar/index.tsx)), **client** ([ClientCalendarController](../app/Http/Controllers/ClientCalendarController.php) + [clients/calendar.tsx](../resources/js/pages/operations/clients/calendar.tsx)), **my-calendar**. Calendars that **do not** render shifts (out of scope): site calendar (site events only), respite, compliance, meal-planner.
- Reminder machinery: scheduled jobs registered in [routes/console.php](../routes/console.php) (NZ `Pacific/Auckland` tz). Pattern to copy: `ChecklistDueJob` / `InspectionDueJob` → query due items → dispatch a `Notification` (`via(['database'])`, optional `mail`). Medication overdue uses `SendMedicationAlerts` (every 15 min) → `MedicationOverdueNotification`. **No push/SMS exists.** My Day alert cards come from the `ControlRoomAlert` model (snoozable via `snoozed_until`), separate from the `notifications` table.

## Data model

One migration adding two nullable columns to `shift_tasks`:

| Column | Type | Purpose |
| --- | --- | --- |
| `scheduled_time` | `TIME` nullable | The time-of-day. `NULL` = not time-specific (checkbox unticked). No separate boolean needed. |
| `reminder_sent_at` | `TIMESTAMP` nullable | Idempotency guard so the reminder job notifies once. Reset to `NULL` when `scheduled_time` changes or the task is reopened. |

- `app/Models/ShiftTask.php`: add both to `$fillable`; cast `scheduled_time` → `'datetime:H:i'` (or keep string) and `reminder_sent_at` → `'datetime'`.
- **Single source of truth for the absolute moment** — add a helper so cross-midnight logic lives in one place, reused by My Day, calendars and the reminder job:

```php
// ShiftTask::scheduledFor() — requires $this->shift to be loaded
public function scheduledFor(): ?\Carbon\CarbonImmutable
{
    if (! $this->scheduled_time || ! $this->shift) return null;
    $start = \Carbon\CarbonImmutable::parse($this->shift->starts_at); // Shift uses starts_at/ends_at
    $moment = $start->setTimeFrom(\Carbon\CarbonImmutable::parse($this->scheduled_time));
    return $moment->lt($start) ? $moment->addDay() : $moment; // cross-midnight roll
}
```

> Verified: the Shift model uses `starts_at` / `ends_at` (both cast `datetime`).

No backfill required — existing tasks keep `NULL`.

## Write path (form → DB)

| File | Change |
| --- | --- |
| [create-shift-dialog.tsx](../resources/js/pages/operations/shifts/components/create-shift-dialog.tsx) | Per task row: a checkbox "Is this task for a specific time?" + a `<input type="time">` shown when ticked. Add `scheduled_time` to the task row state, hydrate it from `initialShift`, include it in the POST payload. |
| `create-shift-dialog.tsx` (`EditableShift` type, ~line 104) | Add `scheduled_time?: string \| null` to the task array type. (The type lives in the dialog file, not a separate shift-types.ts.) |
| [ShiftController](../app/Http/Controllers/ShiftController.php) `store()` | Validation `'tasks.*.scheduled_time' => ['nullable','date_format:H:i']`; carry `scheduled_time` into the task-create map. |
| `ShiftController` `update()` + `syncShiftTasks()` | Same validation; carry `scheduled_time` in the incoming map and in both the update and create branches; reset `reminder_sent_at = null` when `scheduled_time` changes. |
| `ShiftController` `editableShiftPayload()` | Emit `scheduled_time` (`->scheduled_time?->format('H:i')`) so edits preload the value. |
| `ShiftController` duplicate-shift logic | Copy `scheduled_time` onto the cloned tasks. |
| [ShiftSeriesController](../app/Http/Controllers/ShiftSeriesController.php) `store()` | Same validation; carry `scheduled_time` so each repeated occurrence inherits it. |
| [ShiftTaskController](../app/Http/Controllers/ShiftTaskController.php) `update()` | **No change** — it only toggles completion. |

> Optional validation polish: soft-warn (not block) if `scheduled_time` falls outside the shift window.

## Read / surface path

### My Day "What's next" (priority — minimal work)

| File | Change |
| --- | --- |
| [MyShiftResource.php:52-57](../app/Http/Resources/MyShiftResource.php) | In the task map, add `'scheduled_for' => $task->scheduledFor()?->toIso8601String()` (method already receives `$workerNow`). |
| [MyTasksController.php:366-371](../app/Http/Controllers/MyTasksController.php) | Same addition in the clock-session `open_session.tasks` map (second, separate mapping). |
| [my-day/lib/types.ts:38-45](../resources/js/pages/my-day/lib/types.ts) | Add `scheduled_for?: string \| null` to `MyDayShiftTask`. |
| [stream-grouping.ts:93](../resources/js/pages/my-day/lib/stream-grouping.ts), [whats-next-rail.tsx](../resources/js/pages/my-day/components/whats-next-rail.tsx) | **No change** — `inferTaskTime()` already consumes `scheduled_for`; the rail sorts/groups by time automatically. Untimed tasks (empty time) sort last, as today. |

### Shift detail + roster

| File | Change |
| --- | --- |
| [shifts/show.tsx:1258-1295](../resources/js/pages/operations/shifts/show.tsx) | Render a time badge on each task row (Task type at lines 93-97 gains `scheduled_time`); optionally sort tasks by time. Ensure the shift payload includes `scheduled_time`. |
| [roster/shift-detail-sheet.tsx](../resources/js/components/roster/shift-detail-sheet.tsx) | Optional: list the timed tasks (currently a progress bar only). Ensure `RosterShift.tasks` carries `scheduled_time`. |

### Calendars

| File | Change |
| --- | --- |
| [CalendarController.php:94-98,178-179](../app/Http/Controllers/CalendarController.php) | Alongside `tasks_total`/`tasks_completed`, add a `tasks` array (`id, label, scheduled_time, is_completed`) to `extendedProps` for shifts that have timed tasks. |
| [calendar/index.tsx:931-933](../resources/js/pages/calendar/index.tsx) | In the event tooltip/popover, list timed tasks ("14:00 · Give medication") under the existing count badge. |
| [ClientCalendarController.php:28-52](../app/Http/Controllers/ClientCalendarController.php) | Add a `tasks` array to the shift event `extendedProps` (none today). Eager-load `tasks`. |
| [clients/calendar.tsx:673-677](../resources/js/pages/operations/clients/calendar.tsx) | In the shift event detail card (near `staff_name`), render the timed task list. |
| my-calendar (`MyCalendarController` + my-calendar.tsx) | Same treatment as the team calendar. |

## Reminders ("notify when due")

Reuse the existing scheduled-job + Notification pattern. No push/SMS — delivery is the in-app notification inbox (bell), optionally email, optionally a snoozable My Day alert.

1. **`app/Notifications/ShiftTaskDueNotification.php`** (new) — copy `ChecklistDueNotification`. `via(['database'])` baseline; add `'mail'` only if wanted. Payload: shift id, task label, client, scheduled time, deep link to the shift / My Day.
2. **`app/Jobs/CheckShiftTasksDueJob.php`** (new) — copy `ChecklistDueJob`/`InspectionDueJob`. Query `ShiftTask` where `is_completed = false`, `scheduled_time` not null, `reminder_sent_at` null, whose parent shift is **assigned** (`user_id` set), today/active, and whose `scheduledFor() <= now`. Notify `shift->user_id`. Stamp `reminder_sent_at = now()` (idempotency). Skip open/unassigned shifts.
3. **Register in [routes/console.php](../routes/console.php)** — `->everyFiveMinutes()->timezone('Pacific/Auckland')` (matches `SendEventReminderJob` cadence; meds use 15 min).
4. **Optional — snoozable My Day card:** for higher-signal reminders, also emit a `ControlRoomAlert` (mirroring `InspectionDueJob`'s Control Room signal) so it appears in the My Day alert rail with ack/snooze. Likely redundant given the task already shows at the right time in the stream — treat as a follow-up, not v1.

**Permissions/seeding:** reuse existing shift permissions; no new permission key, so the deploy-seeder gotcha (permissions seeded separately from migrations) does **not** apply here.

## Localisation

Add strings to [lang/en/my-day.php](../lang/en/my-day.php) and [lang/mi/my-day.php](../lang/mi/my-day.php) (e.g. "Scheduled :time", "Task due"), plus any shift-form labels for the new checkbox/time field in the relevant `lang/*` files.

## Tests

- `ShiftControllerTest`: store + update persist/round-trip `scheduled_time`; editable payload returns it; duplicate copies it.
- Series test: each repeated occurrence inherits `scheduled_time`.
- My Day payload test (mirror `MyDayMedicationsDuePayloadTest`): a timed task emits `scheduled_for` and lands in the correct "What's next" bucket; cross-midnight task rolls to the next day.
- New `CheckShiftTasksDueJob` test: notifies the assigned worker once when due, is idempotent (`reminder_sent_at`), skips completed/unassigned/open shifts.
- Frontend: shift-detail and whats-next-rail render the time; ordering verified.

## Suggested phasing

1. **Data model** — migration + model helper (`scheduledFor`) + casts.
2. **Write path** — form UI + controllers/series + editable/duplicate.
3. **My Day** — emit `scheduled_for` (2 mappings) + type; verify ordering.
4. **Detail + calendars** — shift detail, roster, team + client calendars.
5. **Reminders** — notification + job + scheduler (+ optional My Day alert).
6. **Tests + i18n + cleanup.**

Phases 1-3 deliver the headline ask (timed tasks ordered in "What's next"); 4-5 complete "pulls through everywhere" + reminders.

## Open items to confirm at implementation

- Shift start column name for `scheduledFor()` (`start_at` vs `starts_at`).
- Reminder cadence (5 vs 15 min) and whether email is wanted in addition to in-app.
- Whether to also render each timed task as its own calendar marker (currently: listed in event detail only).
- Whether to soft-validate task time within the shift window.
