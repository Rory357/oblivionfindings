<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\Permission;
use App\Models\RestraintEvent;
use App\Models\Site;
use App\Models\User;
use App\Services\HealthSafety\RestraintService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

abstract class RestraintEventCommandTestCase extends TestCase
{
    use RefreshDatabase;

    protected Site $site;

    protected Client $client;

    protected User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 11)->utc());
        Queue::fake();
        $this->seed(RbacSeeder::class);
        $this->site = Site::factory()->create(['is_active' => true]);
        $this->client = Client::factory()->create(['site_id' => $this->site->id]);
        $this->actor = $this->staff(['restraints.view', 'restraints.create', 'restraints.review', 'restraints.manage', 'clients.viewAny', 'incidents.viewAny', 'incidents.update']);
    }

    protected function staff(array $permissions, ?Site $site = null): User
    {
        $user = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => ($site ?? $this->site)->id, 'secondary_site_ids' => [], 'is_active' => true, 'start_date' => now('Pacific/Auckland')->subYear()->toDateString(), 'end_date' => null]);
        foreach ($permissions as $key) {
            $permission = Permission::where('key', $key)->sole();
            $user->permissionOverrides()->attach($permission, ['allowed' => true]);
        }

        return $user;
    }

    protected function service(): RestraintService
    {
        return app(RestraintService::class);
    }

    protected function capture(array $overrides = []): array
    {
        return ['client_id' => $this->client->id, 'started_at' => now()->subHours(2)->toIso8601String(), 'ended_at' => now()->subHour()->toIso8601String(), 'restraint_type' => 'physical', 'severity' => 'medium', 'trigger_description' => 'Factual trigger', 'de_escalation_attempted' => 'Actual support attempted', 'restraint_description' => 'Sensitive factual source', 'within_support_plan' => true, 'injury_occurred' => false, 'idempotency_key' => (string) Str::uuid(), ...$overrides];
    }

    protected function event(array $overrides = []): RestraintEvent
    {
        $receipt = $this->service()->create($this->actor, $this->capture($overrides));

        return RestraintEvent::findOrFail($receipt['event_id']);
    }

    protected function command(RestraintEvent $event, array $values = []): array
    {
        return ['expected_version' => $event->fresh()->lock_version, 'idempotency_key' => (string) Str::uuid(), ...$values];
    }

    protected function invalid(callable $operation, string $field): void
    {
        try {
            $operation();
            $this->fail('This invalid command must not be accepted.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey($field, $error->errors());
        }
    }

    protected function denied(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Current source authority must deny this operation.');
        } catch (HttpException $error) {
            $this->assertContains($error->getStatusCode(), [403, 404]);
        }
    }

    protected function revoke(User $user, string $key): void
    {
        $user->permissionOverrides()->updateExistingPivot(Permission::where('key', $key)->sole()->id, ['allowed' => false]);
    }
}
