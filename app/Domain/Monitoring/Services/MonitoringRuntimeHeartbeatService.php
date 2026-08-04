<?php

namespace App\Domain\Monitoring\Services;

use App\Domain\Monitoring\Jobs\RuntimeQueueHeartbeat;
use App\Domain\Monitoring\Models\MonitoringRuntimeHeartbeat;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

final class MonitoringRuntimeHeartbeatService
{
    /** @return array<string, string> */
    public function components(): array
    {
        $components = [
            'events' => config('monitoring.queues.events'),
            'checks' => config('monitoring.queues.checks'),
            'discovery' => config('monitoring.queues.discovery'),
            'provider' => config('monitoring.queues.provider'),
            'topology' => config('monitoring.queues.topology'),
            'maintenance' => config('monitoring.queues.maintenance'),
            'orchestration' => config('monitoring.queues.orchestration'),
            'commands' => config('monitoring.queues.commands'),
        ];

        foreach ($components as $component => $queue) {
            if (! is_string($queue)
                || preg_match('/^[a-z0-9][a-z0-9_-]{1,63}$/', $queue) !== 1) {
                throw new UnexpectedValueException("Monitoring runtime queue {$component} is invalid.");
            }
        }

        if (count(array_unique($components)) !== count($components)) {
            throw new UnexpectedValueException('Monitoring runtime queues must be isolated from one another.');
        }

        return $components;
    }

    public function dispatch(): int
    {
        $now = now()->utc()->startOfSecond();
        $claims = DB::transaction(function () use ($now): array {
            $claims = [];

            foreach ($this->components() as $component => $queue) {
                $token = (string) Str::orderedUuid();
                MonitoringRuntimeHeartbeat::query()->updateOrCreate(
                    ['component' => $component],
                    [
                        'queue' => $queue,
                        'last_dispatched_token' => $token,
                        'last_dispatched_at' => $now,
                    ],
                );
                $claims[] = [
                    'component' => $component,
                    'queue' => $queue,
                    'token' => $token,
                ];
            }

            return $claims;
        }, 3);

        $failures = 0;

        foreach ($claims as $claim) {
            try {
                RuntimeQueueHeartbeat::dispatch(
                    $claim['component'],
                    $claim['queue'],
                    $claim['token'],
                );
            } catch (Throwable) {
                $failures++;
            }
        }

        if ($failures > 0) {
            throw new RuntimeException('One or more monitoring runtime heartbeat jobs could not be dispatched.');
        }

        return count($claims);
    }

    public function acknowledge(string $component, string $queue, string $dispatchToken): void
    {
        DB::transaction(function () use ($component, $queue, $dispatchToken): void {
            $heartbeat = MonitoringRuntimeHeartbeat::query()
                ->where('component', $component)
                ->lockForUpdate()
                ->first();

            if ($heartbeat === null
                || $heartbeat->queue !== $queue
                || ! hash_equals($heartbeat->last_dispatched_token, $dispatchToken)) {
                return;
            }

            $heartbeat->forceFill([
                'last_consumed_token' => $dispatchToken,
                'last_consumed_dispatch_at' => $heartbeat->last_dispatched_at,
                'last_consumed_at' => now()->utc()->startOfSecond(),
            ])->save();
        }, 3);
    }
}
