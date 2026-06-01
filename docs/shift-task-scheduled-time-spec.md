# Implementation spec — Timed shift tasks + due reminders

**Audience:** the implementing engineer (Codex). Self-contained; do not assume access to the chat that produced it.
**Authored:** 2026-06-01. Companion to [shift-task-scheduled-time-plan.md](shift-task-scheduled-time-plan.md) (rationale/overview). Where this spec and the plan doc disagree on a file path or line, **this spec wins** (it was verified against the code).

> All line numbers were confirmed on 2026-06-01 but may drift — match on the quoted code, not the number.

---

## 0. What we're building (locked scope)

Each shift task gains an optional **specific time-of-day** ("Is this task for a specific time?" checkbox → time picker in the create/edit shift dialog). When set, the time:

1. Orders the task in **My Day → "What's next"** (priority).
2. Shows on the **shift detail** page and the **roster** detail sheet.
3. Shows in the **team calendar** and **client calendar** event details.
4. **Notifies the assigned worker when the task is due**, via **in-app bell + email + push (web *and* Expo) + a snoozable My Day alert card**.

Untimed tasks are unchanged (flat checklist, no time, no reminder). Everything is additive and backward-compatible.

**Locked product decisions:** calendars in scope (team + client); reminders ON; cross-midnight handled; push target = **both web Web-Push and Expo**; extra channels = **email + snoozable My Day alert**.

---

## 1. Current state (verified facts to build on)

**Shift** (`app/Models/Shift.php`): start/end columns are **`starts_at` / `ends_at`** (both cast `datetime`); assigned worker is **`user_id`**; `status` ∈ {`draft`,`scheduled`,`in_progress`,`completed`,`cancelled`}; `tasks()` = `hasMany(ShiftTask::class)->orderBy('sort_order')`; has `client()` and `site()`.

**ShiftTask** (`app/Models/ShiftTask.php`) — `$fillable = [shift_id, label, is_completed, completed_at, completed_by, sort_order]`; casts `is_completed`→bool, `completed_at`→datetime. Table from `database/migrations/2026_01_23_000009_create_shift_tasks_table.php` — **no time column**.

**My Day stream is already time-aware.** `inferTaskTime()` in `resources/js/pages/my-day/lib/stream-grouping.ts:93-98` reads `task.at` **or** `task.scheduled_for` (ISO, via `isoToHourMinute`). Meds already use `scheduled_for`. ⇒ emit `scheduled_for` per task and the rail orders it with no stream changes.

**Reminder pattern** (mirror exactly): `app/Jobs/ChecklistDueJob.php` queries due items and calls `$user->notify(new ChecklistDueNotification(...))` on queue `notifications`; **no idempotency guard** (it runs daily). `app/Notifications/ChecklistDueNotification.php` → `via()=['database','mail']` + `toMail()` + `toDatabase()`. `app/Jobs/InspectionDueJob.php` additionally injects `FacilitySignalService` and emits a Control Room signal on overdue. Scheduler in `routes/console.php`: `app(Schedule::class)->job(new XJob)->timezone('Pacific/Auckland')->everyFiveMinutes();` (imports grouped at the top, ~lines 30-44).

**Push (Expo backend complete; no channel bridge; no web capture):**
- Table `user_push_subscriptions` (`database/migrations/2026_04_24_000100_*`): `user_id, provider(default 'expo'), token(512), device_id, platform, enabled, last_used_at`; `unique(provider, token)`. Model `app/Models/UserPushSubscription.php`. `User::pushSubscriptions()` at `app/Models/User.php:186-189`.
- `app/Services/Notifications/PushProvider.php` interface: `send(array $tokens, string $message, ?string $title=null, array $data=[]): PushSendResult`. `ExpoPushProvider` (POSTs to Expo), `FailingPushProvider`, `PushSendResult`. Bound in `app/Providers/AppServiceProvider.php:138-149` by `config('services.push.provider')` (single provider). Already used by `DeliverBroadcastCommunicationJob::sendPush()`.
- Subscribe/unsubscribe: `routes/settings.php` → `settings.notifications.push-subscriptions.store|destroy` → `app/Http/Controllers/Settings/PushSubscriptionController.php` (validates `{provider, token, device_id, platform}`, `updateOrCreate` on `(provider, token)`).
- Prefs: `UserNotificationPreference` (`user_id, key, enabled, channel_inapp, channel_email, channel_push, channel_sms`; push defaults false). Channel-level prefs are **not yet enforced** in `via()` (TODO in `NotificationService`). Prefs UI: `app/Http/Controllers/Settings/NotificationPreferencesController.php` + `resources/js/pages/settings/notifications.tsx`.
- **Missing:** (a) a Laravel notification channel that routes `$user->notify()` to the push provider(s); (b) a Web-Push provider + `minishlink/web-push` package + VAPID; (c) browser token capture (service worker + subscribe flow). No web-push lib in `composer.json`/`package.json`.

---

## 2. Data model

New migration `database/migrations/2026_06_01_000000_add_scheduled_time_to_shift_tasks.php`:

```php
Schema::table('shift_tasks', function (Blueprint $table) {
    $table->time('scheduled_time')->nullable()->after('label');
    $table->timestamp('reminder_sent_at')->nullable()->after('completed_by');
    $table->index(['is_completed', 'reminder_sent_at']); // for the due-scan query
});
```

`app/Models/ShiftTask.php` — add to `$fillable`: `'scheduled_time'`, `'reminder_sent_at'`; add casts `'scheduled_time' => 'string'` (store/return `'H:i'`) and `'reminder_sent_at' => 'datetime'`.

**Single source of truth for the absolute moment (cross-midnight).** Add to `ShiftTask` (requires `shift` loaded):

```php
public function scheduledFor(): ?\Carbon\CarbonImmutable
{
    if (! $this->scheduled_time || ! $this->shift?->starts_at) {
        return null;
    }
    $start = \Carbon\CarbonImmutable::parse($this->shift->starts_at);
    $moment = $start->setTimeFromTimeString($this->scheduled_time);
    return $moment->lt($start) ? $moment->addDay() : $moment; // roll past midnight
}
```

`scheduled_time === null` ⇒ "not time-specific" (no separate boolean). `reminder_sent_at` makes the due-scan idempotent. No backfill — existing rows stay null.

---

## 3. Write path (form → DB)

> `tasks` rows currently carry `{id?, label}`. Add `scheduled_time` (`'H:i'` string or null) everywhere a task row is validated, mapped, created, updated, copied, or serialized for editing.

**3.1 `app/Http/Controllers/ShiftController.php`**

- `store()` validation (~865-866): add `'tasks.*.scheduled_time' => ['nullable','date_format:H:i']`.
- `store()` create map (~949-960): include `'scheduled_time' => $t['scheduled_time'] ?? null` in the mapped array and in the `ShiftTask::create([...])`.
- `update()` validation (~1224-1227): add the same `'tasks.*.scheduled_time'` rule.
- `syncShiftTasks()` (2308-2343): in the `$incoming` map add `'scheduled_time' => $task['scheduled_time'] ?? null`; in the **update** branch add `'scheduled_time' => $task['scheduled_time']` **and reset the reminder when the time changes**:
  ```php
  $existingRow = $existing[$task['id']];
  $timeChanged = $existingRow->scheduled_time !== $task['scheduled_time'];
  $existingRow->update([
      'label' => $task['label'],
      'sort_order' => $task['sort_order'],
      'scheduled_time' => $task['scheduled_time'],
      'reminder_sent_at' => $timeChanged ? null : $existingRow->reminder_sent_at,
  ]);
  ```
  in the **create** branch add `'scheduled_time' => $task['scheduled_time']`.
- `editableShiftPayload()` (1170-1176): add `'scheduled_time' => $task->scheduled_time` to the tasks map so edits preload the value.
- duplicate-shift block (1087-1099): add `'scheduled_time' => $task->scheduled_time` (leave `reminder_sent_at` null on the copy).

**3.2 `app/Http/Controllers/ShiftSeriesController.php`**
- `store()` validation (~305-306): add `'tasks.*.scheduled_time' => ['nullable','date_format:H:i']`.
- per-occurrence task map + create (~467-500): carry `'scheduled_time'` so every occurrence inherits it.

**3.3 `ShiftTaskController@update`** — no change (completion toggle only).

**3.4 Frontend `resources/js/pages/operations/shifts/components/create-shift-dialog.tsx`**
- `EditableShift` type (88-105, tasks at 104): `tasks?: Array<{ id: number; label: string; scheduled_time?: string | null }>;`
- state init (281-284): map `scheduled_time: t.scheduled_time ?? null`; widen the inline cast to `Array<{ id?: number; label: string; scheduled_time?: string | null }>`.
- helpers (358-371): keep `addTask` (`{ label: '', scheduled_time: null }`); add `setTaskTime(i, value)` that sets `scheduled_time: value || null`; `setTask` unchanged.
- row render (1049-1083): after the label `<input>` and before the trash button, add a checkbox + time input:
  ```tsx
  <label className="flex items-center gap-1 text-xs text-muted-foreground">
    <input type="checkbox"
      checked={t.scheduled_time != null}
      onChange={(e) => setTaskTime(i, e.target.checked ? '09:00' : null)} />
    Specific time
  </label>
  {t.scheduled_time != null && (
    <input type="time" className="input w-28"
      value={t.scheduled_time}
      onChange={(e) => setTaskTime(i, e.target.value)} />
  )}
  ```
- submit: single-shift `form.transform` (550-561) passes `tasks` through — fine; recurring path (602) filters empty labels — keep, `scheduled_time` rides along.

---

## 4. Read / surface path

**4.1 My Day "What's next" (emit `scheduled_for`; no stream changes)**
- `app/Http/Resources/MyShiftResource.php` task map (52-57): add `'scheduled_for' => $task->scheduledFor()?->toIso8601String()`. (Method already receives `$workerNow`; ensure `$task->shift` is set — it's mapped off `$shift`, so set the relation or compute the moment from the parent `$shift->starts_at` directly.)
- `app/Http/Controllers/MyTasksController.php` clock-session task map (366-371): add the same `'scheduled_for'`.
- Ensure both queries eager-load tasks (they already load `tasks`; confirm `scheduled_time` is selected — it will be with `with('tasks')`).
- `resources/js/pages/my-day/lib/types.ts` `MyDayShiftTask` (38-45): add `scheduled_for?: string | null;`.
- `stream-grouping.ts` / `whats-next-rail.tsx`: **no change** (consume `scheduled_for`; untimed tasks sort last as today). Optionally show a small "time" badge on the timed task item in `resources/js/pages/my-day/components/stream-item.tsx`.

**4.2 Shift detail + roster**
- `resources/js/pages/operations/shifts/show.tsx`: extend the `Task` type (93-97) with `scheduled_time?: string | null`; in the task row (1258-1295) render a time badge when set; optionally sort the list by `scheduled_time` (nulls last). Ensure the shift→props payload includes `scheduled_time` (the controller building `show` props must map it).
- `resources/js/components/roster/shift-detail-sheet.tsx` (progress bar 81-87): ensure `RosterShift.tasks` includes `scheduled_time`; optionally list timed tasks under the progress bar.

**4.3 Calendars**
- `app/Http/Controllers/CalendarController.php`: alongside the `withCount` (94-98) and `extendedProps` (178-179), eager-load `tasks` and add a compact `'tasks' => [...]` array (`label`, `scheduled_time`, `is_completed`) to `extendedProps` for shifts that have ≥1 timed task. `resources/js/pages/calendar/index.tsx` tooltip (931-933): under the count badge, list the timed tasks (e.g. "14:00 · Give medication").
- `app/Http/Controllers/ClientCalendarController.php` shift serialization (28-52, no tasks today): eager-load + add the same `tasks` array to the shift event `extendedProps`. `resources/js/pages/operations/clients/calendar.tsx` shift detail card (~673-677, near `staff_name`): render the timed task list.
- `MyCalendarController` + `resources/js/pages/my-calendar.tsx`: same treatment as the team calendar (own shifts).

---

## 5. Reminder: notification + job + scheduler + My Day alert

**5.1 `app/Notifications/ShiftTaskDueNotification.php`** (model after `ChecklistDueNotification`):

```php
class ShiftTaskDueNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public ShiftTask $task) {}

    public function via(object $notifiable): array
    {
        $channels = ['database', 'mail', \App\Notifications\Channels\PushChannel::class];
        return $channels; // PushChannel self-skips users with no subscriptions / push disabled
    }

    public function toMail(object $notifiable): MailMessage { /* subject + line + ->action('Open My Day', url('/my-day')) */ }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'shift_task_due',
            'title' => 'Task due',
            'message' => "{$this->task->label} is due now"
                . ($this->task->shift?->client?->name ? " for {$this->task->shift->client->name}" : ''),
            'severity' => 'info',
            'shift_id' => $this->task->shift_id,
            'shift_task_id' => $this->task->id,
            'action_url' => '/my-day',
        ];
    }

    // Consumed by PushChannel (§6)
    public function toPush(object $notifiable): array
    {
        return [
            'title' => 'Task due',
            'body' => "{$this->task->label} is due now",
            'data' => ['url' => '/my-day', 'shift_id' => $this->task->shift_id, 'shift_task_id' => $this->task->id],
        ];
    }
}
```

**5.2 `app/Jobs/ShiftTaskDueJob.php`** (model after `ChecklistDueJob`, queue `notifications`):

```php
public function handle(\App\Services\Facility\FacilitySignalService $signals): void
{
    $now = now();
    $tasks = ShiftTask::query()
        ->where('is_completed', false)
        ->whereNull('reminder_sent_at')
        ->whereNotNull('scheduled_time')
        ->whereHas('shift', fn ($q) => $q
            ->whereNotNull('user_id')
            ->whereIn('status', ['scheduled', 'in_progress'])
            ->whereDate('starts_at', '>=', $now->copy()->subDay()->toDateString()) // window incl. overnight
            ->whereDate('starts_at', '<=', $now->toDateString()))
        ->with(['shift.user', 'shift.client'])
        ->get();

    foreach ($tasks as $task) {
        $due = $task->scheduledFor();
        if (! $due || $due->gt($now)) {
            continue; // not due yet
        }
        $worker = $task->shift?->user;
        if ($worker) {
            $worker->notify(new ShiftTaskDueNotification($task));
            // §5.4 snoozable My Day alert
            $this->raiseMyDayAlert($signals, $task, $worker);
        }
        $task->forceFill(['reminder_sent_at' => $now])->save(); // idempotent — fire once
    }
}
```

> The `starts_at` date window is a coarse prefilter; `scheduledFor()` + the `gt($now)` check are authoritative (and handle cross-midnight). Tune the window if shifts can span >1 day.

**5.3 Register in `routes/console.php`** — add `use App\Jobs\ShiftTaskDueJob;` with the other job imports, then:
```php
// Notify the assigned worker when a timed shift task falls due
app(Schedule::class)
    ->job(new ShiftTaskDueJob)
    ->timezone('Pacific/Auckland')
    ->everyFiveMinutes();
```

**5.4 Snoozable My Day alert (opted in).** My Day alert cards come from `ControlRoomAlert` rows assigned to the user (read by `MyTasksController::getAlertTasks()`; ack/snooze via `MyDayActionsController`). Raise one per due task, mirroring how `InspectionDueJob` emits a signal. Preferred: add an `emitShiftTaskDue(ShiftTask $task, User $worker)` to `app/Services/Facility/FacilitySignalService.php` following the existing `emit*` methods so it flows through the normal signal→alert pipeline. If that pipeline requires a registered signal type that's heavier than warranted, fall back to creating the `ControlRoomAlert` directly with: `source` (e.g. `'shift_task'`), `alert_type='shift_task_due'`, `severity` (info/low), `status='open'`, `assigned_to_user_id=$worker->id`, `site_id`/`client_id` from the shift, `triggered_at=now()`, `due_at=$task->scheduledFor()`, `context=['shift_id'=>..., 'shift_task_id'=>..., 'label'=>...]`.
> **VERIFY:** the exact `FacilitySignalService` emit signature and that `getAlertTasks()` surfaces this `source`/`alert_type` (grep `getAlertTasks` in `MyTasksController`). Dedupe via the same `reminder_sent_at` guard (alert is created in the same loop iteration, so it fires once).

---

## 6. Push delivery — channel bridge (Expo) + Web-Push stack

### 6.1 Backend: one notification channel, multiple providers

**`app/Notifications/Channels/PushChannel.php`** (new) — invoked because `via()` returns its class name:

```php
public function send($notifiable, Notification $notification): void
{
    if (! method_exists($notification, 'toPush')) return;
    if ($this->pushDisabled($notifiable, $notification)) return; // optional pref check, see §6.5

    $subs = $notifiable->pushSubscriptions()->where('enabled', true)->get();
    if ($subs->isEmpty()) return;

    $payload = $notification->toPush($notifiable);

    // Expo: token-based, reuse existing provider
    $expoTokens = $subs->where('provider', 'expo')->pluck('token')->all();
    if ($expoTokens) {
        app(\App\Services\Notifications\ExpoPushProvider::class) // or resolve via config
            ->send($expoTokens, $payload['body'] ?? '', $payload['title'] ?? null, $payload['data'] ?? []);
    }

    // Web Push: endpoint+keys per subscription
    $web = $subs->where('provider', 'webpush');
    if ($web->isNotEmpty()) {
        app(\App\Services\Notifications\WebPushProvider::class)->sendToSubscriptions($web, $payload);
    }
}
```

> The existing `AppServiceProvider` binds a single `PushProvider` by `PUSH_PROVIDER`; the channel deliberately resolves both concrete providers so web + Expo work simultaneously regardless of that env. Keep the legacy binding for `DeliverBroadcastCommunicationJob`.

**`app/Services/Notifications/WebPushProvider.php`** (new) — uses `minishlink/web-push`:

```php
public function sendToSubscriptions(\Illuminate\Support\Collection $subs, array $payload): void
{
    $auth = [ 'VAPID' => [
        'subject' => config('services.webpush.subject'),
        'publicKey' => config('services.webpush.public_key'),
        'privateKey' => config('services.webpush.private_key'),
    ]];
    $webPush = new \Minishlink\WebPush\WebPush($auth);
    foreach ($subs as $sub) {
        $subscription = \Minishlink\WebPush\Subscription::create([
            'endpoint' => $sub->token,                 // endpoint stored in token (see 6.2)
            'keys' => $sub->keys ?? [],                 // {p256dh, auth}
        ]);
        $webPush->queueNotification($subscription, json_encode([
            'title' => $payload['title'] ?? '', 'body' => $payload['body'] ?? '', 'data' => $payload['data'] ?? [],
        ]));
    }
    foreach ($webPush->flush() as $report) {
        if (! $report->isSuccess() && $report->isSubscriptionExpired()) {
            \App\Models\UserPushSubscription::where('provider','webpush')
                ->where('token', $report->getRequest()->getUri()->__toString())->update(['enabled' => false]);
        }
    }
}
```

**Composer:** add `minishlink/web-push` (`composer require minishlink/web-push`). **Config:** add to `config/services.php`:
```php
'webpush' => [
    'public_key'  => env('VAPID_PUBLIC_KEY'),
    'private_key' => env('VAPID_PRIVATE_KEY'),
    'subject'     => env('VAPID_SUBJECT', 'mailto:admin@oblivionfindings.com'),
],
```
Add the three keys to `.env.example`. Generate a keypair once with `Minishlink\WebPush\VAPID::createVapidKeys()` (a tiny `php artisan` command or `tinker`) and set them per environment.

### 6.2 Subscription storage for web push

New migration `..._add_webpush_keys_to_user_push_subscriptions.php`: add `$table->json('keys')->nullable();` to `user_push_subscriptions`. For `provider='webpush'`, store the browser `endpoint` in the existing `token` column (browser endpoints fit in 512; widen to `text` only if you hit the limit) and `{p256dh, auth}` in `keys`. Add `'keys'` to `UserPushSubscription::$fillable` and cast `'keys' => 'array'`.

Extend `app/Http/Controllers/Settings/PushSubscriptionController.php@store` validation + write to accept web push:
```php
'provider' => ['nullable','string','max:32'],
'token'    => ['required','string','max:512'],     // expo token OR webpush endpoint
'keys'     => ['nullable','array'],
'keys.p256dh' => ['required_if:provider,webpush','string'],
'keys.auth'   => ['required_if:provider,webpush','string'],
'device_id'=> ['nullable','string','max:255'],
'platform' => ['nullable','string','max:32'],
```
…and include `'keys' => $data['keys'] ?? null` in the `updateOrCreate` values. `destroy` already keys off `(provider, token)` — pass the endpoint as `token` for webpush.

### 6.3 Frontend: service worker + subscribe flow

- **Service worker** `public/sw.js` (served at root ⇒ root scope):
  ```js
  self.addEventListener('push', (e) => {
    const p = e.data ? e.data.json() : {};
    e.waitUntil(self.registration.showNotification(p.title || 'Notification', {
      body: p.body || '', data: p.data || {}, icon: '/icon-192.png', badge: '/badge.png',
    }));
  });
  self.addEventListener('notificationclick', (e) => {
    e.notification.close();
    const url = (e.notification.data && e.notification.data.url) || '/my-day';
    e.waitUntil(clients.matchAll({ type: 'window' }).then((cs) => {
      for (const c of cs) if (c.url.includes(url) && 'focus' in c) return c.focus();
      return clients.openWindow(url);
    }));
  });
  ```
- **Expose VAPID public key** to the SPA: add `webpush_public_key => config('services.webpush.public_key')` to the shared props in `app/Http/Middleware/HandleInertiaRequests.php` (so `usePage().props` can read it).
- **Hook** `resources/js/hooks/use-web-push.ts`: feature-detect (`'serviceWorker' in navigator && 'PushManager' in window`); `register('/sw.js')`; `Notification.requestPermission()`; `reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: urlBase64ToUint8Array(vapidPublicKey) })`; POST `{ provider:'webpush', token: sub.endpoint, keys: { p256dh, auth }, platform:'web' }` to `settings.notifications.push-subscriptions.store`; unsubscribe → `sub.unsubscribe()` + DELETE to `.destroy`. Include the standard `urlBase64ToUint8Array` helper.
- **Toggle UI:** add a "Push notifications (this device)" switch to `resources/js/pages/settings/notifications.tsx` wired to the hook, showing enabled/permission state.

> **VERIFY:** the SPA entry is `resources/js/app.tsx`; register the SW there (or lazily from the toggle). Confirm the exact shared-props array shape in `HandleInertiaRequests`.

### 6.4 Expo side

No new capture work in this repo — Expo device tokens are registered by the mobile app via the existing `store` route with `provider='expo'`. The new `PushChannel` already fans out to them. Ensure `PUSH_PROVIDER=expo` and `EXPO_ACCESS_TOKEN` are set where Expo delivery is wanted.

### 6.5 Preferences

Channel-level prefs aren't globally enforced yet. Minimum: `PushChannel` sends whenever the user has ≥1 enabled subscription. Optional (nicer): in `pushDisabled()`, return true if a `UserNotificationPreference` row for key `shift_task_due` has `channel_push=false`. Likewise gate `'mail'` on `channel_email` if you want email opt-out. Document whichever you choose.

---

## 7. Localisation

Add strings to `lang/en/my-day.php` and `lang/mi/my-day.php` (e.g. `scheduled_at_time => 'Scheduled :time'`, `task_due => 'Task due'`) and any shift-form labels ("Is this task for a specific time?") in the relevant `lang/*` files. Notification subject/body should use translations where the surrounding notifications do.

---

## 8. Tests

PHP (Pest/PHPUnit, mirror `tests/Feature/ShiftControllerTest.php` & `MyDayMedicationsDuePayloadTest.php`):
- store + update round-trip `scheduled_time`; editable payload returns it; duplicate copies it; series occurrences inherit it.
- `update` resets `reminder_sent_at` when `scheduled_time` changes.
- `MyShiftResource`/My Day payload emits `scheduled_for`; a cross-midnight task (e.g. 02:00 on a 22:00→06:00 shift) returns next-day ISO.
- `ShiftTaskDueJob`: notifies the assigned worker once when due (`Notification::fake()` asserts `ShiftTaskDueNotification`); sets `reminder_sent_at`; second run sends nothing; skips completed / unassigned / draft / future-time tasks; raises the My Day alert.
- `PushChannel`: with an expo sub → `ExpoPushProvider::send` called; with a webpush sub → `WebPushProvider::sendToSubscriptions` called; with none → no-op. (Bind fakes/mocks.)
- `PushSubscriptionController@store` persists a webpush subscription (`token`=endpoint, `keys`={p256dh,auth}).

Frontend (vitest): create-shift dialog renders the checkbox + time input and includes `scheduled_time` in the payload; shift-detail + whats-next render the time and order correctly.

---

## 9. Build order

1. **Data model** — migrations (scheduled_time + reminder_sent_at; webpush keys) + `ShiftTask` fillable/casts/`scheduledFor()`.
2. **Write path** — dialog UI + `EditableShift` type + ShiftController (store/update/sync/editable/duplicate) + ShiftSeriesController.
3. **My Day** — emit `scheduled_for` (MyShiftResource + MyTasksController) + `MyDayShiftTask` type. Verify ordering in "What's next".
4. **Detail + calendars** — shift detail, roster sheet, team + client (+ my-) calendars.
5. **Reminders core** — `ShiftTaskDueNotification` (database+mail) + `ShiftTaskDueJob` + scheduler registration. Verify in-app + email.
6. **My Day alert** — `FacilitySignalService::emitShiftTaskDue` (or direct `ControlRoomAlert`); verify it appears + snoozes.
7. **Push** — `PushChannel`, `WebPushProvider`, `minishlink/web-push`, config/VAPID, subscription storage migration + controller, service worker + hook + settings toggle, shared VAPID prop. Add `PushChannel::class` to `via()`.
8. **i18n + tests + cleanup.**

Steps 1-3 deliver the headline (timed tasks ordered in "What's next"); 4-7 complete "pulls through everywhere" + all four reminder channels.

---

## 10. Environment / deploy prerequisites

- **VAPID keys** generated and set (`VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY`, `VAPID_SUBJECT`) in every environment that should send web push. Without them, web push silently no-ops.
- **`PUSH_PROVIDER=expo` + `EXPO_ACCESS_TOKEN`** where Expo delivery is wanted.
- **Queue worker** must process the `notifications` queue (notifications + job are queued).
- **Scheduler/cron** must run (`php artisan schedule:run` every minute) for `ShiftTaskDueJob` to fire.
- **No new permission** is introduced, so the permissions-seeded-separately deploy gotcha does **not** apply. (Email needs working mail config; in-app needs nothing extra.)
- Mail templates: reuse the default notification mail layout like `ChecklistDueNotification`.

---

## 11. Open items / verify during implementation

- `MyShiftResource` builds tasks off `$shift`; make sure each `ShiftTask` has its `shift` relation set (or compute the moment inline from `$shift->starts_at`) so `scheduledFor()` works.
- `FacilitySignalService` emit API + that `MyTasksController::getAlertTasks()` surfaces the new alert `source`/`alert_type` (§5.4).
- SPA entry / SW registration point (`resources/js/app.tsx`) and exact `HandleInertiaRequests` shared-props shape (§6.3).
- Whether to enforce `channel_push`/`channel_email` prefs now or send unconditionally (§6.5).
- Web-push endpoint length vs the 512-char `token` column (widen to `text` if needed).
- Confirm the `show.tsx` props builder (controller) includes `scheduled_time` for the detail page.
