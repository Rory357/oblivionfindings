<?php

namespace Tests\Unit\Timesheets;

use Exception;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\PackageManifest;
use Illuminate\Foundation\ProviderRepository;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
use Tests\Support\TimesheetPayrollAdjustmentProcess;

/** Filesystem/bootstrap prerequisites only: mocked connection, no kernel or child execution. */
class TimesheetPayrollAdjustmentProcessTest extends TestCase
{
    private string $directory;

    private Container $previousContainer;

    private ?Container $previousFacadeApplication;

    private ?array $previousFacadeInstances;

    private Application $application;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'payroll-marker-unit-'.bin2hex(random_bytes(12));
        $this->assertTrue(mkdir($this->directory, 0700));
        $this->previousContainer = Container::getInstance();
        $this->previousFacadeApplication = Facade::getFacadeApplication();
        $this->previousFacadeInstances = (new ReflectionProperty(Facade::class, 'resolvedInstance'))->getValue();
        $this->application = new Application($this->directory);
        $this->application->instance('env', 'testing');
        $this->application->instance('config', new Repository(['app' => [
            'key' => 'base64:'.base64_encode(str_repeat('k', 32)), 'worker_timezone' => 'Pacific/Auckland',
        ]]));
        Facade::setFacadeApplication($this->application);
        Facade::clearResolvedInstances();
    }

    protected function tearDown(): void
    {
        try {
            foreach (glob($this->application->basePath('test-results').DIRECTORY_SEPARATOR.'*.php') ?: [] as $file) {
                unlink($file);
            }
            if (is_dir($this->application->basePath('test-results'))) {
                rmdir($this->application->basePath('test-results'));
            }
            if (is_file($this->directory.DIRECTORY_SEPARATOR.'.env.testing')) {
                unlink($this->directory.DIRECTORY_SEPARATOR.'.env.testing');
            }
            rmdir($this->directory);
        } finally {
            Container::setInstance($this->previousContainer);
            Facade::setFacadeApplication($this->previousFacadeApplication);
            (new ReflectionProperty(Facade::class, 'resolvedInstance'))->setValue(null, $this->previousFacadeInstances);
            parent::tearDown();
        }
    }

    public function test_a_fresh_owned_runtime_can_write_its_package_and_service_manifests(): void
    {
        $this->mockConnection('oblivion_findings_codex_test_'.getmypid());
        $prefix = $this->application->basePath('test-results'.DIRECTORY_SEPARATOR.'payroll-adjustment-'.bin2hex(random_bytes(16)));
        $packages = $prefix.'-packages.php';
        $files = new Filesystem;
        $manifest = new PackageManifest($files, $this->directory, $packages);
        $manifest->vendorPath = $this->directory.DIRECTORY_SEPARATOR.'vendor';
        $this->assertDirectoryDoesNotExist(dirname($prefix));
        try {
            $manifest->build();
            $this->fail('Laravel must reject the missing manifest directory before runtime preparation.');
        } catch (Exception $exception) {
            $this->assertSame(Exception::class, $exception::class);
            $this->assertSame('The '.dirname($prefix).' directory must be present and writable.', $exception->getMessage());
        }
        $this->assertFileDoesNotExist($packages);

        $process = TimesheetPayrollAdjustmentProcess::make(['phase' => 'marker_saved'], $prefix);
        $this->assertFalse($process->isStarted());
        $this->assertDirectoryExists(dirname($prefix));
        $this->assertSame('test-results/'.basename($prefix).'-packages.php', $process->getEnv()['APP_PACKAGES_CACHE']);
        $manifest->build();
        $this->assertSame([], require $packages);
        $services = $prefix.'-services.php';
        (new ProviderRepository($this->application, $files, $services))->writeManifest([
            'providers' => [], 'eager' => [], 'deferred' => [],
        ]);
        $this->assertFileExists($services);
    }

    #[DataProvider('rejectedInputs')]
    public function test_rejected_runtime_input_creates_no_artifact_directory(string $case): void
    {
        $database = 'oblivion_findings_codex_test_'.($case === 'unowned schema' ? getmypid() + 1 : getmypid());
        $this->mockConnection($database, $case !== 'unowned schema');
        $prefix = $this->application->basePath('test-results'.DIRECTORY_SEPARATOR.'payroll-adjustment-'.bin2hex(random_bytes(16)));
        if ($case === 'foreign prefix') {
            $prefix = $this->application->basePath('foreign'.DIRECTORY_SEPARATOR.basename($prefix));
        } elseif ($case === 'testing dotenv') {
            file_put_contents($this->directory.DIRECTORY_SEPARATOR.'.env.testing', 'APP_ENV=testing');
        }
        try {
            TimesheetPayrollAdjustmentProcess::make(['phase' => 'marker_saved'], $prefix);
            $this->fail('Rejected runtime input must not prepare or launch a child.');
        } catch (RuntimeException $exception) {
            $this->assertSame($case === 'testing dotenv'
                ? 'A testing dotenv override cannot replace the pinned runtime environment.'
                : 'Only the exact local process-owned test schema and receipt prefix are permitted.', $exception->getMessage());
        }
        $this->assertDirectoryDoesNotExist($this->application->basePath('test-results'));
        $this->assertDirectoryDoesNotExist($this->application->basePath('foreign'));
    }

    public static function rejectedInputs(): array
    {
        return ['unowned schema' => ['unowned schema'], 'foreign prefix' => ['foreign prefix'], 'testing dotenv' => ['testing dotenv']];
    }

    private function mockConnection(string $database, bool $readsDatabase = true): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getConfig')->willReturn([
            'database' => $database, 'host' => '127.0.0.1', 'port' => 3306,
            'username' => 'unit-only', 'password' => '', 'unix_socket' => '',
        ]);
        $connection->method('getDriverName')->willReturn('mysql');
        $connection->expects($readsDatabase ? $this->once() : $this->never())->method('selectOne')
            ->with('SELECT DATABASE() AS db')->willReturn((object) ['db' => $database]);
        $manager = $this->createMock(DatabaseManager::class);
        $manager->expects($this->once())->method('connection')->willReturn($connection);
        $this->application->instance('db', $manager);
    }
}
