<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Throwable;

class ApiSettingsController extends Controller
{
    private const API_KEYS_KEY = 'settings.api.keys';

    private const WEBHOOKS_KEY = 'settings.api.webhooks';

    private const AVAILABLE_SCOPES = [
        'Read Clients',
        'Write Clients',
        'Read Shifts',
        'Write Shifts',
        'Read HR',
        'Reports',
    ];

    private const AVAILABLE_EVENTS = [
        'client.created',
        'client.updated',
        'shift.created',
        'shift.completed',
        'shift.cancelled',
        'incident.reported',
        'incident.resolved',
        'timesheet.submitted',
        'timesheet.approved',
        'document.uploaded',
        'document.expired',
        'leave.requested',
        'leave.approved',
    ];

    public function index(Request $request)
    {
        $this->authorizeView($request);

        $apiKeys = $this->loadApiKeys();
        $webhooks = $this->loadWebhooks();

        return Inertia::render('settings/api', [
            'api_keys' => $apiKeys,
            'webhooks' => $webhooks,
            'available_scopes' => self::AVAILABLE_SCOPES,
            'available_events' => self::AVAILABLE_EVENTS,
            'stats' => [
                'active_keys' => collect($apiKeys)->where('status', 'active')->count(),
                'revoked_keys' => collect($apiKeys)->where('status', 'revoked')->count(),
                'active_webhooks' => collect($webhooks)->where('status', 'active')->count(),
                'successful_tests' => collect($webhooks)->filter(fn (array $webhook) => ! empty($webhook['lastDelivery']))->count(),
            ],
            'can' => [
                'manage' => $request->user()?->canDo('integrations.manage_tenant_secrets') ?? false,
            ],
        ]);
    }

    public function storeKey(Request $request): JsonResponse
    {
        $this->authorizeManage($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['string', 'in:' . implode(',', self::AVAILABLE_SCOPES)],
        ]);

        $plainKey = $this->generateToken('sk_live_', 32);
        $keys = $this->loadStoredArray(self::API_KEYS_KEY);
        $record = [
            'id' => (string) Str::uuid(),
            'name' => $validated['name'],
            'encrypted_key' => Crypt::encryptString($plainKey),
            'masked_key' => $this->maskToken($plainKey),
            'created_at' => now()->toDateString(),
            'last_used_at' => null,
            'status' => 'active',
            'scopes' => array_values($validated['scopes']),
        ];

        $keys[] = $record;
        $this->storeArray(self::API_KEYS_KEY, $keys);

        return response()->json([
            'message' => 'API key generated.',
            'generatedKey' => $plainKey,
            'apiKey' => $this->mapApiKey($record),
        ]);
    }

    public function revokeKey(Request $request, string $keyId): JsonResponse
    {
        $this->authorizeManage($request);

        $keys = collect($this->loadStoredArray(self::API_KEYS_KEY))
            ->map(function (array $record) use ($keyId) {
                if (($record['id'] ?? null) === $keyId) {
                    $record['status'] = 'revoked';
                }

                return $record;
            })
            ->values()
            ->all();

        $this->storeArray(self::API_KEYS_KEY, $keys);

        $updated = collect($keys)->firstWhere('id', $keyId);

        abort_unless(is_array($updated), 404);

        return response()->json([
            'message' => 'API key revoked.',
            'apiKey' => $this->mapApiKey($updated),
        ]);
    }

    public function storeWebhook(Request $request): JsonResponse
    {
        $this->authorizeManage($request);

        $validated = $request->validate([
            'url' => ['required', 'url', 'max:1000'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['string', 'in:' . implode(',', self::AVAILABLE_EVENTS)],
        ]);

        $plainSecret = $this->generateToken('whsec_', 24);
        $webhooks = $this->loadStoredArray(self::WEBHOOKS_KEY);
        $record = [
            'id' => (string) Str::uuid(),
            'url' => $validated['url'],
            'events' => array_values($validated['events']),
            'status' => 'active',
            'last_delivery' => null,
            'encrypted_secret' => Crypt::encryptString($plainSecret),
        ];

        $webhooks[] = $record;
        $this->storeArray(self::WEBHOOKS_KEY, $webhooks);

        return response()->json([
            'message' => 'Webhook added.',
            'secret' => $plainSecret,
            'webhook' => $this->mapWebhook($record),
        ]);
    }

    public function destroyWebhook(Request $request, string $webhookId): JsonResponse
    {
        $this->authorizeManage($request);

        $webhooks = collect($this->loadStoredArray(self::WEBHOOKS_KEY))
            ->reject(fn (array $record) => ($record['id'] ?? null) === $webhookId)
            ->values()
            ->all();

        $this->storeArray(self::WEBHOOKS_KEY, $webhooks);

        return response()->json([
            'message' => 'Webhook deleted.',
        ]);
    }

    public function testWebhook(Request $request, string $webhookId): JsonResponse
    {
        $this->authorizeManage($request);

        $webhooks = $this->loadStoredArray(self::WEBHOOKS_KEY);
        $index = collect($webhooks)->search(fn (array $record) => ($record['id'] ?? null) === $webhookId);

        abort_if($index === false, 404);

        $record = $webhooks[$index];
        $payload = [
            'event' => 'webhook.test',
            'sent_at' => now()->toIso8601String(),
        ];
        $url = (string) ($record['url'] ?? '');
        $status = $this->isSameApplicationUrl($url, $request)
            ? $this->probeInternalWebhook($url, $payload)
            : $this->probeExternalWebhook($url, $payload);

        if (! $this->responseLooksSuccessful($status ?? 0)) {
            return response()->json([
                'message' => 'Webhook test failed.',
            ], 422);
        }

        $record['last_delivery'] = now()->format('Y-m-d H:i');
        $webhooks[$index] = $record;
        $this->storeArray(self::WEBHOOKS_KEY, $webhooks);

        return response()->json([
            'message' => 'Webhook test succeeded.',
            'webhook' => $this->mapWebhook($record),
        ]);
    }

    private function authorizeView(Request $request): void
    {
        abort_unless($request->user()?->canDo('integrations.view'), 403);
    }

    private function authorizeManage(Request $request): void
    {
        abort_unless($request->user()?->canDo('integrations.manage_tenant_secrets'), 403);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadApiKeys(): array
    {
        return collect($this->loadStoredArray(self::API_KEYS_KEY))
            ->map(fn (array $record) => $this->mapApiKey($record))
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadWebhooks(): array
    {
        return collect($this->loadStoredArray(self::WEBHOOKS_KEY))
            ->map(fn (array $record) => $this->mapWebhook($record))
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadStoredArray(string $key): array
    {
        $value = AppSetting::query()->where('key', $key)->value('value');

        return is_array($value) ? $value : [];
    }

    /**
     * @param array<int, array<string, mixed>> $value
     */
    private function storeArray(string $key, array $value): void
    {
        AppSetting::updateOrCreate(
            ['key' => $key],
            ['value' => array_values($value)],
        );
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    private function mapApiKey(array $record): array
    {
        return [
            'id' => (string) ($record['id'] ?? ''),
            'name' => (string) ($record['name'] ?? ''),
            'maskedKey' => (string) ($record['masked_key'] ?? ''),
            'created' => (string) ($record['created_at'] ?? ''),
            'lastUsed' => $record['last_used_at'] ? (string) $record['last_used_at'] : null,
            'status' => ($record['status'] ?? 'active') === 'revoked' ? 'revoked' : 'active',
            'scopes' => array_values(array_filter($record['scopes'] ?? [], 'is_string')),
        ];
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    private function mapWebhook(array $record): array
    {
        return [
            'id' => (string) ($record['id'] ?? ''),
            'url' => (string) ($record['url'] ?? ''),
            'events' => array_values(array_filter($record['events'] ?? [], 'is_string')),
            'status' => ($record['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active',
            'lastDelivery' => $record['last_delivery'] ? (string) $record['last_delivery'] : null,
        ];
    }

    private function generateToken(string $prefix, int $length): string
    {
        $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        $token = $prefix;

        for ($index = 0; $index < $length; $index++) {
            $token .= $chars[random_int(0, strlen($chars) - 1)];
        }

        return $token;
    }

    private function maskToken(string $token): string
    {
        return '****' . substr($token, -8);
    }

    private function responseLooksSuccessful(int $status): bool
    {
        return $status >= 200 && $status < 400;
    }

    private function probeExternalWebhook(string $url, array $payload): ?int
    {
        foreach (['POST', 'HEAD', 'GET'] as $method) {
            $status = $this->attemptExternalWebhookRequest($method, $url, $payload);

            if ($this->responseLooksSuccessful($status ?? 0)) {
                return $status;
            }
        }

        return null;
    }

    private function probeInternalWebhook(string $url, array $payload): ?int
    {
        foreach (['POST', 'HEAD', 'GET'] as $method) {
            $status = $this->attemptInternalWebhookRequest($method, $url, $payload);

            if ($this->responseLooksSuccessful($status ?? 0)) {
                return $status;
            }
        }

        return null;
    }

    private function attemptExternalWebhookRequest(string $method, string $url, array $payload): ?int
    {
        try {
            $pendingRequest = Http::timeout(5)->acceptJson();

            $response = match ($method) {
                'POST' => $pendingRequest->asJson()->post($url, $payload),
                'HEAD' => $pendingRequest->head($url),
                default => $pendingRequest->get($url),
            };

            return $response->status();
        } catch (ConnectionException) {
            return null;
        }
    }

    private function attemptInternalWebhookRequest(string $method, string $url, array $payload): ?int
    {
        $path = parse_url($url, PHP_URL_PATH) ?: '/';
        $query = parse_url($url, PHP_URL_QUERY);
        $host = parse_url($url, PHP_URL_HOST);
        $port = parse_url($url, PHP_URL_PORT);
        $scheme = parse_url($url, PHP_URL_SCHEME) ?: 'http';

        if ($query) {
            $path .= '?' . $query;
        }

        $server = [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_HOST' => $port ? sprintf('%s:%d', $host, $port) : $host,
            'SERVER_PORT' => $port ?: ($scheme === 'https' ? 443 : 80),
            'REQUEST_SCHEME' => $scheme,
            'HTTPS' => $scheme === 'https' ? 'on' : 'off',
        ];

        if ($method === 'POST') {
            $server['CONTENT_TYPE'] = 'application/json';
        }

        $internalRequest = Request::create(
            $path,
            $method,
            [],
            [],
            [],
            $server,
            $method === 'POST' ? json_encode($payload) : null,
        );

        $kernel = app(Kernel::class);

        try {
            $response = $kernel->handle($internalRequest);

            return $response->getStatusCode();
        } catch (Throwable) {
            return null;
        } finally {
            if (isset($response)) {
                $kernel->terminate($internalRequest, $response);
            }
        }
    }

    private function isSameApplicationUrl(string $url, Request $request): bool
    {
        $targetParts = parse_url($url);
        $appParts = parse_url((string) config('app.url'));
        $requestParts = parse_url($request->root());
        $referenceParts = is_array($appParts) && isset($appParts['host']) ? $appParts : $requestParts;

        if (! is_array($targetParts) || ! is_array($referenceParts)) {
            return false;
        }

        $targetScheme = strtolower((string) ($targetParts['scheme'] ?? 'http'));
        $referenceScheme = strtolower((string) ($referenceParts['scheme'] ?? 'http'));
        $targetHost = strtolower((string) ($targetParts['host'] ?? ''));
        $referenceHost = strtolower((string) ($referenceParts['host'] ?? ''));
        $targetPort = (int) ($targetParts['port'] ?? ($targetScheme === 'https' ? 443 : 80));
        $referencePort = (int) ($referenceParts['port'] ?? ($referenceScheme === 'https' ? 443 : 80));

        return $targetHost !== ''
            && $targetHost === $referenceHost
            && $targetScheme === $referenceScheme
            && $targetPort === $referencePort;
    }
}
