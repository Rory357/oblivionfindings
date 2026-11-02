<?php

namespace Oblivion\Collector\Runtime;

/**
 * The only commands a hub ever sends over SSH: read-only settings exports, per device family.
 *
 * The server names a profile; it never sends command text. These lists match the server's backup
 * drivers (`app/Domain/SecurityDevices/Drivers/Network/*ConfigDriver.php`); the app test
 * `HubSshExportProfilesMatchDriversTest` fails if they drift apart.
 *
 * This file has no dependencies, so that test can load it on its own.
 */
final class SshExportProfiles
{
    /** @var array<string, array{commands: list<string>, cli_errors: list<string>}> */
    public const array PROFILES = [
        'mikrotik_routeros' => [
            'commands' => ['/export terse hide-sensitive', '/export terse'],
            'cli_errors' => ['bad command name', 'syntax error (line', 'not enough permissions', 'expected end of command'],
        ],
        'cisco_ios' => [
            'commands' => ['show running-config'],
            'cli_errors' => ['% invalid input detected', '% incomplete command', '% authorization failed', 'command authorization failed'],
        ],
        'aruba_aos' => [
            'commands' => ['show running-config'],
            'cli_errors' => ['invalid input:', '% invalid input', 'ambiguous input:', 'not authorized'],
        ],
        'fortinet_fortios' => [
            'commands' => ['show'],
            'cli_errors' => ['command parse error', 'unknown action', 'permission denied'],
        ],
    ];

    /** @return array{commands: list<string>, cli_errors: list<string>}|null */
    public static function for(string $profile): ?array
    {
        return self::PROFILES[$profile] ?? null;
    }

    public static function looksLikeCliError(string $output, string $profile): bool
    {
        $head = strtolower(substr($output, 0, 2048));
        foreach (self::PROFILES[$profile]['cli_errors'] ?? [] as $pattern) {
            if (str_contains($head, strtolower($pattern))) {
                return true;
            }
        }

        return false;
    }
}
