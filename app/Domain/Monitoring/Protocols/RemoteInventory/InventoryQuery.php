<?php

namespace App\Domain\Monitoring\Protocols\RemoteInventory;

use LogicException;

final readonly class InventoryQuery
{
    /** @var array<string, list<string>> */
    private const array LINUX_OPERATIONS = [
        'uname' => ['-sr'],
        'uptime' => ['-s'],
        'df' => ['-P', '-B1'],
        'systemctl' => ['list-units', '--type=service', '--state=failed', '--no-legend'],
    ];

    /** @var array<string, list<string>> */
    private const array WINDOWS_OPERATIONS = [
        'Win32_OperatingSystem' => ['Caption', 'Version', 'LastBootUpTime'],
        'Win32_LogicalDisk' => ['Size', 'FreeSpace'],
        'Win32_Service' => ['State', 'StartMode'],
    ];

    /**
     * @param  list<list<string>|array{class: string, properties: list<string>}>  $operations
     */
    private function __construct(
        public string $profile,
        public string $platform,
        public array $operations,
    ) {}

    /** @param array<string, array<string, mixed>>|null $profiles */
    public static function fromProfile(string $profile, ?array $profiles = null): self
    {
        if (preg_match('/^[a-z][a-z0-9_.-]{1,63}$/', $profile) !== 1) {
            throw new LogicException('Inventory profile is not approved.');
        }

        $profiles ??= config('monitoring-inventory.profiles', []);
        $definition = $profiles[$profile] ?? null;
        if (! is_array($definition) || array_is_list($definition)
            || array_diff(array_keys($definition), ['platform', 'operations']) !== []) {
            throw new LogicException('Inventory profile is not approved.');
        }

        $platform = $definition['platform'] ?? null;
        $operations = $definition['operations'] ?? null;
        if (! is_string($platform) || ! in_array($platform, ['linux', 'windows'], true)
            || ! is_array($operations) || ! array_is_list($operations)
            || $operations === [] || count($operations) > 8) {
            throw new LogicException('Inventory profile is not approved.');
        }

        $validated = $platform === 'linux'
            ? self::linuxOperations($operations)
            : self::windowsOperations($operations);

        return new self($profile, $platform, $validated);
    }

    public static function fromArbitraryCommand(string $command): never
    {
        unset($command);

        throw new LogicException('Arbitrary remote commands are forbidden.');
    }

    /** @param list<mixed> $operations @return list<list<string>> */
    private static function linuxOperations(array $operations): array
    {
        $validated = [];
        $seen = [];
        foreach ($operations as $operation) {
            if (! is_array($operation) || ! array_is_list($operation) || count($operation) < 1
                || count($operation) > 8
                || collect($operation)->contains(fn (mixed $part): bool => ! is_string($part)
                    || $part === '' || strlen($part) > 128
                    || preg_match('/[\\\\\x00-\x20\x7f;&|`$<>]/', $part) === 1)) {
                throw new LogicException('Inventory profile is not approved.');
            }

            $executable = $operation[0];
            $arguments = array_slice($operation, 1);
            if (! array_key_exists($executable, self::LINUX_OPERATIONS)
                || $arguments !== self::LINUX_OPERATIONS[$executable]
                || isset($seen[$executable])) {
                throw new LogicException('Inventory profile is not approved.');
            }
            $seen[$executable] = true;
            $validated[] = array_values($operation);
        }

        return $validated;
    }

    /**
     * @param  list<mixed>  $operations
     * @return list<array{class: string, properties: list<string>}>
     */
    private static function windowsOperations(array $operations): array
    {
        $validated = [];
        $seen = [];
        foreach ($operations as $operation) {
            if (! is_array($operation) || array_is_list($operation)
                || array_diff(array_keys($operation), ['class', 'properties']) !== []) {
                throw new LogicException('Inventory profile is not approved.');
            }
            $class = $operation['class'] ?? null;
            $properties = $operation['properties'] ?? null;
            if (! is_string($class) || ! is_array($properties) || ! array_is_list($properties)
                || ! array_key_exists($class, self::WINDOWS_OPERATIONS)
                || $properties !== self::WINDOWS_OPERATIONS[$class]
                || isset($seen[$class])) {
                throw new LogicException('Inventory profile is not approved.');
            }
            $seen[$class] = true;
            $validated[] = ['class' => $class, 'properties' => $properties];
        }

        return $validated;
    }
}
