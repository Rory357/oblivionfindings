<?php

namespace App\Jobs;

use App\Models\SiteCalendarEvent;
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
        $windowStart = $now->copy();
        $windowEnd = $now->copy()->addMinutes(10);

        // Find events with reminders due
        $events = SiteCalendarEvent::query()
            ->where('start_at', '>', $now)
            ->whereNull('last_reminder_sent_at')
            ->whereNotNull('reminder_minutes')
            ->whereIn('status', ['approved', 'draft'])
            ->whereHas('owner')
            ->get();

        foreach ($events as $event) {
            $reminderMinutes = $event->reminder_minutes ?? [];
            
            foreach ($reminderMinutes as $minutes) {
                $reminderTime = $event->start_at->copy()->subMinutes($minutes);
                
                // Check if reminder is due (within window)
                if ($reminderTime >= $windowStart && $reminderTime <= $windowEnd) {
                    // Send to owner
                    if ($event->owner) {
                        $event->owner->notify(new EventReminderNotification($event, $minutes));
                    }
                    
                    // Send to attendees
                    if (!empty($event->attendee_user_ids)) {
                        $attendees = \App\Models\User::whereIn('id', $event->attendee_user_ids)->get();
                        Notification::send($attendees, new EventReminderNotification($event, $minutes));
                    }
                    
                    $event->update(['last_reminder_sent_at' => $now]);
                    break; // Only send one reminder per job run
                }
            }
        }
    }
}
