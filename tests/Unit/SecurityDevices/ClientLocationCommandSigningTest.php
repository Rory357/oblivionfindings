<?php

namespace Tests\Unit\SecurityDevices;

use App\Domain\SecurityDevices\Management\Data\ClientLocationCommandOrigin;
use App\Domain\SecurityDevices\Management\Data\CommandSigningPayload;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class ClientLocationCommandSigningTest extends TestCase
{
    public function test_null_origin_preserves_schema_two_through_seven_canonical_bytes(): void
    {
        // Captured by executing the unchanged HEAD payload class at 2302ca33.
        $expected = [
            2 => '39024cc1020c439343f7760baf2e5902b88bfbf0a1971842c7bcc8d061a8ad11',
            3 => '6bcf2c5b3f4f7bf0d5f8e56a3642a61d762c2cb12ad037297ff0191a182699a4',
            4 => '117d4b8584f28405ae59458ece8c2141e7a4727de572851a48cb814336bbcc25',
            5 => '0a56b332303209ade91ed3df449cab664add6047d88e521da7352f7a9213bba4',
            6 => '7830480ca9417f9c4643435711dabc13ea6b644c9c202d8066656d05510042bd',
            7 => 'fece990f50dd8b430f87af80b24050d582b21ffd990a836617f5da0ed0ca35b4',
        ];
        foreach ($expected as $schema => $hash) {
            $payload = $this->payload($schema)->toArray();
            $this->assertSame($schema, $payload['schema_version']);
            $this->assertArrayNotHasKey('origin_context', $payload);
            $this->assertSame($hash, hash('sha256', $this->canonicalJson($payload)));
        }
    }

    public function test_schema_eight_signs_the_exact_client_origin_without_losing_prior_contract_fields(): void
    {
        $origin = ClientLocationCommandOrigin::fromArray(['kind' => 'client_location', 'version' => 1,
            'client_id' => 8, 'assignment_id' => 9, 'consent_id' => 10, 'access_fingerprint' => str_repeat('e', 64)]);
        $payload = $this->payload(4, $origin)->toArray();
        $this->assertSame(8, $payload['schema_version']);
        $this->assertSame($origin->toArray(), $payload['origin_context']);
        unset($payload['origin_context']);
        $payload['schema_version'] = 4;
        $this->assertSame($this->payload(4)->toArray(), $payload);
    }

    private function payload(int $schema, ?ClientLocationCommandOrigin $origin = null): CommandSigningPayload
    {
        return new CommandSigningPayload(commandUuid: 'test-command', deviceId: 11, siteId: 22, requestedByUserId: 33,
            capability: 'tracking.location_refresh', capabilityVersion: 1, managementLevel: 'operate', risk: 'medium',
            idempotencyKey: 'legacy-test', parametersHash: str_repeat('a', 64), reasonHash: str_repeat('b', 64),
            expectedState: ['action_completed' => true], reconciliationRule: 'fresh_location_observation',
            expiresAt: CarbonImmutable::parse('2026-09-21T00:03:00Z'), itChangeId: null, collectorId: null,
            isBreakGlass: $schema % 2 === 1, provider: 'test',
            breakGlassReviewerUserId: $schema % 2 === 1 ? 44 : null,
            breakGlassReasonHash: $schema % 2 === 1 ? str_repeat('c', 64) : null,
            assignmentFingerprint: $schema >= 4 ? str_repeat('d', 64) : null,
            confirmationMode: $schema >= 6 ? 'impact_acknowledgement' : null,
            impactAcknowledgedAt: $schema >= 6 ? CarbonImmutable::parse('2026-09-21T00:00:00Z') : null, originContext: $origin);
    }

    private function canonicalJson(array $value): string
    {
        $sort = function ($item) use (&$sort) {
            if (! is_array($item)) {
                return $item;
            }
            if (! array_is_list($item)) {
                ksort($item, SORT_STRING);
            }

            return array_map($sort, $item);
        };

        return json_encode($sort($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }
}
