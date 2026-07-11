<?php

use App\Domain\Hr\Models\HrDriverEligibility;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Rostering\RosterPublishValidator;
use App\Models\Client;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Services\Eligibility\Rules\RequiredDriverLicenceRule;
use Database\Seeders\RbacSeeder;

beforeEach(function () {
    $this->seed(RbacSeeder::class);

    $this->manager = User::factory()->create([
        'role' => 'admin',
        'organization_id' => 1,
        'approved_at' => now(),
    ]);
    $this->manager->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'admin')->firstOrFail()->id,
    ]);

    $this->worker = User::factory()->create([
        'role' => 'support_worker',
        'organization_id' => 1,
        'approved_at' => now(),
    ]);
    $this->worker->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'support_worker')->firstOrFail()->id,
    ]);

    $this->site = Site::factory()->create(['tenant_id' => 1]);
    $this->client = Client::factory()->create([
        'organization_id' => 1,
        'site_id' => $this->site->id,
    ]);
    $this->context = ServiceContext::factory()->create();

    HrEmployeeProfile::factory()->create([
        'tenant_id' => 1,
        'user_id' => $this->worker->id,
        'employee_number' => 'LICENCE-1',
        'is_active' => true,
        'primary_site_id' => $this->site->id,
        'created_by' => $this->manager->id,
        'updated_by' => $this->manager->id,
    ]);
});

function licenceRequirementShift(array $overrides = []): Shift
{
    return Shift::factory()->create([
        'organization_id' => 1,
        'client_id' => test()->client->id,
        'site_id' => test()->site->id,
        'service_context_id' => test()->context->id,
        'user_id' => test()->worker->id,
        'starts_at' => now()->addMonth()->setTime(9, 0),
        'ends_at' => now()->addMonth()->setTime(17, 0),
        'status' => 'scheduled',
        'coverage_roles' => [],
        'required_licence_class' => null,
        'required_licence_endorsements' => [],
        'created_by' => test()->manager->id,
        ...$overrides,
    ]);
}

function licenceRequirementDriver(array $overrides = []): HrDriverEligibility
{
    return HrDriverEligibility::query()->create([
        'tenant_id' => 1,
        'user_id' => test()->worker->id,
        'licence_number' => 'DL-REQUIREMENT',
        'licence_class' => '2',
        'licence_endorsements' => ['P', 'F'],
        'licence_expires_at' => now()->addYear()->toDateString(),
        'status' => 'eligible',
        'can_drive_clients' => true,
        'created_by' => test()->manager->id,
        ...$overrides,
    ]);
}

test('an ordinary shift with no licence requirement remains compatible', function () {
    $result = app(RequiredDriverLicenceRule::class)->evaluate(
        licenceRequirementShift(),
        $this->worker,
    );

    expect($result['passed'])->toBeTrue();
});

test('a current matching licence class and endorsements passes', function () {
    licenceRequirementDriver(['licence_class' => 'Class 2']);

    $result = app(RequiredDriverLicenceRule::class)->evaluate(
        licenceRequirementShift([
            'required_licence_class' => '2',
            'required_licence_endorsements' => ['P', 'F'],
        ]),
        $this->worker,
    );

    expect($result['passed'])->toBeTrue();
});

test('a missing required licence class blocks eligibility', function () {
    licenceRequirementDriver(['licence_class' => '1']);

    $result = app(RequiredDriverLicenceRule::class)->evaluate(
        licenceRequirementShift(['required_licence_class' => '2']),
        $this->worker,
    );

    expect($result['passed'])->toBeFalse()
        ->and($result['severity'])->toBe('block')
        ->and($result['message'])->toContain('Class 2');
});

test('a missing required endorsement blocks eligibility', function () {
    licenceRequirementDriver(['licence_endorsements' => ['P']]);

    $result = app(RequiredDriverLicenceRule::class)->evaluate(
        licenceRequirementShift(['required_licence_endorsements' => ['P', 'F']]),
        $this->worker,
    );

    expect($result['passed'])->toBeFalse()
        ->and($result['message'])->toContain('F endorsement');
});

test('a licence that expires before the shift blocks eligibility', function () {
    licenceRequirementDriver(['licence_expires_at' => now()->addWeek()->toDateString()]);

    $result = app(RequiredDriverLicenceRule::class)->evaluate(
        licenceRequirementShift(['required_licence_class' => '2']),
        $this->worker,
    );

    expect($result['passed'])->toBeFalse()
        ->and($result['message'])->toContain('expires before this shift');
});

test('driver data from another organisation never satisfies the requirement', function () {
    licenceRequirementDriver(['tenant_id' => 2]);

    $result = app(RequiredDriverLicenceRule::class)->evaluate(
        licenceRequirementShift(['required_licence_class' => '2']),
        $this->worker,
    );

    expect($result['passed'])->toBeFalse()
        ->and($result['message'])->toContain('this organisation');
});

test('the assignment endpoint hard-blocks a worker who misses the shift licence requirement', function () {
    licenceRequirementDriver(['licence_class' => '1']);
    $shift = licenceRequirementShift([
        'user_id' => null,
        'status' => 'draft',
        'required_licence_class' => '2',
    ]);

    $this->actingAs($this->manager)
        ->post(route('operations.shifts.assign', $shift), ['user_id' => $this->worker->id])
        ->assertSessionHasErrors('user_id');

    expect($shift->fresh()->user_id)->toBeNull();
});

test('the roster publish gate hard-blocks an assigned shift with a missing endorsement', function () {
    licenceRequirementDriver(['licence_endorsements' => ['P']]);
    $shift = licenceRequirementShift(['required_licence_endorsements' => ['F']]);

    $result = app(RosterPublishValidator::class)->validateProposedShifts(collect([$shift]));

    expect($result['can_publish'])->toBeFalse()
        ->and(collect($result['blocks'])->pluck('message')->implode(' '))->toContain('F endorsement');
});

test('the direct shift publish action cannot bypass a licence requirement block', function () {
    licenceRequirementDriver(['licence_class' => '1']);
    $shift = licenceRequirementShift([
        'status' => 'draft',
        'required_licence_class' => '2',
    ]);

    $this->actingAs($this->manager)
        ->patch(route('operations.shifts.publishShift', $shift))
        ->assertSessionHasErrors('user_id');

    expect($shift->fresh()->published_at)->toBeNull();
});
