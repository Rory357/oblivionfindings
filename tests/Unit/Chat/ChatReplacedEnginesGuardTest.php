<?php

use PHPUnit\Framework\TestCase;

/**
 * Engines replaced through the Chat clean room must not come back unnoticed
 * (docs/chat-experience/clean-room/README.md, "Checking" step 3). Adding one again needs
 * Stephan's approval and a register entry, and then this list changes with it.
 */
final class ChatReplacedEnginesGuardTest extends TestCase
{
    private const PHP_PACKAGES = ['laravel/reverb', 'pusher/pusher-php-server'];

    private const BROWSER_PACKAGES = ['pusher-js', 'laravel-echo', 'ws'];

    private static function manifest(string $file): array
    {
        return json_decode(file_get_contents(dirname(__DIR__, 3).'/'.$file), true, 32, JSON_THROW_ON_ERROR);
    }

    public function test_composer_does_not_require_the_replaced_live_update_engine(): void
    {
        $composer = self::manifest('composer.json');
        foreach (['require', 'require-dev'] as $section) {
            foreach (self::PHP_PACKAGES as $package) {
                $this->assertArrayNotHasKey($package, $composer[$section] ?? [], "$package is replaced by Oblivion Live");
            }
        }
        $locked = array_column([...self::manifest('composer.lock')['packages'], ...self::manifest('composer.lock')['packages-dev'] ?? []], 'name');
        foreach (self::PHP_PACKAGES as $package) {
            $this->assertNotContains($package, $locked, "$package is replaced by Oblivion Live");
        }
    }

    public function test_npm_does_not_depend_on_a_websocket_library(): void
    {
        $package = self::manifest('package.json');
        foreach (['dependencies', 'devDependencies', 'optionalDependencies'] as $section) {
            foreach (self::BROWSER_PACKAGES as $name) {
                $this->assertArrayNotHasKey($name, $package[$section] ?? [], "$name is replaced by Oblivion Live");
            }
        }
    }
}
