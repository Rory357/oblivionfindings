<?php

/**
 * The generic bridge below requires a separately agreed pharmacy contract.
 * It is not a Toniq adapter. Keep disabled until the real partner validates it.
 * Secrets belong in deployment configuration; they are never accepted by UI.
 */
return [
    'enabled' => false,
    'timeout_seconds' => 10,
    'sending_lease_seconds' => 120,
    'acknowledgment_tolerance_seconds' => 300,
    'partners' => [
        // 'approved-partner' => [
        //     'label' => 'Approved pharmacy bridge',
        //     'protocol' => 'oblivion-json-v1',
        //     'endpoint' => 'https://approved.example/orders',
        //     'outbound_token' => /* deployment secret */ '',
        //     'acknowledgment_secret' => /* separate deployment secret */ '',
        //     'supports_idempotency' => true,
        //     'site_references' => [/* canonical Site ID => partner account */],
        // ],
    ],
];
