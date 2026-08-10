<?php

namespace App\Domain\Monitoring\Services;

use App\Infrastructure\Monitoring\InfluxDbTimeSeriesStore;

final class ProductionRetentionEndpointGuard
{
    /**
     * @param  array{driver?: mixed, url?: mixed, token?: mixed, organisation?: mixed, bucket?: mixed}  $settings
     * @param  array{host?: mixed, port?: mixed, database?: mixed}  $databaseSettings
     * @return list<string>
     */
    public function errors(
        string $environment,
        bool $runningUnitTests,
        string $databaseDriver,
        string $storeClass,
        array $settings,
        array $databaseSettings = [],
    ): array {
        $errors = [];

        if ($environment !== 'production') {
            $errors[] = 'production_environment_required';
        }
        if ($runningUnitTests) {
            $errors[] = 'unit_test_runtime_ineligible';
        }
        if ($databaseDriver !== 'mysql') {
            $errors[] = 'mysql_endpoint_required';
        }
        $databaseHost = is_string($databaseSettings['host'] ?? null)
            ? strtolower(trim($databaseSettings['host']))
            : '';
        if ($databaseHost === ''
            || $this->reservedHost($databaseHost)
            || ! is_string($databaseSettings['database'] ?? null)
            || trim((string) $databaseSettings['database']) === '') {
            $errors[] = 'pinned_mysql_endpoint_required';
        }
        if (($settings['driver'] ?? null) !== 'influxdb'
            || $storeClass !== InfluxDbTimeSeriesStore::class) {
            $errors[] = 'influxdb_endpoint_required';
        }

        foreach (['url', 'token', 'organisation', 'bucket'] as $setting) {
            if (! is_string($settings[$setting] ?? null)
                || trim((string) $settings[$setting]) === '') {
                $errors[] = 'influxdb_configuration_incomplete';

                break;
            }
        }

        $url = is_string($settings['url'] ?? null) ? trim($settings['url']) : '';
        $parts = $url === '' ? false : parse_url($url);
        if (! is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || ! isset($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
            || $this->reservedHost(strtolower((string) ($parts['host'] ?? '')))) {
            $errors[] = 'secure_influxdb_url_required';
        }

        return array_values(array_unique($errors));
    }

    private function reservedHost(string $host): bool
    {
        if ($host === 'localhost'
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.invalid')
            || str_ends_with($host, '.test')
            || str_ends_with($host, '.example')) {
            return true;
        }
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return str_starts_with($host, '127.') || $host === '0.0.0.0';
        }

        return in_array($host, ['::', '::1'], true);
    }
}
