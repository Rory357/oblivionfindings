<?php

use App\Domain\Hr\Models\HrComplianceRequirement;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Services\HrEligibilityRuleSettings;
use App\Models\AppSetting;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\StaffCredential;
use App\Models\StaffQualificationRequirement;
use App\Models\StaffTrainingRecord;
use App\Models\TrainingCourse;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

it('scopes qualification requirements and direct mutations to accessible Client Sites', function () {
    $accessibleSite = Site::factory()->create();
    $outsideSite = Site::factory()->create();
    $manager = qualificationSiteManager($accessibleSite);
    $visibleClient = Client::factory()->create(['site_id' => $accessibleSite->id]);
    $outsideClient = Client::factory()->create(['site_id' => $outsideSite->id]);
    $visibleRequirement = qualificationRequirementFor($visibleClient, 'Medication support');
    $outsideRequirement = qualificationRequirementFor($outsideClient, 'Clinical delegation');

    $this->actingAs($manager)
        ->get(route('operations.qualifications.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('operations/qualifications/Index')
            ->has('requirements.data', 1)
            ->where('requirements.data.0.id', $visibleRequirement->id));

    $this->actingAs($manager)
        ->put(route('operations.qualifications.update', $outsideRequirement), [
            'qualification_name' => 'Hidden change',
        ])
        ->assertNotFound();

    expect($outsideRequirement->fresh()->qualification_name)->toBe('Clinical delegation');
});

it('rejects creating a qualification requirement for an inaccessible Client Site', function () {
    $accessibleSite = Site::factory()->create();
    $outsideSite = Site::factory()->create();
    $manager = qualificationSiteManager($accessibleSite);
    $outsideClient = Client::factory()->create(['site_id' => $outsideSite->id]);

    $this->actingAs($manager)
        ->post(route('operations.qualifications.store'), [
            'client_id' => $outsideClient->id,
            'qualification_name' => 'Medication support',
            'qualification_type' => 'certification',
            'is_mandatory' => true,
        ])
        ->assertForbidden();

    expect(StaffQualificationRequirement::query()->where('client_id', $outsideClient->id)->exists())->toBeFalse();
});

it('keeps joined Client options and statistics within the requirement Site and service context', function () {
    $accessibleSite = Site::factory()->create();
    $outsideSite = Site::factory()->create();
    $manager = qualificationSiteManager($accessibleSite);
    $globalContext = ServiceContext::factory()->create(['site_id' => null]);
    $matchingContext = ServiceContext::factory()->create(['site_id' => $accessibleSite->id]);
    $outsideContext = ServiceContext::factory()->create(['site_id' => $outsideSite->id]);
    $client = Client::factory()->create(['site_id' => $accessibleSite->id, 'service_context_id' => $globalContext->id]);
    $outsideClient = Client::factory()->create(['site_id' => $outsideSite->id]);
    $plain = qualificationRequirementFor($client, 'Medication support');
    $matching = qualificationRequirementFor($client, 'Clinical delegation');
    $matching->update(['service_context_id' => $matchingContext->id]);
    $global = qualificationRequirementFor($client, 'First aid');
    $global->update(['service_context_id' => $globalContext->id]);
    $mismatch = qualificationRequirementFor($client, 'Foreign context');
    $mismatch->update(['service_context_id' => $outsideContext->id]);
    qualificationRequirementFor($outsideClient, 'Outside Site');
    $visibleIds = [$plain->id, $matching->id, $global->id];
    sort($visibleIds);
    $before = StaffQualificationRequirement::query()->orderBy('id')->get()->map->getRawOriginal()->all();

    $this->actingAs($manager)
        ->get(route('operations.qualifications.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('requirements.data', 3)
            ->where('requirements.data', fn ($rows) => collect($rows)->pluck('id')->sort()->values()->all() === $visibleIds)
            ->where('stats', ['total' => 3, 'mandatory' => 3, 'clients' => 1])
            ->has('clients', 1)
            ->where('clients.0.id', $client->id));

    $this->actingAs($manager)
        ->put(route('operations.qualifications.update', $mismatch), ['qualification_name' => 'Hidden change'])
        ->assertNotFound();
    $this->actingAs($manager)
        ->delete(route('operations.qualifications.destroy', $mismatch))
        ->assertNotFound();
    expect(StaffQualificationRequirement::query()->orderBy('id')->get()->map->getRawOriginal()->all())->toBe($before);
});

it('keeps a roster-only qualification reader out of each mutation route', function () {
    $site = Site::factory()->create();
    $reader = qualificationSiteManager($site, false);
    $client = Client::factory()->create(['site_id' => $site->id]);
    $row = qualificationRequirementFor($client, 'Existing requirement');
    $before = StaffQualificationRequirement::query()->orderBy('id')->get()->map->getRawOriginal()->all();

    $this->actingAs($reader)->post(route('operations.qualifications.store'), [
        'client_id' => $client->id, 'qualification_name' => 'Reader write',
    ])->assertForbidden();
    $this->put(route('operations.qualifications.update', $row), ['qualification_name' => 'Reader write'])->assertForbidden();
    $this->delete(route('operations.qualifications.destroy', $row))->assertForbidden();
    expect(StaffQualificationRequirement::query()->orderBy('id')->get()->map->getRawOriginal()->all())->toBe($before);
});

function qualificationSiteManager(Site $site, bool $canMutate = true): User
{
    $manager = User::factory()->create(['approved_at' => now()]);
    $keys = $canMutate ? ['rostering.viewAny', 'qualifications.create', 'qualifications.edit', 'qualifications.delete'] : ['rostering.viewAny'];
    $permissions = collect($keys)->map(fn ($key) => Permission::firstOrCreate(
        ['key' => $key],
        ['description' => $key, 'group' => 'Rostering', 'module' => 'operations'],
    )->id)->all();
    $role = Role::create([
        'name' => 'qualification-site-test-'.uniqid(),
        'label' => 'Qualification Site test',
        'level' => 10,
        'type' => 'custom',
    ]);
    $role->permissions()->sync($permissions);
    $manager->roles()->attach($role);
    HrEmployeeProfile::factory()->create([
        'user_id' => $manager->id,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'start_date' => today()->subYear(),
        'end_date' => null,
        'is_active' => true,
    ]);

    return $manager;
}

function qualificationRequirementFor(Client $client, string $name): StaffQualificationRequirement
{
    return StaffQualificationRequirement::query()->create([
        'client_id' => $client->id,
        'qualification_name' => $name,
        'qualification_type' => 'certification',
        'is_mandatory' => true,
    ]);
}

it('returns only scheduling qualification DTOs to a roster-only reader despite private recorded evidence', function () {
    $fixture = qualificationPrivacyFixture();
    extract($fixture);
    StaffCredential::create(['user_id' => $worker->id, 'type' => $canonical->code, 'issued_at' => '2026-01-01',
        'expires_at' => '2027-01-01', 'issuer' => 'PrivateIssuerSentinel', 'reference' => 'PrivateCredentialReferenceSentinel',
        'notes' => 'PrivateCredentialNotesSentinel']);
    qualificationPrivacyTraining($worker);
    $loaded = $worker->fresh()->load(['staffCredentials', 'staffTrainingRecords']);
    expect($loaded->staffCredentials)->toHaveCount(1);
    expect($loaded->staffTrainingRecords)->toHaveCount(1);
    expect($reader->canDo('hr.training.view'))->toBeFalse();
    expect($reader->canDo('hr.employees.viewAny'))->toBeFalse();
    expect($reader->canDo('shifts.manageAny'))->toBeFalse();
    $state = qualificationPrivacyState();
    $queue = qualificationPrivacyQueue();

    $response = $this->actingAs($reader)->get(route('operations.qualifications.check', $duty));
    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('operations/qualifications/CheckShift')
        ->where('shift', [
            'id' => $duty->id, 'starts_at' => $duty->starts_at->toJSON(), 'ends_at' => $duty->ends_at->toJSON(),
            'staff' => ['id' => $worker->id, 'name' => $worker->name],
            'client' => ['id' => $client->id, 'first_name' => $client->first_name, 'last_name' => $client->last_name],
        ])
        ->has('results', 1)
        ->where('results.0.requirement', ['id' => $requirement->id, 'qualification_name' => $requirement->qualification_name,
            'qualification_type' => $requirement->qualification_type, 'description' => $requirement->description])
        ->where('results.0.mapping', ['status' => 'configured', 'requirement_id' => $canonical->id,
            'label' => $canonical->name, 'code' => $canonical->code, 'check_type' => 'credential'])
        ->where('results.0.met', true)->where('results.0.status', 'met')->where('results.0.severity', 'info')
        ->where('results.0.reasons', [])->where('results.0.requires_acknowledgement', false)
        ->where('allMandatoryMet', true)->where('hasBlocks', false)->where('hasWarnings', false));
    $privateValues = ['PrivateCredentialReferenceSentinel', 'PrivateCredentialNotesSentinel', 'PrivateIssuerSentinel',
        'PrivateAssessmentNotesSentinel', 'PrivateCertificatePathSentinel', 'PrivateCertificateNumberSentinel',
        'PrivateExemptionReasonSentinel', 'PrivateTrainingNotesSentinel', 'PrivateWorkerPhoneSentinel',
        'private-worker-email@example.test', 'PrivateClientLifeStorySentinel', 'PrivateClientFundingSentinel',
        'PrivateDutyNotesSentinel', 'PrivateCatalogueDescriptionSentinel'];
    foreach ($privateValues as $privateValue) {
        $response->assertDontSee($privateValue);
    }
    $json = $this->withHeaders([
        'X-Inertia' => 'true',
        'X-Inertia-Version' => $response->viewData('page')['version'],
    ])->get(route('operations.qualifications.check', $duty));
    $json->assertOk()->assertJsonPath('component', 'operations/qualifications/CheckShift')
        ->assertJsonPath('props.shift.staff', ['id' => $worker->id, 'name' => $worker->name])
        ->assertJsonPath('props.shift.client', ['id' => $client->id, 'first_name' => $client->first_name, 'last_name' => $client->last_name])
        ->assertJsonMissingPath('props.shift.staff.staff_training_records')
        ->assertJsonMissingPath('props.shift.staff.staff_credentials')
        ->assertJsonMissingPath('props.results.0.requirement.hr_compliance_requirement');
    foreach ($privateValues as $privateValue) {
        $json->assertDontSee($privateValue);
    }
    expect(qualificationPrivacyState())->toBe($state);
    expect(qualificationPrivacyQueue())->toBe($queue);
});

it('preserves actual evidence verdicts and warning governance after DTO redaction', function (
    string $kind, bool $met, string $status, string $severity, bool $mandatory, bool $acknowledge, string $mode,
) {
    extract(qualificationPrivacyFixture());
    AppSetting::updateOrCreate(['key' => HrEligibilityRuleSettings::KEY], ['value' => ['version' => 1,
        'values' => ['unmapped_mandatory_qualification' => $mode, 'house_qualification_approach' => 'per_requirement']]]);
    if (str_starts_with($kind, 'unmapped')) {
        $requirement->update(['hr_compliance_requirement_id' => null]);
    }
    if ($kind === 'configured inactive') {
        $canonical->update(['is_active' => false]);
    }
    if (! $mandatory) {
        $requirement->update(['is_mandatory' => false]);
    }
    if ($kind === 'unassigned') {
        $duty->update(['user_id' => null]);
    } elseif ($kind === 'training met') {
        $record = qualificationPrivacyTraining($worker);
        $canonical->update(['check_type' => 'training_course', 'reference_id' => $record->training_course_id]);
    } elseif (in_array($kind, ['credential met', 'credential expired'], true)) {
        StaffCredential::create(['user_id' => $worker->id, 'type' => $canonical->code, 'issued_at' => '2026-01-01',
            'expires_at' => $kind === 'credential expired' ? '2026-10-10' : '2027-01-01', 'notes' => 'PrivateVerdictNotesSentinel']);
    }
    $state = qualificationPrivacyState();
    $queue = qualificationPrivacyQueue();
    $response = $this->actingAs($reader)->get(route('operations.qualifications.check', $duty));
    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('results', 1)->where('results.0.requirement.id', $requirement->id)
        ->where('results.0.met', $met)->where('results.0.status', $status)->where('results.0.severity', $severity)
        ->where('results.0.is_mandatory', $mandatory)->where('results.0.requires_acknowledgement', $acknowledge)
        ->where('allMandatoryMet', ! $mandatory || $met)->where('hasBlocks', $severity === 'block')
        ->where('hasWarnings', $severity === 'warning')->where('unmappedMandatoryMode', $mode)
        ->where('results.0.reasons', fn ($reasons) => $met ? count($reasons) === 0 : count($reasons) > 0));
    $response->assertDontSee('PrivateVerdictNotesSentinel');
    expect(qualificationPrivacyState())->toBe($state);
    expect(qualificationPrivacyQueue())->toBe($queue);
})->with([
    'credential passed' => ['credential met', true, 'met', 'info', true, false, 'warn'],
    'actual training passed' => ['training met', true, 'met', 'info', true, false, 'warn'],
    'mapped expired blocks' => ['credential expired', false, 'expired', 'block', true, false, 'warn'],
    'mapped missing blocks' => ['credential missing', false, 'not_started', 'block', true, false, 'warn'],
    'configured inactive is unavailable' => ['configured inactive', false, 'unavailable', 'block', true, false, 'warn'],
    'optional configured inactive warns' => ['configured inactive', false, 'unavailable', 'warning', false, false, 'warn'],
    'optional missing warns' => ['credential missing', false, 'not_started', 'warning', false, false, 'warn'],
    'unmapped warning needs manager acknowledgement' => ['unmapped warn', false, 'unmapped', 'warning', true, true, 'warn'],
    'unmapped blocking setting stays blocking' => ['unmapped block', false, 'unmapped', 'block', true, false, 'block'],
    'unassigned stays explicitly unchecked' => ['unassigned', false, 'unassigned', 'info', true, false, 'warn'],
]);

it('does not replace the rostering route grant with an HR evidence grant', function () {
    extract(qualificationPrivacyFixture(['hr.training.view', 'qualifications.viewAny']));
    $state = qualificationPrivacyState();
    $queue = qualificationPrivacyQueue();
    $this->actingAs($reader)->get(route('operations.qualifications.check', $duty))->assertForbidden();
    expect(qualificationPrivacyState())->toBe($state);
    expect(qualificationPrivacyQueue())->toBe($queue);
});

it('keeps foreign and mismatched canonical Client Sites outside the qualification check', function (bool $mismatch) {
    extract(qualificationPrivacyFixture(['rostering.viewAny', 'reports.viewAny']));
    $outside = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
    if ($mismatch) {
        $outsideClient = Client::factory()->create(['site_id' => $outside->id, 'first_name' => 'PrivateForeignClientSentinel']);
        DB::table('shifts')->where('id', $duty->id)->update(['client_id' => $outsideClient->id]);
    } else {
        $client->update(['site_id' => $outside->id]);
        $duty->site_id = $outside->id;
        $duty->save();
        $worker->hrEmployeeProfile->update(['primary_site_id' => $outside->id]);
    }
    $state = qualificationPrivacyState();
    $queue = qualificationPrivacyQueue();
    $response = $this->actingAs($reader)->get(route('operations.qualifications.check', $duty));
    $response->assertNotFound()->assertDontSee('PrivateForeignClientSentinel');
    expect(qualificationPrivacyState())->toBe($state);
    expect(qualificationPrivacyQueue())->toBe($queue);
})->with(['foreign Site' => [false], 'Client and Shift Site disagree' => [true]]);

it('keeps exact service context applicability without returning another context requirement', function () {
    extract(qualificationPrivacyFixture());
    $otherContext = ServiceContext::factory()->create(['site_id' => $site->id, 'is_active' => true]);
    $other = qualificationRequirementFor($client, 'PrivateOtherContextRequirementSentinel');
    $other->update(['service_context_id' => $otherContext->id]);
    $state = qualificationPrivacyState();
    $queue = qualificationPrivacyQueue();
    $response = $this->actingAs($reader)->get(route('operations.qualifications.check', $duty));
    $response->assertOk()->assertInertia(fn (Assert $page) => $page->has('results', 1)->where('results.0.requirement.id', $requirement->id));
    $response->assertDontSee('PrivateOtherContextRequirementSentinel');
    expect(qualificationPrivacyState())->toBe($state);
    expect(qualificationPrivacyQueue())->toBe($queue);
});

it('preserves the existing manageAny Site bypass while still redacting private worker evidence', function () {
    extract(qualificationPrivacyFixture(['rostering.viewAny', 'shifts.manageAny']));
    $outside = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
    $reader->hrEmployeeProfile->update(['primary_site_id' => $outside->id]);
    qualificationPrivacyTraining($worker);
    $state = qualificationPrivacyState();
    $queue = qualificationPrivacyQueue();
    $response = $this->actingAs($reader->fresh())->get(route('operations.qualifications.check', $duty));
    $response->assertOk()->assertInertia(fn (Assert $page) => $page->where('shift.staff', ['id' => $worker->id, 'name' => $worker->name]));
    $response->assertDontSee('PrivateTrainingNotesSentinel')->assertDontSee('PrivateExemptionReasonSentinel');
    expect(qualificationPrivacyState())->toBe($state);
    expect(qualificationPrivacyQueue())->toBe($queue);
});

function qualificationPrivacyFixture(array $keys = ['rostering.viewAny']): array
{
    Carbon::setTestNow(Carbon::parse('2026-10-10 00:00:00', 'UTC'));
    test()->beforeApplicationDestroyed(fn () => Carbon::setTestNow());
    config(['app.timezone' => 'UTC', 'app.worker_timezone' => 'Pacific/Auckland', 'hr.eligibility_rules' => HrEligibilityRuleSettings::DEFAULTS]);
    Queue::fake();
    $site = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
    $reader = qualificationSiteManager($site, false);
    $permissions = collect($keys)->map(fn ($key) => Permission::firstOrCreate(['key' => $key],
        ['description' => $key, 'group' => 'workforce', 'module' => 'Operations'])->id)->all();
    $reader->roles()->first()->permissions()->sync($permissions);
    $reader = $reader->fresh();
    $worker = User::factory()->create(['approved_at' => now(), 'role' => 'support_worker',
        'email' => 'private-worker-email@example.test', 'cellphone' => 'PrivateWorkerPhoneSentinel']);
    HrEmployeeProfile::factory()->create(['user_id' => $worker->id, 'primary_site_id' => $site->id, 'secondary_site_ids' => [],
        'is_active' => true, 'start_date' => '2025-01-01', 'end_date' => null]);
    $context = ServiceContext::factory()->create(['site_id' => $site->id, 'is_active' => true]);
    $client = Client::factory()->create(['site_id' => $site->id, 'service_context_id' => $context->id,
        'life_story' => 'PrivateClientLifeStorySentinel', 'funding_notes' => 'PrivateClientFundingSentinel']);
    $canonical = HrComplianceRequirement::factory()->create(['code' => 'PRIVACY_CREDENTIAL', 'name' => 'Recorded first aid qualification',
        'check_type' => 'credential', 'reference_id' => null, 'validity_months' => null, 'hard_stop' => false,
        'is_active' => true, 'description' => 'PrivateCatalogueDescriptionSentinel']);
    $requirement = qualificationRequirementFor($client, 'First aid qualification');
    $requirement->update(['service_context_id' => $context->id, 'hr_compliance_requirement_id' => $canonical->id,
        'description' => 'Plain-language scheduling guidance']);
    $duty = Shift::factory()->create(['site_id' => $site->id, 'client_id' => $client->id, 'service_context_id' => $context->id,
        'user_id' => $worker->id, 'created_by' => $reader->id, 'status' => 'scheduled', 'shift_type' => 'standard',
        'starts_at' => '2026-10-11 20:00:00', 'ends_at' => '2026-10-12 00:00:00', 'coverage_roles' => [],
        'required_licence_class' => null, 'required_licence_endorsements' => [], 'notes' => 'PrivateDutyNotesSentinel']);

    return compact('site', 'reader', 'worker', 'context', 'client', 'canonical', 'requirement', 'duty');
}

function qualificationPrivacyTraining(User $worker): StaffTrainingRecord
{
    $course = TrainingCourse::factory()->create();

    return StaffTrainingRecord::create(['user_id' => $worker->id, 'training_course_id' => $course->id, 'status' => 'completed',
        'completed_at' => '2026-01-01 00:00:00', 'expires_at' => '2027-01-01 00:00:00', 'assessment_score' => 87,
        'assessment_passed' => true, 'assessment_notes' => 'PrivateAssessmentNotesSentinel',
        'certificate_path' => 'PrivateCertificatePathSentinel', 'certificate_number' => 'PrivateCertificateNumberSentinel',
        'exemption_reason' => 'PrivateExemptionReasonSentinel', 'notes' => 'PrivateTrainingNotesSentinel']);
}

function qualificationPrivacyState(): array
{
    $tables = ['users', 'role_user', 'role_permission', 'hr_employee_profiles', 'clients', 'service_contexts', 'shifts',
        'shift_tasks', 'shift_eligibility_overrides', 'coverage_reservations', 'training_courses', 'staff_training_records',
        'staff_credentials', 'staff_qualification_requirements', 'hr_compliance_requirements', 'app_settings',
        'timeline_events', 'audit_logs', 'workforce_eligibility_rechecks', 'workforce_eligibility_observations'];

    return collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->get()
        ->map(fn ($row) => (array) $row)->sortBy(fn ($row) => json_encode($row, JSON_THROW_ON_ERROR))->values()->all()])->all();
}

function qualificationPrivacyQueue(): array
{
    return collect(Queue::pushedJobs())->map(fn ($entries) => array_map(static fn ($entry) => [
        'job' => serialize($entry['job']), 'queue' => $entry['queue'], 'data' => $entry['data'] ?? null,
    ], $entries))->all();
}
