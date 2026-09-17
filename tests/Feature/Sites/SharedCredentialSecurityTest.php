<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteCredential;
use App\Models\SiteCredentialAuditLog;
use App\Models\SiteCredentialVersion;
use App\Models\User;
use App\Services\Sites\SiteCredentialAccess;
use App\Services\Sites\SiteCredentialEncryptionService;
use Database\Seeders\RbacSeeder;
use Database\Seeders\VendorVaultPermissionsSeeder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;

beforeEach(function () {
    config(['inertia.ssr.enabled' => false]);
    $this->seed([RbacSeeder::class, VendorVaultPermissionsSeeder::class]);
    $this->vaultSites = Site::factory()->count(2)->create(['type' => 'house', 'is_active' => true]);
    $this->vaultActor = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $this->vaultRole = Role::create(['name' => 'vault-'.str()->uuid(), 'label' => 'Vault fixture', 'level' => 10, 'type' => 'custom']);
    $this->vaultRole->permissions()->attach(Permission::whereIn('key', ['sites.viewAny', 'credentials.view', 'credentials.reveal', 'credentials.copy', 'credentials.manage', 'credentials.audit'])->pluck('id'));
    $this->vaultActor->roles()->sync([$this->vaultRole->id]);
    ensureCanonicalHrStaffProfile($this->vaultActor, $this->vaultSites[0]);
    $this->vaultProfile = $this->vaultActor->fresh()->hrEmployeeProfile;
    $this->vaultCredential = vaultFixture($this->vaultSites[0]);
});

function vaultFixture(Site $site, array $attributes = []): SiteCredential
{
    return SiteCredential::create(array_replace([
        'site_id' => $site->id, 'label' => 'Synthetic shared access', 'credential_type' => 'password',
        'encrypted_value' => app(SiteCredentialEncryptionService::class)->encrypt('synthetic-vault-value-!')['value'],
        'requires_reauth' => true, 'is_shareable' => false, 'house_staff_access' => false,
        'visibility' => 'site', 'lock_version' => 1,
    ], $attributes));
}

function vaultUrl(SiteCredential $credential, string $suffix = ''): string
{
    return "/sites/{$credential->site_id}/credentials/{$credential->id}".($suffix ? '/'.$suffix : '');
}

test('canonical Sites and IT projections share the same masked credential ID and action grants', function () {
    $this->actingAs($this->vaultActor)->get('/vendors')->assertOk()
        ->assertInertia(fn ($page) => $page->where('credentials.0.id', $this->vaultCredential->id)
            ->where('credentials.0.lock_version', 1)->where('credentials.0.can_copy', true)
            ->missing('credentials.0.encrypted_value')->missing('credentials.0.totp_secret_encrypted'))
        ->assertDontSee('synthetic-vault-value-!');
    $projection = app(\App\Services\Sites\Profile\SiteProfileAdminPresenter::class)->vendorsCredentials($this->vaultActor, $this->vaultSites[0]);
    expect($projection['credentials'][0]['id'])->toBe($this->vaultCredential->id)
        ->and($projection['credentials'][0]['lock_version'])->toBe(1)
        ->and($projection['credentials'][0]['can_reveal'])->toBeTrue();
});

test('metadata reveal copy manage and audit grants do not imply each other', function () {
    $this->vaultRole->permissions()->sync(Permission::whereIn('key', ['sites.viewAny', 'credentials.view', 'credentials.reveal'])->pluck('id'));
    $actor = $this->vaultActor->fresh();
    $this->actingAs($actor)->getJson(vaultUrl($this->vaultCredential, 'status'))->assertOk()
        ->assertJsonPath('can_reveal', true)->assertJsonPath('can_copy', false)
        ->assertJsonPath('can_manage', false)->assertJsonPath('can_audit', false);
    $this->actingAs($actor)->postJson(vaultUrl($this->vaultCredential, 'reveal'), ['password' => 'password'])->assertOk();
    $this->actingAs($actor)->postJson(vaultUrl($this->vaultCredential, 'copy'))->assertNotFound();
    $this->actingAs($actor)->getJson(vaultUrl($this->vaultCredential, 'audit'))->assertForbidden();
    $this->actingAs($actor)->deleteJson(vaultUrl($this->vaultCredential), ['lock_version' => 1, 'evidence' => 'Synthetic retire request'])->assertForbidden();
});

test('only explicit house staff opt-in grants assigned staff reveal and copy and assignment revocation denies it', function () {
    $this->vaultRole->permissions()->sync(Permission::where('key', 'sites.viewAny')->pluck('id'));
    $this->vaultCredential->update(['is_shareable' => true]);
    $actor = $this->vaultActor->fresh();
    expect(\App\Domain\It\ItModuleNavigation::registerCapabilities($actor))->toBe(['vendors' => false, 'contracts' => false, 'credentials' => false]);
    $this->actingAs($actor)->postJson(vaultUrl($this->vaultCredential, 'reveal'), ['password' => 'password'])->assertNotFound();
    $this->vaultCredential->update(['house_staff_access' => true]);
    expect(\App\Domain\It\ItModuleNavigation::registerCapabilities($actor))->toBe(['vendors' => false, 'contracts' => false, 'credentials' => true]);
    $this->actingAs($actor)->get('/vendors?tab=credentials')->assertOk()
        ->assertInertia(fn ($page) => $page->where('auth.can.credentials.view', true)->where('auth.can.vendors.view', false));
    $this->actingAs($actor)->getJson(vaultUrl($this->vaultCredential, 'status'))->assertOk()
        ->assertJsonPath('can_reveal', true)->assertJsonPath('can_copy', true)
        ->assertJsonPath('can_manage', false)->assertJsonPath('can_audit', false);
    $this->actingAs($actor)->postJson(vaultUrl($this->vaultCredential, 'reveal'))->assertUnprocessable();
    $this->actingAs($actor)->postJson(vaultUrl($this->vaultCredential, 'copy'), ['password' => 'password'])->assertOk();
    $this->vaultProfile->update(['end_date' => today()->subDay()]);
    expect(\App\Domain\It\ItModuleNavigation::registerCapabilities($actor->fresh()))->toBe(['vendors' => false, 'contracts' => false, 'credentials' => false]);
    $this->actingAs($actor->fresh())->postJson(vaultUrl($this->vaultCredential, 'reveal'))->assertNotFound();
});

test('explicit all approved Sites visibility permits shared reads but never out of scope management or no-site access', function () {
    $other = vaultFixture($this->vaultSites[1]);
    $this->actingAs($this->vaultActor)->getJson(vaultUrl($other, 'status'))->assertNotFound();
    $other->update(['visibility' => 'all_approved_sites']);
    $this->actingAs($this->vaultActor)->getJson(vaultUrl($other, 'status'))->assertOk()
        ->assertJsonPath('can_reveal', true)->assertJsonPath('can_manage', false);
    $this->actingAs($this->vaultActor)->postJson(vaultUrl($other, 'reveal'), ['password' => 'password'])->assertOk();
    $this->actingAs($this->vaultActor)->postJson("/sites/{$this->vaultSites[0]->id}/credentials/{$other->id}/reveal")->assertNotFound();
    $this->vaultProfile->update(['end_date' => today()->subDay()]);
    $this->actingAs($this->vaultActor->fresh())->getJson(vaultUrl($other, 'status'))->assertNotFound();
});

test('SSO-only personal authenticator uses Fortify encryption and consumes each code durably across sessions', function () {
    $secret = (new Google2FA)->generateSecretKey();
    // Match canonical Microsoft provisioning: an unknown random local password.
    $this->vaultActor->forceFill(['password' => bcrypt(str()->random(32)), 'two_factor_secret' => Fortify::currentEncrypter()->encrypt($secret), 'two_factor_confirmed_at' => now()])->save();
    $code = (new Google2FA)->getCurrentOtp($secret);
    $this->actingAs($this->vaultActor->fresh())->postJson(vaultUrl($this->vaultCredential, 'reveal'), ['verification_code' => $code])->assertOk();
    $this->flushSession();
    $this->actingAs($this->vaultActor->fresh())->postJson(vaultUrl($this->vaultCredential, 'reveal'), ['verification_code' => $code])
        ->assertUnprocessable()->assertDontSee('synthetic-vault-value-!');
    $this->vaultActor->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null])->save();
    $this->flushSession();
    $this->actingAs($this->vaultActor->fresh())->postJson(vaultUrl($this->vaultCredential, 'reveal'), ['verification_code' => '123456'])->assertUnprocessable();
});

test('disclosure fails closed when audit persistence fails and never leaks exception text or SQL parameters', function () {
    Event::listen('eloquent.creating: '.SiteCredentialAuditLog::class, function () {
        throw new RuntimeException('synthetic-vault-value-! private SQL binding');
    });
    try {
        $this->actingAs($this->vaultActor)->postJson(vaultUrl($this->vaultCredential, 'reveal'), ['password' => 'password'])
            ->assertStatus(500)->assertDontSee('synthetic-vault-value-!')->assertDontSee('private SQL binding');
        expect(SiteCredentialAuditLog::where('credential_id', $this->vaultCredential->id)->count())->toBe(0);
    } finally {
        Event::forget('eloquent.creating: '.SiteCredentialAuditLog::class);
    }
});

test('copy records intent before disclosure and only a matching receipt can report a clipboard outcome', function () {
    $response = $this->actingAs($this->vaultActor)->postJson(vaultUrl($this->vaultCredential, 'copy'), ['password' => 'password'])->assertOk();
    $intent = $response->json('intent_id');
    expect(SiteCredentialAuditLog::findOrFail($intent)->action)->toBe('copy_intent');
    $other = vaultFixture($this->vaultSites[0]);
    $this->actingAs($this->vaultActor)->postJson(vaultUrl($other, 'copy-result'), ['intent_id' => $intent, 'outcome' => 'succeeded'])->assertStatus(409);
    $this->actingAs($this->vaultActor)->postJson(vaultUrl($this->vaultCredential, 'copy-result'), ['intent_id' => $intent, 'outcome' => 'failed'])->assertOk();
    $this->actingAs($this->vaultActor)->postJson(vaultUrl($this->vaultCredential, 'copy-result'), ['intent_id' => $intent, 'outcome' => 'failed'])->assertOk();
    $this->actingAs($this->vaultActor)->postJson(vaultUrl($this->vaultCredential, 'copy-result'), ['intent_id' => $intent, 'outcome' => 'succeeded'])->assertStatus(409);
    expect(SiteCredentialAuditLog::where('credential_id', $this->vaultCredential->id)->where('action', 'copy_reported_failed')->count())->toBe(1)
        ->and(SiteCredentialAuditLog::where('credential_id', $this->vaultCredential->id)->where('action', 'copy_reported_succeeded')->exists())->toBeFalse();
});

test('credential edits retain encrypted recovery versions and reject stale edits without claiming external rotation', function () {
    $input = ['label' => 'Edited synthetic access', 'credential_type' => 'password', 'value' => 'synthetic-replacement-!', 'lock_version' => 1,
        'visibility' => 'site', 'house_staff_access' => false];
    $this->actingAs($this->vaultActor)->putJson(vaultUrl($this->vaultCredential), $input)->assertOk();
    $this->actingAs($this->vaultActor)->putJson(vaultUrl($this->vaultCredential), $input)->assertUnprocessable()->assertJsonValidationErrors('lock_version');
    $current = $this->vaultCredential->fresh();
    expect($current->lock_version)->toBe(2)->and($current->last_rotated_at)->toBeNull()
        ->and(app(SiteCredentialEncryptionService::class)->decrypt($current->encrypted_value))->toBe('synthetic-replacement-!');
    $history = SiteCredentialVersion::where('credential_id', $current->id)->orderBy('version')->get();
    expect($history)->toHaveCount(2);
    foreach ($history as $version) expect($version->encrypted_snapshot)->not->toContain('synthetic-');
    $this->actingAs($this->vaultActor)->postJson(vaultUrl($current, 'recover'), ['lock_version' => 2, 'version_id' => $history[0]->id,
        'evidence' => 'Synthetic rollback verified against retained backup', 'password' => 'password'])->assertOk();
    expect(app(SiteCredentialEncryptionService::class)->decrypt($current->fresh()->encrypted_value))->toBe('synthetic-vault-value-!')
        ->and($current->fresh()->house_staff_access)->toBeFalse()->and($current->fresh()->last_rotated_at)->toBeNull();
});

test('retirement retains evidence and history and blocks disclosure until separately restored', function () {
    $this->actingAs($this->vaultActor)->deleteJson(vaultUrl($this->vaultCredential), ['lock_version' => 1, 'evidence' => 'Synthetic service decommission evidence', 'password' => 'password'])->assertOk();
    expect($this->vaultCredential->fresh()->retired_at)->not->toBeNull();
    $this->actingAs($this->vaultActor)->postJson(vaultUrl($this->vaultCredential, 'reveal'), ['password' => 'password'])->assertStatus(409);
    $snapshot = json_decode(Crypt::decryptString(SiteCredentialVersion::where('credential_id', $this->vaultCredential->id)->where('version', 2)->firstOrFail()->encrypted_snapshot), true);
    expect($snapshot['_evidence'])->toBe('Synthetic service decommission evidence');
    $this->actingAs($this->vaultActor)->postJson(vaultUrl($this->vaultCredential, 'restore'), ['lock_version' => 2,
        'evidence' => 'Synthetic service restored after review', 'password' => 'password'])->assertOk();
    expect($this->vaultCredential->fresh()->retired_at)->toBeNull()->and($this->vaultCredential->fresh()->lock_version)->toBe(3);
});

test('date-only rotation and secret validation cannot claim success or flash input', function () {
    $this->actingAs($this->vaultActor)->postJson(vaultUrl($this->vaultCredential, 'rotate'), ['lock_version' => 1, 'changed_at' => now()->toIso8601String()])->assertUnprocessable();
    expect($this->vaultCredential->fresh()->last_rotated_at)->toBeNull();
    $this->actingAs($this->vaultActor)->post("/sites/{$this->vaultSites[0]->id}/credentials", ['value' => 'synthetic-rejected-value', 'totp_secret' => 'synthetic-rejected-totp'])
        ->assertUnprocessable()->assertDontSee('synthetic-rejected-value')->assertDontSee('synthetic-rejected-totp');
    expect(session()->getOldInput())->toBe([]);
});


test('copy outcome audit projections distinguish reported failure from authorization and respect archived Sites', function () {
    $intent = $this->actingAs($this->vaultActor)->postJson(vaultUrl($this->vaultCredential, 'copy'), ['password' => 'password'])->assertOk()->json('intent_id');
    $this->postJson(vaultUrl($this->vaultCredential, 'copy-result'), ['intent_id' => $intent, 'outcome' => 'failed'])->assertOk();
    $logs = collect($this->getJson('/vendors/audit')->assertOk()->json('logs'));
    expect($logs->firstWhere('action', 'copy_intent')['result'])->toBe('intent')
        ->and($logs->firstWhere('action', 'copy_reported_failed')['result'])->toBe('failed');
    $this->vaultSites[0]->update(['archived' => true, 'archived_at' => now()]);
    $this->actingAs($this->vaultActor->fresh())->getJson('/vendors/audit')->assertOk()->assertJsonCount(0, 'logs');
});

test('calendar adapters preserve current vault and vendor privacy and conceal token-only requests', function () {
    $this->vaultCredential->forceFill(['created_at' => now()->subDays(91)])->save();
    $vendor = \App\Models\SiteVendor::create(['site_id' => $this->vaultSites[0]->id, 'company_name' => 'Private calendar supplier',
        'service_type' => 'software', 'preferred_contact_method' => 'email', 'is_active' => true, 'insurance_expiry' => today()]);
    $credentialProvider = app(\App\Services\Sites\Calendar\Providers\CredentialReminderProvider::class);
    $vendorProvider = app(\App\Services\Sites\Calendar\Providers\VendorReminderProvider::class);
    $ids = [$this->vaultSites[0]->id]; $start = now()->subDays(2); $end = now()->addDays(2);
    expect($credentialProvider->obligations($ids, $start, $end))->toBe([])->and($vendorProvider->obligations($ids, $start, $end))->toBe([]);
    $this->actingAs($this->vaultActor);
    expect($credentialProvider->obligations($ids, $start, $end))->toHaveCount(1)->and($vendorProvider->obligations($ids, $start, $end))->toBe([]);
    $this->vaultRole->permissions()->attach(Permission::where('key', 'vendors.view')->firstOrFail());
    expect($vendorProvider->obligations($ids, $start, $end))->toHaveCount(1);
    $this->vaultActor->permissionOverrides()->attach(Permission::whereIn('key', ['vendors.view', 'credentials.view'])->pluck('id'), ['allowed' => false]);
    expect($credentialProvider->obligations($ids, $start, $end))->toBe([])->and($vendorProvider->obligations($ids, $start, $end))->toBe([]);
});

test('a lost create response can be retried without duplicating credentials and changed payloads are rejected', function () {
    $data = ['creation_key' => (string) str()->uuid(), 'label' => 'Retry-safe synthetic credential', 'credential_type' => 'password', 'value' => 'synthetic-create-retry'];
    $url = '/sites/'.$this->vaultSites[0]->id.'/credentials';
    $first = $this->actingAs($this->vaultActor)->postJson($url, $data)->assertCreated()->json('id');
    $this->actingAs($this->vaultActor)->postJson($url, $data)->assertCreated()->assertJsonPath('id', $first);
    $this->actingAs($this->vaultActor)->postJson($url, [...$data, 'value' => 'synthetic-changed-retry'])->assertStatus(409);
    expect(SiteCredential::where('label', $data['label'])->count())->toBe(1)
        ->and(SiteCredentialAuditLog::where('credential_id', $first)->where('action', 'create')->count())->toBe(1);
});

test('an explicit per-user denial overrides assigned house staff opt-in access', function () {
    $this->vaultCredential->update(['house_staff_access' => true]);
    $this->vaultRole->permissions()->sync(Permission::where('key', 'sites.viewAny')->pluck('id'));
    $this->vaultActor->permissionOverrides()->attach(Permission::where('key', 'credentials.copy')->firstOrFail(), ['allowed' => false]);
    $actor = $this->vaultActor->fresh();
    $this->actingAs($actor)->getJson(vaultUrl($this->vaultCredential, 'status'))->assertOk()->assertJsonPath('can_copy', false)->assertJsonPath('can_reveal', true);
    $this->actingAs($actor)->postJson(vaultUrl($this->vaultCredential, 'copy'), ['password' => 'password'])->assertNotFound();
});

test('managers can select recovery versions without audit disclosure and revoked management removes access', function () {
    app(\App\Services\Sites\SiteCredentialHistory::class)->retain($this->vaultCredential, $this->vaultActor, 'created');
    $this->vaultRole->permissions()->sync(Permission::whereIn('key', ['sites.viewAny', 'credentials.manage'])->pluck('id'));
    $actor = $this->vaultActor->fresh();
    $this->actingAs($actor)->getJson(vaultUrl($this->vaultCredential, 'versions'))->assertOk()->assertJsonCount(1, 'versions')
        ->assertJsonMissingPath('versions.0.encrypted_snapshot')->assertDontSee('synthetic-vault-value-!');
    $this->actingAs($actor)->getJson(vaultUrl($this->vaultCredential, 'audit'))->assertForbidden();
    $this->vaultRole->permissions()->sync([]);
    $this->actingAs($actor->fresh())->getJson(vaultUrl($this->vaultCredential, 'versions'))->assertForbidden();
});

test('storage key maintenance retains external evidence encrypted versions and TOTP without exposing values', function () {
    $this->vaultCredential->update(['last_rotated_at' => now()->subDays(20), 'last_rotated_by_user_id' => $this->vaultActor->id,
        'rotation_kind' => 'external_attestation', 'rotation_evidence' => 'Synthetic external confirmation',
        'totp_secret_encrypted' => Crypt::encryptString('JBSWY3DPEHPK3PXP')]);
    $before = $this->vaultCredential->fresh();
    expect(app(SiteCredentialEncryptionService::class)->rotateAllCredentials($this->vaultActor))->toBe(1);
    $after = $before->fresh();
    expect($after->lock_version)->toBe(2)->and($after->storage_key_maintained_at)->not->toBeNull()
        ->and($after->last_rotated_at->toIso8601String())->toBe($before->last_rotated_at->toIso8601String())
        ->and($after->rotation_kind)->toBe('external_attestation')->and($after->rotation_evidence)->toBe('Synthetic external confirmation')
        ->and($after->encrypted_value)->not->toBe($before->encrypted_value)
        ->and(Crypt::decryptString($after->totp_secret_encrypted))->toBe('JBSWY3DPEHPK3PXP')
        ->and(SiteCredentialVersion::where('credential_id', $after->id)->count())->toBe(2)
        ->and(SiteCredentialAuditLog::where('action', 'storage_key_maintenance')->count())->toBe(1);
    $foreign = vaultFixture($this->vaultSites[1]);
    expect(fn () => app(SiteCredentialEncryptionService::class)->rotateAllCredentials($this->vaultActor))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect($after->fresh()->lock_version)->toBe(2)->and($foreign->fresh()->lock_version)->toBe(1);
});

test('a disposable encrypted fixture backup restores the same canonical ID and remains compatible with previous application keys', function () {
    $this->vaultCredential->update(['totp_secret_encrypted' => Crypt::encryptString('JBSWY3DPEHPK3PXP')]);
    app(\App\Services\Sites\SiteCredentialHistory::class)->retain($this->vaultCredential->fresh(), $this->vaultActor, 'backup_fixture');
    $backup = Crypt::encryptString(json_encode([
        'credential' => $this->vaultCredential->fresh()->getAttributes(),
        'versions' => SiteCredentialVersion::where('credential_id', $this->vaultCredential->id)->get()->map->getAttributes()->all(),
    ], JSON_THROW_ON_ERROR));
    expect($backup)->not->toContain('synthetic-vault-value-!')->not->toContain('JBSWY3DPEHPK3PXP');
    // Only disposable fixture rows in the guarded test schema are removed.
    DB::table('site_credential_versions')->where('credential_id', $this->vaultCredential->id)->delete();
    DB::table('site_credentials')->where('id', $this->vaultCredential->id)->delete();
    $restored = json_decode(Crypt::decryptString($backup), true, 512, JSON_THROW_ON_ERROR);
    DB::table('site_credentials')->insert($restored['credential']);
    DB::table('site_credential_versions')->insert($restored['versions']);
    $credential = SiteCredential::findOrFail($this->vaultCredential->id);
    expect(app(SiteCredentialEncryptionService::class)->decrypt($credential->encrypted_value))->toBe('synthetic-vault-value-!')
        ->and(Crypt::decryptString($credential->totp_secret_encrypted))->toBe('JBSWY3DPEHPK3PXP');
    $currentKey = app('encrypter')->getKey();
    $new = new \Illuminate\Encryption\Encrypter(random_bytes(strlen($currentKey)), config('app.cipher'));
    expect(fn () => $new->decryptString($backup))->toThrow(\Illuminate\Contracts\Encryption\DecryptException::class);
    $new->previousKeys([$currentKey]);
    expect($new->decryptString($credential->encrypted_value))->toBe('synthetic-vault-value-!');
    $this->actingAs($this->vaultActor)->getJson(vaultUrl($credential, 'status'))->assertOk()
        ->assertJsonPath('id', $this->vaultCredential->id)->assertDontSee('synthetic-vault-value-!');
});
