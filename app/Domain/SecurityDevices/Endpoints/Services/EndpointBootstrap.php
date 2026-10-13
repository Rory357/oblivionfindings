<?php

namespace App\Domain\SecurityDevices\Endpoints\Services;

use App\Domain\SecurityDevices\Models\Device;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class EndpointBootstrap
{
    public function __construct(private readonly EndpointAgentIdentity $identity) {}

    public function options(User $actor, Device $device): array
    {
        $context = $this->identity->installationContext($actor, $device);
        $server = $organisation = $key = null;
        try {
            $server = self::serverOrigin((string) config('endpoint_management.server_url'));
            $organisation = trim((string) config('endpoint_management.organisation_label', config('app.name')));
            if ($organisation === '' || mb_strlen($organisation) > 120 || preg_match('/[\p{Cc}\p{Cf}]/u', $organisation)) {
                throw new RuntimeException('Invalid organisation label.');
            }
            $key = app(EndpointCommandSigner::class)->publicKey();
            $configured = true;
        } catch (RuntimeException) {
            $configured = false;
            $server = $organisation = null;
        }
        $context = [...$context, 'platform' => 'windows', 'server_url' => $server, 'organisation' => $organisation,
            'configured' => $configured, 'enrolment_minutes' => max(1, min(30, (int) config('endpoint_management.enrolment_minutes', 15)))];
        $hash = hash('sha256', json_encode([$context, $key], JSON_THROW_ON_ERROR));
        unset($context['assignment_fingerprint']);

        return [...$context, 'context_hash' => $hash];
    }

    /** Raw enrolment material goes only to the issuing administrator, never into events. */
    public function issue(User $actor, Device $device, ?string $expectedContext = null): array
    {
        $server = self::serverOrigin((string) config('endpoint_management.server_url'));
        $organisation = trim((string) config('endpoint_management.organisation_label', config('app.name')));
        if ($organisation === '' || mb_strlen($organisation) > 120 || preg_match('/[\p{Cc}\p{Cf}]/u', $organisation)) {
            throw new RuntimeException('Configure the agent installation organisation label.');
        }

        return DB::transaction(function () use ($actor, $device, $server, $organisation, $expectedContext): array {
            Device::query()->lockForUpdate()->findOrFail($device->id);
            if ($expectedContext !== null) {
                $current = $this->options($actor, $device);
                abort_unless($current['configured'] && hash_equals($current['context_hash'], $expectedContext), 409, 'Installation details changed. Review them again before creating a profile.');
            }
            // Identity issuance authorises the current assignment under the device lock.
            // Keep that lock through profile construction; a bad label rolls issuance back.
            $enrolment = $this->identity->issue($actor, $device, 'windows');
            $device = Device::query()->lockForUpdate()->findOrFail($device->id);
            foreach ([$device->name, $device->device_uid] as $label) {
                if (! is_string($label) || $label === '' || mb_strlen($label) > 300 || preg_match('/[\p{Cc}\p{Cf}]/u', $label)) {
                    throw new RuntimeException('Configure a valid computer name and identifier before downloading an installation profile.');
                }
            }

            return [
                'version' => 1, 'platform' => 'windows', 'server_url' => $server,
                'organisation' => $organisation, 'device_label' => $device->name,
                'device_uid' => $device->device_uid,
                'agent_uuid' => $enrolment['agent_uuid'],
                'server_public_key' => $enrolment['server_public_key'],
                'enrolment_token' => $enrolment['enrolment_token'],
                'expires_at' => $enrolment['expires_at'],
            ];
        });
    }

    public static function serverOrigin(string $url): string
    {
        $parts = parse_url($url);
        if ($url === '' || $url !== trim($url) || ! filter_var($url, FILTER_VALIDATE_URL)
            || ! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || ! in_array($parts['path'] ?? '', ['', '/'], true)
            || preg_match('/[\x00-\x20\x7F]/', $url) || str_contains($url, chr(92))
            || preg_match('/:\/?$/D', $url)
            || (isset($parts['port']) && ($parts['port'] < 1 || $parts['port'] > 65535))) {
            throw new RuntimeException('Configure the public HTTPS origin for agent installation.');
        }

        return rtrim($url, '/');
    }
}
