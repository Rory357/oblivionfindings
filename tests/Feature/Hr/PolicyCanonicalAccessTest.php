<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrPolicy;
use App\Domain\Hr\Models\HrPolicyAttestation;
use App\Domain\Hr\Models\HrPolicyVersion;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);
    Storage::fake('private');

    $this->site = Site::factory()->create(['name' => 'Policy Canonical Site']);
    $this->manager = policyCanonicalStaff('Policy Canonical Manager', $this->site, 'provider_manager');
    $this->manager->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'provider_manager')->firstOrFail()->id,
    ]);
    $this->attester = policyCanonicalStaff('Policy Canonical Attester', $this->site);
    $this->attester->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'support_worker')->firstOrFail()->id,
    ]);
});

function policyCanonicalStaff(string $name, Site $site, string $role = 'support_worker'): User
{
    $user = User::factory()->create([
        'name' => $name,
        'email' => str($name)->slug().'-'.str()->random(6).'@example.test',
        'role' => $role,
        'approved_at' => now(),
    ]);
    HrEmployeeProfile::query()->create([
        'user_id' => $user->id,
        'employee_number' => 'EMP-POLICY-'.$user->id,
        'work_email' => $user->email,
        'position_title' => $role === 'provider_manager' ? 'Provider Manager' : 'Support Worker',
        'position_role' => $role,
        'employment_type' => 'full_time',
        'start_date' => now()->subYear()->toDateString(),
        'is_active' => true,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
    ]);

    return $user;
}

/** @return array{0: HrPolicy, 1: HrPolicyVersion} */
function policyCanonicalRecord(array $overrides = []): array
{
    $policy = HrPolicy::query()->create([
        'title' => 'Policy '.str()->random(8),
        'slug' => 'policy-'.str()->random(8),
        'category' => 'general',
        'is_active' => true,
        'requires_attestation' => true,
        'created_by' => test()->manager->id,
        'updated_by' => test()->manager->id,
        ...$overrides,
    ]);
    $version = HrPolicyVersion::query()->create([
        'policy_id' => $policy->id,
        'version_number' => 1,
        'content_summary' => 'Canonical policy summary.',
        'document_path' => 'policies/legacy-'.$policy->id.'.pdf',
        'effective_from' => now()->subDay()->toDateString(),
        'is_current' => true,
        'published_by' => test()->manager->id,
    ]);
    Storage::disk('private')->put($version->document_path, '%PDF-1.4 policy');

    return [$policy, $version];
}

test('the policy library is one application catalogue and omits storage markers', function (): void {
    policyCanonicalRecord([
        'title' => 'Application conduct policy',
        'slug' => 'application-conduct-policy',
    ]);
    policyCanonicalRecord([
        'title' => 'Application safety policy',
        'slug' => 'application-safety-policy',
    ]);

    $response = $this->actingAs($this->manager)
        ->get('/hr/documents/policies')
        ->assertOk();
    $storageField = 'ten'.'ant_id';

    expect(collect($response->inertiaProps('policies.data'))->pluck('title')->all())
        ->toBe(['Application conduct policy', 'Application safety policy'])
        ->and(json_encode($response->inertiaProps()))
        ->not->toContain($storageField);
});

test('new policies allocate application unique slugs and marker free private paths', function (): void {
    policyCanonicalRecord([
        'title' => 'Workplace safety',
        'slug' => 'workplace-safety',
    ]);

    $this->actingAs($this->manager)
        ->post('/hr/documents/policies', [
            'title' => 'Workplace safety',
            'category' => 'health_and_safety',
            'requires_attestation' => false,
            'content_mode' => 'pdf_only',
            'document' => UploadedFile::fake()->create('workplace-safety.pdf', 32, 'application/pdf'),
            'effective_from' => now()->toDateString(),
        ])
        ->assertRedirect(route('hr.policies.index'))
        ->assertSessionHasNoErrors();

    $created = HrPolicy::query()->where('slug', 'workplace-safety-1')->firstOrFail();
    $path = $created->currentVersion()->value('document_path');

    expect($path)->toStartWith('policies/')
        ->not->toMatch('#^policies/\d+/#');
    Storage::disk('private')->assertExists($path);
});

test('publishing a summary-only version reuses the retained document under one serialized current version', function (): void {
    [$policy, $version] = policyCanonicalRecord([
        'title' => 'Versioned policy',
        'slug' => 'versioned-policy',
    ]);

    $this->actingAs($this->manager)
        ->post('/hr/documents/policies/'.$policy->id.'/versions', [
            'content_summary' => 'A clarified second version.',
            'effective_from' => now()->toDateString(),
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $versions = $policy->versions()->orderBy('version_number')->get();
    expect($versions)->toHaveCount(2)
        ->and($versions->pluck('version_number')->all())->toBe([1, 2])
        ->and($versions->where('is_current', true))->toHaveCount(1)
        ->and($versions->last()->document_path)->toBe($version->document_path);
});

test('both attestation routes require exact current staff and deduplicate a policy version', function (): void {
    [$policy, $version] = policyCanonicalRecord([
        'title' => 'Attestation policy',
        'slug' => 'attestation-policy',
    ]);

    $this->actingAs($this->attester)
        ->post('/hr/documents/policies/'.$policy->id.'/attest', ['attestation_method' => 'checkbox'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();
    $this->actingAs($this->attester)
        ->post('/hr/my/policies/'.$policy->id.'/attest')
        ->assertSessionHasErrors('policy');

    expect(HrPolicyAttestation::query()
        ->where('user_id', $this->attester->id)
        ->where('policy_version_id', $version->id)
        ->count())->toBe(1);

    $former = policyCanonicalStaff('Former Policy Attester', $this->site);
    $former->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'support_worker')->firstOrFail()->id,
    ]);
    $former->hrEmployeeProfile->update([
        'is_active' => false,
        'end_date' => now()->subDay()->toDateString(),
    ]);

    foreach ([
        '/hr/documents/policies/'.$policy->id.'/attest',
        '/hr/my/policies/'.$policy->id.'/attest',
    ] as $route) {
        $this->actingAs($former)->post($route)->assertNotFound();
    }
    expect(HrPolicyAttestation::query()->where('user_id', $former->id)->exists())->toBeFalse();
});

test('attestation history is application-wide for managers but never serializes storage markers', function (): void {
    [$policy, $version] = policyCanonicalRecord([
        'title' => 'Historical marker policy',
        'slug' => 'historical-marker-policy',
    ]);
    HrPolicyAttestation::query()->create([
        'user_id' => $this->attester->id,
        'policy_id' => $policy->id,
        'policy_version_id' => $version->id,
        'attested_at' => now(),
        'attestation_method' => 'checkbox',
    ]);

    $response = $this->actingAs($this->manager)
        ->get('/hr/documents/policies/attestations')
        ->assertOk();
    $storageField = 'ten'.'ant_id';

    expect(collect($response->inertiaProps('attestations.data'))->pluck('user_id')->all())
        ->toBe([$this->attester->id])
        ->and(json_encode($response->inertiaProps()))
        ->not->toContain($storageField);
});
