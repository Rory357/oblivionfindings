<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrCalendarEvent;
use App\Domain\Hr\Models\HrCalendarEventReminder;
use App\Domain\Hr\Notifications\CalendarReminderNotification;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/** Durable per-person/channel receipts. The scheduler also drains failed attempts. */
class HrCalendarReminderDelivery
{
    public const TABLE = 'hr_calendar_reminder_deliveries';

    public function __construct(private readonly HrCalendarReminderOccurrences $occurrences, private readonly HrCalendarReminderRecipients $recipients, private readonly HrCalendarReminderPreferences $preferences) {}

    public function stage(Carbon $now): int
    {
        $count = 0;
        foreach (HrCalendarEventReminder::whereHas('event', fn ($query) => $query->active())->with('event')->get() as $bound) {
            $count += DB::transaction(function () use ($bound, $now) {
                $root = HrCalendarEvent::whereKey($bound->event->recurrence_parent_id ?? $bound->event_id)->lockForUpdate()->first();
                $event = HrCalendarEvent::find($bound->event_id);
                $reminder = HrCalendarEventReminder::find($bound->id);
                if (! $root || ! $event || ! $reminder || $root->archived_at || (int) ($event->recurrence_parent_id ?? $event->id) !== (int) $root->id) {
                    return 0;
                }
                $count = 0;
                foreach ($this->occurrences->actionable($event, $reminder->offset_minutes, $now) as $row) {
                    foreach ($this->recipients->recipients($root, $row['event'], $row['date']) as $user) {
                        $identity = ['event_id' => $root->id, 'user_id' => $user->id, 'occurrence_date' => $row['date'], 'starts_at' => $row['start'], 'offset_minutes' => $reminder->offset_minutes, 'channel' => $reminder->channel];
                        // Old receipts predate per-recipient delivery. Retain their deduplication boundary.
                        $legacySent = $reminder->last_sent_at && $reminder->last_sent_at->gte($row['due']) && ! DB::table(self::TABLE)->where($identity)->exists();
                        $count += DB::table(self::TABLE)->insertOrIgnore([...$identity, 'due_at' => $row['due'], 'expires_at' => $row['end'],
                            'state' => $legacySent ? 'skipped' : 'pending', 'outcome' => $legacySent ? 'legacy_receipt' : null, 'created_at' => $now, 'updated_at' => $now]);
                    }
                }

                return $count;
            });
        }

        return $count;
    }

    public function deliver(Carbon $now): int
    {
        $count = 0;
        $users = DB::table(self::TABLE)->where('state', 'pending')->where(fn ($q) => $q->whereNull('retry_at')->orWhere('retry_at', '<=', $now))->distinct()->pluck('user_id');
        foreach ($users as $userId) {
            $count += DB::transaction(function () use ($userId, $now) {
                $rootIds = DB::table(self::TABLE)->where('state', 'pending')->where('user_id', $userId)->pluck('event_id')->unique()->sort()->all();
                $roots = HrCalendarEvent::whereIn('id', $rootIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $rows = DB::table(self::TABLE)->where('user_id', $userId)->whereIn('event_id', $rootIds)->where('state', 'pending')
                    ->where(fn ($q) => $q->whereNull('retry_at')->orWhere('retry_at', '<=', $now))->orderBy('id')->lockForUpdate()->get();
                $user = User::find($userId);
                $ready = collect();
                foreach ($rows as $delivery) {
                    $root = $roots->get($delivery->event_id);
                    $current = $root ? $this->current($root, $delivery, $now) : null;
                    if (! $user || ! $current || ! $this->recipients->eligible($user, $root, $current['event'], $delivery->occurrence_date)) {
                        $this->finish($delivery->id, 'skipped', 'event_or_recipient_unavailable', $now);

                        continue;
                    }
                    if (! $this->preferences->allows($user, $delivery->channel)) {
                        $this->finish($delivery->id, 'skipped', 'notification_preference', $now);

                        continue;
                    }
                    $available = $this->preferences->availableAt($user, $delivery->channel, Carbon::parse($delivery->due_at), $now);
                    if ($available->gt($now)) {
                        DB::table(self::TABLE)->where('id', $delivery->id)->update(['retry_at' => $available->min($current['end']), 'expires_at' => $current['end'], 'updated_at' => $now]);

                        continue;
                    }
                    $ready->push($delivery);
                }
                // After downtime send the latest applicable lead time, not a burst of old notices.
                $ready = $ready->groupBy(fn ($row) => $row->event_id.'|'.$row->occurrence_date.'|'.$row->starts_at.'|'.$row->channel)->flatMap(function ($group) use ($now, $userId) {
                    $latest = $group->sortByDesc('due_at')->first();
                    $newerSent = DB::table(self::TABLE)->where('event_id', $latest->event_id)->where('user_id', $userId)->where('occurrence_date', $latest->occurrence_date)
                        ->where('starts_at', $latest->starts_at)->where('channel', $latest->channel)->where('state', 'delivered')->where('due_at', '>', $latest->due_at)->exists();
                    foreach ($group as $row) {
                        if ($newerSent || $row->id !== $latest->id) {
                            $this->finish($row->id, 'skipped', 'superseded_reminder', $now);
                        }
                    }

                    return $newerSent ? [] : [$latest];
                });
                $sent = 0;
                // Digest email bundles every currently eligible reminder in one message.
                $batches = $ready->groupBy(fn ($row) => $row->channel === 'email' && in_array($user->email_digest_frequency, ['daily', 'weekly'], true) ? 'digest' : (string) $row->id);
                foreach ($batches as $batch) {
                    $first = $batch->first();
                    $ids = $batch->pluck('id')->all();
                    if ($first->channel === 'email' && ! $this->preferences->emailConfigured()) {
                        DB::table(self::TABLE)->whereIn('id', $ids)->update(['retry_at' => $now->copy()->addMinutes(5), 'outcome' => 'email_not_configured', 'updated_at' => $now]);

                        continue;
                    }
                    $receipt = $first->receipt_id ?? (string) Str::uuid();
                    DB::table(self::TABLE)->whereIn('id', $ids)->update(['receipt_id' => $receipt, 'attempts' => DB::raw('attempts + 1'), 'updated_at' => $now]);
                    $notice = new CalendarReminderNotification($first->channel, $user->canDo('company_hub.view') ? '/company-hub?view=events' : '/my-calendar', $batch->count());
                    $notice->id = $receipt;
                    try {
                        Notification::sendNow($user, $notice);
                    } catch (\Throwable $error) {
                        // A transport failure remains retryable; no event content goes into logs.
                        DB::table(self::TABLE)->whereIn('id', $ids)->update(['retry_at' => $now->copy()->addMinutes(min(30, 2 ** min(5, $first->attempts))), 'outcome' => 'delivery_failed', 'updated_at' => $now]);
                        Log::warning('hr.calendar_reminder_delivery_failed', ['delivery_ids' => $ids, 'error_type' => $error::class]);

                        continue;
                    }
                    foreach ($batch as $delivery) {
                        $this->finish($delivery->id, 'delivered', 'sent_to_channel', $now, $receipt);
                        $sent++;
                    }
                }

                return $sent;
            });
        }

        return $count;
    }

    private function current(HrCalendarEvent $root, object $delivery, Carbon $now): ?array
    {
        if ($root->archived_at) {
            return null;
        }
        $event = HrCalendarEvent::where('recurrence_parent_id', $root->id)->whereDate('exception_date', $delivery->occurrence_date)->first() ?? $root;
        $source = $event->reminders_override ? $event : $root;
        if (! $source->reminders()->where('offset_minutes', $delivery->offset_minutes)->where('channel', $delivery->channel)->exists()) {
            return null;
        }

        return collect($this->occurrences->actionable($source, $delivery->offset_minutes, $now))->first(fn ($row) => $row['date'] === $delivery->occurrence_date && $row['start']->equalTo(Carbon::parse($delivery->starts_at)));
    }

    private function finish(int $id, string $state, string $outcome, Carbon $now, ?string $receipt = null): void
    {
        DB::table(self::TABLE)->where('id', $id)->update(['state' => $state, 'outcome' => $outcome, 'delivered_at' => $state === 'delivered' ? $now : null,
            'receipt_id' => $receipt, 'retry_at' => null, 'updated_at' => $now]);
    }
}
