<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\FundingClaim;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceAgreement;
use App\Models\Site;
use App\Models\User;

function grantServiceAgreementBindingPermissions(User $user, array $permissionKeys): void
{
    $role = Role::query()->firstOrCreate(
        ['name' => 'service_agreement_binding_'.$user->id],
        ['label' => 'Service Agreement Binding', 'level' => 50, 'type' => 'custom'],
    );

    foreach ($permissionKeys as $key) {
        Permission::query()->firstOrCreate(
            ['key' => $key],
            ['description' => $key, 'group' => 'test', 'module' => 'Test'],
        );
    }

    $role->permissions()->sync(
        Permission::query()->whereIn('key', $permissionKeys)->pluck('id')->all(),
    );
    $user->roles()->syncWithoutDetaching([$role->id]);
}

function serviceAgreementActorForSite(Site $site, array $permissionKeys): User
{
    $actor = User::factory()->create(['approved_at' => now()]);
    grantServiceAgreementBindingPermissions($actor, $permissionKeys);

    HrEmployeeProfile::query()->create([
        'user_id' => $actor->id,
        'employee_number' => 'EMP-SA-'.$actor->id,
        'work_email' => $actor->email,
        'position_title' => 'Service Agreement Manager',
        'position_role' => 'manager',
        'employment_type' => 'full_time',
        'start_date' => now()->subMonth()->toDateString(),
        'is_active' => true,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
    ]);

    return $actor;
}

it('rejects an unassigned Site client when creating a service agreement', function () {
    $assignedSite = Site::factory()->create();
    $otherSite = Site::factory()->create();
    $actor = serviceAgreementActorForSite($assignedSite, ['service_agreements.create']);
    $otherClient = Client::factory()->create(['site_id' => $otherSite->id]);

    $this->actingAs($actor)
        ->post('/operations/service-agreements', [
            'client_id' => $otherClient->id,
            'title' => 'Other Site agreement',
            'agreement_type' => 'individualised_funding',
        ])
        ->assertForbidden();

    expect(ServiceAgreement::query()->where('title', 'Other Site agreement')->exists())
        ->toBeFalse();
});

it('rejects moving an accessible service agreement to another Site client', function () {
    $assignedSite = Site::factory()->create();
    $otherSite = Site::factory()->create();
    $actor = serviceAgreementActorForSite($assignedSite, ['service_agreements.update']);
    $assignedClient = Client::factory()->create(['site_id' => $assignedSite->id]);
    $otherClient = Client::factory()->create(['site_id' => $otherSite->id]);
    $agreement = ServiceAgreement::factory()->create([
        'client_id' => $assignedClient->id,
    ]);

    $this->actingAs($actor)
        ->put("/operations/service-agreements/{$agreement->id}", [
            'client_id' => $otherClient->id,
        ])
        ->assertForbidden();

    expect($agreement->fresh()->client_id)->toBe($assignedClient->id);
});

it('fails closed when a service agreement client has no canonical Site', function () {
    $assignedSite = Site::factory()->create();
    $actor = serviceAgreementActorForSite($assignedSite, ['service_agreements.create']);
    $clientWithoutSite = Client::factory()->create(['site_id' => null]);

    $this->actingAs($actor)
        ->post('/operations/service-agreements', [
            'client_id' => $clientWithoutSite->id,
            'title' => 'Orphan agreement',
            'agreement_type' => 'individualised_funding',
        ])
        ->assertForbidden();

    expect(ServiceAgreement::query()->where('title', 'Orphan agreement')->exists())
        ->toBeFalse();
});

it('withholds funding related-record evidence and actions without their exact permissions', function () {
    $site = Site::factory()->create();
    $actor = serviceAgreementActorForSite($site, ['service_agreements.viewAny']);
    $client = Client::factory()->create(['site_id' => $site->id]);
    $agreement = ServiceAgreement::factory()->create(['client_id' => $client->id]);
    FundingClaim::query()->create([
        'service_agreement_id' => $agreement->id,
        'client_id' => $client->id,
        'claim_reference' => 'PRIVATE-CLAIM-COUNT',
        'status' => 'draft',
        'period_start' => today()->startOfMonth(),
        'period_end' => today()->endOfMonth(),
        'total_amount' => 125,
    ]);

    $this->actingAs($actor)
        ->get("/operations/service-agreements/{$agreement->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('related_record_permissions.view_funding_claims', false)
            ->where('related_record_permissions.create_funding_claims', false)
            ->where('related_record_permissions.view_invoices', false)
            ->missing('agreement.funding_claims_count')
            ->missing('agreement.funding_claims')
            ->missing('funding_claims_summary'));
});

it('projects only an aggregate funding count when the related-record permissions are held', function () {
    $site = Site::factory()->create();
    $actor = serviceAgreementActorForSite($site, [
        'service_agreements.viewAny',
        'funding.viewAny',
        'funding.claims.create',
        'finance.ar.view',
    ]);
    $client = Client::factory()->create(['site_id' => $site->id]);
    $agreement = ServiceAgreement::factory()->create(['client_id' => $client->id]);
    FundingClaim::query()->create([
        'service_agreement_id' => $agreement->id,
        'client_id' => $client->id,
        'claim_reference' => 'COUNTED-WITHOUT-DETAIL',
        'status' => 'draft',
        'period_start' => today()->startOfMonth(),
        'period_end' => today()->endOfMonth(),
        'total_amount' => 125,
    ]);

    $this->actingAs($actor)
        ->get("/operations/service-agreements/{$agreement->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('related_record_permissions.view_funding_claims', true)
            ->where('related_record_permissions.create_funding_claims', true)
            ->where('related_record_permissions.view_invoices', true)
            ->where('agreement.funding_claims_count', 1)
            ->missing('agreement.funding_claims')
            ->missing('funding_claims_summary'));
});
