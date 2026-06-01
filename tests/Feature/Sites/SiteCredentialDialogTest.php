<?php

use App\Models\Role;
use App\Models\Site;
use App\Models\SiteCredential;
use App\Models\SiteCredentialAuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

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
});

test('site show page exposes credentials array with safe fields', function () {
    SiteCredential::create([
        'site_id' => $this->site->id,
        'tenant_id' => $this->site->tenant_id,
        'label' => 'Door Code',
        'username' => 'reception',
        'url' => 'https://door.example.test',
        'credential_type' => 'pin',
        'encrypted_value' => \Illuminate\Support\Facades\Crypt::encryptString('1234'),
        'requires_reauth' => false,
        'is_shareable' => true,
        'password_strength' => 3,
        'last_rotated_at' => now(),
    ]);

    $this->actingAs($this->admin)
        ->get("/sites/{$this->site->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('sites/show')
            ->has('credentials', 1)
            ->where('credentials.0.label', 'Door Code')
            ->where('credentials.0.username', 'reception')
            ->where('credentials.0.url', 'https://door.example.test')
            ->where('credentials.0.credential_type', 'pin')
            ->where('credentials.0.is_shareable', true)
            ->where('credentials.0.password_strength', 3)
            ->where('credentials.0.has_totp', false)
            ->missing('credentials.0.encrypted_value')
            ->missing('credentials.0.totp_secret_encrypted')
            ->missing('credentials.0.iv')
        );
});

test('dedicated credentials page exposes the shared dialog safe fields', function () {
    $vendor = \App\Models\SiteVendor::create([
        'site_id' => $this->site->id,
        'tenant_id' => $this->site->tenant_id,
        'service_type' => 'electrician',
        'company_name' => 'Hamilton Electrical Ltd',
        'preferred_contact_method' => 'phone',
        'is_active' => true,
    ]);

    SiteCredential::create([
        'site_id' => $this->site->id,
        'tenant_id' => $this->site->tenant_id,
        'vendor_id' => $vendor->id,
        'label' => 'Browser TOTP Test',
        'username' => 'admin@example.test',
        'url' => 'https://admin.example.test',
        'credential_type' => 'password',
        'encrypted_value' => \Illuminate\Support\Facades\Crypt::encryptString('secret-value'),
        'requires_reauth' => true,
        'is_shareable' => true,
        'password_strength' => 4,
        'totp_secret_encrypted' => \Illuminate\Support\Facades\Crypt::encryptString('JBSWY3DPEHPK3PXP'),
        'last_rotated_at' => now(),
    ]);

    $this->actingAs($this->admin)
        ->get("/sites/{$this->site->id}/credentials")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('sites/credentials/index')
            ->has('credentials', 1)
            ->where('credentials.0.label', 'Browser TOTP Test')
            ->where('credentials.0.username', 'admin@example.test')
            ->where('credentials.0.url', 'https://admin.example.test')
            ->where('credentials.0.vendor_id', $vendor->id)
            ->where('credentials.0.vendor_name', 'Hamilton Electrical Ltd')
            ->where('credentials.0.requires_reauth', true)
            ->where('credentials.0.is_shareable', true)
            ->where('credentials.0.password_strength', 4)
            ->where('credentials.0.has_totp', true)
            ->where('credentials.0.value_preview', '********')
            ->missing('credentials.0.encrypted_value')
            ->missing('credentials.0.totp_secret_encrypted')
            ->missing('credentials.0.iv')
        );
});

test('dedicated vendors page exposes manage permission flag for shared dialogs', function () {
    \App\Models\SiteVendor::create([
        'site_id' => $this->site->id,
        'tenant_id' => $this->site->tenant_id,
        'service_type' => 'electrician',
        'company_name' => 'Hamilton Electrical Ltd',
        'preferred_contact_method' => 'phone',
        'is_active' => true,
    ]);

    $teamLead = User::factory()->create(['role' => 'team_lead', 'approved_at' => now()]);
    $teamLead->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'team_lead')->firstOrFail()->id,
    ]);

    expect($teamLead->canDo('vendors.view'))->toBeTrue();
    expect($teamLead->canDo('vendors.manage'))->toBeFalse();

    $this->actingAs($teamLead)
        ->get("/sites/{$this->site->id}/vendors")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('sites/vendors/index')
            ->has('vendors', 1)
            ->where('vendors.0.company_name', 'Hamilton Electrical Ltd')
            ->where('canManage', false)
        );
});

test('credential store accepts new fields, encrypts password, and writes a create audit row', function () {
    $this->actingAs($this->admin)
        ->post("/sites/{$this->site->id}/credentials", [
            'label' => 'AWS Root',
            'username' => 'root@example.test',
            'url' => 'https://console.aws.amazon.com',
            'credential_type' => 'password',
            'value' => 'Sup3rS3cretPw!',
            'notes' => 'rotate quarterly',
            'requires_reauth' => true,
            'is_shareable' => false,
            'password_strength' => 4,
        ])
        ->assertRedirect();

    $credential = SiteCredential::query()->where('site_id', $this->site->id)->firstOrFail();

    expect($credential->label)->toBe('AWS Root');
    expect($credential->username)->toBe('root@example.test');
    expect($credential->url)->toBe('https://console.aws.amazon.com');
    expect($credential->requires_reauth)->toBeTrue();
    expect($credential->is_shareable)->toBeFalse();
    expect($credential->password_strength)->toBe(4);
    expect($credential->encrypted_value)->not->toBe('Sup3rS3cretPw!');
    expect(\Illuminate\Support\Facades\Crypt::decryptString($credential->encrypted_value))
        ->toBe('Sup3rS3cretPw!');

    expect(
        SiteCredentialAuditLog::query()
            ->where('credential_id', $credential->id)
            ->where('action', 'create')
            ->exists(),
    )->toBeTrue();
});

test('credential store rejects unsafe url schemes', function () {
    $this->actingAs($this->admin)
        ->from("/sites/{$this->site->id}")
        ->post("/sites/{$this->site->id}/credentials", [
            'label' => 'Bad Link',
            'username' => 'root@example.test',
            'url' => 'javascript:alert(1)',
            'credential_type' => 'password',
            'value' => 'Sup3rS3cretPw!',
        ])
        ->assertRedirect("/sites/{$this->site->id}")
        ->assertSessionHasErrors(['url']);

    expect(SiteCredential::query()->where('label', 'Bad Link')->exists())->toBeFalse();
});

test('credential update rejects unsafe url schemes', function () {
    $credential = SiteCredential::create([
        'site_id' => $this->site->id,
        'tenant_id' => $this->site->tenant_id,
        'label' => 'old name',
        'credential_type' => 'password',
        'url' => 'https://safe.example.test',
        'encrypted_value' => \Illuminate\Support\Facades\Crypt::encryptString('keep-me'),
    ]);

    $this->actingAs($this->admin)
        ->from("/sites/{$this->site->id}")
        ->put("/sites/{$this->site->id}/credentials/{$credential->id}", [
            'label' => 'new name',
            'credential_type' => 'password',
            'url' => 'data:text/html,<script>alert(1)</script>',
            'value' => '',
        ])
        ->assertRedirect("/sites/{$this->site->id}")
        ->assertSessionHasErrors(['url']);

    expect($credential->fresh()->url)->toBe('https://safe.example.test');
});

test('credential update can change metadata without rotating password', function () {
    $credential = SiteCredential::create([
        'site_id' => $this->site->id,
        'tenant_id' => $this->site->tenant_id,
        'label' => 'old name',
        'credential_type' => 'password',
        'encrypted_value' => \Illuminate\Support\Facades\Crypt::encryptString('keep-me'),
        'requires_reauth' => false,
        'is_shareable' => false,
    ]);

    $this->actingAs($this->admin)
        ->put("/sites/{$this->site->id}/credentials/{$credential->id}", [
            'label' => 'new name',
            'credential_type' => 'password',
            'username' => 'user@example.test',
            'url' => 'https://new.example.test',
            'value' => '',
            'is_shareable' => true,
        ])
        ->assertRedirect();

    $credential->refresh();
    expect($credential->label)->toBe('new name');
    expect($credential->username)->toBe('user@example.test');
    expect($credential->is_shareable)->toBeTrue();
    expect(\Illuminate\Support\Facades\Crypt::decryptString($credential->encrypted_value))
        ->toBe('keep-me');

    expect(
        SiteCredentialAuditLog::query()
            ->where('credential_id', $credential->id)
            ->where('action', 'edit')
            ->exists(),
    )->toBeTrue();
});

test('site show for a vendor-only user: vendors populated, credentials empty', function () {
    \App\Models\SiteVendor::create([
        'site_id' => $this->site->id,
        'tenant_id' => $this->site->tenant_id,
        'service_type' => 'electrician',
        'company_name' => 'Sparks NZ',
        'preferred_contact_method' => 'phone',
        'is_active' => true,
    ]);
    SiteCredential::create([
        'site_id' => $this->site->id,
        'tenant_id' => $this->site->tenant_id,
        'label' => 'Should not be visible',
        'credential_type' => 'password',
        'encrypted_value' => \Illuminate\Support\Facades\Crypt::encryptString('x'),
    ]);

    // team_lead has sites.viewAny + sites.type.house.view + vendors.view +
    // credentials.view. Override-deny credentials.view to construct a
    // "vendor-only" tester that can still load the site show page.
    $vendorOnly = User::factory()->create(['role' => 'team_lead', 'approved_at' => now()]);
    $vendorOnly->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'team_lead')->firstOrFail()->id,
    ]);
    $vendorOnly->permissionOverrides()->syncWithoutDetaching([
        \App\Models\Permission::query()->where('key', 'credentials.view')->firstOrFail()->id => ['allowed' => false],
    ]);

    expect($vendorOnly->canDo('vendors.view'))->toBeTrue();
    expect($vendorOnly->canDo('credentials.view'))->toBeFalse();

    $this->actingAs($vendorOnly)
        ->get("/sites/{$this->site->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('sites/show')
            ->has('vendors', 1)
            ->where('vendors.0.company_name', 'Sparks NZ')
            ->has('credentials', 0)
            ->where('credentialCount', 0)
        );
});

test('site show for a credential-only user: credentials populated, vendors empty', function () {
    \App\Models\SiteVendor::create([
        'site_id' => $this->site->id,
        'tenant_id' => $this->site->tenant_id,
        'service_type' => 'electrician',
        'company_name' => 'Sparks NZ',
        'preferred_contact_method' => 'phone',
        'is_active' => true,
    ]);
    SiteCredential::create([
        'site_id' => $this->site->id,
        'tenant_id' => $this->site->tenant_id,
        'label' => 'Door Code',
        'credential_type' => 'pin',
        'encrypted_value' => \Illuminate\Support\Facades\Crypt::encryptString('1234'),
    ]);

    // team_lead minus vendors.view = credential-only tester.
    $credentialOnly = User::factory()->create(['role' => 'team_lead', 'approved_at' => now()]);
    $credentialOnly->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'team_lead')->firstOrFail()->id,
    ]);
    $credentialOnly->permissionOverrides()->syncWithoutDetaching([
        \App\Models\Permission::query()->where('key', 'vendors.view')->firstOrFail()->id => ['allowed' => false],
    ]);

    expect($credentialOnly->canDo('vendors.view'))->toBeFalse();
    expect($credentialOnly->canDo('credentials.view'))->toBeTrue();

    $this->actingAs($credentialOnly)
        ->get("/sites/{$this->site->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('sites/show')
            ->has('vendors', 0)
            ->has('credentials', 1)
            ->where('credentials.0.label', 'Door Code')
            ->where('credentialCount', 1)
        );
});

test('site show for a both-permission user (admin): both sides populated', function () {
    \App\Models\SiteVendor::create([
        'site_id' => $this->site->id,
        'tenant_id' => $this->site->tenant_id,
        'service_type' => 'electrician',
        'company_name' => 'Sparks NZ',
        'preferred_contact_method' => 'phone',
        'is_active' => true,
    ]);
    SiteCredential::create([
        'site_id' => $this->site->id,
        'tenant_id' => $this->site->tenant_id,
        'label' => 'Door Code',
        'credential_type' => 'pin',
        'encrypted_value' => \Illuminate\Support\Facades\Crypt::encryptString('1234'),
    ]);

    $this->actingAs($this->admin)
        ->get("/sites/{$this->site->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('sites/show')
            ->has('vendors', 1)
            ->has('credentials', 1)
            ->where('credentialCount', 1)
        );
});

test('credential destroy returns back(303) and audits delete (audit row survives via nullOnDelete)', function () {
    $credential = SiteCredential::create([
        'site_id' => $this->site->id,
        'tenant_id' => $this->site->tenant_id,
        'label' => 'to delete',
        'credential_type' => 'password',
        'encrypted_value' => \Illuminate\Support\Facades\Crypt::encryptString('x'),
    ]);

    $tenantId = $this->site->tenant_id;
    $deleteAuditsBefore = SiteCredentialAuditLog::query()
        ->where('tenant_id', $tenantId)
        ->where('action', 'delete')
        ->count();

    $this->actingAs($this->admin)
        ->from("/sites/{$this->site->id}")
        ->delete("/sites/{$this->site->id}/credentials/{$credential->id}")
        ->assertRedirect("/sites/{$this->site->id}");

    expect(SiteCredential::query()->find($credential->id))->toBeNull();

    // FK is nullOnDelete; the audit row survives with credential_id = null.
    $deleteAuditsAfter = SiteCredentialAuditLog::query()
        ->where('tenant_id', $tenantId)
        ->where('action', 'delete')
        ->count();
    expect($deleteAuditsAfter)->toBe($deleteAuditsBefore + 1);
});
