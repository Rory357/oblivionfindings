<?php

namespace App\Domain\SecurityDevices\Management\Adapters;

use App\Domain\SecurityDevices\Models\Device;
use App\Models\ItSecurityDesktopReleaseFixturePack;
use Closure;
use Illuminate\Contracts\Foundation\Application;

final class DatabaseReleaseFixtureCommandRuntime implements ReleaseFixtureCommandRuntime
{
    private const string ATTACHMENT_PATH = 'it-security-release-fixtures/release-network-evidence.txt';

    private const string ATTACHMENT_CONTENT = "Non-sensitive desktop release acceptance evidence.\n";

    /** @param null|Closure(): string $environment */
    public function __construct(
        private readonly Application $app,
        private readonly ?Closure $environment = null,
    ) {}

    public function isApprovedStagingFixtureRuntime(): bool
    {
        $revision = config('it.desktop_release_fixtures.release_revision');

        return $this->environment() === 'staging'
            && config('it.desktop_release_fixtures.enabled') === true
            && config('it.desktop_release_fixtures.environment_class') === 'approved_non_production'
            && is_string($revision)
            && preg_match('/\A[0-9a-f]{40}\z/', $revision) === 1;
    }

    public function owns(Device $device): bool
    {
        if (! $this->isApprovedStagingFixtureRuntime()
            || (! is_int($device->id) && ! ctype_digit((string) $device->id))) {
            return false;
        }

        $packs = ItSecurityDesktopReleaseFixturePack::query()
            ->where('pack_key', ItSecurityDesktopReleaseFixturePack::PACK_KEY)
            ->where('state', ItSecurityDesktopReleaseFixturePack::STATE_READY)
            ->get(['release_revision', 'manifest', 'manifest_sha256']);
        if ($packs->count() !== 1) {
            return false;
        }

        $pack = $packs->sole();
        $revision = (string) config('it.desktop_release_fixtures.release_revision');
        $manifest = $pack->manifest;
        if (! hash_equals($revision, (string) $pack->release_revision)
            || ! is_array($manifest)
            || ! $this->manifestShapeValid($manifest)
            || ! hash_equals((string) $pack->manifest_sha256, $this->manifestHash($manifest))) {
            return false;
        }

        $listed = collect($manifest['records'])->contains(
            fn (array $record): bool => $record['type'] === 'device'
                && $record['id'] === (int) $device->id,
        );
        if (! $listed) {
            return false;
        }

        $persisted = Device::query()->whereKey($device->id)->first();

        return $persisted instanceof Device
            && $persisted->is($device)
            && $persisted->getAttributes() === $device->getAttributes();
    }

    private function environment(): string
    {
        return $this->environment instanceof Closure
            ? (string) ($this->environment)()
            : (string) $this->app->environment();
    }

    /** @param array<string, mixed> $manifest */
    private function manifestShapeValid(array $manifest): bool
    {
        $keys = array_keys($manifest);
        sort($keys, SORT_STRING);
        if ($keys !== ['files', 'records', 'schema_version']
            || ($manifest['schema_version'] ?? null) !== 1
            || ! is_array($manifest['records'] ?? null)
            || ! array_is_list($manifest['records'])
            || $manifest['records'] === []
            || ! is_array($manifest['files'] ?? null)
            || ! array_is_list($manifest['files'])
            || count($manifest['files']) !== 1
            || ! is_array($manifest['files'][0] ?? null)
            || array_diff_key($manifest['files'][0], ['path' => true, 'sha256' => true]) !== []
            || array_diff_key(['path' => true, 'sha256' => true], $manifest['files'][0]) !== []
            || ($manifest['files'][0]['path'] ?? null) !== self::ATTACHMENT_PATH
            || ($manifest['files'][0]['sha256'] ?? null) !== hash('sha256', self::ATTACHMENT_CONTENT)) {
            return false;
        }

        $seen = [];
        foreach ($manifest['records'] as $record) {
            if (! is_array($record)
                || array_diff_key($record, ['type' => true, 'id' => true]) !== []
                || array_diff_key(['type' => true, 'id' => true], $record) !== []
                || ! is_string($record['type'])
                || preg_match('/\A[a-z][a-z0-9_]*\z/', $record['type']) !== 1
                || ! is_int($record['id'])
                || $record['id'] < 1) {
                return false;
            }
            $key = $record['type'].':'.$record['id'];
            if (isset($seen[$key])) {
                return false;
            }
            $seen[$key] = true;
        }

        return true;
    }

    /** @param array<string, mixed> $manifest */
    private function manifestHash(array $manifest): string
    {
        return hash('sha256', json_encode(
            $this->canonicalValue($manifest),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    private function canonicalValue(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalValue($item);
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }
}
