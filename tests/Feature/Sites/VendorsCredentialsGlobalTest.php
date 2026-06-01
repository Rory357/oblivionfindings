<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
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

test('vendor compliance fields persist and are exposed in global and site profile surfaces', function () {
    $this->actingAs($this->admin)
        ->from('/vendors')
        ->post("/sites/{$this->site->id}/vendors", [
            'service_type' => 'contractor',
            'company_name' => 'SafeWorks NZ',
            'contact_name' => 'Jordan Safe',
            'phone' => '+64 21 555 0101',
            'email' => 'jobs@safeworks.example',
            'account_number' => 'SW-100',
            'preferred_contact_method' => 'email',
            'is_preferred' => true,
            'hs_induction_completed' => true,
            'hs_induction_date' => '2026-05-20',
            'qualifications_verified' => true,
            'qualifications_notes' => 'Electrical practising licence sighted.',
            'insurance_verified' => true,
            'insurance_expiry' => '2026-12-31',
            'insurance_provider' => 'Southern Cover',
            'insurance_policy_number' => 'POL-9988',
            'site_specific_hs_plan' => 'Use lockout process before panel work.',
            'hs_performance_rating' => 'good',
            'hs_last_reviewed_at' => '2026-05-22',
            'notes' => 'Use after-hours entrance.',
        ])
        ->assertRedirect('/vendors');

    $vendor = SiteVendor::query()->where('company_name', 'SafeWorks NZ')->firstOrFail();
    expect($vendor->hs_induction_completed)->toBeTrue();
    expect($vendor->hs_induction_completed_by)->toBe($this->admin->id);
    expect($vendor->qualifications_verified)->toBeTrue();
    expect($vendor->insurance_verified)->toBeTrue();

    $this->actingAs($this->admin)
        ->get('/vendors')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('vendors.0.company_name', 'SafeWorks NZ')
            ->where('vendors.0.hs_induction_completed', true)
            ->where('vendors.0.hs_induction_date', '2026-05-20')
            ->where('vendors.0.qualifications_verified', true)
            ->where('vendors.0.qualifications_notes', 'Electrical practising licence sighted.')
            ->where('vendors.0.insurance_verified', true)
            ->where('vendors.0.insurance_expiry', '2026-12-31')
            ->where('vendors.0.insurance_provider', 'Southern Cover')
            ->where('vendors.0.insurance_policy_number', 'POL-9988')
            ->where('vendors.0.site_specific_hs_plan', 'Use lockout process before panel work.')
            ->where('vendors.0.hs_performance_rating', 'good')
            ->where('vendors.0.hs_last_reviewed_at', '2026-05-22')
        );

    $this->actingAs($this->admin)
        ->get("/sites/{$this->site->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('vendors.0.company_name', 'SafeWorks NZ')
            ->where('vendors.0.hs_induction_completed', true)
            ->where('vendors.0.insurance_expiry', '2026-12-31')
            ->where('vendors.0.hs_performance_rating', 'good')
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

test('global audit feed returns scoped JSON for credential revealers', function () {
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

test('credential metadata viewers without reveal rights are forbidden from the global audit feed', function () {
    $viewer = User::factory()->create(['role' => 'team_lead', 'approved_at' => now()]);
    $viewer->roles()->syncWithoutDetaching([Role::query()->where('name', 'team_lead')->firstOrFail()->id]);

    expect($viewer->canDo('credentials.view'))->toBeTrue();
    expect($viewer->canDo('credentials.reveal'))->toBeFalse();

    $this->actingAs($viewer)
        ->getJson('/vendors/audit')
        ->assertForbidden();
});

test('global feeds are scoped to the user\'s assigned sites (no horizontal access)', function () {
    $siteA = $this->site; // house
    $siteB = Site::factory()->create(['type' => 'house', 'is_active' => true]);

    gvcVendor($siteA, ['company_name' => 'Site A Plumbing']);
    gvcVendor($siteB, ['company_name' => 'Site B Plumbing']);
    $credA = gvcCredential($siteA, ['label' => 'Site A Door']);
    $credB = gvcCredential($siteB, ['label' => 'Site B Door']);
    foreach ([$credA, $credB] as $cred) {
        SiteCredentialAuditLog::create([
            'credential_id' => $cred->id,
            'tenant_id' => $cred->tenant_id,
            'user_id' => $this->admin->id,
            'action' => 'reveal',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'phpunit',
            'created_at' => now(),
        ]);
    }

    // A credential viewer assigned to site A only via their HR profile.
    $scoped = User::factory()->create(['role' => 'maintenance_coordinator', 'approved_at' => now()]);
    $scoped->roles()->syncWithoutDetaching([Role::query()->where('name', 'maintenance_coordinator')->firstOrFail()->id]);
    HrEmployeeProfile::create([
        'user_id' => $scoped->id,
        'tenant_id' => $siteA->tenant_id,
        'employee_number' => 'EMP-' . $scoped->id,
        'work_email' => 'scoped@example.test',
        'position_title' => 'Maintenance Coordinator',
        'position_role' => 'maintenance_coordinator',
        'employment_type' => 'full_time',
        'start_date' => now()->subYear()->toDateString(),
        'primary_site_id' => $siteA->id,
        'secondary_site_ids' => [],
    ]);

    expect($scoped->canDo('credentials.view'))->toBeTrue();

    $this->actingAs($scoped)
        ->get('/vendors')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('vendors', 1)
            ->where('vendors.0.company_name', 'Site A Plumbing')
            ->has('credentials', 1)
            ->where('credentials.0.label', 'Site A Door'));

    $logs = collect($this->actingAs($scoped)->getJson('/vendors/audit')->assertOk()->json('logs'));
    expect($logs->pluck('target'))->toContain('Site A Door');
    expect($logs->pluck('target'))->not->toContain('Site B Door');
});

test('credential update without a vendor_id key keeps the existing vendor link', function () {
    $vendor = gvcVendor($this->site);
    $credential = gvcCredential($this->site, ['credential_type' => 'password', 'vendor_id' => $vendor->id]);

    $this->actingAs($this->admin)
        ->from('/vendors')
        ->put("/sites/{$this->site->id}/credentials/{$credential->id}", [
            'label' => 'renamed',
            'credential_type' => 'password',
            'value' => '',
            // no vendor_id key sent — must not wipe the link
        ])
        ->assertRedirect();

    expect($credential->fresh()->vendor_id)->toBe($vendor->id);
});

test('credential update can set and clear the vendor link when vendor_id is sent', function () {
    $vendor = gvcVendor($this->site);
    $credential = gvcCredential($this->site, ['credential_type' => 'password']);

    $this->actingAs($this->admin)->from('/vendors')
        ->put("/sites/{$this->site->id}/credentials/{$credential->id}", [
            'label' => $credential->label, 'credential_type' => 'password', 'value' => '', 'vendor_id' => $vendor->id,
        ])->assertRedirect();
    expect($credential->fresh()->vendor_id)->toBe($vendor->id);

    $this->actingAs($this->admin)->from('/vendors')
        ->put("/sites/{$this->site->id}/credentials/{$credential->id}", [
            'label' => $credential->label, 'credential_type' => 'password', 'value' => '', 'vendor_id' => null,
        ])->assertRedirect();
    expect($credential->fresh()->vendor_id)->toBeNull();
});

test('a failed re-auth reveal is recorded as reauth_failed', function () {
    $credential = gvcCredential($this->site, ['credential_type' => 'password', 'requires_reauth' => true]);

    $this->actingAs($this->admin)
        ->postJson("/sites/{$this->site->id}/credentials/{$credential->id}/reveal", ['password' => 'definitely-wrong'])
        ->assertStatus(403);

    expect(SiteCredentialAuditLog::query()
        ->where('credential_id', $credential->id)
        ->where('action', 'reauth_failed')
        ->exists())->toBeTrue();
});

test('global audit feed excludes routine view_list rows', function () {
    $credential = gvcCredential($this->site);
    foreach (['view_list', 'reveal'] as $action) {
        SiteCredentialAuditLog::create([
            'credential_id' => $credential->id,
            'tenant_id' => $credential->tenant_id,
            'user_id' => $this->admin->id,
            'action' => $action,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'phpunit',
            'created_at' => now(),
        ]);
    }

    $logs = collect($this->actingAs($this->admin)->getJson('/vendors/audit')->assertOk()->json('logs'));
    expect($logs->pluck('action'))->not->toContain('view_list');
    expect($logs->pluck('action'))->toContain('reveal');
});

test('per-site credentials page exposes the site vendor list for the linked-vendor picker', function () {
    gvcVendor($this->site, ['company_name' => 'Hamilton Electrical Ltd']);

    $this->actingAs($this->admin)
        ->get("/sites/{$this->site->id}/credentials")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('sites/credentials/index')
            ->has('vendors', 1)
            ->where('vendors.0.company_name', 'Hamilton Electrical Ltd'));
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
