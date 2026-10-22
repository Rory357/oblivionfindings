<?php

namespace App\Services\Push;

use Illuminate\Contracts\Config\Repository;

/**
 * Which push resource URLs Oblivion will post to. Browsers hand the server any URL they
 * like, so only known push services are contacted: https on port 443, a host listed in
 * chat.notifications.push_hosts (CHAT_BROWSER_PUSH_HOSTS), no IP address, credentials or fragment.
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

        return $host !== '' && ! filter_var($host, FILTER_VALIDATE_IP)
            && in_array($host, (array) $this->config->get('chat.notifications.push_hosts', []), true);
    }
}
