<?php

use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Artisan;

uses(TestCase::class);

it('passes only with a configured active id and valid sodium-sized key without printing key material', function (): void {
    $encodedKey = base64_encode(random_bytes(SODIUM_CRYPTO_AUTH_KEYBYTES));
    config()->set('monitoring.signing.active_key_id', 'deploy-preflight-test');
    config()->set('monitoring.signing.keys', ['deploy-preflight-test' => $encodedKey]);

    $exit = Artisan::call('monitoring:verify-envelope-signing', ['--json' => true]);
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain('"ready":true', '"active_key_id":"configured"', '"active_key":"valid"', '"signing_probe":"verified"')
        ->not->toContain($encodedKey, 'deploy-preflight-test');
});

it('fails closed when the active signing id is absent', function (): void {
    config()->set('monitoring.signing.active_key_id', null);
    config()->set('monitoring.signing.keys', []);

    $exit = Artisan::call('monitoring:verify-envelope-signing', ['--json' => true]);

    expect($exit)->toBe(1)
        ->and(Artisan::output())
        ->toContain('"ready":false', '"active_key_id":"missing"', '"active_key":"invalid"', '"signing_probe":"not_verified"');
});

it('fails closed when the active key is not a valid 32-byte sodium key', function (): void {
    config()->set('monitoring.signing.active_key_id', 'invalid-key');
    config()->set('monitoring.signing.keys', ['invalid-key' => base64_encode('too-short')]);

    $exit = Artisan::call('monitoring:verify-envelope-signing', ['--json' => true]);

    expect($exit)->toBe(1)
        ->and(Artisan::output())
        ->toContain('"ready":false', '"active_key_id":"configured"', '"active_key":"invalid"', '"signing_probe":"not_verified"')
        ->not->toContain('invalid-key');
});
