<?php

namespace App\Domain\It\Services;

use App\Jobs\PollItMailboxJob;
use App\Models\ItAutomationRun;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Cron\CronExpression;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Schema;

class ItAutomationScheduleCatalog
{
    public function __construct(private readonly Schedule $schedule) {}

    /**
     * The one canonical definition of IT automations. Console scheduling and
     * the HTTP operations audit both consume these records, so the web view
     * never depends on routes/console.php having been loaded.
     *
     * @var array<int, array{key: string, label: string, type: 'command'|'job', handler: string, expression: string, timezone: string, without_overlapping: bool, overlap_minutes?: int, on_one_server: bool}>
     */
    private const DEFINITIONS = [
        [
            'key' => 'it.check-sla',
            'label' => 'SLA watchdog',
            'type' => 'command',
            'handler' => 'it:check-sla',
            'expression' => '0 * * * *',
            'timezone' => 'Pacific/Auckland',
            'without_overlapping' => true,
            'on_one_server' => true,
        ],
        [
            'key' => 'it.close-resolved',
            'label' => 'Close resolved tickets',
            'type' => 'command',
            'handler' => 'it:close-resolved',
            'expression' => '10 7 * * *',
            'timezone' => 'Pacific/Auckland',
            'without_overlapping' => true,
            'on_one_server' => true,
        ],
        [
            'key' => 'it.poll-mailbox',
            'label' => 'Poll support mailbox',
            'type' => 'job',
            'handler' => PollItMailboxJob::class,
            'expression' => '0 * * * *',
            'timezone' => 'Pacific/Auckland',
            'without_overlapping' => true,
            'on_one_server' => true,
        ],
        [
            'key' => 'it.dispatch-notifications',
            'label' => 'Recover pending notifications',
            'type' => 'command',
            'handler' => 'it:dispatch-notifications',
            'expression' => '* * * * *',
            'timezone' => 'Pacific/Auckland',
            'without_overlapping' => true,
            'on_one_server' => true,
        ],
        [
            'key' => 'it.retry-attachment-cleanup',
            'label' => 'Recover pending attachment cleanup',
            'type' => 'command',
            'handler' => 'it:retry-attachment-cleanup --limit=100',
            'expression' => '*/5 * * * *',
            'timezone' => 'Pacific/Auckland',
            'without_overlapping' => true,
            'overlap_minutes' => 10,
            'on_one_server' => true,
        ],
        [
            'key' => 'it.check-approval-deadlines',
            'label' => 'Approval deadlines and reminders',
            'type' => 'command',
            'handler' => 'it:check-approval-deadlines --limit=100',
            'expression' => '* * * * *',
            'timezone' => 'Pacific/Auckland',
            'without_overlapping' => true,
            'overlap_minutes' => 10,
            'on_one_server' => true,
        ],
    ];

    /** Register the canonical events once in the Laravel scheduler. */
    public function register(): void
    {
        $registered = collect($this->schedule->events())
            ->pluck('description')
            ->filter()
            ->all();

        foreach (self::DEFINITIONS as $definition) {
            if (in_array($definition['key'], $registered, true)) {
                continue;
            }

            if ($definition['type'] === 'job') {
                $jobClass = $definition['handler'];
                $event = $this->schedule->job(new $jobClass);
            } else {
                $event = $this->schedule->command($definition['handler']);
            }

            $event
                ->name($definition['key'])
                ->timezone($definition['timezone'])
                ->cron($definition['expression']);

            if ($definition['without_overlapping']) {
                $event->withoutOverlapping($definition['overlap_minutes'] ?? 1440);
            }
            if ($definition['on_one_server']) {
                $event->onOneServer();
            }
        }
    }

    /** @return array<int, array<string, mixed>> */
    public function definitions(): array
    {
        return collect(self::DEFINITIONS)
            ->map(function (array $definition): array {
                [$latest, $lastSuccess] = $this->runEvidence($definition['key']);

                $nextRun = (new CronExpression($definition['expression']))
                    ->getNextRunDate(now($definition['timezone']), 0, false, $definition['timezone']);

                return [
                    'key' => $definition['key'],
                    'label' => $definition['label'],
                    'expression' => $definition['expression'],
                    'timezone' => $definition['timezone'],
                    'next_run_at' => CarbonImmutable::instance($nextRun)->toIso8601String(),
                    'without_overlapping' => $definition['without_overlapping'],
                    'overlap_minutes' => $definition['overlap_minutes'] ?? 1440,
                    'on_one_server' => $definition['on_one_server'],
                    'latest_status' => $latest?->status,
                    'latest_at' => $latest?->started_at?->toIso8601String(),
                    'freshness' => app(ItAutomationFreshnessService::class)->verdict(
                        $definition['expression'], $definition['timezone'], $latest, $lastSuccess, now(),
                    ),
                ];
            })
            ->all();
    }

    public function labelFor(string $key): string
    {
        return $this->labels()[$key] ?? 'Unrecognised automation';
    }

    /** Public schedule labels without loading execution evidence. */
    public function labels(): array
    {
        return array_column(self::DEFINITIONS, 'label', 'key');
    }

    /** Read only one named automation's evidence for live hub/report projections. */
    public function freshnessFor(string $key, ?CarbonInterface $at = null): ?array
    {
        foreach (self::DEFINITIONS as $definition) {
            if ($definition['key'] !== $key) {
                continue;
            }
            [$latest, $lastSuccess] = $this->runEvidence($key);

            return app(ItAutomationFreshnessService::class)->verdict(
                $definition['expression'], $definition['timezone'], $latest, $lastSuccess, $at ?? now(),
            );
        }

        return null;
    }

    /** @return array{0: ?ItAutomationRun, 1: ?ItAutomationRun} */
    private function runEvidence(string $key): array
    {
        if (! Schema::hasTable('it_automation_runs')) {
            return [null, null];
        }

        return [
            ItAutomationRun::query()->where('automation_key', $key)->latest('id')->first(),
            ItAutomationRunOutcome::verifiedSuccess(ItAutomationRun::query()->where('automation_key', $key), $key)
                ->latest('finished_at')->first(),
        ];
    }
}
