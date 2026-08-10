<?php

use App\Console\Commands\QueclinkInstall;
use Illuminate\Console\OutputStyle;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class TestableQueclinkInstallCommand extends QueclinkInstall
{
    public bool $linuxRuntime = true;

    public bool $systemdAvailable = true;

    public bool $unitReadable = true;

    public string $serviceState = 'active';

    public int $serviceExitCode = 0;

    public function runExec(string $command, bool $required = true): int
    {
        return $this->exec($command, $required);
    }

    public function runReportStatus(): int
    {
        return $this->reportStatus();
    }

    protected function isLinuxRuntime(): bool
    {
        return $this->linuxRuntime;
    }

    protected function isSystemdAvailable(): bool
    {
        return $this->systemdAvailable;
    }

    protected function unitFilePath(): string
    {
        return '/test/oblivion-queclink.service';
    }

    protected function unitFileIsReadable(): bool
    {
        return $this->unitReadable;
    }

    protected function systemdServiceState(): array
    {
        return [
            'state' => $this->serviceState,
            'exit_code' => $this->serviceExitCode,
        ];
    }
}

function testableQueclinkInstallCommand(): TestableQueclinkInstallCommand
{
    $command = new TestableQueclinkInstallCommand;
    $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput));

    return $command;
}

function failingPhpCommand(): string
{
    return escapeshellarg(PHP_BINARY).' -r "exit(7);"';
}

it('fails required service commands instead of hiding a broken listener restart', function () {
    expect(fn () => testableQueclinkInstallCommand()->runExec(failingPhpCommand()))
        ->toThrow(RuntimeException::class, 'exit code 7');
});

it('allows optional firewall command failures without failing the deploy', function () {
    expect(testableQueclinkInstallCommand()->runExec(failingPhpCommand(), required: false))
        ->toBe(7);
});

it('fails closed when systemd readiness cannot be inspected', function () {
    $unsupported = testableQueclinkInstallCommand();
    $unsupported->linuxRuntime = false;

    $missingSystemd = testableQueclinkInstallCommand();
    $missingSystemd->systemdAvailable = false;

    $missingUnit = testableQueclinkInstallCommand();
    $missingUnit->unitReadable = false;

    expect($unsupported->runReportStatus())->toBe(1)
        ->and($missingSystemd->runReportStatus())->toBe(1)
        ->and($missingUnit->runReportStatus())->toBe(1);
});

it('requires an exact successful active systemd state', function (string $state, int $exitCode) {
    $command = testableQueclinkInstallCommand();
    $command->serviceState = $state;
    $command->serviceExitCode = $exitCode;

    expect($command->runReportStatus())->toBe($state === 'active' && $exitCode === 0 ? 0 : 1);
})->with([
    'active' => ['active', 0],
    'inactive' => ['inactive', 3],
    'failed' => ['failed', 3],
    'unknown state' => ['unknown', 0],
    'command failure despite active output' => ['active', 127],
]);

it('checks readiness after restart before reporting installer success', function () {
    $source = (string) file_get_contents(__DIR__.'/../../../app/Console/Commands/QueclinkInstall.php');
    $checkOption = strpos($source, "if (\$this->option('check'))");
    $platformFallback = strpos($source, 'if (! $this->isLinuxRuntime())', $checkOption);
    $restart = strpos($source, "\$this->exec('systemctl restart ", $platformFallback);
    $readiness = strpos($source, 'if ($this->reportStatus() !== self::SUCCESS)', $restart);
    $success = strpos($source, '$this->info("Queclink listener installed', $readiness);

    expect($checkOption)->not->toBeFalse()
        ->and($platformFallback)->toBeGreaterThan($checkOption)
        ->and($restart)->toBeGreaterThan($platformFallback)
        ->and($readiness)->toBeGreaterThan($restart)
        ->and($success)->toBeGreaterThan($readiness);
});
