<?php

namespace App\Domain\SecurityDevices\Endpoints\Services;

use App\Domain\SecurityDevices\Endpoints\Models\EndpointRemoteSession;

final class EndpointRemoteRelay
{
    /** The deployment secret stays server-side; clients receive expiring TURN REST credentials. */
    public function grant(EndpointRemoteSession $session, string $role, string $participant): array
    {
        $settings = app(EndpointRemoteSupportSettings::class);
        $urls = $settings->relayUrls();
        $secret = $settings->relaySecret();
        abort_unless(is_array($urls) && array_is_list($urls) && count($urls) >= 1 && count($urls) <= 4
            && is_string($secret) && strlen($secret) >= 32 && strlen($secret) <= 512, 503);
        foreach ($urls as $url) {
            abort_unless(is_string($url) && self::isValidRelayUrl($url), 503);
        }
        abort_unless(in_array($role, ['agent', 'technician'], true)
            && $session->session_expires_at->isFuture()
            && $session->session_expires_at->lte(now()->addMinutes(120)), 409);
        $expires = $session->session_expires_at->utc();
        // Opaque, distinct participant identifiers avoid publishing staff/device IDs to relay logs.
        $identity = hash_hmac('sha256', implode('|', [$session->uuid, $session->desktop_session, $role, $participant]), (string) config('app.key'));
        $username = $expires->getTimestamp().':obli-'.$role.'-'.$identity;

        return [
            'type' => 'remote_relay', 'version' => 1, 'agent_uuid' => $session->agent_uuid,
            'session_uuid' => $session->uuid, 'desktop_session' => $session->desktop_session,
            'role' => $role, 'access_mode' => $session->access_mode,
            'urls' => $urls, 'username' => $username,
            // Coturn's TURN REST protocol specifies HMAC-SHA1, not a password hash.
            'credential' => base64_encode(hash_hmac('sha1', $username, $secret, true)),
            'credential_expires_at' => $expires->format('Y-m-d\TH:i:s\Z'),
            'issued_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'expires_at' => $session->lease_expires_at->min(now()->addSeconds(15))->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    public static function isValidRelayUrl(string $url): bool
    {
        if (strlen($url) > 512 || ! preg_match('/\A(turns?):(\[[0-9a-fA-F:]+\]|[a-zA-Z0-9.-]+):([0-9]{1,5})(?:\?transport=(udp|tcp))?\z/', $url, $parts)) {
            return false;
        }
        if ((int) $parts[3] < 1 || (int) $parts[3] > 65535 || ($parts[1] === 'turns' && ($parts[4] ?? 'tcp') !== 'tcp')) {
            return false;
        }

        return str_starts_with($parts[2], '[')
            ? filter_var(trim($parts[2], '[]'), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
            : strlen($parts[2]) <= 253 && filter_var($parts[2], FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
    }
}
