<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use App\Models\WorkplaceInjury;
use App\Models\WorkplaceInjurySourceVersion;
use App\Services\HealthSafety\InjuryWorkspaceService;
use App\Services\HealthSafety\RestraintCommandValues;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

abstract class InjuryWorkspaceReadTestCase extends TestCase
{
    use RefreshDatabase;

    protected Site $site;
    protected User $worker;
    protected User $viewer;
    protected WorkplaceInjury $injury;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 12)->utc());
        Queue::fake();
        Storage::fake('private');
        $this->seed(RbacSeeder::class);
        $this->site = Site::factory()->create(['is_active' => true]);
        $this->worker = $this->staff([]);
        $this->viewer = $this->staff(['hazards.view', 'hr.wellbeing.view']);
        $this->injury = WorkplaceInjury::withoutEvents(fn () => WorkplaceInjury::factory()->create([
            'user_id' => $this->worker->id, 'site_id' => $this->site->id, 'created_by' => $this->viewer->id,
            'severity' => 'minor', 'description' => 'Factual workplace occurrence', 'status' => 'under_treatment',
            'immediate_treatment' => 'Private clinical treatment', 'medical_treatment_type' => 'specialist',
            'notes' => 'Private recovery note', 'acc_claim_number' => 'PRIVATE-ACC-123',
        ]));
    }

    protected function staff(array $permissions, ?Site $site = null): User
    {
        $person = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        HrEmployeeProfile::factory()->create(['user_id' => $person->id, 'primary_site_id' => ($site ?? $this->site)->id,
            'secondary_site_ids' => [], 'is_active' => true, 'start_date' => now('Pacific/Auckland')->subYear()->toDateString(), 'end_date' => null]);
        foreach ($permissions as $key) {
            $person->permissionOverrides()->attach(Permission::where('key', $key)->sole(), ['allowed' => true]);
        }
        return $person;
    }

    protected function grant(User $person, string $permission): void
    {
        $person->permissionOverrides()->syncWithoutDetaching([Permission::where('key', $permission)->sole()->id => ['allowed' => true]]);
    }

    protected function revoke(User $person, string $permission): void
    {
        $person->permissionOverrides()->syncWithoutDetaching([Permission::where('key', $permission)->sole()->id => ['allowed' => false]]);
    }

    protected function detail(?User $viewer = null): array
    {
        return app(InjuryWorkspaceService::class)->detail($viewer ?? $this->viewer, $this->injury->id);
    }

    protected function denied(callable $read, int $status): void
    {
        try {
            $read();
            $this->fail('This read must reject current missing authority or an inaccessible source.');
        } catch (HttpExceptionInterface $error) {
            $this->assertSame($status, $error->getStatusCode());
        }
    }

    protected function version(Model $subject, string $type, int $revision = 1, array $changes = []): WorkplaceInjurySourceVersion
    {
        $this->injury->updateQuietly(['source_revision' => max((int) $this->injury->fresh()->source_revision, $revision), 'source_schema_version' => 1]);
        $content = ['scope' => 'medical', 'before' => null, 'after' => $subject->fresh()->attributesToArray(), 'command_facts' => [], 'attribution_state' => 'recorded'];
        $content = [...$content, ...($changes['content'] ?? [])];
        $factual = \App\Services\HealthSafety\InjuryAccessService::factualSnapshot($this->injury->fresh());
        return WorkplaceInjurySourceVersion::create([
            'workplace_injury_id' => $this->injury->id, 'aggregate_revision' => $revision, 'source_schema_version' => 1,
            'source_type' => $type, 'source_id' => $subject->id, 'command' => 'record_'.$type,
            'worker_id' => $this->worker->id, 'site_id' => $this->site->id,
            'recorded_by' => $this->viewer->id, 'recorder_snapshot' => ['id' => $this->viewer->id, 'name' => $this->viewer->name],
            'factual_content' => $factual, 'factual_digest' => RestraintCommandValues::digest($factual),
            'content' => $content, 'content_digest' => RestraintCommandValues::digest($content), 'recorded_at' => now()->utc(),
            ...array_diff_key($changes, ['content' => true]),
        ]);
    }
}
