<?php

namespace App\Domain\SecurityDevices\NetworkConfig\Services;

use App\Domain\Monitoring\Contracts\SnapshotStore;
use App\Domain\Monitoring\Models\ConfigurationSnapshot;
use App\Domain\Monitoring\Services\ConfigurationSnapshotService;
use App\Domain\Monitoring\Services\NetworkConfigurationRedactor;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use JsonException;
use RuntimeException;
use Throwable;

/**
 * S&D Phase G: a settings backup as lines, the way the snapshot service stored them (secrets already
 * redacted, and redacted again on the way out, exactly as Compare has always done).
 *
 * - forViewer() is the only way to the lines themselves, and it goes through the governed
 *   ConfigurationSnapshotService::retrieve(): the viewer's permission, the device's Site and the
 *   stored copy's integrity are all checked there.
 * - changedLines() only ever returns a number ("3 lines changed"), so a device page can say it for
 *   every backup. It reads the stored copies itself (hash-checked) and keeps the number, keyed by
 *   both copies' content hashes, because a stored backup never changes.
 */
final class SettingsBackupLines
{
    public function __construct(
        private readonly ConfigurationSnapshotService $snapshots,
        private readonly SnapshotStore $store,
        private readonly NetworkConfigurationRedactor $redactor,
        private readonly SettingsLineDiff $diff,
    ) {}

    /** @return list<string> */
    public function forViewer(ConfigurationSnapshot $snapshot, User $viewer): array
    {
        return $this->lines($this->snapshots->retrieve($snapshot, $viewer));
    }

    /**
     * Lines changed from the copy before, or null when either copy can't be read now.
     *
     * @return array{lines: int|null, too_many: bool}|null
     */
    public function changedLines(ConfigurationSnapshot $previous, ConfigurationSnapshot $snapshot): ?array
    {
        if ((int) $previous->device_id !== (int) $snapshot->device_id || (int) $previous->site_id !== (int) $snapshot->site_id) {
            return null;
        }
        if (is_string($previous->configuration_hash) && is_string($snapshot->configuration_hash)
            && hash_equals($previous->configuration_hash, $snapshot->configuration_hash)) {
            return ['lines' => 0, 'too_many' => false];
        }
        if (! self::readable($previous) || ! self::readable($snapshot)) {
            return null;
        }

        $key = sprintf('sd:settings-lines:v1:%d:%d:%s:%s', $previous->id, $snapshot->id, $previous->content_hash, $snapshot->content_hash);
        $cached = Cache::get($key);
        if (is_array($cached) && array_key_exists('lines', $cached) && array_key_exists('too_many', $cached)) {
            return ['lines' => is_int($cached['lines']) ? $cached['lines'] : null, 'too_many' => (bool) $cached['too_many']];
        }

        try {
            $result = $this->diff->compare($this->lines($this->verifiedRead($previous)), $this->lines($this->verifiedRead($snapshot)), false);
        } catch (Throwable) {
            return null;
        }
        $answer = ['lines' => $result['too_many'] ? null : $result['lines'], 'too_many' => $result['too_many']];
        Cache::forever($key, $answer);

        return $answer;
    }

    public static function readable(ConfigurationSnapshot $snapshot): bool
    {
        return $snapshot->storage_state === 'available' && $snapshot->payload_deleted_at === null;
    }

    /**
     * The same lines Compare has always shown: the running settings when the backup has them, or the
     * stored details laid out one per line.
     *
     * @return list<string>
     */
    public function lines(string $encoded): array
    {
        try {
            $decoded = json_decode($encoded, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }
        if (! is_array($decoded) || $decoded === []) {
            return [];
        }
        $payload = $this->redactor->redactPayload($decoded);
        if (isset($payload['running_config_lines']) && is_array($payload['running_config_lines'])) {
            return $this->redactor->redactLines($payload['running_config_lines']);
        }
        $pretty = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (! is_string($pretty)) {
            return [];
        }

        return array_slice(preg_split("/\r\n|\n|\r/", $pretty) ?: [], 0, NetworkConfigurationRedactor::MAX_CONFIG_LINES);
    }

    /** The stored copy, refused unless it is exactly what was stored. */
    private function verifiedRead(ConfigurationSnapshot $snapshot): string
    {
        $path = (string) $snapshot->storage_path;
        if ($path === '' || ! $this->store->exists($path)) {
            throw new RuntimeException('The stored settings copy is missing.');
        }
        $payload = $this->store->read($path);
        if (strlen($payload) !== (int) $snapshot->content_size || ! hash_equals((string) $snapshot->content_hash, hash('sha256', $payload))) {
            throw new RuntimeException('The stored settings copy failed its check.');
        }

        return $payload;
    }
}
