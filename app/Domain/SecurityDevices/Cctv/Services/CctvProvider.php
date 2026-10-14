<?php

namespace App\Domain\SecurityDevices\Cctv\Services;

use App\Models\Integration\IntegrationProviderConnection;
use App\Services\Integration\IntegrationSecretManager;
use App\Services\Integration\IntegrationSecretMaterialService;
use App\Services\Integration\UnifiTransportSecurity;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

final class CctvProvider
{
    public function verify(IntegrationProviderConnection $connection, string $console, string $cameraId, mixed $cameraMac, mixed $recorderMac): array
    {
        $meta = $this->call($connection, $console, 'meta/info');
        $version = $meta['applicationVersion'] ?? null;
        abort_unless(is_string($version) && in_array($version, config('cctv.supported_protect_versions', []), true), 503, 'temporarily_unavailable');
        $camera = $this->call($connection, $console, 'cameras/'.CctvContract::providerId($cameraId));
        $nvr = $this->call($connection, $console, 'nvrs');
        abort_unless(($camera['id'] ?? null) === $cameraId && $this->mac($camera['mac'] ?? null) === $this->mac($cameraMac) && $this->mac($nvr['mac'] ?? null) === $this->mac($recorderMac) && isset($nvr['id']) && is_string($nvr['id']), 409, 'context_changed');

        CctvContract::providerId($nvr['id']);

        return ['application_version' => $version, 'recorder_provider_id' => $nvr['id']];
    }

    public function existing(IntegrationProviderConnection $connection, array $configuration): string
    {
        $streams = $this->call($connection, $configuration['console_id'], 'cameras/'.CctvContract::providerId($configuration['provider_camera_id']).'/rtsps-stream');
        try {
            return CctvContract::locator($streams['low'] ?? null, $configuration);
        } catch (\InvalidArgumentException) {
            abort(503, 'temporarily_unavailable');
        }
    }

    public function enable(IntegrationProviderConnection $connection, array $configuration): void
    {
        $this->call($connection, $configuration['console_id'], 'cameras/'.CctvContract::providerId($configuration['provider_camera_id']).'/rtsps-stream', true);
    }

    private function call(IntegrationProviderConnection $connection, string $console, string $path, bool $post = false): array
    {
        CctvContract::providerId($console);
        abort_unless(RateLimiter::attempt('cctv-provider-'.$console, 80, fn () => true, 60), 503, 'temporarily_unavailable');
        $key = null;
        try {
            $key = app(IntegrationSecretMaterialService::class)->application($connection, IntegrationSecretManager::PURPOSE_PRIMARY, 'api_key');
            $client = app(UnifiTransportSecurity::class)->request(['X-API-Key' => $key])->acceptJson()->timeout(8)->connectTimeout(3)->withOptions(['stream' => true]);
            $url = 'https://api.ui.com/v1/connector/consoles/'.$console.'/proxy/protect/integration/v1/'.$path;
            $response = $post ? $client->post($url, ['qualities' => ['low']]) : $client->get($url);
            abort_unless($response->status() === 200, 503, 'temporarily_unavailable');
            $body = $response->toPsrResponse()->getBody();
            $bytes = $body->read(262145);
            abort_unless(strlen($bytes) <= 262144 && $body->eof(), 503, 'temporarily_unavailable');
            $json = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
            abort_unless(is_array($json) && ! array_is_list($json), 503, 'temporarily_unavailable');

            return $json;
        } catch (Throwable) {
            abort(503, 'temporarily_unavailable');
        } finally {
            if (is_string($key) && $key !== '') {
                sodium_memzero($key);
            }
        }
    }

    private function mac(mixed $value): string
    {
        abort_unless(is_string($value), 409, 'context_changed');
        $mac = strtolower(str_replace([':', '-'], '', $value));
        abort_unless(preg_match('/\A[0-9a-f]{12}\z/', $mac) === 1, 409, 'context_changed');

        return $mac;
    }
}
