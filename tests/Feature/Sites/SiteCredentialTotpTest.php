<?php

use App\Models\Role;
use App\Models\Site;
use App\Models\SiteCredential;
use App\Models\SiteCredentialAuditLog;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use PragmaRX\Google2FA\Google2FA;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RbacSeeder::class);

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
});

test('credential store accepts a pasted Base32 TOTP secret and persists it encrypted', function () {
    $google2fa = new Google2FA;
    $secret = $google2fa->generateSecretKey(); // emulating a secret from another service

    $this->actingAs($this->admin)
        ->from("/sites/{$this->site->id}")
        ->post("/sites/{$this->site->id}/credentials", [
            'label' => 'AWS Console',
            'username' => 'root@example.test',
            'credential_type' => 'password',
            'value' => 'pw',
            'totp_secret' => $secret,
        ])
        ->assertRedirect("/sites/{$this->site->id}");

    $credential = SiteCredential::query()->where('site_id', $this->site->id)->firstOrFail();
    expect($credential->totp_secret_encrypted)->not->toBeNull();
    expect(Crypt::decryptString($credential->totp_secret_encrypted))->toBe($secret);
    expect($credential->totp_issuer)->toBe($this->site->name);
    expect($credential->totp_account)->toBe('root@example.test');
    expect($credential->hasTotp())->toBeTrue();
});

test('credential store normalizes pasted secrets: whitespace stripped, uppercased', function () {
    $google2fa = new Google2FA;
    $secret = $google2fa->generateSecretKey();
    // Many services show the secret in groups of 4 (lowercase too in some).
    $messy = strtolower(implode(' ', str_split($secret, 4)));

    $this->actingAs($this->admin)
        ->post("/sites/{$this->site->id}/credentials", [
            'label' => 'Router admin',
            'credential_type' => 'password',
            'value' => 'pw',
            'totp_secret' => $messy,
        ]);

    $credential = SiteCredential::query()->where('site_id', $this->site->id)->firstOrFail();
    expect(Crypt::decryptString($credential->totp_secret_encrypted))->toBe($secret);
});

test('credential update with totp_secret rotates the secret; leaving it blank keeps existing', function () {
    $google2fa = new Google2FA;
    $original = $google2fa->generateSecretKey();
    $credential = SiteCredential::create([
        'site_id' => $this->site->id,
        'label' => 'before',
        'credential_type' => 'password',
        'encrypted_value' => Crypt::encryptString('pw'),
        'totp_secret_encrypted' => Crypt::encryptString($original),
        'totp_issuer' => 'old',
        'totp_account' => 'me',
    ]);

    // Blank totp_secret in payload — existing secret must be preserved.
    $this->actingAs($this->admin)
        ->put("/sites/{$this->site->id}/credentials/{$credential->id}", [
            'label' => 'still keeps old totp',
            'credential_type' => 'password',
            'value' => '',
            'totp_secret' => '',
        ]);
    $credential->refresh();
    expect(Crypt::decryptString($credential->totp_secret_encrypted))->toBe($original);

    // Non-blank totp_secret — rotate.
    $replacement = $google2fa->generateSecretKey();
    $this->actingAs($this->admin)
        ->put("/sites/{$this->site->id}/credentials/{$credential->id}", [
            'label' => 'rotated',
            'credential_type' => 'password',
            'value' => '',
            'totp_secret' => $replacement,
        ]);
    $credential->refresh();
    expect(Crypt::decryptString($credential->totp_secret_encrypted))->toBe($replacement);
});

test('totp code endpoint returns a valid 6-digit code for a pasted secret + audits', function () {
    $google2fa = new Google2FA;
    $secret = $google2fa->generateSecretKey();

    $this->actingAs($this->admin)
        ->post("/sites/{$this->site->id}/credentials", [
            'label' => 'AWS',
            'credential_type' => 'password',
            'value' => 'pw',
            'totp_secret' => $secret,
        ]);

    $credential = SiteCredential::query()->where('site_id', $this->site->id)->firstOrFail();

    $response = $this->actingAs($this->admin)
        ->postJson("/sites/{$this->site->id}/credentials/{$credential->id}/totp/code")
        ->assertOk();

    $body = $response->json();
    expect($body['code'])->toMatch('/^\d{6}$/');
    expect($body['seconds_remaining'])->toBeGreaterThan(0)->toBeLessThanOrEqual(30);
    expect($body['period'])->toBe(30);
    expect($google2fa->verifyKey($secret, $body['code'], 1))->toBeTrue();

    expect(
        SiteCredentialAuditLog::query()
            ->where('credential_id', $credential->id)
            ->where('action', 'totp_code')
            ->exists(),
    )->toBeTrue();
});

test('totp code endpoint requires re-auth when the credential requires reauth', function () {
    $google2fa = new Google2FA;
    $secret = $google2fa->generateSecretKey();

    $credential = SiteCredential::create([
        'site_id' => $this->site->id,
        'label' => 'Protected admin',
        'credential_type' => 'password',
        'encrypted_value' => Crypt::encryptString('pw'),
        'requires_reauth' => true,
        'totp_secret_encrypted' => Crypt::encryptString($secret),
    ]);

    $this->actingAs($this->admin)
        ->postJson("/sites/{$this->site->id}/credentials/{$credential->id}/totp/code")
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['password']);

    $this->actingAs($this->admin)
        ->postJson("/sites/{$this->site->id}/credentials/{$credential->id}/totp/code", [
            'password' => 'definitely-wrong',
        ])
        ->assertStatus(403);

    expect(
        SiteCredentialAuditLog::query()
            ->where('credential_id', $credential->id)
            ->where('action', 'reauth_failed')
            ->exists(),
    )->toBeTrue();

    $response = $this->actingAs($this->admin)
        ->postJson("/sites/{$this->site->id}/credentials/{$credential->id}/totp/code", [
            'password' => 'password',
        ])
        ->assertOk();

    expect($google2fa->verifyKey($secret, $response->json('code'), 1))->toBeTrue();

    $this->actingAs($this->admin)
        ->postJson("/sites/{$this->site->id}/credentials/{$credential->id}/totp/code")
        ->assertOk()
        ->assertJsonStructure(['code', 'seconds_remaining', 'period']);
});

test('totp code endpoint returns 404 when no secret is stored', function () {
    $credential = SiteCredential::create([
        'site_id' => $this->site->id,
        'label' => 'no totp',
        'credential_type' => 'password',
        'encrypted_value' => Crypt::encryptString('pw'),
    ]);

    $this->actingAs($this->admin)
        ->postJson("/sites/{$this->site->id}/credentials/{$credential->id}/totp/code")
        ->assertNotFound();
});

test('removing TOTP clears the columns and audits totp_remove', function () {
    $credential = SiteCredential::create([
        'site_id' => $this->site->id,
        'label' => 'with totp',
        'credential_type' => 'password',
        'encrypted_value' => Crypt::encryptString('pw'),
        'totp_secret_encrypted' => Crypt::encryptString('JBSWY3DPEHPK3PXP'),
        'totp_issuer' => 'X',
        'totp_account' => 'y',
    ]);

    $this->actingAs($this->admin)
        ->from("/sites/{$this->site->id}")
        ->delete("/sites/{$this->site->id}/credentials/{$credential->id}/totp")
        ->assertRedirect("/sites/{$this->site->id}");

    $credential->refresh();
    expect($credential->totp_secret_encrypted)->toBeNull();
    expect($credential->hasTotp())->toBeFalse();

    expect(
        SiteCredentialAuditLog::query()
            ->where('credential_id', $credential->id)
            ->where('action', 'totp_remove')
            ->exists(),
    )->toBeTrue();
});

test('removed enrollment endpoints no longer respond', function () {
    $credential = SiteCredential::create([
        'site_id' => $this->site->id,
        'label' => 'x',
        'credential_type' => 'password',
        'encrypted_value' => Crypt::encryptString('pw'),
    ]);

    $this->actingAs($this->admin)
        ->postJson("/sites/{$this->site->id}/credentials/totp/generate-secret")
        ->assertNotFound();

    $this->actingAs($this->admin)
        ->postJson("/sites/{$this->site->id}/credentials/{$credential->id}/totp/setup", [
            'secret' => 'JBSWY3DPEHPK3PXP',
            'verification_code' => '123456',
        ])
        ->assertNotFound();
});
