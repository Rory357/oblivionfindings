<?php

namespace App\Services\Llm;

use Carbon\Carbon;
use Illuminate\Support\Collection;

interface LlmClient
{
    /**
     * Whether the client is configured (API keys, etc.).
     */
    public function isEnabled(): bool;

    /**
     * Human-readable model name stored on Summary records.
     */
    public function modelName(): string;

    /**
     * Returns summary text, or null if generation failed.
     *
     * @param  Collection<int, \App\Models\TimelineEvent>  $events
     */
    public function summarizeTimeline(
        string $scopeType,
        int $scopeId,
        Carbon $periodStart,
        Carbon $periodEnd,
        Collection $events,
    ): ?string;
}
