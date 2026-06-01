<?php

use App\Models\CredentialType;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(\Database\Seeders\RbacSeeder::class);
    $this->site = Site::factory()->create(['type' => 'house', 'is_active' => true]);
    // The registry is tenant-scoped via the user's organization_id.
    $this->admin = User::factory()->create([
        'role' => 'admin',
        'approved_at' => now(),
        'organization_id' => $this->site->tenant_id,
    ]);
    $this->admin->roles()->sync([Role::query()->where('name', 'admin')->firstOrFail()->id]);
});

function ctCredential(Site $site, string $type): SiteCredential
{
    return SiteCredential::create([
        'site_id' => $site->id,
        'tenant_id' => $site->tenant_id,
        'label' => 'cred ' . $type,
        'credential_type' => $type,
        'encrypted_value' => Crypt::encryptString('x'),
        'requires_reauth' => false,
        'is_shareable' => false,
    ]);
}

test('index returns the merged default registry with usage counts', function () {
    ctCredential($this->site, 'pin');

    $res = $this->actingAs($this->admin)->getJson('/credential-types')->assertOk();
    $types = collect($res->json('types'));

    expect($types->pluck('key'))->toContain('password', 'pin', 'api_key', 'ssh_key', 'oauth', 'certificate', 'other');
    expect($types->firstWhere('key', 'password')['system'])->toBeTrue();
    expect($types->firstWhere('key', 'pin')['count'])->toBe(1);
    expect($types->firstWhere('key', 'api_key')['count'])->toBe(0);
    $res->assertJsonStructure([
        'types' => [['key', 'label', 'icon', 'description', 'active', 'sort_order', 'system', 'count']],
        'icons',
    ]);
});

test('bulk save persists overrides, custom types, and hides a type', function () {
    $this->actingAs($this->admin)
        ->putJson('/credential-types', ['types' => [
            ['key' => 'password', 'label' => 'Master Password', 'icon' => 'lock', 'description' => 'primary', 'active' => true],
            ['key' => 'pin', 'label' => 'PIN / Code', 'icon' => 'fingerprint', 'description' => null, 'active' => false],
            ['key' => 'database', 'label' => 'Database', 'icon' => 'database', 'description' => 'DB creds', 'active' => true],
        ]])
        ->assertOk()
        ->assertJson(['ok' => true]);

    $tenant = $this->admin->organization_id;
    $this->assertDatabaseHas('credential_types', ['tenant_id' => $tenant, 'key' => 'password', 'label' => 'Master Password']);
    $this->assertDatabaseHas('credential_types', ['tenant_id' => $tenant, 'key' => 'pin', 'active' => false]);
    $this->assertDatabaseHas('credential_types', ['tenant_id' => $tenant, 'key' => 'database', 'is_system' => false]);

    $picker = CredentialType::pickerOptionsForTenant($tenant)->pluck('key');
    expect($picker)->toContain('database');
    expect($picker)->not->toContain('pin'); // hidden
});

test('system types can never be hidden even when requested', function () {
    $this->actingAs($this->admin)
        ->putJson('/credential-types', ['types' => [
            ['key' => 'password', 'label' => 'Password', 'icon' => 'lock', 'active' => false],
            ['key' => 'other', 'label' => 'Other', 'icon' => 'shield', 'active' => false],
        ]])
        ->assertOk();

    $tenant = $this->admin->organization_id;
    expect((bool) CredentialType::query()->where('tenant_id', $tenant)->where('key', 'password')->value('active'))->toBeTrue();
    expect((bool) CredentialType::query()->where('tenant_id', $tenant)->where('key', 'other')->value('active'))->toBeTrue();
});

test('a custom type still in use is kept even if removed from the payload', function () {
    $tenant = $this->admin->organization_id;
    CredentialType::create(['tenant_id' => $tenant, 'key' => 'database', 'label' => 'Database', 'icon' => 'database', 'active' => true, 'sort_order' => 10, 'is_system' => false]);
    ctCredential($this->site, 'database');

    $this->actingAs($this->admin)
        ->putJson('/credential-types', ['types' => [
            ['key' => 'password', 'label' => 'Password', 'icon' => 'lock', 'active' => true],
        ]])
        ->assertOk();

    expect(CredentialType::query()->where('tenant_id', $tenant)->where('key', 'database')->exists())->toBeTrue();
});

test('an unused custom type removed from the payload is deleted', function () {
    $tenant = $this->admin->organization_id;
    CredentialType::create(['tenant_id' => $tenant, 'key' => 'legacy', 'label' => 'Legacy', 'icon' => 'shield', 'active' => true, 'sort_order' => 10, 'is_system' => false]);

    $this->actingAs($this->admin)
        ->putJson('/credential-types', ['types' => [
            ['key' => 'password', 'label' => 'Password', 'icon' => 'lock', 'active' => true],
        ]])
        ->assertOk();

    expect(CredentialType::query()->where('tenant_id', $tenant)->where('key', 'legacy')->exists())->toBeFalse();
});

test('the global page exposes active type options and the manage flag', function () {
    $this->actingAs($this->admin)
        ->get('/vendors')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('sites/vendors-credentials/global')
            ->where('can.manageCredentialTypes', true)
            ->has('credentialTypeOptions')
            ->where('credentialTypeOptions.0.key', 'password'));
});

test('credentials.manage is required for the registry endpoints', function () {
    $viewer = User::factory()->create(['role' => 'team_lead', 'approved_at' => now()]);
    $viewer->roles()->syncWithoutDetaching([Role::query()->where('name', 'team_lead')->firstOrFail()->id]);
    expect($viewer->canDo('credentials.manage'))->toBeFalse();

    $this->actingAs($viewer)->getJson('/credential-types')->assertForbidden();
    $this->actingAs($viewer)
        ->putJson('/credential-types', ['types' => [['key' => 'password', 'label' => 'x', 'icon' => 'lock']]])
        ->assertForbidden();
});
