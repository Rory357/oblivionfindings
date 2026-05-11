<?php

namespace App\Console\Commands;

use App\Models\AppSetting;
use App\Models\Queclink\QueclinkDevice;
use App\Models\Queclink\QueclinkRawFrame;
use Illuminate\Console\Command;

/**
 * Diagnostic / status check, also used as the data source for the
 * "Listener status" panel on the integration hub page.
 *
 *   php artisan queclink:status
 *   php artisan queclink:status --json
 */
class QueclinkStatus extends Command
{
    protected $signature = 'queclink:status {--json : Output JSON instead of human-readable text}';

    protected $description = 'Report Queclink listener service status, port, and recent activity';

    public function handle(): int
    {
        $port = (int) (AppSetting::query()->where('key', 'queclink.listener.port')->value('value')
            ?? config('services.queclink.port', 8090));

        $hostname = AppSetting::query()->where('key', 'queclink.public_hostname')->value('value')
            ?? config('services.queclink.public_hostname')
            ?? gethostname();

        $serviceState = $this->systemdState();

        $devices = [
            'total' => QueclinkDevice::count(),
            'paired' => QueclinkDevice::paired()->count(),
            'pending' => QueclinkDevice::pending()->count(),
            'rejected' => QueclinkDevice::rejected()->count(),
            'connected_now' => QueclinkDevice::connected()->count(),
        ];

        $recentFrames = QueclinkRawFrame::query()
            ->where('created_at', '>=', now()->subHour())
            ->count();

        $lastFrameAt = QueclinkRawFrame::query()->max('created_at');

        $status = [
            'port' => $port,
            'public_hostname' => $hostname,
            'service_state' => $serviceState,
            'devices' => $devices,
            'frames_last_hour' => $recentFrames,
            'last_frame_at' => $lastFrameAt,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($status, JSON_PRETTY_PRINT));
            return self::SUCCESS;
        }

        $this->info('Queclink listener status');
        $this->line('  Service:        ' . $serviceState);
        $this->line('  Port:           ' . $port);
        $this->line('  Public host:    ' . $hostname);
        $this->line('  Devices total:  ' . $devices['total']);
        $this->line('  ├─ paired:      ' . $devices['paired']);
        $this->line('  ├─ pending:     ' . $devices['pending']);
        $this->line('  ├─ rejected:    ' . $devices['rejected']);
        $this->line('  └─ connected:   ' . $devices['connected_now']);
        $this->line('  Frames (1h):    ' . $recentFrames);
        $this->line('  Last frame:     ' . ($lastFrameAt?->diffForHumans() ?? 'never'));

        return self::SUCCESS;
    }

    protected function systemdState(): string
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            return 'not_applicable (non-linux dev host)';
        }
        $output = [];
        $code = 0;
        exec('systemctl is-active oblivion-queclink.service 2>&1', $output, $code);
        return trim(implode(' ', $output)) ?: 'unknown';
    }
}
