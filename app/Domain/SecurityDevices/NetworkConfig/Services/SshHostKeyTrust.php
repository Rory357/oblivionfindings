<?php

namespace App\Domain\SecurityDevices\NetworkConfig\Services;

use App\Domain\SecurityDevices\Models\Device;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Which SSH key Oblivion trusts for a device (Phase A, item A6).
 *
 * The SSH backup used to accept any key and send the saved login to whatever answered, so a fake device
 * could collect it. Now the login is only sent to a device whose key someone has trusted. The first time,
 * the backup stops and records the key the device offered; a person checks it and chooses "Trust this
 * key". If the key later changes, backups are refused, and only an IT admin can replace the trusted key.
 * Every trust and replacement is in the audit log.
 */
final class SshHostKeyTrust
{
    public const string FINGERPRINT_PATTERN = '/\ASHA256:[A-Za-z0-9+\/]{43}\z/';

    public function trusted(Device $device): ?string
    {
        $pin = data_get($device->config ?? [], 'ssh.host_key_fingerprint')
            ?? data_get($device->meta ?? [], 'ssh_host_key_fingerprint');

        return is_string($pin) && trim($pin) !== '' ? trim($pin) : null;
    }

    /** @return array{fingerprint: string, seen_at: string, changed: bool}|null */
    public function offered(Device $device): ?array
    {
        $offered = data_get($device->meta ?? [], 'ssh_host_key_offered');
        if (! is_array($offered) || ! is_string($offered['fingerprint'] ?? null)) {
            return null;
        }

        return [
            'fingerprint' => $offered['fingerprint'],
            'seen_at' => (string) ($offered['seen_at'] ?? ''),
            'changed' => (bool) ($offered['changed'] ?? false),
        ];
    }

    /** Remember the key a device offered that isn't trusted (yet), for a person to check. */
    public function recordOffered(Device $device, string $fingerprint): void
    {
        if (preg_match(self::FINGERPRINT_PATTERN, $fingerprint) !== 1) {
            return;
        }

        $trusted = $this->trusted($device);
        $meta = is_array($device->meta) ? $device->meta : [];
        $meta['ssh_host_key_offered'] = [
            'fingerprint' => $fingerprint,
            'seen_at' => CarbonImmutable::now('UTC')->toIso8601String(),
            'changed' => $trusted !== null && ! hash_equals($trusted, $fingerprint),
        ];

        $device->forceFill(['meta' => $meta])->saveQuietly();
    }

    /** Whether this person may trust the key the device offered (first trust, or replacing a trusted key). */
    public function canTrust(User $user, Device $device): bool
    {
        $offered = $this->offered($device);
        if ($offered === null) {
            return false;
        }

        return $offered['changed']
            ? $user->canDo('securityDevices.commands.admin')
            : $user->canDo('securityDevices.commands.manage');
    }

    /**
     * Trust the key the device offered. The fingerprint must match the one recorded, so nobody trusts a
     * key they haven't been shown.
     */
    public function trust(User $user, Device $device, string $fingerprint): void
    {
        DB::transaction(function () use ($user, $device, $fingerprint): void {
            $locked = Device::query()->lockForUpdate()->findOrFail($device->id);
            $offered = $this->offered($locked);

            if ($offered === null || ! hash_equals($offered['fingerprint'], trim($fingerprint))) {
                throw ValidationException::withMessages([
                    'fingerprint' => 'This isn’t the key the device offered. Run the backup again to see its current key.',
                ]);
            }

            abort_unless($this->canTrust($user, $locked), 403, $offered['changed']
                ? 'Only an IT admin can replace a trusted SSH key.'
                : 'You can’t trust SSH keys for devices.');

            $previous = $this->trusted($locked);
            $config = is_array($locked->config) ? $locked->config : [];
            $config['ssh'] = array_replace(is_array($config['ssh'] ?? null) ? $config['ssh'] : [], [
                'host_key_fingerprint' => $offered['fingerprint'],
                'host_key_trusted_at' => CarbonImmutable::now('UTC')->toIso8601String(),
                'host_key_trusted_by' => (int) $user->id,
            ]);
            $meta = is_array($locked->meta) ? $locked->meta : [];
            unset($meta['ssh_host_key_offered'], $meta['ssh_host_key_fingerprint']);

            $locked->forceFill(['config' => $config, 'meta' => $meta])->saveQuietly();

            AuditLogger::logOrFail($previous === null ? 'security_devices.ssh_host_key.trusted' : 'security_devices.ssh_host_key.replaced', $locked, [
                'fingerprint' => $offered['fingerprint'],
                'previous_fingerprint' => $previous,
                'actor_id' => (int) $user->id,
            ]);
        });
    }

    /**
     * What the device page shows about the SSH key.
     *
     * @return array{trusted: string|null, offered: array{fingerprint: string, seen_at: string, changed: bool}|null, can_trust: bool}
     */
    public function present(User $user, Device $device): array
    {
        return [
            'trusted' => $this->trusted($device),
            'offered' => $this->offered($device),
            'can_trust' => $this->canTrust($user, $device),
        ];
    }
}
