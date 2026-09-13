<?php

namespace App\Domain\It\Services;

use App\Models\ItAutomationRun;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Cron\CronExpression;

/** Freshness is evidence about the watchdog, separate from a live ticket verdict. */
final class ItAutomationFreshnessService
{
    public const GRACE_SECONDS = 300;

    public function verdict(string $expression, string $timezone, ?ItAutomationRun $latest, ?ItAutomationRun $lastSuccess, CarbonInterface $at): array
    {
        $now = CarbonImmutable::instance($at);
        $expected = CarbonImmutable::instance((new CronExpression($expression))->getPreviousRunDate(
            $now->subSeconds(self::GRACE_SECONDS), 0, true, $timezone,
        ));
        $successAt = $lastSuccess?->finished_at;
        $latestOutcome = $latest ? ItAutomationRunOutcome::state($latest) : null;
        $state = match (true) {
            $latestOutcome === 'failed' => 'failed',
            $successAt?->gt($now) => 'unmeasured',
            $successAt === null && $latest?->started_at?->lt($expected) => 'stale',
            $successAt === null => $latest?->status === 'running' ? 'running' : 'unmeasured',
            $successAt->lt($expected) => 'stale',
            $latest?->automation_key === 'it.poll-mailbox' && in_array($latestOutcome, ['pending', 'skipped', 'no_work', 'unknown'], true) => 'unmeasured',
            default => 'fresh',
        };

        return [
            'state' => $state,
            'last_success_at' => $successAt?->toIso8601String(),
            'latest_status' => $latest?->status,
            'latest_outcome' => $latestOutcome,
            'latest_started_at' => $latest?->started_at?->toIso8601String(),
            'required_since' => $expected->utc()->toIso8601String(),
            'evaluated_at' => $now->utc()->toIso8601String(),
            'grace_seconds' => self::GRACE_SECONDS,
        ];
    }
}
