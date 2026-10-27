<?php

namespace App\Jobs\System;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

final class RecordDefaultWorkerHeartbeatJob implements ShouldQueue
{
    use Queueable;

    public const string CACHE_KEY = 'system:worker:default:last_heartbeat_at';

    public int $tries = 1;

    public int $timeout = 10;

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        Cache::put(self::CACHE_KEY, now()->utc()->toIso8601String(), 3600);
    }
}
