<?php

namespace Tests\Support;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\FirstAidRecord;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use App\Services\HealthSafety\FirstAidService;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

trait FirstAidSourceFixtures
{
    protected Site $sourceSite;

    protected User $manager;

    protected User $worker;

    protected User $firstAider;

    protected function initializeFirstAidFixtures(): void
    {
        $this->seed(RbacSeeder::class);
        Queue::fake();
        Http::preventStrayRequests();
        Storage::fake('private');
        $this->sourceSite = Site::factory()->create(['is_active' => true]);
        $this->worker = $this->sourceStaff(['hazards.view']);
        $this->firstAider = $this->sourceStaff([], true);
        $this->manager = $this->sourceStaff(['hazards.view', 'hazards.manage', 'clients.viewAny', 'incidents.viewAny', 'incidents.create', 'incidents.update']);
        $this->actingAs($this->manager);
    }

    protected function sourceStaff(array $permissions = [], bool $firstAider = false): User
    {
        $user = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $this->sourceSite->id, 'secondary_site_ids' => [], 'is_active' => true, 'start_date' => now()->subYear()->toDateString(), 'end_date' => null, 'is_first_aider' => $firstAider]);
        foreach ($permissions as $key) {
            $user->permissionOverrides()->syncWithoutDetaching([Permission::where('key', $key)->firstOrFail()->id => ['allowed' => true]]);
        }

        return $user;
    }

    protected function sourceService(): FirstAidService
    {
        return app(FirstAidService::class);
    }

    protected function treatment(array $extra = []): array
    {
        return ['site_id' => $this->sourceSite->id, 'treated_person_type' => 'staff', 'treated_person_id' => $this->worker->id, 'treated_person_name' => 'Recorded staff subject',
            'treatment_date' => now()->subHour()->toISOString(), 'injury_illness_type' => 'cut', 'injury_illness_description' => 'Recorded small cut', 'treatment_given' => 'Recorded treatment',
            'treatment_outcome' => 'returned_to_activity', 'first_aider_id' => $this->firstAider->id, 'ambulance_called' => false, 'incident_reported' => false,
            'idempotency_key' => (string) Str::uuid(), ...$extra];
    }

    protected function source(array $extra = []): FirstAidRecord
    {
        return $this->sourceService()->create($this->manager, $this->treatment($extra));
    }

    protected function reviewed(FirstAidRecord $row, array $extra = []): array
    {
        return ['expected_version' => (int) $row->fresh()->lock_version, 'idempotency_key' => (string) Str::uuid(), ...$extra];
    }
}
