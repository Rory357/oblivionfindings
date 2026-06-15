<?php

use App\Domain\Hr\Models\HrPolicy;
use App\Domain\Hr\Models\HrPolicyVersion;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;

beforeEach(function () {
    $this->seed(RbacSeeder::class);

    // hr.policies.view/manage are granted to provider_manager via RbacSeeder.
    $this->manager = User::factory()->create([
        'organization_id' => 1,
        'role' => 'provider_manager',
        'approved_at' => now(),
    ]);
    $this->manager->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'provider_manager')->first()->id,
    ]);

    $this->worker = User::factory()->create([
        'organization_id' => 1,
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);

    $this->policy = HrPolicy::query()->create([
        'tenant_id' => 1,
        'title' => 'Code of Conduct',
        'slug' => 'code-of-conduct',
        'category' => 'conduct',
        'is_active' => true,
        'requires_attestation' => true,
        'created_by' => $this->manager->id,
        'updated_by' => $this->manager->id,
    ]);

    $this->version = HrPolicyVersion::query()->create([
        'policy_id' => $this->policy->id,
        'version_number' => 1,
        'content_summary' => "Summary line one.\nLine two with <script>alert(1)</script>.",
        'document_path' => 'policies/1/code.pdf',
        'effective_from' => now()->subWeek()->toDateString(),
        'is_current' => true,
        'published_by' => $this->manager->id,
    ]);
});

test('the policy show page ships the current version content_summary', function () {
    // Regression: show.tsx read currentVersion.content (a phantom field) so the
    // summary always rendered empty. The real field is content_summary.
    $response = $this->actingAs($this->manager)->get("/hr/documents/policies/{$this->policy->id}");
    $response->assertOk();

    expect($response->inertiaProps('policy.current_version.content_summary'))
        ->toBe($this->version->content_summary);
});

test('a user without hr.policies.view cannot open a policy', function () {
    $this->actingAs($this->worker)
        ->get("/hr/documents/policies/{$this->policy->id}")
        ->assertForbidden();
});

test('the policy show page does not render content via an XSS sink', function () {
    // The summary is plain text — it must be rendered as text, never piped through
    // dangerouslySetInnerHTML (which would execute an injected <script>).
    $source = file_get_contents(resource_path('js/pages/hr/documents/policies/show.tsx'));
    expect($source)->not->toContain('dangerouslySetInnerHTML');
});
