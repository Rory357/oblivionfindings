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

class SendRoadmapDigestJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public ?int $tenantId = null
    ) {}

    public function handle(): void
    {
        $pendingSuggestions = InitiativeSuggestion::query()
            ->when($this->tenantId !== null, fn ($q) => $q->where('tenant_id', $this->tenantId))
            ->where('status', InitiativeSuggestion::STATUS_TRIAGE_PENDING)
            ->count();

        $overdueTasks = InitiativeTask::query()
            ->when($this->tenantId !== null, fn ($q) => $q->where('tenant_id', $this->tenantId))
            ->where('status', '!=', 'completed')
            ->whereDate('due_date', '<', now()->toDateString())
            ->count();

        $pendingDecisions = DecisionRequest::query()
            ->when($this->tenantId !== null, fn ($q) => $q->where('tenant_id', $this->tenantId))
            ->where('status', 'pending')
            ->count();

        $recipients = User::query()
            ->whereHas('roles', fn ($q) => $q->whereIn('name', ['admin', 'provider_manager', 'board_chair']))
            ->get();

        $payload = [
            'kind' => 'roadmap.digest',
            'title' => 'Roadmap weekly digest',
            'body' => 'Roadmap digest generated with pending triage, overdue tasks, and decision queue.',
            'context' => [
                'tenant_id' => $this->tenantId,
                'pending_suggestions' => $pendingSuggestions,
                'overdue_tasks' => $overdueTasks,
                'pending_decisions' => $pendingDecisions,
            ],
            'url' => url('/roadmap/dashboard'),
        ];

        $recipients->each(fn (User $user) => $user->notify(new AppEventNotification($payload)));
    }
}
