<?php

namespace App\Domain\Governance\Jobs;

use App\Domain\Governance\Models\BoardMember;
use App\Domain\Governance\Notifications\BoardDigestNotification;
use App\Domain\Governance\Services\DashboardAggregatorService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendBoardDigest implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(DashboardAggregatorService $aggregator): void
    {
        $boardMembers = BoardMember::active()
            ->with(['preferences', 'user.notifications'])
            ->whereHas('preferences', fn($q) => $q->where('digest_enabled', true))
            ->get();

        $metrics = null;

        foreach ($boardMembers as $member) {
            $preferences = $member->preferences;
            if (! $preferences || ! $member->user || ! $preferences->isDigestDueAt(now())) {
                continue;
            }

            $window = $preferences->digestWindowFor(now());
            if ($this->alreadySentInWindow($member, $window['start'], $window['end'])) {
                continue;
            }

            $metrics ??= ($aggregator->captureSnapshot('week')->snapshot_data['widgets'] ?? []);
            $member->user->notify(new BoardDigestNotification($member, $metrics));
        }
    }

    protected function alreadySentInWindow(BoardMember $member, Carbon $windowStart, Carbon $windowEnd): bool
    {
        return $member->user?->notifications()
            ->where('type', BoardDigestNotification::class)
            ->whereBetween('created_at', [
                $windowStart->copy()->timezone('UTC'),
                $windowEnd->copy()->timezone('UTC'),
            ])
            ->exists() ?? false;
    }
}
