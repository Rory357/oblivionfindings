<?php

namespace App\Services\Chat;

use App\Services\Files\MalwareScanDisposition;
use App\Services\Files\MalwareScanner;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/** Bounded, content-free diagnostics. Never sends messages, joins rooms or enables services. */
class ChatServiceHealth
{
    private const HISTORY_KEY = 'chat:service-health:last-check:v1';

    public function __construct(private Config $config, private Cache $cache, private MalwareScanner $scanner, private ChatVoiceInspector $voice, private SelfHostedCalling $calling) {}

    public function snapshot(): array
    {
        $previous = null;
        try {
            $previous = $this->cache->get(self::HISTORY_KEY);
        } catch (Throwable) {
            // Diagnostics remain usable without the optional comparison cache.
        }
        $rows = [];
        foreach (['messaging' => 'Messaging', 'realtime' => 'Realtime connection', 'push' => 'Push notifications', 'uploads' => 'Uploads and scanning', 'calling' => 'Voice connections'] as $id => $name) {
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
}
