<?php

namespace Tests\Unit\Medication\PharmacyConnect;

use App\Domain\Hr\Services\HrWebhookDestinationPolicy;
use App\Domain\Monitoring\Contracts\DnsResolver;
use App\Domain\Monitoring\Services\CidrMatcher;
use App\Services\Medication\PharmacyConnect\HttpJsonPharmacyTransport;
use App\Services\Medication\PharmacyConnect\PharmacyAcknowledgmentVerifier;
use App\Services\Medication\PharmacyConnect\PharmacyConnectionException;
use App\Services\Medication\PharmacyConnect\PharmacyDispatchService;
use App\Services\Medication\PharmacyConnect\PharmacyPartnerRegistry;
use App\Services\Medication\PharmacyConnect\PharmacyTransportResult;
use Illuminate\Config\Repository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\TestCase;

final class PharmacyProtocolTest extends TestCase
{
    public function test_preinstall_recovery_is_safe_without_database_or_queue_services(): void
    {
        Schema::swap(new class
        {
            public function hasTable(string $table): bool
            {
                return false;
            }
        });
        try {
            // No container/database is present: touching either would fail this test.
            $service = (new \ReflectionClass(PharmacyDispatchService::class))->newInstanceWithoutConstructor();
            $this->assertSame(['unknown' => 0, 'queued' => 0], $service->recover());
        } finally {
            Schema::clearResolvedInstance('db.schema');
        }
    }

    public function test_http_status_never_claims_pharmacy_acceptance(): void
    {
        foreach ([200, 201, 202, 204, 299] as $status) {
            $this->assertSame('sent', PharmacyTransportResult::fromHttp($status)->state);
        }
        foreach ([301, 302, 400, 401, 408, 409, 425, 429, 500, 502, 503] as $status) {
            $this->assertSame('unknown', PharmacyTransportResult::fromHttp($status)->state);
        }
        $this->assertSame('failed', PharmacyTransportResult::fromHttp(422, [422])->state);
        foreach ([408, 409, 425, 429, 500] as $status) {
            $this->assertSame('unknown', PharmacyTransportResult::fromHttp($status, [$status])->state);
        }
    }

    public function test_signatures_bind_raw_payload_connection_and_fresh_timestamp(): void
    {
        $verifier = new PharmacyAcknowledgmentVerifier(new Repository);
        $secret = str_repeat('synthetic-secret-', 3);
        $body = json_encode(['event_id' => 'EVENT-1', 'dispatch_uuid' => '11111111-1111-4111-8111-111111111111',
            'outcome' => 'accepted', 'supplier_reference' => 'SUPPLIER-1'], JSON_THROW_ON_ERROR);
        $now = 1791320000;
        $signature = 'v1='.hash_hmac('sha256', $now.'.12.'.$body, $secret);
        $this->assertSame('accepted', $verifier->verify(12, $body, (string) $now, $signature, $secret, $now)['outcome']);
        foreach ([[13, $body, $now, $signature], [12, $body.' ', $now, $signature], [12, $body, $now - 301, $signature],
            [12, $body, $now, 'v1='.str_repeat('0', 64)]] as [$connection, $raw, $timestamp, $signed]) {
            try {
                $verifier->verify($connection, $raw, (string) $timestamp, $signed, $secret, $now);
                $this->fail('Untrusted receipt was accepted.');
            } catch (PharmacyConnectionException $exception) {
                $this->assertSame('acknowledgment_unauthenticated', $exception->errorCode);
                $this->assertStringNotContainsString($secret, $exception->getMessage());
            }
        }
    }

    public function test_signed_receipt_rejects_extra_clinical_payload_or_structured_identifiers(): void
    {
        $verifier = new PharmacyAcknowledgmentVerifier(new Repository);
        $secret = str_repeat('s', 40);
        $now = 1791320000;
        foreach ([['event_id' => ['unsafe']], ['outcome' => 'delivered'], ['patient' => 'Not permitted']] as $extra) {
            $body = json_encode(array_merge(['event_id' => 'EV1', 'dispatch_uuid' => '11111111-1111-4111-8111-111111111111',
                'outcome' => 'accepted', 'supplier_reference' => 'REF1'], $extra), JSON_THROW_ON_ERROR);
            try {
                $verifier->verify(12, $body, (string) $now, 'v1='.hash_hmac('sha256', $now.'.12.'.$body, $secret), $secret, $now);
                $this->fail('An invalid signed protocol payload was accepted.');
            } catch (PharmacyConnectionException $exception) {
                $this->assertSame('acknowledgment_invalid', $exception->errorCode);
            }
        }
    }

    public function test_transport_is_pinned_bounded_and_does_not_follow_redirects(): void
    {
        $http = new Factory;
        $http->preventStrayRequests();
        $optionsSeen = null;
        $http->fake(function ($request, $options) use ($http, &$optionsSeen) {
            $optionsSeen = $options;

            return $http->response('untrusted body', 302, ['Location' => 'https://evil.example/private']);
        });
        $transport = $this->transport($http, ['93.184.216.34']);
        $result = $transport->send($this->partner(), '11111111-1111-4111-8111-111111111111', ['quantity' => 10]);
        $this->assertSame('unknown', $result->state);
        $this->assertFalse($optionsSeen['allow_redirects']);
        $this->assertSame('', $optionsSeen['proxy']);
        $this->assertTrue($optionsSeen['verify']);
        $this->assertLessThanOrEqual(15, $optionsSeen['timeout']);
        $this->assertSame(['pharmacy.example.test:443:93.184.216.34'], $optionsSeen['curl'][CURLOPT_RESOLVE]);
        $http->assertSentCount(1);
        $http->assertSent(fn ($request) => $request->hasHeader('Idempotency-Key', '11111111-1111-4111-8111-111111111111')
            && $request->data() === ['quantity' => 10]);
    }

    public function test_private_dns_answer_sends_nothing_and_timeout_is_unknown(): void
    {
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(fn () => throw new ConnectionException('contains secret and person details'));
        $this->assertSame('failed', $this->transport($http, ['127.0.0.1'])->send($this->partner(), 'request', [])->state);
        $http->assertNothingSent();
        $result = $this->transport($http, ['93.184.216.34'])->send($this->partner(), 'request', []);
        $this->assertSame('unknown', $result->state);
        $this->assertSame('transport_timeout_or_connection_lost', $result->code);
    }

    public function test_registry_exposes_only_approved_house_scoped_partner_options(): void
    {
        $partner = [...$this->partner(), 'protocol' => 'oblivion-json-v1', 'site_references' => [8 => 'HOUSE8']];
        $registry = new PharmacyPartnerRegistry(new Repository(['emar-pharmacy-connect' => ['partners' => ['approved' => $partner]]]));
        $this->assertSame([], $registry->options([9]));
        $options = $registry->options([8, 9]);
        $this->assertSame([8], $options[0]['site_ids']);
        $this->assertStringNotContainsString($partner['outbound_token'], json_encode($options));
        $this->assertStringNotContainsString($partner['endpoint'], json_encode($options));
        $this->expectException(PharmacyConnectionException::class);
        $registry->forSite('approved', 9);
    }

    private function partner(): array
    {
        return ['endpoint' => 'https://pharmacy.example.test/orders', 'outbound_token' => str_repeat('synthetic-token-', 3),
            'acknowledgment_secret' => str_repeat('synthetic-ack-', 3)];
    }

    private function transport(Factory $http, array $answers): HttpJsonPharmacyTransport
    {
        $resolver = new class($answers) implements DnsResolver
        {
            public function __construct(private readonly array $answers) {}

            public function resolve(string $host): array
            {
                return $this->answers;
            }
        };

        return new HttpJsonPharmacyTransport($http, new HrWebhookDestinationPolicy(new CidrMatcher, $resolver), new Repository);
    }
}
