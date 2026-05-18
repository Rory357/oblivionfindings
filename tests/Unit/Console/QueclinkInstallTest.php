<?php

use App\Console\Commands\QueclinkInstall;
use Illuminate\Console\OutputStyle;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class TestableQueclinkInstallCommand extends QueclinkInstall
{
    public function runExec(string $command, bool $required = true): int
    {
        return $this->exec($command, $required);
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
