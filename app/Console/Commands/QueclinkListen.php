<?php

namespace App\Console\Commands;

use App\Models\AppSetting;
use App\Services\Queclink\Listener\TcpListener;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Long-running TCP daemon that listens for Queclink @Track frames.
 *
 *   php artisan queclink:listen            # use configured port
 *   php artisan queclink:listen --port=8090
 *
 * Designed to run under systemd in production. On Linux deployments the
 * `queclink:install` command writes a unit file that runs this command on
 * boot. On Windows (dev) operators run it manually.
 */
class QueclinkListen extends Command
{
    protected $signature = 'queclink:listen {--port= : Override the configured TCP port}';

    protected $description = 'Run the Queclink @Track TCP listener daemon (server-mode device intake)';

    public function handle(TcpListener $listener): int
    {
        $port = (int) ($this->option('port')
            ?? AppSetting::query()->where('key', 'queclink.listener.port')->value('value')
            ?? config('services.queclink.port', 8090));

        if ($port < 1 || $port > 65535) {
            $this->error("Invalid port: {$port}");
            return self::FAILURE;
        }

        $this->info("Queclink listener starting on tcp://0.0.0.0:{$port}");
        $this->line('Press Ctrl+C to stop.');

        // Long-running daemon hygiene:
        // 1. Disable query log so we don't accumulate every query in memory.
        // 2. Don't keep model events firing on hot paths we don't observe.
        DB::connection()->disableQueryLog();

        $lastPing = microtime(true);

        try {
            $listener->run($port, function () use (&$lastPing) {
                // Every 60 seconds, send a cheap SELECT 1 to keep the MySQL
                // connection from being closed by the server's wait_timeout
                // (default 8h). Without this, the first frame after a long
                // idle period would throw "MySQL server has gone away".
                $now = microtime(true);
                if ($now - $lastPing >= 60) {
                    try {
                        DB::connection()->select('SELECT 1');
                    } catch (\Throwable) {
                        try {
                            DB::reconnect();
                        } catch (\Throwable) {
                            // Will be retried on the next tick.
                        }
                    }
                    $lastPing = $now;
                }
            });
        } catch (\Throwable $e) {
            $this->error('Listener failed: ' . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
