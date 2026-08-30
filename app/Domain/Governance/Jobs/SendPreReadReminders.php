<?php

namespace App\Domain\Governance\Jobs;

use App\Domain\Governance\Models\BoardMember;
use App\Domain\Governance\Models\GovernanceMeeting;
use App\Domain\Governance\Notifications\PreReadReminderNotification;
use App\Domain\Governance\Services\BoardPackAccessService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendPreReadReminders implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(BoardPackAccessService $boardPackAccess): void
    {
        // Find meetings happening in the next 3-7 days with board packs distributed
        $meetings = GovernanceMeeting::query()
            ->with('boardPack')
            ->whereHas('boardPack', fn ($query) => $query->whereNotNull('distributed_at'))
            ->whereBetween('scheduled_at', [now()->addDays(3), now()->addDays(7)])
            ->get();

        foreach ($meetings as $meeting) {
            $pack = $meeting->boardPack;
            if (! $pack) {
                continue;
            }

            $members = BoardMember::query()
                ->active()
                ->with('user')
                ->whereIn('id', array_map('intval', $pack->distributed_to ?? []))
                ->get();

            foreach ($members as $member) {
                $user = $member->user;
                if ($user
                    && $boardPackAccess->canView($user, $pack)
                    && $boardPackAccess->recipientBoardMemberId($user, $pack) === (int) $member->id) {
                    $user->notify(new PreReadReminderNotification($meeting));
                }
            }
        }
    }
}
