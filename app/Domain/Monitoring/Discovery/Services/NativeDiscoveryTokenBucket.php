<?php

namespace App\Domain\Monitoring\Discovery\Services;

use App\Domain\Monitoring\Discovery\Contracts\DiscoveryThrottle;
use InvalidArgumentException;
use LogicException;

final class NativeDiscoveryTokenBucket implements DiscoveryThrottle
{
    private int $rate = 0;

    private float $tokens = 0.0;

    private int $lastRefillNanoseconds = 0;

    public function reset(int $packetsPerSecond): void
    {
        if ($packetsPerSecond < 1 || $packetsPerSecond > 1000) {
            throw new InvalidArgumentException('Discovery packet rate is invalid.');
        }

        $this->rate = $packetsPerSecond;
        $this->tokens = (float) $packetsPerSecond;
        $this->lastRefillNanoseconds = hrtime(true);
    }

    public function acquire(): void
    {
        if ($this->rate < 1 || $this->lastRefillNanoseconds < 1) {
            throw new LogicException('Discovery token bucket has not been initialised.');
        }

        $this->refill();
        if ($this->tokens < 1.0) {
            $waitMicroseconds = (int) ceil(((1.0 - $this->tokens) / $this->rate) * 1_000_000);
            usleep(max(1, min(1_000_000, $waitMicroseconds)));
            $this->refill();
        }

        $this->tokens = max(0.0, $this->tokens - 1.0);
    }

    private function refill(): void
    {
        $now = hrtime(true);
        $elapsedSeconds = max(0, $now - $this->lastRefillNanoseconds) / 1_000_000_000;
        $this->tokens = min((float) $this->rate, $this->tokens + ($elapsedSeconds * $this->rate));
        $this->lastRefillNanoseconds = $now;
    }
}
