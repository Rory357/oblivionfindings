<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteCredential;
use App\Models\SiteCredentialAuditLog;
use App\Models\SiteVendor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(\Database\Seeders\RbacSeeder::class);

    $this->admin = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
    $this->admin->roles()->sync([Role::query()->where('name', 'admin')->firstOrFail()->id]);

    $this->site = Site::factory()->create(['type' => 'house', 'is_active' => true]);
});

function gvcVendor(Site $site, array $attrs = []): SiteVendor
{
    return SiteVendor::create(array_merge([
        'site_id' => $site->id,
        'tenant_id' => $site->tenant_id,
        'service_type' => 'electrician',
        'company_name' => 'Hamilton Electrical Ltd',
        'preferred_contact_method' => 'phone',
        'is_active' => true,
    ], $attrs));
}

function gvcCredential(Site $site, array $attrs = []): SiteCredential
{
    return SiteCredential::create(array_merge([
        'site_id' => $site->id,
        'tenant_id' => $site->tenant_id,
        'label' => 'Door Code',
        'credential_type' => 'pin',
        'encrypted_value' => Crypt::encryptString('1234'),
        'requires_reauth' => false,
        'is_shareable' => false,
    ], $attrs));
}

test('global index exposes enriched vendor/credential fields and manage flags', function () {
    gvcVendor($this->site, ['account_number' => 'ACC-9001', 'notes' => 'after-hours ok']);
    gvcCredential($this->site, [
        'label' => 'AWS Root',
        'credential_type' => 'password',
        'username' => 'root@example.test',
        'url' => 'https://console.aws.amazon.com',
        'is_shareable' => true,
        'password_strength' => 4,
        'totp_secret_encrypted' => Crypt::encryptString('JBSWY3DPEHPK3PXP'),
        'last_rotated_at' => now(),
    ]);

    $this->actingAs($this->admin)
        ->get('/vendors')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('sites/vendors-credentials/global')
            ->where('vendors.0.account_number', 'ACC-9001')
            ->where('vendors.0.notes', 'after-hours ok')
            ->where('credentials.0.username', 'root@example.test')
            ->where('credentials.0.url', 'https://console.aws.amazon.com')
            ->where('credentials.0.is_shareable', true)
            ->where('credentials.0.password_strength', 4)
            ->where('credentials.0.has_totp', true)
            ->where('can.vendorsManage', true)
            ->where('can.credentialsManage', true)
            ->where('can.credentialsReveal', true)
            ->missing('credentials.0.encrypted_value')
            ->missing('credentials.0.totp_secret_encrypted')
        );
});

test('vendor flags endpoint toggles preferred and active', function () {
    $vendor = gvcVendor($this->site, ['is_preferred' => false, 'is_active' => true]);

    $this->actingAs($this->admin)
        ->from('/vendors')
        ->patch("/sites/{$this->site->id}/vendors/{$vendor->id}/flags", ['is_preferred' => true])
        ->assertRedirect();
    expect($vendor->fresh()->is_preferred)->toBeTrue();

    $this->actingAs($this->admin)
        ->from('/vendors')
        ->patch("/sites/{$this->site->id}/vendors/{$vendor->id}/flags", ['is_active' => false])
        ->assertRedirect();
    expect($vendor->fresh()->is_active)->toBeFalse();
});

test('credential rotate stamps last_rotated_at and audits a rotate row', function () {
    $credential = gvcCredential($this->site, ['last_rotated_at' => now()->subDays(300)]);

    $this->actingAs($this->admin)
        ->from('/vendors')
        ->post("/sites/{$this->site->id}/credentials/{$credential->id}/rotate")
        ->assertRedirect();

    expect($credential->fresh()->last_rotated_at->diffInMinutes(now()))->toBeLessThan(2);
    expect(SiteCredentialAuditLog::query()
        ->where('credential_id', $credential->id)
        ->where('action', 'rotate')
        ->exists())->toBeTrue();
});

test('credential reauth endpoint toggles the flag and audits an edit row', function () {
    $credential = gvcCredential($this->site, ['requires_reauth' => false]);

    $this->actingAs($this->admin)
        ->from('/vendors')
        ->patch("/sites/{$this->site->id}/credentials/{$credential->id}/reauth", ['requires_reauth' => true])
        ->assertRedirect();

    expect($credential->fresh()->requires_reauth)->toBeTrue();
    expect(SiteCredentialAuditLog::query()
        ->where('credential_id', $credential->id)
        ->where('action', 'edit')
        ->exists())->toBeTrue();
});

test('global audit feed returns scoped JSON for credential viewers', function () {
    $credential = gvcCredential($this->site, ['label' => 'Server Room PIN']);
    SiteCredentialAuditLog::create([
        'credential_id' => $credential->id,
        'tenant_id' => $this->site->tenant_id,
        'user_id' => $this->admin->id,
        'action' => 'reveal',
        'ip_address' => '127.0.0.1',
        'user_agent' => 'phpunit',
        'created_at' => now(),
    ]);

    $this->actingAs($this->admin)
        ->getJson('/vendors/audit')
        ->assertOk()
        ->assertJsonPath('logs.0.action', 'reveal')
        ->assertJsonPath('logs.0.target', 'Server Room PIN')
        ->assertJsonPath('logs.0.result', 'ok')
        ->assertJsonStructure(['logs' => [['id', 'at', 'action', 'actor' => ['name', 'initials'], 'target', 'site_name', 'ip', 'result']]]);
});

test('vendor-only user is forbidden from the credential audit feed', function () {
    $vendorOnly = User::factory()->create(['role' => 'team_lead', 'approved_at' => now()]);
    $vendorOnly->roles()->syncWithoutDetaching([Role::query()->where('name', 'team_lead')->firstOrFail()->id]);
    $vendorOnly->permissionOverrides()->syncWithoutDetaching([
        Permission::query()->where('key', 'credentials.view')->firstOrFail()->id => ['allowed' => false],
    ]);

    expect($vendorOnly->canDo('credentials.view'))->toBeFalse();

    $this->actingAs($vendorOnly)
        ->getJson('/vendors/audit')
        ->assertForbidden();
});

test('manage-less user cannot toggle flags, rotate, or change reauth', function () {
    $vendor = gvcVendor($this->site);
    $credential = gvcCredential($this->site);

    $viewer = User::factory()->create(['role' => 'team_lead', 'approved_at' => now()]);
    $viewer->roles()->syncWithoutDetaching([Role::query()->where('name', 'team_lead')->firstOrFail()->id]);

    expect($viewer->canDo('vendors.manage'))->toBeFalse();
    expect($viewer->canDo('credentials.manage'))->toBeFalse();

    $this->actingAs($viewer)
        ->patch("/sites/{$this->site->id}/vendors/{$vendor->id}/flags", ['is_preferred' => true])
        ->assertForbidden();
    $this->actingAs($viewer)
        ->post("/sites/{$this->site->id}/credentials/{$credential->id}/rotate")
        ->assertForbidden();
    $this->actingAs($viewer)
        ->patch("/sites/{$this->site->id}/credentials/{$credential->id}/reauth", ['requires_reauth' => true])
        ->assertForbidden();
});
