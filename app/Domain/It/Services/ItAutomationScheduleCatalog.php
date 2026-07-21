<?php

namespace App\Domain\It\Services;

use App\Jobs\PollItMailboxJob;
use App\Models\ItAutomationRun;
use Carbon\CarbonImmutable;
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
     * @var array<int, array{key: string, label: string, type: 'command'|'job', handler: string, expression: string, timezone: string, without_overlapping: bool, on_one_server: bool}>
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
                $event->withoutOverlapping();
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
                $latest = null;
                if (Schema::hasTable('it_automation_runs')) {
                    $latest = ItAutomationRun::query()
                        ->where('automation_key', $definition['key'])
                        ->latest('id')
                        ->first();
                }

                $nextRun = (new CronExpression($definition['expression']))
                    ->getNextRunDate(now($definition['timezone']), 0, false, $definition['timezone']);

                return [
                    'key' => $definition['key'],
                    'label' => $definition['label'],
                    'expression' => $definition['expression'],
                    'timezone' => $definition['timezone'],
                    'next_run_at' => CarbonImmutable::instance($nextRun)->toIso8601String(),
                    'without_overlapping' => $definition['without_overlapping'],
                    'on_one_server' => $definition['on_one_server'],
                    'latest_status' => $latest?->status,
                    'latest_at' => $latest?->started_at?->toIso8601String(),
                ];
            })
            ->all();
    }
}
