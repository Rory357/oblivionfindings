<?php

namespace App\Domain\Governance\Jobs;

use App\Domain\Governance\Models\BoardMember;
use App\Domain\Governance\Notifications\BoardDigestNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendBoardDigest implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        $boardMembers = BoardMember::active()
            ->whereHas('preferences', fn($q) => $q->where('digest_enabled', true))
            ->get();

        foreach ($boardMembers as $member) {
            // Check if it's time for their digest
            $nextDigest = $member->preferences?->getNextDigestDateTime();
            
            if (!$nextDigest || !$nextDigest->isSameDay(now())) {
                continue;
            }

            // Send digest
            $member->user->notify(new BoardDigestNotification($member));
        }
    }
}
