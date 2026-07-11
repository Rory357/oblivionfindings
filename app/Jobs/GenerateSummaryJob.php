<?php

namespace App\Jobs;

use App\Models\Client;
use App\Models\Site;
use App\Models\Summary;
use App\Models\TimelineEvent;
use App\Models\User;
use App\Services\Llm\LlmClient;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Gate;

class GenerateSummaryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $scopeType,
        public int $scopeId,
        public string $periodStartIso,
        public string $periodEndIso,
        public ?int $generatedByUserId = null,
    ) {}

    public function handle(): void
    {
        $this->authorizeCurrentRequester();

        $start = Carbon::parse($this->periodStartIso);
        $end = Carbon::parse($this->periodEndIso);

        $query = TimelineEvent::query()
            ->whereBetween('occurred_at', [$start, $end])
            ->orderBy('occurred_at');

        if ($this->scopeType === 'staff') {
            $query->where('actor_user_id', $this->scopeId);
        } elseif ($this->scopeType === 'client') {
            $query->where('client_id', $this->scopeId);
        } elseif ($this->scopeType === 'site') {
            $query->where('site_id', $this->scopeId);
        }

        $events = $query->limit(500)->get();

        // Generate with an LLM when configured; fallback to deterministic summary.
        $model = 'local-deterministic';
        $promptVersion = 'v1';
        $summaryText = $this->deterministicSummary($events, $start, $end);

        /** @var ?LlmClient $llm */
        $llm = app()->bound(LlmClient::class) ? app(LlmClient::class) : null;
        if ($llm && $llm->isEnabled()) {
            $promptVersion = 'v2-openai-responses';
            $model = $llm->modelName();
            $summaryText = $llm->summarizeTimeline(
                scopeType: $this->scopeType,
                scopeId: $this->scopeId,
                periodStart: $start,
                periodEnd: $end,
                events: $events,
            ) ?? $summaryText;
        }

        Summary::query()->updateOrCreate(
            [
                'scope_type' => $this->scopeType,
                'scope_id' => $this->scopeId,
                'period_start' => $start,
                'period_end' => $end,
            ],
            [
                'model' => $model,
                'prompt_version' => $promptVersion,
                'summary_text' => $summaryText,
                'sources' => ['timeline_event_ids' => $events->pluck('id')->values()->all()],
                'generated_at' => now(),
                'generated_by' => $this->generatedByUserId,
            ]
        );
    }

    private function authorizeCurrentRequester(): void
    {
        if ($this->generatedByUserId === null) {
            return;
        }

        $requester = User::query()->find($this->generatedByUserId);
        if (! $requester
            || $requester->hasRole('client', 'next_of_kin')
            || ! $requester->canDo('summaries.generate')) {
            throw new AuthorizationException('Summary generation is not authorized.');
        }

        $authorized = match ($this->scopeType) {
            'client' => ($client = Client::query()->find($this->scopeId))
                && Gate::forUser($requester)->allows('view', $client),
            'staff' => ($staff = User::query()->find($this->scopeId))
                && $this->sharesOrganization($requester->organization_id, $staff->organization_id),
            'site' => ($site = Site::query()->find($this->scopeId))
                && $this->sharesOrganization($requester->organization_id, $site->organization_id),
            default => false,
        };

        if (! $authorized) {
            throw new AuthorizationException('Summary scope is not authorized.');
        }
    }

    private function sharesOrganization(?int $viewerOrganizationId, ?int $targetOrganizationId): bool
    {
        return $viewerOrganizationId === null
            || $targetOrganizationId === null
            || $viewerOrganizationId === $targetOrganizationId;
    }

    private function deterministicSummary($events, Carbon $start, Carbon $end): string
    {
        $counts = $events->groupBy('type')->map->count()->toArray();
        $last = $events->last();

        $lines = [];
        $lines[] = "Summary for {$this->scopeType} #{$this->scopeId}";
        $lines[] = "Period: {$start->toDateString()} → {$end->toDateString()}";
        $lines[] = '';
        $lines[] = 'Activity counts:';
        if (count($counts) === 0) {
            $lines[] = '- No activity recorded.';
        } else {
            foreach ($counts as $type => $count) {
                $lines[] = "- {$type}: {$count}";
            }
        }
        if ($last) {
            $lines[] = '';
            $lines[] = 'Most recent event:';
            $lines[] = "- {$last->occurred_at?->toDateTimeString()} — {$last->subject}";
        }

        return implode("\n", $lines);
    }
}
