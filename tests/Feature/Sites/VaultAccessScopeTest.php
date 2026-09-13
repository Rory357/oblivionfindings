<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteCredential;
use App\Models\SiteCredentialAuditLog;
use App\Models\SiteVendor;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\Crypt;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->vaultActor = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $this->vaultRole = Role::query()->create([
        'name' => 'vault-scope-'.str()->uuid(), 'label' => 'Restricted vault fixture',
        'level' => 10, 'type' => 'custom',
    ]);
    $this->vaultRole->permissions()->attach(Permission::query()->whereIn('key', [
        'vendors.view', 'credentials.view', 'credentials.reveal', 'credentials.audit', 'sites.viewAny',
    ])->pluck('id'));
    $this->vaultActor->roles()->sync([$this->vaultRole->id]);
    $this->vaultSites = Site::factory()->count(2)->create(['type' => 'house', 'is_active' => true]);
    $this->vaultCredentials = $this->vaultSites->map(function (Site $site): SiteCredential {
        SiteVendor::query()->create([
            'site_id' => $site->id, 'service_type' => 'electrician',
            'company_name' => 'Restricted supplier '.$site->id, 'is_active' => true,
        ]);
        $credential = SiteCredential::query()->create([
            'site_id' => $site->id, 'label' => 'Restricted credential '.$site->id,
            'credential_type' => 'pin', 'encrypted_value' => Crypt::encryptString('synthetic-vault-pin'),
            'requires_reauth' => false, 'is_shareable' => false,
        ]);
        SiteCredentialAuditLog::query()->create([
            'credential_id' => $credential->id, 'site_id' => $site->id,
            'credential_label' => $credential->label, 'credential_type' => 'pin',
            'user_id' => $this->vaultActor->id, 'action' => 'reveal',
            'created_at' => now(),
        ]);

        return $credential;
    });
});

test('vault metadata and audit feeds deny empty current Site scope', function (string $assignment) {
    if ($assignment !== 'missing profile') {
        $profile = HrEmployeeProfile::factory()->create([
            'user_id' => $this->vaultActor->id, 'primary_site_id' => $this->vaultSites[0]->id,
            'secondary_site_ids' => [], 'is_active' => true,
            'start_date' => today()->subMonth(), 'end_date' => null,
        ]);
        $this->actingAs($this->vaultActor->fresh())->get('/vendors')->assertOk()
            ->assertInertia(fn ($page) => $page->has('credentials', 1)->has('vendors', 1));
        $profile->update($assignment === 'ended profile'
            ? ['end_date' => today()->subDay()]
            : ['primary_site_id' => null, 'secondary_site_ids' => []]);
    }

    $actor = $this->vaultActor->fresh();
    expect($actor->canDo('credentials.view'))->toBeTrue()
        ->and($actor->canDo('credentials.reveal'))->toBeTrue()
        ->and($actor->canDo('sites.viewAll'))->toBeFalse();
    $this->actingAs($actor)->get('/vendors')->assertOk()
        ->assertInertia(fn ($page) => $page->has('vendors', 0)->has('credentials', 0)
            ->has('sites', 0)->has('serviceTypes', 0)->has('credentialTypes', 0));
    $this->actingAs($actor)->getJson('/vendors/audit')->assertOk()->assertExactJson(['logs' => []]);
    foreach ($this->vaultCredentials as $credential) {
        $this->actingAs($actor)->postJson("/sites/{$credential->site_id}/credentials/{$credential->id}/reveal")
            ->assertNotFound();
    }
})->with(['missing profile', 'ended profile', 'last assignment removed']);

test('vault feeds honor only the same explicit all-Sites exception as direct credential access', function () {
    $bypass = Permission::query()->where('key', 'sites.viewAll')->firstOrFail();
    $this->vaultRole->permissions()->attach($bypass);
    $actor = $this->vaultActor->fresh();
    $this->actingAs($actor)->get('/vendors')->assertOk()
        ->assertInertia(fn ($page) => $page->has('credentials', 2)->has('vendors', 2)->has('sites', 2)
            ->missing('credentials.0.encrypted_value')->missing('credentials.1.encrypted_value'));
    $this->actingAs($actor)->getJson('/vendors/audit')->assertOk()->assertJsonCount(2, 'logs');
    foreach ($this->vaultCredentials as $credential) {
        expect($actor->can('view', $this->vaultSites->firstWhere('id', $credential->site_id)))->toBeTrue();
        $this->actingAs($actor)->postJson("/sites/{$credential->site_id}/credentials/{$credential->id}/reveal")
            ->assertOk()->assertJsonStructure(['value']);
    }

    $this->vaultRole->permissions()->detach($bypass);
    $actor = $this->vaultActor->fresh();
    $this->actingAs($actor)->get('/vendors')->assertOk()
        ->assertInertia(fn ($page) => $page->has('credentials', 0)->has('vendors', 0));
    $this->actingAs($actor)->getJson('/vendors/audit')->assertOk()->assertExactJson(['logs' => []]);
    $credential = $this->vaultCredentials[0];
    $this->actingAs($actor)->postJson("/sites/{$credential->site_id}/credentials/{$credential->id}/reveal")
        ->assertNotFound();
});
