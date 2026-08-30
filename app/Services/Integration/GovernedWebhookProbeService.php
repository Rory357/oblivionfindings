<?php

namespace App\Services\Integration;

use App\Domain\Hr\Data\AuthorizedHrWebhookDestination;
use App\Domain\Hr\Exceptions\UnsafeWebhookDestination;
use App\Domain\Hr\Services\HrWebhookDestinationPolicy;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class GovernedWebhookProbeService
{
    private const MAX_REDIRECTS = 3;

    public function __construct(
        private readonly HrWebhookDestinationPolicy $destinationPolicy,
    ) {}

    /**
     * Authorize and canonicalize a destination before it is persisted.
     */
    public function canonicalize(string $url): string
    {
        return $this->destinationPolicy->authorize($url)->url;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function probe(string $url, array $payload): ?int
    {
        // Re-authorize immediately before every probe. This closes the gap
        // between configuration time and delivery time, including DNS rebinding.
        $target = $this->destinationPolicy->authorize($url);

        foreach (['POST', 'HEAD', 'GET'] as $method) {
            try {
                $response = $this->requestWithRedirects($method, $target, $payload);
            } catch (ConnectionException) {
                continue;
            }

            if ($response->status() >= 200 && $response->status() < 400) {
                return $response->status();
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws ConnectionException
     * @throws UnsafeWebhookDestination
     */
    private function requestWithRedirects(
        string $method,
        AuthorizedHrWebhookDestination $initialTarget,
        array $payload,
    ): Response {
        $target = $initialTarget;
        $visited = [];

        for ($redirects = 0; $redirects <= self::MAX_REDIRECTS; $redirects++) {
            if (isset($visited[$target->url])) {
                throw new UnsafeWebhookDestination('Webhook redirect is not approved.');
            }
            $visited[$target->url] = true;

            $response = $this->requestAuthorizedTarget($method, $target, $payload);
            if (! in_array($response->status(), [301, 302, 303, 307, 308], true)) {
                return $response;
            }

            $location = $response->header('Location');
            if (! is_string($location) || $location === '') {
                return $response;
            }
            if ($redirects === self::MAX_REDIRECTS) {
                throw new UnsafeWebhookDestination('Webhook redirect is not approved.');
            }

            $target = $this->destinationPolicy->authorizeRedirect($target, $location);
        }

        throw new UnsafeWebhookDestination('Webhook redirect is not approved.');
    }

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws ConnectionException
     */
    private function requestAuthorizedTarget(
        string $method,
        AuthorizedHrWebhookDestination $target,
        array $payload,
    ): Response {
        $options = [
            'allow_redirects' => false,
            'connect_timeout' => 5,
            'timeout' => 5,
            'http_errors' => false,
            'stream' => true,
            'decode_content' => false,
            'proxy' => '',
            'verify' => true,
        ];

        if ($target->requiresDnsPin()) {
            if (! defined('CURLOPT_RESOLVE')) {
                throw new RuntimeException('Pinned webhook transport is unavailable.');
            }

            $options['curl'] = [
                constant('CURLOPT_RESOLVE') => [$target->curlResolveEntry()],
            ];
        }

        $request = Http::withOptions($options)->acceptJson();

        return match ($method) {
            'POST' => $request->asJson()->post($target->url, $payload),
            'HEAD' => $request->head($target->url),
            default => $request->get($target->url),
        };
    }
}
