<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\CredentialType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteCredential;
use App\Models\SiteCredentialAuditLog;
use App\Models\SiteCredentialVersion;
use App\Models\SiteVendor;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);
    $this->site = Site::factory()->create(['name' => 'Vault Site', 'type' => 'house', 'is_active' => true]);
    $this->hiddenSite = Site::factory()->create(['name' => 'Hidden Vault Site', 'type' => 'house', 'is_active' => true]);
    $this->admin = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
    $this->admin->roles()->sync([Role::query()->where('name', 'admin')->firstOrFail()->id]);
});

function accessVaultCredential(Site $site, array $overrides = []): SiteCredential
{
    return SiteCredential::query()->create([
        'site_id' => $site->id,
        'label' => 'Canonical vault credential',
        'credential_type' => 'password',
        'encrypted_value' => Crypt::encryptString('keep-secret'),
        'requires_reauth' => false,
        'is_shareable' => false,
        ...$overrides,
    ])->fresh();
}

function accessVaultScopedManager(Site $site): User
{
    $manager = User::factory()->create([
        'role' => 'maintenance_coordinator',
        'approved_at' => now(),
    ]);
    $manager->roles()->sync([
        Role::query()->where('name', 'maintenance_coordinator')->firstOrFail()->id,
    ]);
    $manager->permissionOverrides()->syncWithoutDetaching([
        Permission::query()->where('key', 'credentials.manage')->firstOrFail()->id => ['allowed' => true],
    ]);
    HrEmployeeProfile::factory()->create([
        'user_id' => $manager->id,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'start_date' => today()->subYear(),
        'end_date' => null,
        'is_active' => true,
    ]);

    return $manager->fresh('hrEmployeeProfile');
}

test('Site-hidden credential and vendor direct objects are concealed before mutation', function (): void {
    $manager = accessVaultScopedManager($this->site);
    $hiddenCredential = accessVaultCredential($this->hiddenSite);
    $hiddenVendor = SiteVendor::query()->create([
        'site_id' => $this->hiddenSite->id,
        'service_type' => 'networking',
        'company_name' => 'Hidden Network Vendor',
        'preferred_contact_method' => 'email',
        'is_active' => true,
    ]);

    expect($manager->canDo('credentials.reveal'))->toBeTrue()
        ->and($manager->canDo('credentials.manage'))->toBeTrue()
        ->and($manager->canDo('vendors.manage'))->toBeTrue();

    $this->actingAs($manager)
        ->postJson("/sites/{$this->hiddenSite->id}/credentials/{$hiddenCredential->id}/reveal")
        ->assertNotFound();
    $this->actingAs($manager)
        ->put("/sites/{$this->hiddenSite->id}/credentials/{$hiddenCredential->id}", [
            'label' => 'Mutated hidden credential',
            'credential_type' => 'password',
        ])
        ->assertNotFound();
    $this->actingAs($manager)
        ->patch("/sites/{$this->hiddenSite->id}/vendors/{$hiddenVendor->id}/flags", [
            'is_active' => false,
        ])
        ->assertNotFound();

    expect($hiddenCredential->fresh()->label)->toBe('Canonical vault credential')
        ->and($hiddenVendor->fresh()->is_active)->toBeTrue()
        ->and(SiteCredentialAuditLog::query()->exists())->toBeFalse();
});

test('inactive catalogue types cannot be assigned but remain valid on their existing credential', function (): void {
    CredentialType::query()->create([
        'key' => 'legacy_router',
        'label' => 'Legacy router',
        'icon' => 'wifi',
        'active' => false,
        'sort_order' => 20,
        'is_system' => false,
    ]);
    $existing = accessVaultCredential($this->site, [
        'label' => 'Existing legacy router',
        'credential_type' => 'legacy_router',
    ]);

    $this->actingAs($this->admin)
        ->postJson("/sites/{$this->site->id}/credentials", [
            'label' => 'Rejected legacy router',
            'credential_type' => 'legacy_router',
            'value' => 'secret',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['credential_type']);

    $this->actingAs($this->admin)
        ->putJson("/sites/{$this->site->id}/credentials/{$existing->id}", [
            'label' => 'Updated legacy router',
            'credential_type' => 'legacy_router',
            'value' => '',
            'lock_version' => $existing->lock_version,
        ])
        ->assertOk();

    expect($existing->fresh()->label)->toBe('Updated legacy router')
        ->and(SiteCredential::query()->where('label', 'Rejected legacy router')->exists())->toBeFalse();
});

test('invalid authenticator secrets are rejected before a credential is stored', function (): void {
    $this->actingAs($this->admin)
        ->postJson("/sites/{$this->site->id}/credentials", [
            'label' => 'Invalid authenticator',
            'credential_type' => 'password',
            'value' => 'secret',
            'totp_secret' => 'not-a-base32-secret!',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['totp_secret']);

    expect(SiteCredential::query()->where('label', 'Invalid authenticator')->exists())->toBeFalse();
});

test('retiring a credential requires identity and evidence and retains Site and target audit provenance', function (): void {
    $credential = accessVaultCredential($this->site, ['label' => 'Retired router admin']);
    $version = $credential->lock_version;
    $ciphertext = $credential->encrypted_value;
    $evidence = 'Router administrator access retired after the replacement was verified.';

    $this->actingAs($this->admin)
        ->deleteJson("/sites/{$this->site->id}/credentials/{$credential->id}", [
            'password' => 'password',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['lock_version', 'evidence']);

    $this->actingAs($this->admin)
        ->deleteJson("/sites/{$this->site->id}/credentials/{$credential->id}", [
            'lock_version' => $version,
            'evidence' => $evidence,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['verification_code']);

    expect($credential->fresh()->retired_at)->toBeNull()
        ->and($credential->fresh()->lock_version)->toBe($version)
        ->and($credential->fresh()->encrypted_value)->toBe($ciphertext)
        ->and(SiteCredentialAuditLog::query()->where('action', 'retire')->exists())->toBeFalse();

    $this->actingAs($this->admin)
        ->deleteJson("/sites/{$this->site->id}/credentials/{$credential->id}", [
            'lock_version' => $version,
            'evidence' => $evidence,
            'password' => 'password',
        ])
        ->assertOk()
        ->assertJson(['ok' => true]);

    expect($credential->fresh()->retired_at)->not->toBeNull()
        ->and($credential->fresh()->lock_version)->toBe($version + 1)
        ->and($credential->fresh()->encrypted_value)->toBe($ciphertext)
        ->and(Crypt::decryptString($credential->fresh()->encrypted_value))->toBe('keep-secret');

    $audit = SiteCredentialAuditLog::query()->where('action', 'retire')->firstOrFail();
    expect($audit->credential_id)->toBe($credential->id)
        ->and($audit->site_id)->toBe($this->site->id)
        ->and($audit->user_id)->toBe($this->admin->id)
        ->and($audit->credential_label)->toBe('Retired router admin')
        ->and($audit->credential_type)->toBe('password');

    $retained = SiteCredentialVersion::query()
        ->where('credential_id', $credential->id)
        ->where('version', $version + 1)
        ->firstOrFail();
    $snapshot = json_decode(Crypt::decryptString($retained->encrypted_snapshot), true, flags: JSON_THROW_ON_ERROR);
    expect($retained->action)->toBe('retire')
        ->and($retained->user_id)->toBe($this->admin->id)
        ->and($snapshot['_evidence'])->toBe($evidence)
        ->and($snapshot['site_id'])->toBe($this->site->id);

    $feed = $this->actingAs($this->admin)
        ->getJson('/vendors/audit')
        ->assertOk();
    $retirement = collect($feed->json('logs'))->firstWhere('action', 'retire');
    expect($retirement)->not->toBeNull()
        ->and($retirement['target'])->toBe('Retired router admin')
        ->and($retirement['target_type'])->toBe('password')
        ->and($retirement['site_name'])->toBe('Vault Site');
});

test('credential security activity requires reveal permission', function (): void {
    $credential = accessVaultCredential($this->site);
    $viewer = User::factory()->create(['role' => 'team_lead', 'approved_at' => now()]);
    $viewer->roles()->sync([Role::query()->where('name', 'team_lead')->firstOrFail()->id]);

    expect($viewer->canDo('credentials.view'))->toBeTrue()
        ->and($viewer->canDo('credentials.reveal'))->toBeFalse();

    $this->actingAs($viewer)
        ->get("/sites/{$this->site->id}/credentials/{$credential->id}/audit")
        ->assertForbidden();
    $this->actingAs($viewer)
        ->getJson('/vendors/audit')
        ->assertForbidden();
});
