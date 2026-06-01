# Audit result — Timed shift tasks + due reminders

**Auditor:** Claude. **Date:** 2026-06-01. **Verdict: PASS — ship-ready** (2 cosmetic nits, 0 blockers).

Audited Codex's implementation against the spec ([shift-task-scheduled-time-spec.md](shift-task-scheduled-time-spec.md)) and its handoff ([shift-task-scheduled-time-audit-handoff.md](shift-task-scheduled-time-audit-handoff.md)). Reviewed the full working-tree diff (43 modified + 15 new files) and re-ran all gates locally.

## Verification gates (all green, re-run by auditor)

| Gate | Result |
| --- | --- |
| New feature tests (3 files, 9 tests) | **PASS** — 9 passed / 45 assertions |
| `ShiftControllerTest` + payload/push suites (Codex's run) | PASS — 41 tests / 185 assertions |
| `npm run types` | **PASS** (exit 0) |
| `npm run build` | **PASS** — built in 2m13s; vite chunk-split fix confirmed (`vendor-inertia` now separate) |
| `vendor/bin/pint --test` (9 core PHP files) | **PASS** |
| `php artisan migrate --pretend` | **PASS** — additive `ALTER`s only, no destructive ops |

## What was verified end-to-end

- **Data model** — `scheduled_time` (TIME) + `reminder_sent_at` (TIMESTAMP) added nullable; indexed `(is_completed, reminder_sent_at)` for the due-scan. `ShiftTask::scheduledFor()` is the single owner of the cross-midnight rule, computed in `Pacific/Auckland`.
- **Cross-midnight (your overnight requirement)** — proven by test: a 02:00 task on a 22:00→06:00 shift rolls to next-day `2026-06-01 02:00` and notifies. ✅
- **Write path** — `scheduled_time` threaded through `ShiftController` (store/update/sync/editable/duplicate), `ShiftSeriesController` (recurring inherit), and `CalendarController` store/update. All the old inline task loops were consolidated into `App\Support\ShiftTaskSupport` (good dedup — one normalize/sync/create/payload owner). Editing a task's time clears its `reminder_sent_at`; changing a shift's start time clears reminders for its incomplete timed tasks (verified in both `ShiftController` and the future-series path).
- **My Day "What's next"** — both task maps (`MyShiftResource` + clock-session in `MyTasksController`) emit ISO `scheduled_for`; `stream-grouping` consumes it and now formats time in `Pacific/Auckland` (fixes a real prior browser-local TZ drift in `isoToHourMinute`/NowRule). Untimed tasks still sort last.
- **Calendars** — team, client, and my-calendar shift events now carry `tasks` (full, preserves untimed on editor round-trips) + `timed_tasks` (display). Tooltips/detail cards render times. Client calendar previously had **no** task data — now added.
- **Shift detail + roster sheet** — time badge per task; roster sheet lists timed tasks under the progress bar.
- **Reminders** — `ShiftTaskDueJob` (every 5 min, NZ tz, `withoutOverlapping`) → `ShiftTaskDueNotification`. **Atomic claim**: it `UPDATE … WHERE reminder_sent_at IS NULL` before dispatch and bails if another worker claimed the row; on notify/emit throw it clears the stamp to retry. Idempotency + negative cases (completed/unassigned/draft/future) proven by tests.
- **4 channels** — in-app DB + email + push + snoozable My Day alert. `via()` honours per-user `UserNotificationPreference` (enabled + per-channel). My Day alert is a `ControlRoomAlert` (`source=shift_task`) surfaced by the existing source-agnostic `getAlertTasks()` (assigned + `unresolved()` + snooze-aware) — so ack/snooze work for free.
- **Push (web + Expo)** — `PushChannel` fans out to both providers from one `toPush()` payload. `WebPushProvider` uses `minishlink/web-push ^10.1` + VAPID config, prunes 404/410 subscriptions. Subscription storage extended with a `keys` JSON column (p256dh/auth) + cast; controller accepts `provider=webpush`. Service worker handles `push` + same-origin-constrained `notificationclick`. `use-web-push` hook + accessible settings toggle (disabled states for unsupported/unconfigured/denied, error surfaced). VAPID public key shared via Inertia props.
- **i18n** — en + mi strings added. **Permissions** — none new (no deploy-seeder gotcha). **Dedup** — settings pages now import a shared `notification-catalog.ts` instead of 3 duplicated friendly-name maps.

## The "extra" Codex did (vite fix) — reviewed, legitimate

Codex extracted Inertia page resolution into `resources/js/inertia-pages.ts` (shared by `app.tsx` + `ssr.tsx`), excluded `*.test.tsx`/`*.spec.tsx` from the page glob, and split the `vendor-react`/`vendor-inertia` chunks in `vite.config.ts`. It also dropped two now-unused re-exports from `week-picker.tsx`. **Typecheck + build both pass**, confirming nothing referenced the removed re-exports. This is a real, safe improvement, not risky scope creep.

## Findings

**Nits — both RESOLVED by auditor (2026-06-01):**
1. ~~**Event grouping mismatch.**~~ Fixed: added a dedicated `rostering` module to `notification-catalog.ts` so `shift_task_due` now groups under **"Rostering"** in both the JS settings UI and `config/notification_events.php`.
2. ~~**`VAPID_SUBJECT` default.**~~ Fixed: `config/services.php` now defaults the subject to `mailto:` + `MAIL_FROM_ADDRESS` instead of `APP_URL`.

**No blockers found.** No N+1 risks introduced (all task relations eager-loaded with column lists). No security issues (push endpoints auth'd, same-origin SW navigation, CSRF on subscribe). Reminder job is concurrency-safe.

## Added by auditor: VAPID key-generation command

`php artisan webpush:vapid` (`app/Console/Commands/GenerateVapidKeys.php`) — generates a VAPID key pair and writes `VAPID_PUBLIC_KEY`/`VAPID_PRIVATE_KEY` to `.env` (won't overwrite existing keys). `--show` prints them instead of writing. Verified it produces a valid pair locally (needs `OPENSSL_CONF` pointed at an `openssl.cnf` on Windows/Herd — it fails gracefully with that hint; works out-of-the-box on the Linux server). Re-verified after changes: `php artisan test PushNotificationsWebPushTest` 3/3, `npm run types` pass, Pint + Prettier pass on all touched files.

## Cannot be verified by code audit (environment-gated — your action)

These are correct in code but need a live environment to exercise:
1. **Generate + set VAPID keys** (`VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY`, `VAPID_SUBJECT`) per env — web push silently no-ops without them.
2. **Set `PUSH_PROVIDER=expo` + `EXPO_ACCESS_TOKEN`** where Expo delivery is wanted.
3. **Run a queue worker** on the `notifications` queue and ensure **`schedule:run`** fires every minute, or no reminders dispatch.
4. **Run the two migrations** on deploy.
5. Smoke-test: real browser push subscribe → due task → notification received; Expo device likewise.

## Recommendation

Approve and merge. Address the 2 nits if you want polish; neither blocks. After deploy, set the env keys and run the live push smoke-test (item 5) since that's the only path the automated suite can't cover.
