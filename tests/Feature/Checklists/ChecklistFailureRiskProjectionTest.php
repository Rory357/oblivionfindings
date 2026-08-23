<?php

namespace Tests\Feature\Checklists;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteChecklistAssignment;
use App\Models\SiteChecklistRun;
use App\Models\SiteChecklistTemplate;
use App\Models\SiteChecklistTemplateItem;
use App\Models\SiteDamage;
use App\Models\SiteHazard;
use App\Models\SiteHazardAction;
use App\Models\User;
use App\Services\Sites\SiteChecklistFailureRiskMapper;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class ChecklistFailureRiskProjectionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->admin = $this->roleUser('admin');
        $this->site = Site::factory()->create(['type' => 'house']);
    }

    public function test_ordinary_and_critical_failures_use_governed_projection_and_required_escalation(): void
    {
        [$run, $items] = $this->makeRun([
            [
                'question' => 'Ordinary property check passes?',
                'failure_creates_hazard' => true,
                'failure_creates_damage' => true,
                'failure_risk_level' => SiteChecklistFailureRiskMapper::ORDINARY,
            ],
            [
                'question' => 'Critical fire protection is operational?',
                'failure_creates_hazard' => false,
                'failure_creates_damage' => true,
                'failure_risk_level' => SiteChecklistFailureRiskMapper::CRITICAL,
            ],
        ]);

        $this->actingAs($this->admin)
            ->post("/checklists/runs/{$run->id}/responses", [
                'responses' => $this->failedPayload($items),
            ])
            ->assertRedirect();

        $responses = $run->responses()->get()->keyBy('template_item_id');
        $ordinaryHazard = SiteHazard::query()->findOrFail($responses[$items[0]->id]->created_hazard_id);
        $ordinaryDamage = SiteDamage::query()->findOrFail($responses[$items[0]->id]->created_damage_id);
        $criticalHazard = SiteHazard::query()->findOrFail($responses[$items[1]->id]->created_hazard_id);
        $criticalDamage = SiteDamage::query()->findOrFail($responses[$items[1]->id]->created_damage_id);

        $this->assertSame('medium', $ordinaryHazard->severity);
        $this->assertSame('possible', $ordinaryHazard->likelihood);
        $this->assertSame('medium', $ordinaryHazard->risk_rating);
        $this->assertSame('minor', $ordinaryDamage->severity);
        $this->assertSame('critical', $criticalHazard->severity);
        $this->assertSame('possible', $criticalHazard->likelihood);
        $this->assertSame('extreme', $criticalHazard->risk_rating);
        $this->assertSame('critical', $criticalDamage->severity);

        $this->assertFalse($items[1]->failure_creates_hazard);
        $this->assertDatabaseHas('site_hazard_actions', [
            'hazard_id' => $criticalHazard->id,
            'action_type' => SiteChecklistFailureRiskMapper::REQUIRED_ESCALATION_ACTION,
            'status' => 'pending',
        ]);
        $this->assertDatabaseMissing('site_hazard_actions', [
            'hazard_id' => $ordinaryHazard->id,
            'action_type' => SiteChecklistFailureRiskMapper::REQUIRED_ESCALATION_ACTION,
        ]);

        $this->actingAs($this->admin)
            ->get("/checklists?run={$run->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('runDetail.template.flags.hazard', true)
                ->where('runDetail.items.1.failure_creates_hazard', false)
                ->where('runDetail.items.1.failure_risk_level', SiteChecklistFailureRiskMapper::CRITICAL));
    }

    public function test_failure_status_is_derived_from_the_canonical_item_not_client_flags(): void
    {
        [$failedRun, $failedItems] = $this->makeRun([[
            'question' => 'Emergency exit remains usable?',
            'failure_creates_hazard' => false,
            'failure_creates_damage' => true,
            'failure_risk_level' => SiteChecklistFailureRiskMapper::CRITICAL,
        ]]);

        $this->actingAs($this->admin)
            ->post("/checklists/runs/{$failedRun->id}/responses", [
                'responses' => [[
                    'template_item_id' => $failedItems[0]->id,
                    'response_value' => 'no',
                    'is_failed' => false,
                ]],
            ])
            ->assertRedirect();

        $this->assertTrue((bool) $failedRun->responses()->sole()->is_failed);
        $criticalHazard = SiteHazard::query()
            ->where('linked_checklist_run_id', $failedRun->id)
            ->sole();
        $this->assertDatabaseHas('site_hazard_actions', [
            'hazard_id' => $criticalHazard->id,
            'action_type' => SiteChecklistFailureRiskMapper::REQUIRED_ESCALATION_ACTION,
            'status' => 'pending',
        ]);

        [$passingRun, $passingItems] = $this->makeRun([[
            'question' => 'Emergency exit remains usable?',
            'failure_creates_hazard' => true,
            'failure_creates_damage' => true,
            'failure_risk_level' => SiteChecklistFailureRiskMapper::CRITICAL,
        ]]);

        $this->actingAs($this->admin)
            ->post("/checklists/runs/{$passingRun->id}/responses", [
                'responses' => [[
                    'template_item_id' => $passingItems[0]->id,
                    'response_value' => 'yes',
                    'is_failed' => true,
                ]],
            ])
            ->assertRedirect();

        $this->assertFalse((bool) $passingRun->responses()->sole()->is_failed);
        $this->assertDatabaseMissing('site_hazards', [
            'linked_checklist_run_id' => $passingRun->id,
        ]);
        $this->assertDatabaseMissing('site_damages', [
            'checklist_run_id' => $passingRun->id,
        ]);
    }

    public function test_repeat_save_and_completion_replay_create_one_follow_up_set(): void
    {
        [$run, $items] = $this->makeRun([[
            'question' => 'Emergency lighting is operational?',
            'failure_creates_hazard' => true,
            'failure_creates_damage' => true,
            'failure_risk_level' => SiteChecklistFailureRiskMapper::CRITICAL,
        ]]);
        $payload = $this->failedPayload($items);

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->actingAs($this->admin)
                ->post("/checklists/runs/{$run->id}/responses", ['responses' => $payload])
                ->assertRedirect();
        }

        $this->actingAs($this->admin)
            ->post("/checklists/runs/{$run->id}/complete", [
                'responses' => $payload,
                'signature_name' => 'Checklist Manager',
            ])
            ->assertRedirect();
        $this->actingAs($this->admin)
            ->post("/checklists/runs/{$run->id}/complete", [
                'responses' => $payload,
                'signature_name' => 'Replay Must Not Replace',
            ])
            ->assertRedirect()
            ->assertSessionHas('success', 'Checklist already completed.');

        $hazard = SiteHazard::query()->where('linked_checklist_run_id', $run->id)->sole();
        $this->assertSame(1, SiteDamage::query()->where('checklist_run_id', $run->id)->count());
        $this->assertSame(1, SiteHazardAction::query()
            ->where('hazard_id', $hazard->id)
            ->where('action_type', SiteChecklistFailureRiskMapper::REQUIRED_ESCALATION_ACTION)
            ->count());
        $this->assertSame('Checklist Manager', $run->fresh()->signature_name);
    }

    public function test_failure_projection_rolls_back_with_the_owning_save_transaction(): void
    {
        [$run, $items] = $this->makeRun([[
            'question' => 'Fire suppression is operational?',
            'failure_creates_hazard' => true,
            'failure_creates_damage' => true,
            'failure_risk_level' => SiteChecklistFailureRiskMapper::CRITICAL,
        ]]);
        $eventName = 'eloquent.creating: '.AuditLog::class;
        Event::listen($eventName, static function (): never {
            throw new RuntimeException('Simulated checklist audit failure.');
        });
        $caught = null;

        $this->withoutExceptionHandling();
        try {
            $this->actingAs($this->admin)
                ->post("/checklists/runs/{$run->id}/responses", [
                    'responses' => $this->failedPayload($items),
                ]);
        } catch (RuntimeException $exception) {
            $caught = $exception;
        } finally {
            $this->withExceptionHandling();
            Event::forget($eventName);
        }

        $this->assertSame('Simulated checklist audit failure.', $caught?->getMessage());
        $this->assertSame('scheduled', $run->fresh()->status);
        $this->assertDatabaseMissing('site_checklist_responses', ['run_id' => $run->id]);
        $this->assertDatabaseMissing('site_hazards', ['linked_checklist_run_id' => $run->id]);
        $this->assertDatabaseMissing('site_damages', ['checklist_run_id' => $run->id]);
        $this->assertDatabaseCount('site_hazard_actions', 0);
    }

    public function test_critical_action_blocks_close_and_foreign_ids_are_concealed_before_effects(): void
    {
        [$run, $items] = $this->makeRun([[
            'question' => 'Fire doors close and latch?',
            'failure_creates_hazard' => true,
            'failure_creates_damage' => false,
            'failure_risk_level' => SiteChecklistFailureRiskMapper::CRITICAL,
        ]]);
        $this->actingAs($this->admin)
            ->post("/checklists/runs/{$run->id}/responses", [
                'responses' => $this->failedPayload($items),
            ])
            ->assertRedirect();

        $hazard = SiteHazard::query()->where('linked_checklist_run_id', $run->id)->sole();
        $action = SiteHazardAction::query()
            ->where('hazard_id', $hazard->id)
            ->where('action_type', SiteChecklistFailureRiskMapper::REQUIRED_ESCALATION_ACTION)
            ->sole();

        $this->actingAs($this->admin)
            ->post("/hazards/{$hazard->id}/actions", [
                'title' => 'Forged governed escalation marker',
                'action_type' => SiteChecklistFailureRiskMapper::REQUIRED_ESCALATION_ACTION,
            ])
            ->assertSessionHasErrors('action_type');
        $this->assertSame(1, SiteHazardAction::query()
            ->where('hazard_id', $hazard->id)
            ->where('action_type', SiteChecklistFailureRiskMapper::REQUIRED_ESCALATION_ACTION)
            ->count());

        $foreignSite = Site::factory()->create(['type' => 'house']);
        $foreignOfficer = $this->roleUser('health_safety_officer');
        HrEmployeeProfile::factory()->create([
            'user_id' => $foreignOfficer->id,
            'primary_site_id' => $foreignSite->id,
            'secondary_site_ids' => [],
            'start_date' => today()->subYear(),
            'end_date' => null,
            'is_active' => true,
        ]);
        $this->assertTrue($foreignOfficer->canDo('hazards.close'));
        $this->assertTrue($foreignOfficer->canDo('hazards.manage'));
        $this->assertFalse($foreignOfficer->canDo('sites.viewAll'));
        $missingHazardId = (int) SiteHazard::query()->max('id') + 10_000;

        $foreign = $this->actingAs($foreignOfficer)
            ->post("/hazards/{$hazard->id}/close", ['resolution_summary' => 'Must stay hidden.']);
        $missing = $this->actingAs($foreignOfficer)
            ->post("/hazards/{$missingHazardId}/close", ['resolution_summary' => 'Must stay hidden.']);
        $foreign->assertNotFound();
        $missing->assertNotFound();
        $this->assertSame($missing->getContent(), $foreign->getContent());
        $this->actingAs($foreignOfficer)
            ->post("/hazard-actions/{$action->id}/complete", ['completion_notes' => 'Forged completion.'])
            ->assertNotFound();
        $this->actingAs($foreignOfficer)
            ->post("/hazards/{$hazard->id}/actions", [
                'title' => 'Forged foreign action.',
                'action_type' => 'engineering',
            ])
            ->assertNotFound();
        $this->assertSame('pending', $action->fresh()->status);
        $this->assertSame(1, $hazard->actions()->count());
        $this->assertSame('open', $hazard->fresh()->status);

        $globalWithoutAction = User::factory()->create(['approved_at' => now()]);
        $globalWithoutAction->permissionOverrides()->attach(
            Permission::query()
                ->whereIn('key', ['sites.viewAny', 'sites.viewAll', 'sites.type.house.view'])
                ->pluck('id')
                ->all(),
            ['allowed' => true],
        );
        $this->assertTrue($globalWithoutAction->canDo('sites.viewAll'));
        $this->assertFalse($globalWithoutAction->canDo('hazards.close'));
        $this->actingAs($globalWithoutAction)
            ->post("/hazards/{$hazard->id}/close", ['resolution_summary' => 'No action capability.'])
            ->assertForbidden();

        $this->actingAs($this->admin)
            ->post("/hazards/{$hazard->id}/close", ['resolution_summary' => 'Premature closure.'])
            ->assertRedirect()
            ->assertSessionHas('error', 'Complete all corrective actions before closing this hazard.');
        $this->assertSame('open', $hazard->fresh()->status);
        $this->assertNull($hazard->fresh()->resolution_summary);

        $this->actingAs($this->admin)
            ->post("/hazard-actions/{$action->id}/complete", ['completion_notes' => 'Controls verified.'])
            ->assertRedirect();
        $completedAction = $action->fresh();
        $this->actingAs($this->admin)
            ->post("/hazard-actions/{$action->id}/complete", ['completion_notes' => 'Replay must not replace evidence.'])
            ->assertRedirect()
            ->assertSessionHas('success', 'Corrective action already completed.');
        $this->assertTrue($completedAction->completed_at->equalTo($action->fresh()->completed_at));
        $this->assertSame('Controls verified.', $action->fresh()->completion_notes);

        $this->actingAs($this->admin)
            ->post("/hazards/{$hazard->id}/close", ['resolution_summary' => 'Controls complete and verified.'])
            ->assertRedirect();

        $this->assertSame('closed', $hazard->fresh()->status);
        $this->assertSame('Controls complete and verified.', $hazard->fresh()->resolution_summary);
    }

    /**
     * @param  array<int, array<string, mixed>>  $definitions
     * @return array{0: SiteChecklistRun, 1: array<int, SiteChecklistTemplateItem>}
     */
    private function makeRun(array $definitions): array
    {
        $template = SiteChecklistTemplate::query()->create([
            'key' => 'risk_projection_'.Str::lower(Str::random(16)),
            'name' => 'Risk Projection Test',
            'applicable_to_type' => 'house',
            'frequency' => 'daily',
            'is_active' => true,
        ]);
        $items = [];
        foreach ($definitions as $index => $definition) {
            $items[] = $template->items()->create([
                ...$definition,
                'response_type' => 'yes_no',
                'is_required' => true,
                'sort_order' => $index,
            ]);
        }
        $assignment = SiteChecklistAssignment::query()->create([
            'site_id' => $this->site->id,
            'template_id' => $template->id,
            'frequency' => 'daily',
            'start_date' => today(),
            'is_active' => true,
        ]);
        $run = SiteChecklistRun::query()->create([
            'assignment_id' => $assignment->id,
            'site_id' => $this->site->id,
            'template_id' => $template->id,
            'scheduled_date' => today(),
            'status' => 'scheduled',
        ]);

        return [$run, $items];
    }

    /** @param array<int, SiteChecklistTemplateItem> $items */
    private function failedPayload(array $items): array
    {
        return collect($items)->map(fn (SiteChecklistTemplateItem $item): array => [
            'template_item_id' => $item->id,
            'response_value' => 'no',
            'notes' => 'Observed during the checklist run.',
            'is_failed' => true,
        ])->all();
    }

    private function roleUser(string $role): User
    {
        $user = User::factory()->create(['role' => $role, 'approved_at' => now()]);
        $user->roles()->attach(Role::query()->where('name', $role)->firstOrFail());

        return $user;
    }
}
