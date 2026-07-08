<?php

use App\Domain\Hr\Models\HrCase;
use App\Models\ClientIncident;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;

/**
 * Seam S5 — H&S/client incidents ↔ HR cases. `HrCase.linked_incident_ids` is a
 * JSON array of `ClientIncident` ids (docs/hr-module-design.md:900). The seam's
 * integrity guarantee: the link is a *reference*, not a duplicate — creating an
 * HR case that links an incident must NOT copy or mutate the H&S/client-owned
 * `ClientIncident`. This test proves that guarantee.
 *
 * NB (dead-link 🟠 → Decision D-9): the cross-reference is currently INERT —
 * the case-create wizard never sets `linked_incident_ids` and nothing surfaces
 * it (no `linkedIncidents()` relation on HrCase, no load in `show()`, no render
 * in the pages), unlike RespiteDailyNote/ClinicalEvent which consume their
 * `linkedIncident`. So the write path works + is safe, but the feature is not
 * wired end-to-end. See D-9.
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

test('S5 seam: an HR case references a client incident by id and never mutates the H&S-owned incident', function () {
    $incident = ClientIncident::factory()->create();
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

    // One owner per fact: the H&S/client-owned incident is untouched — HR
    // referenced it, did not copy or mutate it, and created no duplicate.
    expect(ClientIncident::query()->count())->toBe(1);
    expect($incident->fresh()->updated_at->equalTo($originalUpdatedAt))->toBeTrue();
});
