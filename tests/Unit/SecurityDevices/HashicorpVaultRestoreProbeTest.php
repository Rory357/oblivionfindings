<?php

use App\Domain\SecurityDevices\Credentials\Services\HashicorpVaultLeaseIssuer;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(TestCase::class);

beforeEach(function (): void {
    config()->set('monitoring.credentials.vault.url', 'https://vault.restore.test');
    config()->set('monitoring.credentials.vault.token', 'restore-probe-token-sentinel');
    config()->set('monitoring.credentials.vault.namespace', '');
});

it('uses only the documented read-only Vault health endpoint', function (): void {
    Http::fake([
        'https://vault.restore.test/v1/sys/health*' => Http::response([], 200),
    ]);

    expect((new HashicorpVaultLeaseIssuer)->healthy())->toBeTrue();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'HEAD'
        && str_starts_with($request->url(), 'https://vault.restore.test/v1/sys/health?')
        && str_contains($request->url(), 'standbyok=true')
        && str_contains($request->url(), 'perfstandbyok=true')
        && ! $request->hasHeader('X-Vault-Token')
        && ! $request->hasHeader('X-Vault-Namespace'));
    Http::assertSentCount(1);
});

it('fails health closed without exposing configuration or calling an insecure endpoint', function (): void {
    config()->set('monitoring.credentials.vault.url', 'http://vault.restore.test');
    Http::fake();

    expect((new HashicorpVaultLeaseIssuer)->healthy())->toBeFalse();

    Http::assertNothingSent();
});
