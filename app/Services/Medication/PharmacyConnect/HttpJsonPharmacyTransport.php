<?php

namespace App\Services\Medication\PharmacyConnect;

use App\Domain\Hr\Exceptions\UnsafeWebhookDestination;
use App\Domain\Hr\Services\HrWebhookDestinationPolicy;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Throwable;

/** A real, pinned HTTPS bridge transport; no vendor compatibility is implied. */
class HttpJsonPharmacyTransport
{
    public function __construct(private readonly Factory $http, private readonly HrWebhookDestinationPolicy $destinations, private readonly Repository $config) {}

    public function send(array $partner, string $dispatchUuid, array $payload): PharmacyTransportResult
    {
        try {
            $target = $this->destinations->authorize($partner['endpoint']);
        } catch (UnsafeWebhookDestination) {
            return new PharmacyTransportResult('failed', 'destination_not_approved');
        }
        $options = [
            'allow_redirects' => false, 'connect_timeout' => 5,
            'timeout' => max(2, min(15, (int) $this->config->get('emar-pharmacy-connect.timeout_seconds', 10))),
            'http_errors' => false, 'stream' => true, 'decode_content' => false, 'proxy' => '', 'verify' => true,
        ];
        if ($target->requiresDnsPin()) {
            if (! defined('CURLOPT_RESOLVE')) {
                return new PharmacyTransportResult('failed', 'pinned_transport_unavailable');
            }
            $options['curl'] = [constant('CURLOPT_RESOLVE') => [$target->curlResolveEntry()]];
        }
        try {
            // Never retry here. Never read or retain the untrusted response body.
            $response = $this->http->withOptions($options)->withToken($partner['outbound_token'])
                ->withHeaders(['Idempotency-Key' => $dispatchUuid, 'X-Pharmacy-Protocol' => 'oblivion-json-v1'])
                ->acceptJson()->asJson()->post($target->url, $payload);

            return PharmacyTransportResult::fromHttp($response->status(), $partner['definitive_failure_statuses'] ?? []);
        } catch (ConnectionException) {
            return new PharmacyTransportResult('unknown', 'transport_timeout_or_connection_lost');
        } catch (Throwable) {
            // Request exceptions can contain Authorization and patient payloads.
            return new PharmacyTransportResult('unknown', 'transport_interrupted');
        }
    }
}
