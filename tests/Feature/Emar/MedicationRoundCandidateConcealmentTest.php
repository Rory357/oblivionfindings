<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\MedicationRound;
use App\Models\MedicationRoundTemplate;
use App\Models\Permission;
use App\Models\ServiceContext;
use App\Models\Site;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MedicationRoundCandidateConcealmentTest extends TestCase
{
    use RefreshDatabase;

    private Site $localSite;

    private Site $foreignSite;

    private ServiceContext $localContext;

    private ServiceContext $foreignContext;

    private User $actor;

    private User $localAssignee;

    private User $foreignAssignee;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-28 10:00:00', config('app.worker_timezone', 'Pacific/Auckland')));
        $this->seed(RbacSeeder::class);
        $this->localSite = Site::factory()->create([
            'is_active' => true,
            'archived' => false,
            'archived_at' => null,
        ]);
        $this->foreignSite = Site::factory()->create([
            'is_active' => true,
            'archived' => false,
            'archived_at' => null,
        ]);
        $this->localContext = ServiceContext::factory()->create([
            'site_id' => $this->localSite->id,
            'is_active' => true,
        ]);
        $this->foreignContext = ServiceContext::factory()->create([
            'site_id' => $this->foreignSite->id,
            'is_active' => true,
        ]);
        $this->actor = $this->staffAtSite($this->localSite, ['medications.orders.manage']);
        $this->localAssignee = $this->staffAtSite($this->localSite);
        $this->foreignAssignee = $this->staffAtSite($this->foreignSite);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_store_conceals_foreign_and_missing_site_context_and_assignee_candidates(): void
    {
        $missingSiteId = (int) Site::query()->max('id') + 1000;
        $missingContextId = (int) ServiceContext::query()->max('id') + 1000;
        $missingUserId = (int) User::query()->max('id') + 1000;
        $cases = [
            'site' => [
                ['site_id' => $this->foreignSite->id, 'service_context_id' => null],
                ['site_id' => $missingSiteId, 'service_context_id' => null],
            ],
            'context' => [
                ['service_context_id' => $this->foreignContext->id],
                ['service_context_id' => $missingContextId],
            ],
            'assignee' => [
                ['default_assigned_to' => $this->foreignAssignee->id],
                ['default_assigned_to' => $missingUserId],
            ],
        ];

        foreach ($cases as $case => $candidates) {
            foreach ($candidates as $index => $candidate) {
                $name = "Concealed {$case} candidate {$index}";

                $this->actingAs($this->actor)
                    ->post(route('emar.rounds.templates.store'), [
                        ...$this->validTemplatePayload($name),
                        ...$candidate,
                    ])
                    ->assertNotFound();
                $this->assertDatabaseMissing('medication_round_templates', ['name' => $name]);
            }
        }
    }

    public function test_update_conceals_foreign_and_missing_site_context_and_assignee_candidates(): void
    {
        $template = $this->localTemplate();
        $original = $template->only([
            'site_id',
            'service_context_id',
            'default_assigned_to',
            'name',
        ]);
        $missingSiteId = (int) Site::query()->max('id') + 1000;
        $missingContextId = (int) ServiceContext::query()->max('id') + 1000;
        $missingUserId = (int) User::query()->max('id') + 1000;
        $candidatePairs = [
            [
                ['site_id' => $this->foreignSite->id, 'service_context_id' => null],
                ['site_id' => $missingSiteId, 'service_context_id' => null],
            ],
            [
                ['service_context_id' => $this->foreignContext->id],
                ['service_context_id' => $missingContextId],
            ],
            [
                ['default_assigned_to' => $this->foreignAssignee->id],
                ['default_assigned_to' => $missingUserId],
            ],
        ];

        foreach ($candidatePairs as $candidates) {
            foreach ($candidates as $candidate) {
                $this->actingAs($this->actor)
                    ->put(route('emar.rounds.templates.update', $template), $candidate)
                    ->assertNotFound();
                $template->refresh();
                $this->assertSame($original, $template->only(array_keys($original)));
            }
        }
    }

    public function test_assignment_conceals_foreign_and_missing_staff_candidates(): void
    {
        $round = MedicationRound::query()->create([
            'site_id' => $this->localSite->id,
            'service_context_id' => $this->localContext->id,
            'name' => 'Candidate assignment round',
            'round_type' => 'scheduled',
            'scheduled_time' => '10:00',
            'window_minutes' => 60,
            'round_date' => today(),
            'status' => 'pending',
            'assigned_to' => $this->localAssignee->id,
        ]);
        $missingUserId = (int) User::query()->max('id') + 1000;

        foreach ([$this->foreignAssignee->id, $missingUserId] as $candidateId) {
            $this->actingAs($this->actor)
                ->put(route('emar.rounds.assign', $round), ['assigned_to' => $candidateId])
                ->assertNotFound();
            $this->assertSame($this->localAssignee->id, (int) $round->fresh()->assigned_to);
        }
    }

    public function test_template_routes_conceal_foreign_and_missing_template_ids(): void
    {
        $foreignTemplate = MedicationRoundTemplate::query()->create([
            ...$this->validTemplatePayload('Foreign template'),
            'site_id' => $this->foreignSite->id,
            'service_context_id' => $this->foreignContext->id,
            'default_assigned_to' => $this->foreignAssignee->id,
            'active' => true,
        ]);
        $missingTemplateId = (int) MedicationRoundTemplate::query()->max('id') + 1000;
        $requests = [
            ['PUT', fn (int $id): string => route('emar.rounds.templates.update', $id)],
            ['POST', fn (int $id): string => route('emar.rounds.templates.retire', $id)],
        ];

        foreach ($requests as [$method, $uri]) {
            foreach ([$foreignTemplate->id, $missingTemplateId] as $templateId) {
                $this->actingAs($this->actor)
                    ->call($method, $uri((int) $templateId))
                    ->assertNotFound();
            }
        }

        $this->assertDatabaseHas('medication_round_templates', [
            'id' => $foreignTemplate->id,
            'active' => true,
            'retired_at' => null,
        ]);
    }

    /** @return array<string, mixed> */
    private function validTemplatePayload(string $name): array
    {
        return [
            'name' => $name,
            'scheduled_time' => '10:00',
            'window_minutes' => 60,
            'days_of_week' => [5],
            'site_id' => $this->localSite->id,
            'service_context_id' => $this->localContext->id,
            'default_assigned_to' => $this->localAssignee->id,
        ];
    }

    private function localTemplate(): MedicationRoundTemplate
    {
        return MedicationRoundTemplate::query()->create([
            ...$this->validTemplatePayload('Local template'),
            'active' => true,
        ]);
    }

    /** @param array<int, string> $permissions */
    private function staffAtSite(Site $site, array $permissions = []): User
    {
        $user = User::factory()->create([
            'role' => 'support_worker',
            'approved_at' => now(),
        ]);
        if ($permissions !== []) {
            $permissionMap = Permission::query()
                ->whereIn('key', $permissions)
                ->pluck('id')
                ->mapWithKeys(fn (int $id) => [$id => ['allowed' => true]])
                ->all();
            $user->permissionOverrides()->sync($permissionMap);
        }
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'start_date' => today()->subYear(),
            'end_date' => null,
            'is_active' => true,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        return $user;
    }
}
