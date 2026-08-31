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
use Illuminate\Support\Facades\Gate;

class SendVotingReminder implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public Resolution $resolution,
        public BoardMember $boardMember
    ) {}

    public function handle(): void
    {
        $resolution = Resolution::query()->find($this->resolution->getKey());
        if (! $resolution?->isOpen()) {
            return;
        }

        $boardMember = BoardMember::query()
            ->active()
            ->with(['user.permissionOverrides', 'user.roles.permissions'])
            ->find($this->boardMember->getKey());
        if (! $boardMember?->canVote()) {
            return;
        }

        $user = $boardMember->user;
        if (! $user?->approved_at) {
            return;
        }

        if ($resolution->votes()->where('board_member_id', $boardMember->id)->exists()
            || $resolution->conflictDeclarations()
                ->where('board_member_id', $boardMember->id)
                ->where('withdrew_from_voting', true)
                ->exists()
            || Gate::forUser($user)->denies('vote', $resolution)) {
            return;
        }

        $user->notify(new VotingReminderNotification($resolution));
    }
}
