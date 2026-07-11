<?php

use App\Domain\Hr\Models\HrCase;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;

/**
 * Seam S5 — H&S/client incidents ↔ HR cases. `HrCase.linked_incident_ids` is a
 * JSON array of `ClientIncident` ids (docs/hr-module-design.md:900). The seam's
 * integrity guarantee: the link is a *reference*, not a duplicate — creating an
 * HR case that links an incident must NOT copy or mutate the H&S/client-owned
 * `ClientIncident`. These tests prove that guarantee end-to-end through the
 * tenant-scoped picker, stored reference and read-only case-detail payload.
 */
beforeEach(function () {
    $this->seed(\Database\Seeders\RbacSeeder::class);

    $this->manager = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $hrRole = Role::query()->where('name', 'hr')->first();
    if ($hrRole) {
        $this->manager->roles()->syncWithoutDetaching([$hrRole->id]);
    }
    foreach (['hr.cases.manage', 'hr.cases.view'] as $key) {
        $perm = Permission::query()->where('key', $key)->first();
        if ($perm) {
            $this->manager->permissionOverrides()->syncWithoutDetaching([
                $perm->id => ['allowed' => true],
            ]);
        }
    }

    $this->subject = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
});

test('the case wizard offers only incidents from the HR organisation', function () {
    $sameOrganisation = ClientIncident::factory()->create([
        'title' => 'Same-organisation fall',
        'client_id' => Client::factory()->create(['organization_id' => 1])->id,
    ]);
    $foreign = ClientIncident::factory()->create([
        'title' => 'Foreign incident',
        'client_id' => Client::factory()->create(['organization_id' => 2])->id,
    ]);

    $response = $this->actingAs($this->manager)->get('/hr/cases');

    $response->assertOk();
    expect(collect($response->inertiaProps('incidents'))->pluck('id')->all())
        ->toContain($sameOrganisation->id)
        ->not->toContain($foreign->id);
});

test('S5 seam: an HR case references and renders a client incident without mutating the H&S-owned record', function () {
    $incident = ClientIncident::factory()->create([
        'title' => 'Fall during supported outing',
        'client_id' => Client::factory()->create(['organization_id' => 1])->id,
    ]);
    $originalUpdatedAt = $incident->updated_at;

    $this->actingAs($this->manager)->post('/hr/cases', [
        'user_id' => $this->subject->id,
        'case_type' => 'investigation',
        'severity' => 'medium',
        'title' => 'Investigation linked to incident',
        'linked_incident_ids' => [$incident->id],
    ])->assertSessionHas('success');

    $case = HrCase::query()->where('title', 'Investigation linked to incident')->first();
    expect($case)->not->toBeNull();

    // The cross-reference is stored on the HR-owned case as plain ids.
    expect($case->linked_incident_ids)->toContain($incident->id);

    $response = $this->actingAs($this->manager)->get("/hr/cases/{$case->id}");
    $response->assertOk();

    expect($response->inertiaProps('linkedIncidents.0.id'))->toBe($incident->id)
        ->and($response->inertiaProps('linkedIncidents.0.title'))->toBe('Fall during supported outing');

    // One owner per fact: the H&S/client-owned incident is untouched — HR
    // referenced it, did not copy or mutate it, and created no duplicate.
    expect(ClientIncident::query()->count())->toBe(1);
    expect($incident->fresh()->updated_at->equalTo($originalUpdatedAt))->toBeTrue();
});

test('a case cannot reference an incident owned by another organisation', function () {
    $foreign = ClientIncident::factory()->create([
        'client_id' => Client::factory()->create(['organization_id' => 2])->id,
    ]);

    $this->actingAs($this->manager)->post('/hr/cases', [
        'user_id' => $this->subject->id,
        'case_type' => 'investigation',
        'severity' => 'medium',
        'title' => 'Cross-organisation link attempt',
        'linked_incident_ids' => [$foreign->id],
    ])->assertSessionHasErrors(['linked_incident_ids.0']);

    expect(HrCase::query()->where('title', 'Cross-organisation link attempt')->exists())
        ->toBeFalse();
});
