<?php

use App\Domain\Hr\Exceptions\UnsafeWebhookHeaders;
use App\Domain\Hr\Services\HrWebhookHeaderPolicy;

it('normalizes safe custom webhook headers deterministically', function (): void {
    $policy = new HrWebhookHeaderPolicy;

    expect($policy->normalize([
        'X-Zone' => 'north',
        'X-Correlation-Label' => 'staff-change',
    ]))->toBe([
        'X-Correlation-Label' => 'staff-change',
        'X-Zone' => 'north',
    ]);
});

it('rejects authentication overrides, sensitive headers, and response splitting', function (array $headers): void {
    expect(fn () => (new HrWebhookHeaderPolicy)->normalize($headers))
        ->toThrow(UnsafeWebhookHeaders::class);
})->with([
    'delivery signature' => [['X-Oblivion-Webhook-Signature' => 'override']],
    'authorization material' => [['Authorization' => 'Bearer example']],
    'api key material' => [['X-Api-Key' => 'example']],
    'line injection' => [['X-Safe-Name' => "ok\r\nX-Injected: value"]],
]);

it('filters unsafe historical headers defensively at dispatch time', function (): void {
    $safe = (new HrWebhookHeaderPolicy)->safeForDelivery([
        'X-Environment' => 'production',
        'Content-Type' => 'text/plain',
        'X-Api-Key' => 'legacy-secret',
        'X-Injected' => "safe\nnot-safe",
    ]);

    expect($safe)->toBe(['X-Environment' => 'production']);
});
