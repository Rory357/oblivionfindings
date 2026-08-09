<?php

namespace App\Support\Monitoring;

use InvalidArgumentException;

final class S10ProcessEnvironment
{
    public const string PHP_BINARY_VARIABLE = 'OBLIVION_S10_PHP_BINARY';

    public const string SYSTEM_PATH = '/usr/bin:/bin';

    private const array STARTUP_INJECTION_VARIABLES = [
        'BASH_ENV',
        'BASHOPTS',
        'CDPATH',
        'ENV',
        'GLOBIGNORE',
        'PHPRC',
        'PHP_INI_SCAN_DIR',
        'SHELLOPTS',
    ];

    /**
     * False values tell Symfony Process not to inherit the ambient variable.
     * Ordinary application, database and secret variables remain inherited.
     *
     * @param  array<string, mixed>|null  $ambient
     * @return array<string, string|false>
     */
    public static function processOverrides(string $phpBinary, ?array $ambient = null): array
    {
        if (! str_starts_with($phpBinary, '/') || str_contains($phpBinary, "\0")) {
            throw new InvalidArgumentException('The S10 PHP binary must be one absolute path.');
        }

        $ambient ??= self::ambientEnvironment();
        $overrides = [
            'PATH' => self::SYSTEM_PATH,
            self::PHP_BINARY_VARIABLE => $phpBinary,
        ];

        foreach (array_keys($ambient) as $key) {
            if (is_string($key) && (str_starts_with(strtoupper($key), 'GIT_')
                || str_starts_with($key, 'BASH_FUNC_'))) {
                $overrides[$key] = false;
            }
        }
        foreach ([
            'GIT_ALTERNATE_OBJECT_DIRECTORIES',
            'GIT_COMMON_DIR',
            'GIT_CONFIG',
            'GIT_CONFIG_COUNT',
            'GIT_CONFIG_GLOBAL',
            'GIT_CONFIG_KEY_0',
            'GIT_CONFIG_NOSYSTEM',
            'GIT_CONFIG_SYSTEM',
            'GIT_CONFIG_VALUE_0',
            'GIT_DIR',
            'GIT_INDEX_FILE',
            'GIT_OBJECT_DIRECTORY',
            'GIT_REPLACE_REF_BASE',
            'GIT_SHALLOW_FILE',
            'GIT_WORK_TREE',
            ...self::STARTUP_INJECTION_VARIABLES,
        ] as $key) {
            $overrides[$key] = false;
        }
        $overrides['GIT_OPTIONAL_LOCKS'] = '0';

        return $overrides;
    }

    /**
     * @param  array<string, mixed>  $ambient
     * @return array<string, mixed>
     */
    public static function sanitized(array $ambient, string $phpBinary): array
    {
        foreach (self::processOverrides($phpBinary, $ambient) as $key => $value) {
            if ($value === false) {
                unset($ambient[$key]);

                continue;
            }

            $ambient[$key] = $value;
        }

        return $ambient;
    }

    /** @return array<string, string> */
    public static function runtimeEnvironment(string $phpBinary): array
    {
        $environment = [];
        foreach (self::sanitized(self::ambientEnvironment(), $phpBinary) as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $environment[$key] = $value;
            }
        }

        return $environment;
    }

    /** @return array<string, mixed> */
    private static function ambientEnvironment(): array
    {
        $ambient = getenv();

        return is_array($ambient) ? $ambient : [];
    }
}
