<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteCredential;
use App\Models\SiteCredentialAuditLog;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\Crypt;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->auditActor = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $this->auditRole = Role::query()->create([
        'name' => 'credential-auditor-'.str()->uuid(), 'label' => 'Credential auditor fixture', 'level' => 10, 'type' => 'custom',
    ]);
    $this->auditRole->permissions()->attach(Permission::query()->whereIn('key', ['credentials.view', 'credentials.audit', 'sites.viewAny'])->pluck('id'));
    $this->auditActor->roles()->sync([$this->auditRole->id]);
    $this->auditSites = Site::factory()->count(2)->create(['type' => 'house', 'is_active' => true]);
    $this->auditProfile = HrEmployeeProfile::factory()->create([
        'user_id' => $this->auditActor->id, 'primary_site_id' => $this->auditSites[0]->id,
        'secondary_site_ids' => [], 'is_active' => true, 'start_date' => today()->subMonth(), 'end_date' => null,
    ]);
    $this->auditCredentials = $this->auditSites->map(function (Site $site): SiteCredential {
        $credential = SiteCredential::query()->create([
            'site_id' => $site->id, 'label' => 'Audit-only credential '.$site->id, 'credential_type' => 'password',
            'encrypted_value' => Crypt::encryptString('synthetic-secret-auditor-must-never-see'),
            'totp_secret_encrypted' => Crypt::encryptString('JBSWY3DPEHPK3PXP'),
            'requires_reauth' => false, 'is_shareable' => false,
        ]);
        SiteCredentialAuditLog::query()->create([
            'credential_id' => $credential->id, 'site_id' => $site->id, 'credential_label' => $credential->label,
            'credential_type' => 'password', 'user_id' => $this->auditActor->id, 'action' => 'reveal', 'created_at' => now(),
        ]);

        return $credential;
    });
});

test('credential auditors read scoped activity without receiving reveal copy TOTP or management permissions', function () {
    $credential = $this->auditCredentials[0];
    $this->actingAs($this->auditActor)->get('/vendors')->assertOk()
        ->assertInertia(fn ($page) => $page->has('credentials', 1)->where('can.credentialsAudit', true)
            ->where('can.credentialsReveal', false)->where('can.credentialsManage', false)
            ->missing('credentials.0.encrypted_value')->missing('credentials.0.totp_secret_encrypted'))
        ->assertDontSee('synthetic-secret-auditor-must-never-see');
    $this->actingAs($this->auditActor)->getJson('/vendors/audit')->assertOk()->assertJsonCount(1, 'logs')
        ->assertJsonPath('logs.0.target', $credential->label)->assertDontSee('synthetic-secret-auditor-must-never-see');
    $this->actingAs($this->auditActor)->get("/sites/{$credential->site_id}/credentials/{$credential->id}/audit")
        ->assertOk()->assertInertia(fn ($page) => $page->component('sites/credentials/audit')
        ->where('credential.id', $credential->id)->has('logs.data', 1)->missing('credential.encrypted_value'))
        ->assertDontSee('synthetic-secret-auditor-must-never-see');
    foreach (['reveal', 'copy', 'totp/code', 'rotate'] as $action) {
        $this->actingAs($this->auditActor)->postJson("/sites/{$credential->site_id}/credentials/{$credential->id}/{$action}")
            ->assertForbidden();
    }
    expect(SiteCredentialAuditLog::query()->where('credential_id', $credential->id)->count())->toBe(1);
});

test('auditor direct-object and global feeds follow current Site assignment and hide unrelated credentials', function () {
    $allowed = $this->auditCredentials[0];
    $hidden = $this->auditCredentials[1];
    $this->actingAs($this->auditActor)->get("/sites/{$hidden->site_id}/credentials/{$hidden->id}/audit")->assertNotFound();
    $this->actingAs($this->auditActor)->get("/sites/{$allowed->site_id}/credentials/{$hidden->id}/audit")->assertNotFound();
    $this->auditProfile->update(['end_date' => today()->subDay()]);
    $actor = $this->auditActor->fresh();
    $this->actingAs($actor)->getJson('/vendors/audit')->assertOk()->assertExactJson(['logs' => []]);
    $this->actingAs($actor)->get("/sites/{$allowed->site_id}/credentials/{$allowed->id}/audit")->assertNotFound();
});

test('a reveal grant does not imply audit rights and revoked audit access returns 403', function () {
    $credential = $this->auditCredentials[0];
    $this->auditRole->permissions()->attach(Permission::query()->where('key', 'credentials.reveal')->firstOrFail());
    $this->auditActor->permissionOverrides()->attach(Permission::query()->where('key', 'credentials.audit')->firstOrFail(), ['allowed' => false]);
    $actor = $this->auditActor->fresh();
    $this->actingAs($actor)->get('/vendors')->assertOk()
        ->assertInertia(fn ($page) => $page->where('can.credentialsAudit', false)->where('can.credentialsReveal', true));
    $this->actingAs($actor)->getJson('/vendors/audit')->assertForbidden();
    $this->actingAs($actor)->get("/sites/{$credential->site_id}/credentials/{$credential->id}/audit")->assertForbidden();
    $this->actingAs($actor)->postJson("/sites/{$credential->site_id}/credentials/{$credential->id}/reveal")
        ->assertOk()->assertJsonPath('value', 'synthetic-secret-auditor-must-never-see');
});
