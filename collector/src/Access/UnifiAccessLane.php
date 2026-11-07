<?php

namespace Oblivion\Collector\Access;

use Closure;
use DateTimeImmutable;
use Throwable;

/**
 * S&D Phase H (H0): runs each UniFi Access console's read when it is due (its signed interval)
 * and keeps the hub's own door-event cursor, so events are not read twice while the work list
 * catches up. The cursor moves only once the snapshot is safely in the spool.
 */
final class UnifiAccessLane
{
    /**
     * @param  Closure(string, array<string, mixed>, DateTimeImmutable): bool  $append  adds one item to the spool
     */
    public function __construct(
        private readonly UnifiAccessReader $reader,
        private readonly string $stateFile,
        private readonly Closure $append,
    ) {}

    /**
     * @param  array{consoles: list<array<string, mixed>>}|null  $section
     * @return int snapshots added
     */
    public function run(?array $section, DateTimeImmutable $now): int
    {
        $state = $this->state();
        if ($section === null) {
            if ($state !== []) {
                $this->save([]);
            }

            return 0;
        }
        $added = 0;
        $kept = [];
        foreach ($section['consoles'] as $console) {
            $id = (string) $console['console_id'];
            $mine = is_array($state[$id] ?? null) ? $state[$id] : [];
            $kept[$id] = $mine;
            $lastRun = is_int($mine['last_run'] ?? null) ? $mine['last_run'] : 0;
            if ($now->getTimestamp() - $lastRun < (int) $console['interval_seconds']) {
                continue;
            }
            $since = $this->later((string) $console['events_since'], is_string($mine['events_until'] ?? null) ? $mine['events_until'] : null);
            $snapshot = $this->reader->read($console, $since, $now);
            try {
                $stored = ($this->append)('unifi-access:'.$id.':'.$now->format('YmdHis'), $snapshot, $now);
            } catch (Throwable) {
                $stored = false;
            }
            if (! $stored) {
                continue;
            }
            $added++;
            $kept[$id] = [
                'last_run' => $now->getTimestamp(),
                'events_until' => ($snapshot['state'] ?? null) === 'ok' && is_string($snapshot['events_until'] ?? null)
                    ? $snapshot['events_until']
                    : ($mine['events_until'] ?? null),
            ];
        }
        // Consoles no longer in the work list are forgotten.
        $this->save($kept);

        return $added;
    }

    private function later(string $fromServer, ?string $fromHub): string
    {
        if ($fromHub === null) {
            return $fromServer;
        }
        try {
            return (new DateTimeImmutable($fromHub)) > (new DateTimeImmutable($fromServer)) ? $fromHub : $fromServer;
        } catch (Throwable) {
            return $fromServer;
        }
    }

    /** @return array<string, mixed> */
    private function state(): array
    {
        if (! is_file($this->stateFile) || filesize($this->stateFile) > 65_536) {
            return [];
        }
        try {
            $decoded = json_decode((string) file_get_contents($this->stateFile), true, 8, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];
        }

        return is_array($decoded) && is_array($decoded['consoles'] ?? null) ? $decoded['consoles'] : [];
    }

    /** @param array<string, mixed> $consoles */
    private function save(array $consoles): void
    {
        $temporary = $this->stateFile.'.tmp';
        if (@file_put_contents($temporary, json_encode(['consoles' => (object) $consoles], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), LOCK_EX) === false) {
            return;
        }
        @chmod($temporary, 0600);
        @rename($temporary, $this->stateFile);
    }
}
