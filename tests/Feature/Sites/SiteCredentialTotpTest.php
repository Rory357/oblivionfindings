<?php

use App\Models\Role;
use App\Models\Site;
use App\Models\SiteCredential;
use App\Models\SiteCredentialAuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use PragmaRX\Google2FA\Google2FA;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(\Database\Seeders\RbacSeeder::class);

    $this->admin = User::factory()->create([
        'role' => 'admin',
        'approved_at' => now(),
    ]);
    $adminRole = Role::query()->where('name', 'admin')->firstOrFail();
    $this->admin->roles()->sync([$adminRole->id]);

    $this->site = Site::factory()->create([
        'type' => 'house',
        'is_active' => true,
    ]);

    $this->credential = SiteCredential::create([
        'site_id' => $this->site->id,
        'tenant_id' => $this->site->tenant_id,
        'label' => 'AWS root',
        'credential_type' => 'password',
        'encrypted_value' => Crypt::encryptString('hunter2'),
        'requires_reauth' => false,
    ]);
});

test('totp generate-secret returns a base32 secret + server-rendered QR data URI', function () {
    $response = $this->actingAs($this->admin)
        ->postJson("/sites/{$this->site->id}/credentials/totp/generate-secret", [
            'account' => 'root@example.test',
        ])
        ->assertOk();

    $body = $response->json();
    expect($body['secret'])->toBeString()->not->toBeEmpty();
    expect(preg_match('/^[A-Z2-7]+$/', $body['secret']))->toBe(1, 'secret must be Base32');
    expect($body['qr_data_uri'])->toStartWith('data:image/png;base64,');
    expect($body['otpauth_uri'])->toContain('otpauth://totp/');
    expect($body['otpauth_uri'])->toContain($body['secret']);
});

test('totp setup verifies the 6-digit code, stores an encrypted secret, and audits', function () {
    $google2fa = new Google2FA();
    $secret = $google2fa->generateSecretKey();
    $code = $google2fa->getCurrentOtp($secret);

    $this->actingAs($this->admin)
        ->from("/sites/{$this->site->id}")
        ->post("/sites/{$this->site->id}/credentials/{$this->credential->id}/totp/setup", [
            'secret' => $secret,
            'issuer' => 'Acme Site',
            'account' => 'root@example.test',
            'verification_code' => $code,
        ])
        ->assertRedirect("/sites/{$this->site->id}");

    $this->credential->refresh();
    expect($this->credential->totp_secret_encrypted)->not->toBeNull();
    expect(Crypt::decryptString($this->credential->totp_secret_encrypted))->toBe($secret);
    expect($this->credential->totp_issuer)->toBe('Acme Site');
    expect($this->credential->totp_account)->toBe('root@example.test');
    expect($this->credential->hasTotp())->toBeTrue();

    expect(
        SiteCredentialAuditLog::query()
            ->where('credential_id', $this->credential->id)
            ->where('action', 'totp_setup')
            ->exists(),
    )->toBeTrue();
});

test('totp setup rejects an incorrect verification code', function () {
    $google2fa = new Google2FA();
    $secret = $google2fa->generateSecretKey();

    $this->actingAs($this->admin)
        ->from("/sites/{$this->site->id}")
        ->post("/sites/{$this->site->id}/credentials/{$this->credential->id}/totp/setup", [
            'secret' => $secret,
            'verification_code' => '000000',
        ])
        ->assertSessionHasErrors('verification_code');

    $this->credential->refresh();
    expect($this->credential->totp_secret_encrypted)->toBeNull();
});

test('totp code endpoint returns a 6-digit code valid for the same secret + audits', function () {
    $google2fa = new Google2FA();
    $secret = $google2fa->generateSecretKey();
    $this->credential->update([
        'totp_secret_encrypted' => Crypt::encryptString($secret),
        'totp_issuer' => $this->site->name,
        'totp_account' => $this->credential->label,
    ]);

    $response = $this->actingAs($this->admin)
        ->postJson("/sites/{$this->site->id}/credentials/{$this->credential->id}/totp/code")
        ->assertOk();

    $body = $response->json();
    expect($body['code'])->toMatch('/^\d{6}$/');
    expect($body['seconds_remaining'])->toBeGreaterThan(0)->toBeLessThanOrEqual(30);
    expect($body['period'])->toBe(30);
    expect($google2fa->verifyKey($secret, $body['code'], 1))->toBeTrue();

    expect(
        SiteCredentialAuditLog::query()
            ->where('credential_id', $this->credential->id)
            ->where('action', 'totp_code')
            ->exists(),
    )->toBeTrue();
});

test('totp code endpoint returns 404 when the credential has no TOTP secret', function () {
    $this->actingAs($this->admin)
        ->postJson("/sites/{$this->site->id}/credentials/{$this->credential->id}/totp/code")
        ->assertNotFound();
});

test('removing TOTP clears the columns and audits', function () {
    $this->credential->update([
        'totp_secret_encrypted' => Crypt::encryptString('JBSWY3DPEHPK3PXP'),
        'totp_issuer' => 'X',
        'totp_account' => 'y',
    ]);

    $this->actingAs($this->admin)
        ->from("/sites/{$this->site->id}")
        ->delete("/sites/{$this->site->id}/credentials/{$this->credential->id}/totp")
        ->assertRedirect("/sites/{$this->site->id}");

    $this->credential->refresh();
    expect($this->credential->totp_secret_encrypted)->toBeNull();
    expect($this->credential->totp_issuer)->toBeNull();
    expect($this->credential->totp_account)->toBeNull();
    expect($this->credential->hasTotp())->toBeFalse();

    expect(
        SiteCredentialAuditLog::query()
            ->where('credential_id', $this->credential->id)
            ->where('action', 'totp_remove')
            ->exists(),
    )->toBeTrue();
});
