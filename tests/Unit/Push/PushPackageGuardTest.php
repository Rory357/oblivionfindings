<?php

namespace Tests\Unit\Push;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Web push is Oblivion's own code (App\Services\Push, clean-room plan item C2.3). This stops the
 * replaced library, or the JWT and key packages it brought, from coming back unnoticed.
 * See docs/chat-experience/clean-room/REGISTER.md before changing it.
 */
class PushPackageGuardTest extends TestCase
{
    private const REPLACED = ['minishlink/web-push', 'web-token/jwt-library', 'spomky-labs/base64url', 'spomky-labs/pki-framework'];

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    public function test_composer_does_not_require_the_replaced_push_packages(): void
    {
        $composer = json_decode((string) file_get_contents(self::root().'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        $required = array_keys(($composer['require'] ?? []) + ($composer['require-dev'] ?? []));

        foreach (self::REPLACED as $package) {
            self::assertNotContains($package, $required, "{$package} was replaced by App\\Services\\Push; see the clean-room register.");
        }
    }

    public function test_the_lock_file_does_not_install_the_replaced_push_packages(): void
    {
        $lock = json_decode((string) file_get_contents(self::root().'/composer.lock'), true, flags: JSON_THROW_ON_ERROR);
        $installed = array_column(array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []), 'name');

        foreach (self::REPLACED as $package) {
            self::assertNotContains($package, $installed, "{$package} is installed again, perhaps through another package; see the clean-room register.");
        }
    }

    public function test_oblivion_code_does_not_use_the_replaced_namespaces(): void
    {
        $offenders = [];
        foreach (['app', 'config', 'routes', 'database'] as $folder) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::root().'/'.$folder, RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if ($file->isFile() && $file->getExtension() === 'php'
                    && preg_match('/\bMinishlink\\\\/', (string) file_get_contents($file->getPathname()))) {
                    $offenders[] = substr($file->getPathname(), strlen(self::root()) + 1);
                }
            }
        }

        self::assertSame([], $offenders);
    }
}
