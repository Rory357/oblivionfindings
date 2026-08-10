<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientAppointment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;

function grantClientCalendarMutationPermissions(User $user, array $permissionKeys): void
{
    $role = Role::query()->create([
        'name' => 'client_calendar_mutations_'.$user->id,
        'label' => 'Client Calendar Mutations',
        'level' => 50,
        'type' => 'custom',
    ]);

    foreach ($permissionKeys as $key) {
        Permission::query()->firstOrCreate(
            ['key' => $key],
            ['description' => $key, 'group' => 'test', 'module' => 'Test'],
        );
    }

    $role->permissions()->sync(
        Permission::query()->whereIn('key', $permissionKeys)->pluck('id')->all(),
    );
    $user->roles()->attach($role->id);
}

function scopeClientCalendarMutationStaffToSite(User $user, Site $site): void
{
    HrEmployeeProfile::factory()->create([
        'user_id' => $user->id,
        'position_role' => 'support_worker',
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'start_date' => now()->subMonth()->toDateString(),
        'end_date' => null,
        'is_active' => true,
    ]);
}

function clientCalendarMutationStaff(Site $site, array $permissionKeys): User
{
    $user = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    grantClientCalendarMutationPermissions($user, $permissionKeys);
    scopeClientCalendarMutationStaffToSite($user, $site);

    return $user;
}

function makeClientCalendarAppointment(Client $client, User $creator): ClientAppointment
{
    $startsAt = now()->addDay()->startOfHour();

    return ClientAppointment::query()->create([
        'client_id' => $client->id,
        'title' => 'Original appointment',
        'appointment_type' => 'gp_visit',
        'starts_at' => $startsAt,
        'ends_at' => $startsAt->copy()->addHour(),
        'status' => 'scheduled',
        'share_with_family' => true,
        'created_by' => $creator->id,
    ]);
}

it('requires calendar manage rather than calendar create to update or delete appointments', function () {
    $site = Site::factory()->create();
    $client = Client::factory()->create(['site_id' => $site->id]);
    $creator = clientCalendarMutationStaff($site, [
        'clients.viewAssigned',
        'calendar.create',
    ]);
    $client->supportWorkers()->attach($creator->id);
    $appointment = makeClientCalendarAppointment($client, $creator);

    $this->actingAs($creator)
        ->putJson("/clients/{$client->id}/calendar/appointments/{$appointment->id}", [
            'title' => 'Unauthorized update',
        ])
        ->assertForbidden();

    $this->actingAs($creator)
        ->deleteJson("/clients/{$client->id}/calendar/appointments/{$appointment->id}")
        ->assertForbidden();

    expect($appointment->fresh())
        ->not->toBeNull()
        ->title->toBe('Original appointment');
});

it('returns not found when an appointment is mutated through the wrong parent client', function () {
    $site = Site::factory()->create();
    $manager = clientCalendarMutationStaff($site, [
        'clients.viewAssigned',
        'calendar.manage',
    ]);
    $owningClient = Client::factory()->create(['site_id' => $site->id]);
    $wrongClient = Client::factory()->create(['site_id' => $site->id]);
    $owningClient->supportWorkers()->attach($manager->id);
    $wrongClient->supportWorkers()->attach($manager->id);
    $appointment = makeClientCalendarAppointment($owningClient, $manager);

    $this->actingAs($manager)
        ->putJson("/clients/{$wrongClient->id}/calendar/appointments/{$appointment->id}", [
            'title' => 'Wrong parent update',
        ])
        ->assertNotFound();

    $this->actingAs($manager)
        ->deleteJson("/clients/{$wrongClient->id}/calendar/appointments/{$appointment->id}")
        ->assertNotFound();

    expect($appointment->fresh())
        ->not->toBeNull()
        ->title->toBe('Original appointment');

    $this->actingAs($manager)
        ->putJson("/clients/{$owningClient->id}/calendar/appointments/{$appointment->id}", [
            'title' => 'Authorized update',
            'status' => 'completed',
        ])
        ->assertOk()
        ->assertJsonPath('appointment.title', 'Authorized update')
        ->assertJsonPath('appointment.status', 'completed');

    $this->actingAs($manager)
        ->deleteJson("/clients/{$owningClient->id}/calendar/appointments/{$appointment->id}")
        ->assertOk();

    expect($appointment->fresh())->toBeNull();
});

it('denies appointment updates and deletes across Sites', function () {
    $managerSite = Site::factory()->create();
    $otherSite = Site::factory()->create();
    $manager = clientCalendarMutationStaff($managerSite, [
        'clients.viewAssigned',
        'calendar.manage',
    ]);
    $foreignCreator = clientCalendarMutationStaff($otherSite, []);
    $foreignClient = Client::factory()->create(['site_id' => $otherSite->id]);
    $foreignClient->supportWorkers()->attach($manager->id);
    $appointment = makeClientCalendarAppointment($foreignClient, $foreignCreator);

    $this->actingAs($manager)
        ->putJson("/clients/{$foreignClient->id}/calendar/appointments/{$appointment->id}", [
            'title' => 'Cross-Site update',
        ])
        ->assertForbidden();

    $this->actingAs($manager)
        ->deleteJson("/clients/{$foreignClient->id}/calendar/appointments/{$appointment->id}")
        ->assertForbidden();

    expect($appointment->fresh())
        ->not->toBeNull()
        ->title->toBe('Original appointment');
});

it('rejects appointment updates whose merged effective range does not end after it starts', function (string $case) {
    $site = Site::factory()->create();
    $manager = clientCalendarMutationStaff($site, [
        'clients.viewAssigned',
        'calendar.manage',
    ]);
    $client = Client::factory()->create(['site_id' => $site->id]);
    $client->supportWorkers()->attach($manager->id);
    $appointment = makeClientCalendarAppointment($client, $manager);
    $originalStart = $appointment->starts_at->copy();
    $originalEnd = $appointment->ends_at->copy();

    $payload = match ($case) {
        'start_only' => [
            'starts_at' => $originalEnd->copy()->addMinute()->toIso8601String(),
        ],
        'end_only' => [
            'ends_at' => $originalStart->copy()->toIso8601String(),
        ],
        'full' => [
            'starts_at' => $originalStart->copy()->addHours(3)->toIso8601String(),
            'ends_at' => $originalStart->copy()->addHours(2)->toIso8601String(),
        ],
    };

    $this->actingAs($manager)
        ->putJson("/clients/{$client->id}/calendar/appointments/{$appointment->id}", $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('ends_at');

    $appointment->refresh();
    expect($appointment->starts_at->equalTo($originalStart))->toBeTrue()
        ->and($appointment->ends_at->equalTo($originalEnd))->toBeTrue();
})->with(['start_only', 'end_only', 'full']);
