<?php

namespace App\Jobs;

use App\Models\SiteCalendarEvent;
use App\Models\User;
use App\Notifications\EventReminderNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Notification;

class SendEventReminderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        $this->onQueue('notifications');
    }

    public function handle(): void
    {
        $now = now();
        $windowStart = $now->copy()->subMinutes(5);

        $events = SiteCalendarEvent::query()
            ->where('start_at', '>', $windowStart)
            ->whereNotNull('reminder_minutes')
            ->whereIn('status', ['approved', 'draft'])
            ->whereHas('owner')
            ->get();

        foreach ($events as $event) {
            $lastSentAt = $event->last_reminder_sent_at;
            $reminderMinutes = collect($event->reminder_minutes ?? [])
                ->map(function (mixed $minutes): ?int {
                    if (is_int($minutes)) {
                        return $minutes >= 0 ? $minutes : null;
                    }

                    if (! is_string($minutes) || preg_match('/^(0|[1-9][0-9]*)$/D', $minutes) !== 1) {
                        return null;
                    }

                    $validated = filter_var($minutes, FILTER_VALIDATE_INT, [
                        'options' => [
                            'min_range' => 0,
                            'max_range' => PHP_INT_MAX,
                        ],
                    ]);

                    return is_int($validated) ? $validated : null;
                })
                ->filter(fn (?int $minutes): bool => $minutes !== null)
                ->unique()
                ->sortDesc()
                ->values();

            foreach ($reminderMinutes as $minutes) {
                $reminderTime = $event->start_at->copy()->subMinutes($minutes);

                if (! $reminderTime->gt($windowStart) || $reminderTime->gt($now)) {
                    continue;
                }

                if ($lastSentAt !== null && ! $reminderTime->gt($lastSentAt)) {
                    continue;
                }

                $recipientIds = collect([
                    $event->owner_user_id,
                    ...($event->attendee_user_ids ?? []),
                ])->filter()->unique()->values();
                $recipients = User::query()->whereKey($recipientIds)->get();

                Notification::send($recipients, new EventReminderNotification($event, $minutes));

                $event->update(['last_reminder_sent_at' => $reminderTime]);
                $lastSentAt = $reminderTime;
            }
        }
    }
}
