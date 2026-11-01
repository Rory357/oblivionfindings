<?php

namespace App\Services\Chat;

use App\Services\Files\MalwareScanDisposition;
use App\Services\Files\MalwareScanner;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Throwable;

/** Bounded, content-free diagnostics. Never sends messages, joins rooms or enables services. */
class ChatServiceHealth
{
    private const HISTORY_KEY = 'chat:service-health:last-check:v1';

    private OblivionRelay $relay;

    private ChatRelayCredentials $relayCredentials;

    private ChatRelayProbe $relayProbe;

    public function __construct(private Config $config, private Cache $cache, private MalwareScanner $scanner, private ChatVoiceInspector $voice, private SelfHostedCalling $calling,
        ?OblivionRelay $relay = null, ?ChatRelayCredentials $relayCredentials = null, ?ChatRelayProbe $relayProbe = null)
    {
        $this->relayCredentials = $relayCredentials ?? new ChatRelayCredentials;
        $this->relay = $relay ?? new OblivionRelay($this->relayCredentials);
        $this->relayProbe = $relayProbe ?? new ChatRelayProbe;
    }

    public function snapshot(): array
    {
        $previous = null;
        try {
            $previous = $this->cache->get(self::HISTORY_KEY);
        } catch (Throwable) {
            // Diagnostics remain usable without the optional comparison cache.
        }
        $rows = [];
        foreach (['messaging' => 'Messaging', 'realtime' => 'Realtime connection', 'push' => 'Push notifications', 'uploads' => 'Uploads and scanning', 'calling' => 'Voice connections', 'relay' => 'Calling relay'] as $id => $name) {
            try {
                $result = $this->{$id}();
            } catch (Throwable) {
                $result = ['state' => 'unavailable', 'detail' => 'This check could not finish. Ask the service owner to check the service and try again.'];
            }
            $checkedAt = CarbonImmutable::now()->toISOString();
            $old = is_array($previous) ? ($previous['services'][$id] ?? null) : null;
            $comparison = is_array($old) && is_string($old['state'] ?? null) && is_string($old['checked_at'] ?? null)
                && ($old['checked_at'] >= CarbonImmutable::now()->subHour()->toISOString())
                && ($old['checked_at'] < $checkedAt) ? $old : null;
            $owner = trim((string) $this->config->get('chat.health.owners.'.$id, ''));
            $rows[] = ['id' => $id, 'name' => $name, ...$result, 'owner' => $owner ?: null, 'checked_at' => $checkedAt,
                'trend' => $comparison ? ($comparison['state'] === $result['state'] ? 'unchanged' : 'changed') : 'unknown',
                'compared_at' => $comparison['checked_at'] ?? null];
        }
        try {
            $this->cache->put(self::HISTORY_KEY, ['services' => collect($rows)->mapWithKeys(fn ($row) => [$row['id'] => ['state' => $row['state'], 'checked_at' => $row['checked_at']]])->all()], 3600);
        } catch (Throwable) {
            // No credentials, content, paths or failure messages enter the comparison cache.
        }

        return ['checked_at' => CarbonImmutable::now()->toISOString(), 'rollout_enabled' => (bool) $this->config->get('chat.shared_experience'), 'services' => $rows];
    }

    protected function messaging(): array
    {
        DB::select('select 1');
        foreach (['ops_conversations', 'ops_messages', 'chat_message_changes', 'chat_message_deliveries'] as $table) {
            if (! Schema::hasTable($table)) {
                return ['state' => 'unavailable', 'detail' => 'The Chat database setup is incomplete. Complete the reviewed migration process before rollout.'];
            }
        }

        return ['state' => 'passed', 'detail' => 'The database responded and core Chat tables are present. This read-only check does not verify sending, delivery or background workers.'];
    }

    protected function realtime(): array
    {
        if (! $this->config->get('chat.realtime.enabled')) {
            return ['state' => 'disabled', 'detail' => 'Realtime wake-ups are disabled. Chat uses its polling fallback when available.'];
        }
        foreach (['key', 'secret', 'app_id'] as $field) {
            if (! $this->config->get('broadcasting.connections.chat.'.$field)) {
                return ['state' => 'not_configured', 'detail' => 'The self-hosted realtime connection is not fully configured.'];
            }
        }
        // The configured broadcaster has bounded HTTP timeouts. No event is published.
        $result = Broadcast::connection('chat')->getPusher()->get('/channels', ['filter_by_prefix' => 'private-chat.health-check.']);
        if (! is_object($result) || ! isset($result->channels) || (! is_object($result->channels) && ! is_array($result->channels))) {
            throw new \RuntimeException('Realtime health response was not recognised.');
        }

        return ['state' => 'passed', 'detail' => 'The realtime server accepted an authenticated read. Browser reconnect and background-worker delivery still require a real session check.'];
    }

    protected function push(): array
    {
        return ['state' => 'not_available', 'detail' => 'Chat currently supports in-app message notices. Browser push delivery is not connected; this check does not send a notification.'];
    }

    protected function uploads(): array
    {
        $settings = (array) $this->config->get('chat.files.scanner', []);
        $binary = trim((string) ($settings['binary'] ?? ''));
        if ($binary === '' || ! is_file($binary) || ! is_executable($binary)) {
            return ['state' => 'not_configured', 'detail' => 'File scanning is not configured. New file and voice-message uploads cannot pass their safety check.'];
        }
        $sample = tmpfile();
        if ($sample === false) {
            return ['state' => 'unavailable', 'detail' => 'The server could not create a temporary diagnostic file. Ask the service owner to check temporary storage.'];
        }
        try {
            $bytes = "Oblivion Chat health check. No user content.\n";
            if (fwrite($sample, $bytes) !== strlen($bytes) || ! fflush($sample)) {
                throw new \RuntimeException('Diagnostic sample unavailable.');
            }
            $result = $this->scanner->scanPath(stream_get_meta_data($sample)['uri'], [...$settings, 'timeout_seconds' => 5]);
            if ($result->disposition !== MalwareScanDisposition::Clean) {
                return ['state' => 'unavailable', 'detail' => 'The scanner did not accept a harmless diagnostic file. Uploads remain subject to their normal safety checks.'];
            }
        } finally {
            fclose($sample);
        }

        return ['state' => 'partial', 'detail' => 'The scanner accepted a harmless diagnostic file. Signature freshness, malware detection and private upload storage are not verified by this check. '.($this->voice->configured() ? 'The voice inspector is configured; decoding a recording still needs verification.' : 'The voice inspector is not configured; voice messages cannot pass inspection.')];
    }

    protected function calling(): array
    {
        if (! $this->calling->configured()) {
            return ['state' => 'not_configured', 'detail' => 'Self-hosted calling is not configured. No room was created and calls remain unchanged.'];
        }
        if (! $this->calling->health()) {
            return ['state' => 'unavailable', 'detail' => 'The calling server could not be reached or did not accept the connection check.'];
        }

        return ['state' => $this->calling->ready() ? 'passed' : 'partial', 'detail' => 'The calling server accepted an authenticated read. '.($this->calling->ready() ? 'Deployment verification flags are set.' : 'Deployment verification is incomplete.').' Microphone audio, network relays and real calls are not tested here.'];
    }

    /**
     * Oblivion Relay (spec 9, "In the app"): its status from the loopback health endpoint beside the
     * app, or the signed control status when it has its own server; then the public side as a
     * browser sees it, and the revocations still waiting for a node.
     */
    protected function relay(): array
    {
        if (! $this->relayCredentials->configured() && ! $this->relay->configured()) {
            return ['state' => 'not_configured', 'detail' => 'Oblivion Relay is not configured. Calls on strict networks have no relay; no outside relay is used.'];
        }
        if (! $this->relayCredentials->configured()) {
            return ['state' => 'not_configured', 'detail' => 'The relay addresses or secret are missing or invalid, so calls are offered no relay.'];
        }
        if (! $this->relay->configured()) {
            return ['state' => 'not_configured', 'detail' => 'The relay control channel is missing or invalid, so the app cannot end someone’s relay use at once.'];
        }
        $status = $this->relayStatus();
        $waiting = $this->relay->waiting();
        $queue = $waiting['count'] === 0 ? 'Relay: no revocations waiting.'
            : sprintf('Relay: %d revocation%s waiting, the oldest for %d min.', $waiting['count'], $waiting['count'] === 1 ? '' : 's', intdiv((int) $waiting['oldest_age_seconds'], 60));
        if ($status === null || ($status['status'] ?? null) === 'down') {
            return ['state' => 'unavailable', 'detail' => 'The relay’s status could not be read, or a listener has failed. '.$queue];
        }
        $urls = $this->relayCredentials->urls();
        $udp = collect($urls)->first(fn (string $url) => ChatRelayProbe::target($url)['transport'] === 'udp');
        $tls = collect($urls)->first(fn (string $url) => ChatRelayProbe::target($url)['transport'] === 'tls');
        $challenge = $udp ? $this->relayProbe->allocateChallenge($udp) : null;
        $expiry = $tls ? $this->relayProbe->certificateExpiry($tls) : null;
        $days = $expiry === null ? null : intdiv($expiry - CarbonImmutable::now()->getTimestamp(), 86_400);
        $public = [
            $udp === null ? 'No UDP relay address is listed.' : ($challenge === 401 ? 'The UDP address answered a test request as expected.' : 'The UDP address did not answer a test request as expected.'),
            $tls === null ? 'No TLS relay address is listed.' : ($days === null ? 'The TLS address did not complete a verified handshake.' : sprintf('The TLS certificate expires in %d days.', $days)),
        ];
        $memory = is_numeric($status['memory']['rss_mb'] ?? null) ? sprintf(' Memory %d MB.', (int) $status['memory']['rss_mb']) : '';
        $healthy = ($status['status'] ?? null) === 'ok' && $challenge === 401 && ($tls === null || ($days !== null && $days > 14)) && $waiting['count'] === 0;

        return ['state' => $healthy ? 'passed' : 'partial', 'detail' => 'The relay reported its status. '.implode(' ', $public).$memory.' '.$queue.' Relayed media in a real call is not tested here.'];
    }

    /** /health on loopback when the relay runs beside the app; otherwise the signed control status. */
    private function relayStatus(): ?array
    {
        $url = rtrim((string) $this->config->get('chat.relay.health_url', ''), '/');
        if ($url === '') {
            return $this->relay->status();
        }
        if (! preg_match('/\Ahttp:\/\/(127\.[0-9.]+|\[::1\]):[0-9]{1,5}\z/', $url)) {
            return null;
        }
        $response = Http::timeout(3)->connectTimeout(3)->withoutRedirecting()->get($url.'/health');

        return in_array($response->status(), [200, 503], true) && is_array($response->json()) ? $response->json() : null;
    }
}
