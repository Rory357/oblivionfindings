<?php

namespace App\Console\Commands;

use App\Models\AppSetting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Idempotent installer for the Queclink TCP listener service.
 *
 *   php artisan queclink:install
 *   php artisan queclink:install --port=8092
 *   php artisan queclink:install --no-firewall    (skip ufw rule)
 *   php artisan queclink:install --check          (status check only, no writes)
 *
 * On Linux + systemd hosts this:
 *   1. Writes /etc/systemd/system/oblivion-queclink.service
 *   2. Reloads systemd
 *   3. Enables + starts the service
 *   4. Opens the configured TCP port in UFW (if installed)
 *
 * On any other platform (Windows, no systemd) it prints next-steps
 * instructions and exits 0 — safe to run from a deploy hook that
 * doesn't know which OS it's on.
 *
 * Re-running the command after the port changes rewrites the unit file
 * and restarts the listener. Safe to re-run on every deploy.
 */
class QueclinkInstall extends Command
{
    protected $signature = 'queclink:install
        {--port= : Override the configured port for this install only}
        {--no-firewall : Skip the UFW open-port step}
        {--check : Report status only; make no system changes}
        {--user= : System user to run the service as (default: www-data, fallback to current user)}';

    protected $description = 'Install/refresh the Queclink TCP listener as a systemd service';

    private const UNIT_FILE = '/etc/systemd/system/oblivion-queclink.service';

    private const SERVICE_NAME = 'oblivion-queclink.service';

    public function handle(): int
    {
        $port = (int) ($this->option('port')
            ?? AppSetting::query()->where('key', 'queclink.listener.port')->value('value')
            ?? config('services.queclink.port', 8090));

        if ($port < 1024 || $port > 65535) {
            $this->error("Port {$port} is invalid (must be 1024-65535).");

            return self::FAILURE;
        }

        if (PHP_OS_FAMILY !== 'Linux') {
            $this->warn('queclink:install is a no-op on non-Linux platforms.');
            $this->line('On this machine, run the listener manually:');
            $this->line("  php artisan queclink:listen --port={$port}");

            return self::SUCCESS;
        }

        if (! $this->isSystemdAvailable()) {
            $this->warn('systemd not detected on this host.');
            $this->line('Run the listener under your own supervisor (Supervisor, runit, etc.):');
            $this->line("  php artisan queclink:listen --port={$port}");

            return self::SUCCESS;
        }

        if ($this->option('check')) {
            return $this->reportStatus();
        }

        $user = (string) ($this->option('user') ?: 'www-data');
        $projectRoot = base_path();
        $phpBinary = PHP_BINARY;

        $unit = $this->renderUnit($phpBinary, $projectRoot, $port, $user);
        $existing = is_readable(self::UNIT_FILE) ? file_get_contents(self::UNIT_FILE) : '';

        $changed = trim($existing) !== trim($unit);

        try {
            if ($changed) {
                $this->line('Writing '.self::UNIT_FILE);
                if (@file_put_contents(self::UNIT_FILE, $unit) === false) {
                    $this->error('Could not write unit file. Re-run with sudo.');

                    return self::FAILURE;
                }
                $this->exec('systemctl daemon-reload');
            } else {
                $this->line('Unit file unchanged.');
            }

            $this->exec('systemctl enable '.escapeshellarg(self::SERVICE_NAME));
            // The listener is a long-running PHP process, so code-only deploys need
            // a restart even when the unit file itself did not change.
            $this->exec('systemctl restart '.escapeshellarg(self::SERVICE_NAME));

            if (! $this->option('no-firewall')) {
                $this->openFirewallPort($port);
            }
        } catch (RuntimeException $exception) {
            Log::error('queclink:install failed', [
                'port' => $port,
                'changed_unit' => $changed,
                'message' => $exception->getMessage(),
            ]);

            return self::FAILURE;
        }

        $this->info("Queclink listener installed on TCP port {$port}.");
        $this->line('Status:   systemctl status oblivion-queclink');
        $this->line('Logs:     journalctl -u oblivion-queclink -f');

        Log::info('queclink:install completed', ['port' => $port, 'changed_unit' => $changed]);

        return self::SUCCESS;
    }

    protected function reportStatus(): int
    {
        $unitExists = is_readable(self::UNIT_FILE);
        $this->line('Unit file:     '.(self::UNIT_FILE).' — '.($unitExists ? 'present' : 'missing'));

        if ($unitExists) {
            $output = [];
            $code = 0;
            exec('systemctl is-active '.escapeshellarg(self::SERVICE_NAME).' 2>&1', $output, $code);
            $this->line('Service state: '.trim(implode("\n", $output)));
        }

        return self::SUCCESS;
    }

    protected function renderUnit(string $phpBinary, string $projectRoot, int $port, string $user): string
    {
        $artisan = $projectRoot.'/artisan';

        return <<<UNIT
[Unit]
Description=Oblivion Findings — Queclink @Track TCP listener
After=network-online.target mysql.service
Wants=network-online.target

[Service]
Type=simple
User={$user}
WorkingDirectory={$projectRoot}
ExecStart={$phpBinary} {$artisan} queclink:listen --port={$port}
Restart=always
RestartSec=5
StandardOutput=journal
StandardError=journal
SyslogIdentifier=oblivion-queclink
KillSignal=SIGTERM
TimeoutStopSec=30
# Hardening
NoNewPrivileges=true
ProtectSystem=full
ProtectHome=read-only
PrivateTmp=true

[Install]
WantedBy=multi-user.target
UNIT;
    }

    protected function openFirewallPort(int $port): void
    {
        $ufwPath = trim((string) shell_exec('command -v ufw 2>/dev/null'));
        if ($ufwPath === '') {
            $this->line('UFW not detected — skipping firewall step.');

            return;
        }
        $this->exec("ufw allow {$port}/tcp comment 'Oblivion Queclink listener'", required: false);
        $this->line("UFW rule added for tcp/{$port}.");
    }

    protected function isSystemdAvailable(): bool
    {
        return is_dir('/run/systemd/system') || file_exists('/usr/bin/systemctl');
    }

    protected function exec(string $command, bool $required = true): int
    {
        $output = [];
        $code = 0;
        exec($command.' 2>&1', $output, $code);
        foreach ($output as $line) {
            $this->line('  > '.$line);
        }
        if ($code !== 0) {
            $message = "Command failed with exit code {$code}: {$command}";
            if ($required) {
                $this->error("  ! {$message}");

                throw new RuntimeException($message);
            }

            $this->warn("  ! {$message}");
        }

        return $code;
    }
}
