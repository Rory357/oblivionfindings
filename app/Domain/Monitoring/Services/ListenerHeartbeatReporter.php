<?php

namespace App\Domain\Monitoring\Services;

use Illuminate\Support\Facades\Cache;
use RuntimeException;

final class ListenerHeartbeatReporter
{
    private const array LISTENERS = ['flow', 'snmp_traps', 'syslog'];

    public function started(string $listener): void
    {
        $this->mutate($listener, function (array $state): array {
            $state['started_at'] ??= now()->utc()->format('Y-m-d\TH:i:s.u\Z');
            $state['last_seen_at'] = now()->utc()->format('Y-m-d\TH:i:s.u\Z');

            return $state;
        });
    }

    public function received(string $listener): void
    {
        $this->increment($listener, 'received');
    }

    public function alive(string $listener): void
    {
        $this->mutate($listener, function (array $state): array {
            $state['last_seen_at'] = now()->utc()->format('Y-m-d\TH:i:s.u\Z');

            return $state;
        });
    }

    public function accepted(string $listener): void
    {
        $this->increment($listener, 'accepted');
    }

    public function rejected(string $listener): void
    {
        $this->increment($listener, 'rejected');
    }

    /** @return array<string, int|string|null> */
    public function snapshot(string $listener): array
    {
        $this->assertListener($listener);
        $state = $this->store()->get($this->key($listener), []);

        return is_array($state) ? $state : [];
    }

    private function increment(string $listener, string $counter): void
    {
        $this->mutate($listener, function (array $state) use ($counter): array {
            $state[$counter] = min(PHP_INT_MAX, ((int) ($state[$counter] ?? 0)) + 1);
            $state['last_seen_at'] = now()->utc()->format('Y-m-d\TH:i:s.u\Z');

            return $state;
        });
    }

    /** @param callable(array<string, int|string|null>): array<string, int|string|null> $callback */
    private function mutate(string $listener, callable $callback): void
    {
        $this->assertListener($listener);
        $store = $this->store();
        $lock = $store->lock('monitoring:listener-heartbeat-lock:'.$listener, 5);
        $lock->block(2, function () use ($store, $listener, $callback): void {
            $current = $store->get($this->key($listener), []);
            $state = $callback(is_array($current) ? $current : []);
            $store->put($this->key($listener), $state, now()->addMinutes(10));
        });
    }

    private function store(): mixed
    {
        $name = (string) config('monitoring.inbound.listener_state_store', 'redis');
        $driver = config("cache.stores.{$name}.driver");
        $localTestingStore = app()->environment('testing')
            && (bool) config('monitoring.inbound.allow_local_state_store_for_tests', false);
        if ($driver !== 'redis' && ! $localTestingStore) {
            throw new RuntimeException('Monitoring listener heartbeat requires a shared Redis store.');
        }

        return Cache::store($name);
    }

    private function assertListener(string $listener): void
    {
        if (! in_array($listener, self::LISTENERS, true)) {
            throw new RuntimeException('Monitoring listener identity is invalid.');
        }
    }

    private function key(string $listener): string
    {
        return 'monitoring:listener-heartbeat:'.$listener;
    }
}
