<?php

use App\Domain\SecurityDevices\Endpoints\Services\EndpointCommandSigner;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;

beforeEach(function () {
    $this->signerContainer = Container::getInstance();
    $container = new Container;
    $pair = sodium_crypto_sign_seed_keypair(str_repeat('A', SODIUM_CRYPTO_SIGN_SEEDBYTES));
    $container->instance('config', new Repository(['endpoint_management' => [
        'command_signing_secret' => base64_encode(sodium_crypto_sign_secretkey($pair)),
    ]]));
    Container::setInstance($container);
});

afterEach(function () {
    Container::setInstance($this->signerContainer);
});

it('signs the exact endpoint command and rejects changed identity or action bytes', function () {
    $signer = new EndpointCommandSigner;
    $payload = ['agent_uuid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'action' => 'inventory.refresh'];
    $envelope = $signer->sign($payload);
    $body = base64_decode($envelope['payload'], true);
    $signature = base64_decode($envelope['signature'], true);
    $public = base64_decode($signer->publicKey(), true);
    expect(sodium_crypto_sign_verify_detached($signature, $body, $public))->toBeTrue()
        ->and(json_decode($body, true))->toBe($payload)
        ->and(sodium_crypto_sign_verify_detached($signature, str_replace('inventory.refresh', 'device.reboot', $body), $public))->toBeFalse()
        ->and(sodium_crypto_sign_verify_detached($signature, str_replace('aaaaaaaa-', 'bbbbbbbb-', $body), $public))->toBeFalse();
});

it('does not sign without a valid deployment key', function () {
    config()->set('endpoint_management.command_signing_secret', '');
    expect(fn () => (new EndpointCommandSigner)->sign(['action' => 'inventory.refresh']))->toThrow(RuntimeException::class);
});

it('bounds signed payloads so oversized task content cannot be dispatched', function () {
    expect(fn () => (new EndpointCommandSigner)->sign(['content' => str_repeat('x', 131073)]))->toThrow(RuntimeException::class);
});

it('rejects a malformed key even when its decoded length is correct', function () {
    config()->set('endpoint_management.command_signing_secret', base64_encode(str_repeat('x', SODIUM_CRYPTO_SIGN_SECRETKEYBYTES)));
    expect(fn () => (new EndpointCommandSigner)->publicKey())->toThrow(RuntimeException::class);
});
