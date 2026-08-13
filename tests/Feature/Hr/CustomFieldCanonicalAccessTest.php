<?php

use App\Domain\Hr\Models\HrCustomFieldDefinition;
use App\Domain\Hr\Models\HrCustomFieldValue;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);

    $this->allowedSite = Site::factory()->create(['name' => 'Custom Fields Allowed Site']);
    $this->hiddenSite = Site::factory()->create(['name' => 'Custom Fields Hidden Site']);
    $this->manager = customFieldStaff('Custom Fields Manager', $this->allowedSite, 'hr');
    $this->manager->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'hr')->firstOrFail()->id,
    ]);
    $this->manager->permissionOverrides()->syncWithoutDetaching([
        Permission::query()->where('key', 'hr.settings.manage')->firstOrFail()->id => ['allowed' => true],
        Permission::query()->where('key', 'hr.employees.viewAny')->firstOrFail()->id => ['allowed' => true],
        Permission::query()->where('key', 'hr.employees.viewAllSites')->firstOrFail()->id => ['allowed' => false],
        Permission::query()->where('key', 'hr.employees.manage')->firstOrFail()->id => ['allowed' => true],
    ]);
    $this->allowedStaff = customFieldStaff('Allowed Custom Fields Staff', $this->allowedSite);
    $this->hiddenStaff = customFieldStaff('Hidden Custom Fields Staff', $this->hiddenSite);
});

function customFieldStaff(string $name, Site $site, string $role = 'support_worker'): User
{
    $user = User::factory()->create([
        'name' => $name,
        'email' => str($name)->slug().'-'.str()->random(6).'@example.test',
        'role' => $role,
        'approved_at' => now(),
    ]);
    HrEmployeeProfile::query()->create([
        'user_id' => $user->id,
        'employee_number' => 'EMP-CUSTOM-'.$user->id,
        'work_email' => $user->email,
        'position_title' => $role === 'hr' ? 'HR Manager' : 'Support Worker',
        'position_role' => $role,
        'employment_type' => 'full_time',
        'start_date' => now()->subYear()->toDateString(),
        'is_active' => true,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
    ]);

    return $user;
}

function customFieldDefinition(array $overrides = []): HrCustomFieldDefinition
{
    return HrCustomFieldDefinition::query()->create([
        'name' => 'Emergency contact preference',
        'field_key' => 'emergency_contact_preference_'.str()->random(6),
        'field_type' => 'text',
        'is_required' => false,
        'sort_order' => 0,
        'is_active' => true,
        'created_by' => test()->manager->id,
        ...$overrides,
    ]);
}

test('custom field definitions are one application catalogue with a bounded public payload', function (): void {
    customFieldDefinition([
        'name' => 'Application first aid level',
        'field_key' => 'application_first_aid_level',
        'sort_order' => 10,
    ]);
    customFieldDefinition([
        'name' => 'Application uniform size',
        'field_key' => 'application_uniform_size',
        'sort_order' => 20,
    ]);

    $response = $this->actingAs($this->manager)
        ->get('/hr/settings/custom-fields')
        ->assertOk();

    $definitions = collect($response->inertiaProps('definitions'));
    $publicDefinitionKeys = [
        'id',
        'name',
        'field_key',
        'field_type',
        'options',
        'is_required',
        'sort_order',
        'is_active',
        'created_by',
        'created_at',
        'updated_at',
        'creator',
    ];

    expect($definitions->pluck('name')->all())
        ->toBe(['Application first aid level', 'Application uniform size'])
        ->and($definitions->every(
            fn (array $definition): bool => collect(array_keys($definition))->sort()->values()->all()
                === collect($publicDefinitionKeys)->sort()->values()->all(),
        ))->toBeTrue();
});

test('new custom field keys are unique across the application catalogue', function (): void {
    customFieldDefinition([
        'name' => 'Incident contact',
        'field_key' => 'incident_contact',
    ]);

    $this->actingAs($this->manager)
        ->post('/hr/settings/custom-fields', [
            'name' => 'Incident contact',
            'field_type' => 'text',
            'is_required' => false,
            'sort_order' => 0,
        ])
        ->assertRedirect(route('hr.settings.custom-fields'))
        ->assertSessionHasNoErrors();

    expect(HrCustomFieldDefinition::query()->orderBy('id')->pluck('field_key')->all())
        ->toBe(['incident_contact', 'incident_contact_1']);
});

test('employee custom fields use canonical Site visibility for reads and current staff for writes', function (): void {
    $definition = customFieldDefinition([
        'name' => 'Preferred shift note',
        'field_key' => 'preferred_shift_note',
    ]);
    HrCustomFieldValue::query()->create([
        'employee_profile_id' => $this->allowedStaff->hrEmployeeProfile->id,
        'field_definition_id' => $definition->id,
        'value' => 'Morning handover',
    ]);

    $allowedResponse = $this->actingAs($this->manager)
        ->get('/hr/people/'.$this->allowedStaff->hrEmployeeProfile->id.'/custom-fields')
        ->assertOk()
        ->assertJsonPath('0.definition.name', 'Preferred shift note')
        ->assertJsonPath('0.value', 'Morning handover');

    expect(array_keys($allowedResponse->json('0.definition')))->toEqualCanonicalizing([
        'id',
        'name',
        'field_key',
        'field_type',
        'options',
        'is_required',
        'sort_order',
        'is_active',
        'created_by',
        'created_at',
        'updated_at',
    ]);

    $this->actingAs($this->manager)
        ->get('/hr/people/'.$this->hiddenStaff->hrEmployeeProfile->id.'/custom-fields')
        ->assertNotFound();

    $this->actingAs($this->manager)
        ->put('/hr/people/'.$this->allowedStaff->hrEmployeeProfile->id.'/custom-fields', [
            'fields' => [[
                'definition_id' => $definition->id,
                'value' => 'Evening handover',
            ]],
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(HrCustomFieldValue::query()
        ->where('employee_profile_id', $this->allowedStaff->hrEmployeeProfile->id)
        ->where('field_definition_id', $definition->id)
        ->value('value'))->toBe('Evening handover');

    $this->actingAs($this->manager)
        ->put('/hr/people/'.$this->hiddenStaff->hrEmployeeProfile->id.'/custom-fields', [
            'fields' => [[
                'definition_id' => $definition->id,
                'value' => 'Hidden mutation',
            ]],
        ])
        ->assertNotFound();

    expect(HrCustomFieldValue::query()
        ->where('employee_profile_id', $this->hiddenStaff->hrEmployeeProfile->id)
        ->exists())->toBeFalse();
});

test('custom field writes reject inactive or duplicate definition identifiers atomically', function (): void {
    $active = customFieldDefinition([
        'name' => 'Active custom field',
        'field_key' => 'active_custom_field',
    ]);
    $inactive = customFieldDefinition([
        'name' => 'Inactive custom field',
        'field_key' => 'inactive_custom_field',
        'is_active' => false,
    ]);

    $this->actingAs($this->manager)
        ->put('/hr/people/'.$this->allowedStaff->hrEmployeeProfile->id.'/custom-fields', [
            'fields' => [
                ['definition_id' => $active->id, 'value' => 'first'],
                ['definition_id' => $active->id, 'value' => 'duplicate'],
                ['definition_id' => $inactive->id, 'value' => 'inactive'],
            ],
        ])
        ->assertInvalid(['fields.1.definition_id', 'fields.2.definition_id']);

    expect(HrCustomFieldValue::query()
        ->where('employee_profile_id', $this->allowedStaff->hrEmployeeProfile->id)
        ->exists())->toBeFalse();
});
