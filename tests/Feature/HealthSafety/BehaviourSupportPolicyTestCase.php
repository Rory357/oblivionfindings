<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use App\Services\HealthSafety\BehaviourSupportApprovalPolicySettings as Policy;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

abstract class BehaviourSupportPolicyTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $manager;

    protected Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->travelTo(now()->setDate(2026, 10, 11)->utc());
        $this->site = Site::factory()->create();
        Permission::firstOrCreate(['key' => Policy::PERMISSION], ['description' => 'Manage the organisation behaviour support approval workflow', 'group' => 'Health & Safety', 'module' => 'healthSafety']);
        $this->manager = $this->staff([Policy::PERMISSION]);
    }

    protected function policy(): Policy
    {
        return app(Policy::class);
    }

    protected function staff(array $permissions = [], array $fields = []): User
    {
        $actor = User::factory()->create(array_merge(['role' => 'support_worker', 'approved_at' => now(), 'external_clinical_account' => false], $fields));
        HrEmployeeProfile::factory()->create(['user_id' => $actor->id, 'primary_site_id' => $this->site->id, 'secondary_site_ids' => [], 'start_date' => '2020-01-01', 'end_date' => null, 'is_active' => true]);
        foreach ($permissions as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['description' => $key, 'group' => 'Synthetic tests', 'module' => 'healthSafety']);
            $actor->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);
        }

        return $actor;
    }

    protected function payload(string $mode = Policy::DEFAULT_MODE, int $revision = 0, ?string $key = null): array
    {
        return ['mode' => $mode, 'expected_policy_revision' => $revision, 'idempotency_key' => $key ?? (string) Str::uuid(), 'reason' => 'Reviewed organisation workflow.'];
    }

    protected function validation(callable $operation, string $field): void
    {
        try {
            $operation();
            $this->fail('Expected a preserved draft validation result.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey($field, $error->errors());
        }
    }

    protected function denied(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected current internal settings authority denial.');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
    }
}
