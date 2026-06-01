# Timed Shift Tasks + Due Reminders Audit Handoff

Authored: 2026-06-01

## Scope Implemented

- Added optional `scheduled_time` and `reminder_sent_at` to `shift_tasks`.
- Added `ShiftTask::scheduledFor()` as the single owner for combining a task time with the shift start and rolling cross-midnight tasks into the next day.
- Threaded timed task write/read paths through shift create, edit, duplicate, recurring series, editable payloads, My Day, shift detail, roster detail, team calendar, client calendar, and my calendar.
- Added due reminders through `ShiftTaskDueJob`, `ShiftTaskDueNotification`, in-app database notification, email, push, and a snoozable Control Room/My Day alert through `FacilitySignalService`.
- Added a Laravel `PushChannel`, Expo fan-out reuse, Web Push support through `minishlink/web-push`, VAPID config, service worker push handling, browser subscription hook, and settings toggle.
- Added i18n strings for English and Maori.

## Deduplication Decisions

- `app/Support/ShiftTaskSupport.php` owns task normalization, sync, creation, full calendar payloads, timed calendar payloads, and reminder reset helpers.
- `app/Models/ShiftTask.php::scheduledFor()` owns the cross-midnight rule. Jobs/resources/controllers call that instead of duplicating the time math.
- Calendar event payloads now expose full `tasks` plus display-only `timed_tasks`. This avoids deleting untimed tasks if a calendar editor posts the full `tasks` payload later, while UI timed-task badges/lists read `timed_tasks`.
- Push preference gating lives in `ShiftTaskDueNotification::via()`. `PushChannel` only dispatches a notification already selected for push, avoiding duplicate preference checks.
- Notification names/modules are centralized in `resources/js/pages/settings/notification-catalog.ts`, shared by preferences, defaults, and escalation settings.

## Review Improvements Applied

- Reminder claiming is atomic: `ShiftTaskDueJob` sets `reminder_sent_at` before dispatch and skips if another worker already claimed the row.
- If notification or alert emission throws, the job clears `reminder_sent_at` so the task can retry.
- Shift start-time changes clear prior reminder sends for incomplete timed tasks, including calendar updates and future recurring updates.
- My Day time grouping formats `scheduled_for` in `Pacific/Auckland` instead of the browser's local timezone.
- Browser notification clicks are same-origin constrained and focus/navigate an existing app window before opening a new one.
- The shift dialog's new timed-task checkbox defaults to the shift start time instead of a hard-coded `09:00`.
- The settings push toggle has an accessible label and the web-push hook reports errors without unhandled promise rethrows.
- Composer and npm audit advisories found during implementation were resolved with lockfile updates.

## Deployment Prerequisites

- Run migrations:
  - `database/migrations/2026_06_01_000001_add_scheduled_time_to_shift_tasks.php`
  - `database/migrations/2026_06_01_000002_add_keys_to_user_push_subscriptions.php`
- Set Web Push env values per environment:
  - `VAPID_PUBLIC_KEY`
  - `VAPID_PRIVATE_KEY`
  - `VAPID_SUBJECT`
- For Expo push, set:
  - `PUSH_PROVIDER=expo`
  - `EXPO_ACCESS_TOKEN`
- Ensure the scheduler cron is running. `routes/console.php` schedules `ShiftTaskDueJob` every five minutes in `Pacific/Auckland` with `withoutOverlapping()`.
- Ensure a queue worker is processing the `notifications` queue.
- `npm run build` regenerates Wayfinder route helpers. If running the focused Vitest file without a build first, run `php artisan wayfinder:generate`.

## Verification Run

- `php artisan test tests/Feature/ShiftTaskScheduledTimePayloadTest.php tests/Feature/ShiftTaskDueJobTest.php tests/Feature/PushNotificationsWebPushTest.php tests/Feature/ShiftControllerTest.php`
  - Passed: 41 tests, 185 assertions.
- `npm test -- resources/js/pages/operations/shifts/components/create-shift-dialog.test.tsx`
  - Passed: 1 test file, 1 test.
- `npm run types`
  - Passed.
- `npm run build`
  - Passed. Existing Vite warnings remain: `node:module` browser externalization and chunks over 500 kB.
- `vendor\bin\pint --test ...targeted PHP files...`
  - Passed.
- `npx prettier --check ...targeted frontend files...`
  - Passed.
- `composer audit --no-interaction`
  - Passed: no security vulnerability advisories found.
- `npm audit --audit-level=moderate`
  - Passed: found 0 vulnerabilities.

## Claude Audit Focus

- Confirm `scheduledFor()` handles all overnight examples expected by operations.
- Confirm My Day "What's next" ordering uses `scheduled_for` consistently for open clock-session tasks and shift-resource tasks.
- Confirm calendar update payloads preserve untimed tasks via full `tasks` while rendering only `timed_tasks`.
- Confirm browser Web Push delivery after real VAPID keys are configured.
- Confirm Expo delivery still works through existing Expo subscriptions.
- Confirm the `notifications` queue and scheduler are configured in each deployed environment.
- Confirm Control Room alert snooze/ack flows behave correctly for `source=shift_task`.

## Residual Notes

- Live push delivery was not exercised because VAPID keys and real browser/Expo tokens are environment-specific.
- No broad architecture changes were made; the feature reuses existing notification, scheduler, settings, and shift-task surfaces.
