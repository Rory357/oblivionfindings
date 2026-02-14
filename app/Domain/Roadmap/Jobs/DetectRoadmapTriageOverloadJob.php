<?php

namespace App\Domain\Roadmap\Jobs;

use App\Domain\Roadmap\Models\InitiativeSuggestion;
use App\Models\User;
use App\Notifications\AppEventNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DetectRoadmapTriageOverloadJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $threshold = 100,
        public ?int $tenantId = null
    ) {}

    public function handle(): void
    {
        $count = InitiativeSuggestion::query()
            ->when($this->tenantId !== null, fn ($q) => $q->where('tenant_id', $this->tenantId))
            ->where('status', InitiativeSuggestion::STATUS_TRIAGE_PENDING)
            ->count();

        if ($count < $this->threshold) {
            return;
        }

        $payload = [
            'kind' => 'roadmap.triage_overload',
            'title' => 'Roadmap triage overload',
            'body' => 'Roadmap suggestion inbox exceeded configured threshold.',
            'context' => [
                'tenant_id' => $this->tenantId,
                'pending_suggestions' => $count,
                'threshold' => $this->threshold,
            ],
            'url' => url('/roadmap/suggestions'),
        ];

        $recipients = User::query()
            ->whereHas('roles', fn ($q) => $q->whereIn('name', ['admin', 'provider_manager']))
            ->get();

        $recipients->each(fn (User $user) => $user->notify(new AppEventNotification($payload)));
    }
}
