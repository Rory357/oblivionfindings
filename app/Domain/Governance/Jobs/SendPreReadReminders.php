<?php

namespace App\Domain\Governance\Jobs;

use App\Domain\Governance\Models\BoardMember;
use App\Domain\Governance\Models\GovernanceMeeting;
use App\Domain\Governance\Notifications\PreReadReminderNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendPreReadReminders implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        // Find meetings happening in the next 3-7 days with board packs distributed
        $meetings = GovernanceMeeting::whereNotNull('pack_distributed_at')
            ->whereBetween('scheduled_at', [now()->addDays(3), now()->addDays(7)])
            ->get();

        foreach ($meetings as $meeting) {
            $members = BoardMember::active()->with('user')->get();

            foreach ($members as $member) {
                if ($member->user) {
                    $member->user->notify(new PreReadReminderNotification($meeting));
                }
            }
        }
    }
}
