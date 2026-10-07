<?php

namespace App\Services\Medication\PharmacyConnect;

use Illuminate\Contracts\Config\Repository;

final class PharmacyAcknowledgmentVerifier
{
    public function __construct(private readonly Repository $config) {}

    public function verify(int $connectionId, string $body, string $timestamp, string $signature, string $secret, ?int $now = null): array
    {
        $tolerance = max(30, min(600, (int) $this->config->get('emar-pharmacy-connect.acknowledgment_tolerance_seconds', 300)));
        if (strlen($body) > 4096 || ! preg_match('/^[0-9]{10}$/D', $timestamp)
            || abs(($now ?? time()) - (int) $timestamp) > $tolerance || strlen($secret) < 32
            || ! preg_match('/^v1=[a-f0-9]{64}$/D', $signature)
            || ! hash_equals('v1='.hash_hmac('sha256', $timestamp.'.'.$connectionId.'.'.$body, $secret), $signature)) {
            throw new PharmacyConnectionException('acknowledgment_unauthenticated', 'The pharmacy acknowledgment could not be authenticated.', 401);
        }
        $data = json_decode($body, true);
        if (! is_array($data) || array_is_list($data)
            || array_diff(array_keys($data), ['event_id', 'dispatch_uuid', 'outcome', 'supplier_reference']) !== []
            || ! is_string($data['event_id'] ?? null) || ! preg_match('/^[a-zA-Z0-9._\/-]{1,100}$/D', $data['event_id'])
            || ! is_string($data['dispatch_uuid'] ?? null) || ! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/Di', $data['dispatch_uuid'])
            || ! in_array($data['outcome'] ?? null, ['accepted', 'rejected'], true)
            || ! is_string($data['supplier_reference'] ?? null) || ! preg_match('/^[a-zA-Z0-9._\/-]{1,100}$/D', $data['supplier_reference'])) {
            throw new PharmacyConnectionException('acknowledgment_invalid', 'The pharmacy acknowledgment does not match the agreed protocol.');
        }

        return $data;
    }
}
