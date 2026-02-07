<?php

namespace App\Domain\Governance\Jobs;

use App\Domain\Governance\Models\BoardMember;
use App\Domain\Governance\Models\Resolution;
use App\Domain\Governance\Notifications\VotingReminderNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendVotingReminder implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public Resolution $resolution,
        public BoardMember $boardMember
    ) {}

    public function handle(): void
    {
        $this->boardMember->user->notify(new VotingReminderNotification($this->resolution));
    }
}
