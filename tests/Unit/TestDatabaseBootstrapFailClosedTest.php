<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Tests\TestCase;

final class PendingMigrationKernelFake implements Kernel
{
    public int $calls = 0;

    public function __construct(
        private readonly int|Throwable $result,
        private readonly string $commandOutput = '',
    ) {}

    public function bootstrap(): void {}

    public function handle($input, $output = null): int
    {
        return 0;
    }

    public function call($command, array $parameters = [], $outputBuffer = null): int
    {
        $this->calls++;

        if ($this->result instanceof Throwable) {
            throw $this->result;
        }

        return $this->result;
    }

    public function queue($command, array $parameters = [])
    {
        throw new LogicException('Queueing is not used by this test fake.');
    }

    public function all(): array
    {
        return [];
    }

    public function output(): string
    {
        return $this->commandOutput;
    }

    public function terminate($input, $status): void {}
}

final class PendingMigrationBootstrapHarness extends TestCase
{
    public function applyPendingMigrations(Application $app): void
    {
        $this->runPendingMigrationsAfterSchemaLoad($app);
    }

    public function resetPendingMigrationState(): void
    {
        self::$pendingMigrationsApplied = false;
    }

    public function pendingMigrationsWereApplied(): bool
    {
        return self::$pendingMigrationsApplied;
    }
}

function pendingMigrationBootstrapHarness(): PendingMigrationBootstrapHarness
{
    $reflection = new ReflectionClass(PendingMigrationBootstrapHarness::class);

    /** @var PendingMigrationBootstrapHarness $harness */
    $harness = $reflection->newInstanceWithoutConstructor();
    $harness->resetPendingMigrationState();

    return $harness;
}

function applicationWithPendingMigrationKernel(PendingMigrationKernelFake $kernel): Application
{
    $app = new Application(dirname(__DIR__, 2));
    $app->instance(Kernel::class, $kernel);

    return $app;
}

it('fails closed when pending migrations return a non-zero exit code', function (): void {
    $harness = pendingMigrationBootstrapHarness();
    $kernel = new PendingMigrationKernelFake(1, 'Migration failed.');

    expect(fn () => $harness->applyPendingMigrations(applicationWithPendingMigrationKernel($kernel)))
        ->toThrow(RuntimeException::class, 'Pending test database migrations failed with exit code 1.')
        ->and($harness->pendingMigrationsWereApplied())->toBeFalse()
        ->and($kernel->calls)->toBe(1);
});

it('fails closed when the pending migrator throws', function (): void {
    $harness = pendingMigrationBootstrapHarness();
    $kernel = new PendingMigrationKernelFake(new RuntimeException('Database connection lost.'));

    expect(fn () => $harness->applyPendingMigrations(applicationWithPendingMigrationKernel($kernel)))
        ->toThrow(RuntimeException::class, 'Database connection lost.')
        ->and($harness->pendingMigrationsWereApplied())->toBeFalse()
        ->and($kernel->calls)->toBe(1);
});

it('marks successful pending migrations once and reuses that prepared state', function (): void {
    $harness = pendingMigrationBootstrapHarness();
    $kernel = new PendingMigrationKernelFake(0);
    $app = applicationWithPendingMigrationKernel($kernel);

    $harness->applyPendingMigrations($app);
    $harness->applyPendingMigrations($app);

    expect($harness->pendingMigrationsWereApplied())->toBeTrue()
        ->and($kernel->calls)->toBe(1);
});
