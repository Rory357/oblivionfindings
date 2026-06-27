<?php

namespace App\Console\Commands;

use App\Domain\Hr\Models\HrInterview;
use App\Domain\Hr\Services\InterviewNotificationService;
use Illuminate\Console\Command;

/**
 * Day-before reminder for scheduled interviews. Idempotent via reminder_sent_at
 * so re-running the same day never double-sends. Scheduled dailyAt 08:00 NZ.
 */
class SendInterviewReminders extends Command
{
    protected $signature = 'recruitment:send-interview-reminders {--dry-run : Report eligible interviews without sending}';

    protected $description = 'Email a calendar reminder for interviews scheduled for tomorrow (NZ).';

    public function handle(InterviewNotificationService $notifier): int
    {
        $tz = config('app.worker_timezone', 'Pacific/Auckland');
        // scheduled_at is stored UTC — build tomorrow's NZ day window, in UTC.
        $start = now($tz)->addDay()->startOfDay()->utc();
        $end = now($tz)->addDay()->endOfDay()->utc();

        $interviews = HrInterview::query()
            ->where('status', 'scheduled')
            ->whereNull('reminder_sent_at')
            ->whereBetween('scheduled_at', [$start, $end])
            ->get();

        $this->info("Found {$interviews->count()} interview(s) scheduled for tomorrow.");

        $sent = 0;
        foreach ($interviews as $interview) {
            if ($this->option('dry-run')) {
                $this->line("  would remind interview #{$interview->id} at ".$interview->scheduled_at?->timezone($tz)->format('Y-m-d H:i'));

                continue;
            }
            try {
                $notifier->sendInvites($interview, isReminder: true);
                $sent++;
            } catch (\Throwable $exception) {
                report($exception);
                $this->warn("  failed to remind interview #{$interview->id}: {$exception->getMessage()}");
            }
        }

        $this->info($this->option('dry-run') ? 'Dry run complete.' : "Sent {$sent} reminder(s).");

        return self::SUCCESS;
    }
}
