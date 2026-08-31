<?php

namespace App\Domain\Roadmap\Jobs;

use App\Domain\Roadmap\Models\DecisionRequest;
use App\Domain\Roadmap\Models\InitiativeSuggestion;
use App\Domain\Roadmap\Models\InitiativeTask;
use App\Models\User;
use App\Notifications\AppEventNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

class SendRoadmapDigestJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        $pendingSuggestions = InitiativeSuggestion::query()
            ->where('status', InitiativeSuggestion::STATUS_TRIAGE_PENDING)
            ->count();

        $overdueTasks = InitiativeTask::query()
            ->where('status', '!=', 'completed')
            ->whereDate('due_date', '<', now()->toDateString())
            ->count();

        $pendingDecisions = DecisionRequest::query()
            ->where('status', 'pending')
            ->count();

        $today = Carbon::today();
        $recipients = User::query()
            ->whereNotNull('approved_at')
            ->with([
                'hrEmployeeProfile' => fn ($profile) => $profile->withTrashed(),
                'permissionOverrides',
                'roles.permissions',
            ])
            ->get();

        $payload = [
            'kind' => 'roadmap.digest',
            'title' => 'Roadmap weekly digest',
            'body' => 'Roadmap digest generated with pending triage, overdue tasks, and decision queue.',
            'context' => [
                'pending_suggestions' => $pendingSuggestions,
                'overdue_tasks' => $overdueTasks,
                'pending_decisions' => $pendingDecisions,
            ],
            'url' => url('/roadmap/dashboard'),
        ];

        $recipients
            ->filter(function (User $user) use ($today): bool {
                $profile = $user->hrEmployeeProfile;
                if ($profile && (
                    $profile->trashed()
                    || ! $profile->is_active
                    || ! $profile->start_date
                    || $profile->start_date->startOfDay()->gt($today)
                    || ($profile->end_date && $profile->end_date->startOfDay()->lt($today))
                )) {
                    return false;
                }

                return $user->canDo('roadmap.view') || $user->canDo('governance.view');
            })
            ->each(fn (User $user) => $user->notify(new AppEventNotification($payload)));
    }
}
