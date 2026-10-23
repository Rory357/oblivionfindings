<?php

namespace App\Services\Push;

use Illuminate\Contracts\Config\Repository;

/**
 * Which push resource URLs Oblivion will post to. Browsers hand the server any URL they
 * like, so only known push services are contacted: https on port 443, a host allowed by
 * chat.notifications.push_hosts (CHAT_BROWSER_PUSH_HOSTS), no IP address, credentials or fragment.
 *
 * A list entry is an exact host ("fcm.googleapis.com"), or, starting with a dot, any host
 * below that domain (".notify.windows.com" for Microsoft's push service, whose host varies
 * per subscription; the bare domain itself is not included).
 */
final class PushEndpoints
{
    public function __construct(private readonly Repository $config) {}

    public function allows(string $endpoint): bool
    {
        if (preg_match('/[\x00-\x20\\\\]/', $endpoint) || ! filter_var($endpoint, FILTER_VALIDATE_URL)) {
            return false;
        }
        $url = parse_url($endpoint);
        if (! is_array($url) || ($url['scheme'] ?? '') !== 'https'
            || isset($url['user']) || isset($url['pass']) || isset($url['fragment']) || ($url['port'] ?? 443) !== 443) {
            return false;
        }
        $host = strtolower($url['host'] ?? '');
        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP)) {
            return false;
        }
        foreach ((array) $this->config->get('chat.notifications.push_hosts', []) as $allowed) {
            $allowed = strtolower(trim((string) $allowed));
            if ($allowed === $host) {
                return true;
            }
            // ".notify.windows.com" needs at least one label in front: "x.notify.windows.com".
            if (strlen($allowed) > 1 && $allowed[0] === '.' && strlen($host) > strlen($allowed) && str_ends_with($host, $allowed)) {
                return true;
            }
        }

        return false;
    }
}
