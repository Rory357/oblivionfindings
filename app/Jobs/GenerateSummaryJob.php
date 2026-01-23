<?php

namespace App\Jobs;

use App\Models\Summary;
use App\Models\TimelineEvent;
use App\Services\Llm\LlmClient;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

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
