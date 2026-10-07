<?php

namespace Tests\Unit\Eligibility;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\WorkforceEligibilityRuntimeProcess as Runtime;

/** Pure guard proof: no application bootstrap, database, queue or environment mutation. */
class WorkforceEligibilityRuntimeEnvironmentTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/workforce-runtime-guard-'.bin2hex(random_bytes(12));
        $this->assertTrue(mkdir($this->directory, 0700));
    }

    protected function tearDown(): void
    {
        foreach (['.env', '.env.testing'] as $name) {
            if (is_file($this->directory.'/'.$name)) {
                unlink($this->directory.'/'.$name);
            }
        }
        rmdir($this->directory);
        parent::tearDown();
    }

    public function test_only_existing_pid_owned_loopback_schemas_are_accepted(): void
    {
        foreach (['oblivion_findings_codex_test_731', 'oblivion_findings_codex_test_packpaper_731',
            'oblivion_workforce_settings_integration_20261007_731'] as $database) {
            Runtime::assertOwnedConnection($database, 731, '127.0.0.1', '');
            $this->addToAssertionCount(1);
        }
        foreach ([['oblivion_findings', 731, '127.0.0.1', ''],
            ['oblivion_findings_codex_test_732', 731, '127.0.0.1', ''],
            ['oblivion_findings_codex_test_731', 0, '127.0.0.1', ''],
            ['oblivion_findings_codex_test_731', 731, 'production.example', ''],
            ['oblivion_findings_codex_test_731', 731, 'localhost', '/var/run/mysql-production.sock']] as $input) {
            try {
                Runtime::assertOwnedConnection(...$input);
                $this->fail('An unowned or non-loopback storage connection was accepted.');
            } catch (RuntimeException $exception) {
                $this->assertSame('An exact PID-owned test schema on a loopback MySQL host is required.', $exception->getMessage());
            }
        }
    }

    public function test_current_ci_template_with_only_a_generated_application_key_is_accepted(): void
    {
        $contents = $this->ciEnvironment();
        file_put_contents($this->directory.'/.env', $contents);
        Runtime::assertEnvironmentFiles($this->directory, 'oblivion_findings_codex_test_731', 731, 'true', 'true');
        $this->assertSame($contents, file_get_contents($this->directory.'/.env'));
    }

    public function test_absent_checkout_dotenv_keeps_the_local_workforce_runtime_contract(): void
    {
        Runtime::assertEnvironmentFiles($this->directory, 'oblivion_workforce_settings_integration_20261007_731', 731, false, false);
        $this->assertFileDoesNotExist($this->directory.'/.env');
        $this->assertFileDoesNotExist($this->directory.'/.env.testing');
    }

    #[DataProvider('unsafeEnvironments')]
    public function test_environment_files_cannot_expand_the_pinned_child_contract(string $case): void
    {
        $contents = $this->ciEnvironment();
        $database = 'oblivion_findings_codex_test_731';
        $ci = 'true';
        if ($case === 'database URL fallback') {
            $contents .= "\nDB_URL=mysql://production.example/private\n";
        } elseif ($case === 'altered application environment') {
            $contents = str_replace('APP_ENV=local', 'APP_ENV=production', $contents);
        } elseif ($case === 'invalid generated key') {
            $contents = preg_replace('/^APP_KEY=[^\r\n]*/m', 'APP_KEY=base64:'.base64_encode('not-a-32-byte-key'), $contents);
        } elseif ($case === 'testing dotenv overlay') {
            file_put_contents($this->directory.'/.env.testing', 'DB_DATABASE=production');
        } elseif ($case === 'missing CI contract') {
            $ci = false;
        } elseif ($case === 'local Workforce dotenv') {
            $database = 'oblivion_workforce_settings_integration_20261007_731';
        }
        file_put_contents($this->directory.'/.env', $contents);
        $this->expectException(RuntimeException::class);
        Runtime::assertEnvironmentFiles($this->directory, $database, 731, $ci, 'true');
    }

    public static function unsafeEnvironments(): array
    {
        return array_combine($cases = ['database URL fallback', 'altered application environment', 'invalid generated key',
            'testing dotenv overlay', 'missing CI contract', 'local Workforce dotenv'], array_map(fn (string $case): array => [$case], $cases));
    }

    private function ciEnvironment(): string
    {
        $template = file_get_contents(dirname(__DIR__, 3).'/.env.example');
        $this->assertIsString($template);

        return preg_replace('/^APP_KEY=[^\r\n]*/m', 'APP_KEY=base64:'.base64_encode(str_repeat('k', 32)), $template);
    }
}
