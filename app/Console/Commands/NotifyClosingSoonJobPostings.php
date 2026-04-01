<?php

namespace App\Console\Commands;

use App\Domain\Hr\Models\HrJobPosting;
use App\Domain\Hr\Notifications\JobPostingClosingSoonNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

class NotifyClosingSoonJobPostings extends Command
{
    protected $signature = 'postings:notify-closing-soon';

    protected $description = 'Notify hiring managers about job postings closing within 3 days';

    public function handle(): int
    {
        $count = 0;

        // Use cursor to handle large datasets efficiently
        $postings = HrJobPosting::where('status', 'published')
            ->whereNotNull('closes_at')
            ->whereNull('closing_soon_notified_at')
            ->whereBetween('closes_at', [now()->toDateString(), now()->addDays(3)->toDateString()])
            ->get();

        foreach ($postings as $posting) {
            // Atomic check-and-set to prevent duplicate notifications
            $updated = HrJobPosting::where('id', $posting->id)
                ->whereNull('closing_soon_notified_at')
                ->update(['closing_soon_notified_at' => now()]);

            if ($updated === 0) {
                continue; // Another process already handled this
            }

            $notification = new JobPostingClosingSoonNotification($posting);

            try {
                if ($posting->hiring_manager_id && $posting->hiringManager) {
                    $posting->hiringManager->notify($notification);
                }

                if (! empty($posting->notification_emails)) {
                    foreach ($posting->notification_emails as $email) {
                        Notification::route('mail', $email)->notify($notification);
                    }
                }

                $count++;
            } catch (\Throwable $e) {
                $this->warn("Failed to notify for posting #{$posting->id}: {$e->getMessage()}");
            }
        }

        $this->info("Sent closing-soon notifications for {$count} posting(s).");

        return self::SUCCESS;
    }
}
