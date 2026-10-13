<?php

namespace App\Domain\SecurityDevices\Endpoints\Services;

final class EndpointServiceCatalogue
{
    public const OPERATIONS = ['start', 'stop', 'restart'];

    /** Bounded deployment-approved SCM names and operations, with a fixed protected-service deny boundary. */
    public function all(): array
    {
        $configured = config('endpoint_management.services', []);
        if (! is_array($configured) || ! array_is_list($configured) || count($configured) > 100) {
            return [];
        }
        $out = [];
        foreach ($configured as $entry) {
            if (! is_array($entry) || array_diff(array_keys($entry), ['name', 'label', 'operations']) !== []
                || ! self::safeName($entry['name'] ?? null) || ! is_string($entry['label'] ?? null)
                || ! mb_check_encoding($entry['label'], 'UTF-8') || trim($entry['label']) === '' || mb_strlen($entry['label']) > 200 || preg_match('/[\p{Cc}\p{Cf}]/u', $entry['label'])
                || ! is_array($entry['operations'] ?? null) || ! array_is_list($entry['operations']) || $entry['operations'] === []
                || count(array_filter($entry['operations'], 'is_string')) !== count($entry['operations'])
                || count(array_unique($entry['operations'])) !== count($entry['operations']) || array_diff($entry['operations'], self::OPERATIONS) !== []) {
                return [];
            }
            if (in_array(strtolower($entry['name']), array_map('strtolower', array_column($out, 'name')), true)) {
                return [];
            }
            $out[] = ['name' => $entry['name'], 'label' => $entry['label'], 'operations' => array_values(array_intersect(self::OPERATIONS, $entry['operations']))];
        }
        usort($out, fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $out;
    }

    public static function safeName(mixed $name): bool
    {
        return is_string($name) && trim($name) === $name && preg_match('/\A[A-Za-z0-9][A-Za-z0-9_. -]{0,199}\z/', $name) === 1
            && ! in_array(strtolower($name), ['oblivionagent', 'rpcss', 'rpceptmapper', 'dcomlaunch', 'samss', 'lsm', 'eventlog', 'schedule', 'winmgmt', 'plugplay', 'power', 'windefend', 'sense', 'mpssvc', 'bfe', 'securityhealthservice', 'keyiso', 'netlogon', 'profsvc', 'usermanager', 'appinfo'], true);
    }

    public function digest(): string
    {
        return hash('sha256', 'endpoint-service-catalogue-v1:'.json_encode($this->all(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    public function allows(string $name, string $operation): bool
    {
        foreach ($this->all() as $entry) {
            if ($entry['name'] === $name && in_array($operation, $entry['operations'], true)) {
                return true;
            }
        }

        return false;
    }
}
