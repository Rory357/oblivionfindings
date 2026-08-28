<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\MedicationRound;
use App\Models\MedicationRoundTemplate;
use App\Models\Permission;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Services\GuidedRoundService;
use App\Services\Medication\MedicationRoundGenerationService;
use Carbon\Carbon;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;
use UnexpectedValueException;

class MedicationRoundLifecycleRetirementTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private ServiceContext $context;

    private User $actor;

    private User $originalActor;

    private Client $scopedClient;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-28 10:00:00', config('app.worker_timezone', 'Pacific/Auckland')));
        $this->seed(RbacSeeder::class);
        $this->site = Site::factory()->create([
            'is_active' => true,
            'archived' => false,
            'archived_at' => null,
        ]);
        $this->context = ServiceContext::factory()->create([
            'site_id' => $this->site->id,
            'is_active' => true,
        ]);
        $this->actor = $this->staffAtSite([
            'medications.orders.manage',
            'medications.administer.record',
        ]);
        $this->originalActor = User::factory()->create([
            'role' => 'support_worker',
            'approved_at' => now(),
        ]);

        $this->scopedClient = Client::factory()->create([
            'site_id' => $this->site->id,
            'service_context_id' => $this->context->id,
            'status' => 'active',
        ]);
        Shift::factory()->create([
            'client_id' => $this->scopedClient->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->context->id,
            'user_id' => $this->actor->id,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHours(2),
            'actual_starts_at' => now()->subHour(),
            'actual_ends_at' => null,
            'started_by' => $this->actor->id,
            'created_by' => $this->actor->id,
            'status' => 'in_progress',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_manager_and_guided_start_replays_preserve_original_actor_time_and_update_timestamp(): void
    {
        foreach (['emar.rounds.start', 'meds.round.start'] as $routeName) {
            $round = $this->round([
                'name' => 'Started through '.$routeName,
                'status' => 'in_progress',
                'assigned_to' => $this->actor->id,
                'started_by' => $this->originalActor->id,
                'started_at' => now()->subHours(3),
            ]);
            $expected = $this->lifecycleSnapshot($round);

            Carbon::setTestNow(now()->addMinutes(15));
            $this->actingAs($this->actor)
                ->post(route($routeName, $round))
                ->assertRedirect();

            $this->assertSame($expected, $this->lifecycleSnapshot($round->fresh()));
        }
    }

    public function test_manager_and_guided_complete_replays_preserve_original_evidence_and_count_snapshots(): void
    {
        foreach (['emar.rounds.complete', 'meds.round.complete'] as $routeName) {
            $round = $this->round([
                'name' => 'Completed through '.$routeName,
                'status' => 'completed',
                'assigned_to' => $this->actor->id,
                'started_by' => $this->actor->id,
                'started_at' => now()->subHours(4),
                'completed_by' => $this->originalActor->id,
                'completed_at' => now()->subHours(2),
                'total_medications' => 11,
                'administered_count' => 5,
                'refused_count' => 2,
                'withheld_count' => 1,
                'missed_count' => 3,
            ]);
            $expected = $this->lifecycleSnapshot($round);

            Carbon::setTestNow(now()->addMinutes(15));
            $this->actingAs($this->actor)
                ->post(route($routeName, $round))
                ->assertRedirect();

            $this->assertSame($expected, $this->lifecycleSnapshot($round->fresh()));
        }
    }

    public function test_partial_round_resume_normalizes_status_without_rewriting_start_evidence(): void
    {
        foreach (['emar.rounds.start', 'meds.round.start'] as $routeName) {
            $startedAt = now()->subHours(3);
            $round = $this->round([
                'name' => 'Partial round through '.$routeName,
                'status' => 'partial',
                'assigned_to' => $this->actor->id,
                'started_by' => $this->originalActor->id,
                'started_at' => $startedAt,
            ]);
            $startedAtEvidence = $round->getRawOriginal('started_at');

            $this->actingAs($this->actor)
                ->post(route($routeName, $round))
                ->assertRedirect();

            $resumed = $round->fresh();
            $this->assertSame('in_progress', $resumed->status);
            $this->assertSame($this->originalActor->id, (int) $resumed->started_by);
            $this->assertSame($startedAtEvidence, $resumed->getRawOriginal('started_at'));
        }
    }

    public function test_guided_completion_blocks_pending_items_for_a_same_site_client_outside_worker_scope(): void
    {
        $visibleMedication = $this->scheduledMedication($this->scopedClient);
        $hiddenClient = Client::factory()->create([
            'site_id' => $this->site->id,
            'service_context_id' => $this->context->id,
            'status' => 'active',
        ]);
        $this->scheduledMedication($hiddenClient);
        $round = $this->round([
            'status' => 'in_progress',
            'started_by' => $this->actor->id,
            'started_at' => now()->subHour(),
        ]);
        $this->recordGiven($round, $this->scopedClient, $visibleMedication);

        $this->actingAs($this->actor)
            ->get(route('emar.rounds', ['date' => $this->workerToday()->toDateString(), 'guided' => $round->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('guidedRound.progress.pending', 0)
                ->where('guidedRound.can_complete', false)
                ->where('rounds.0.can_complete', false));

        foreach (['meds.round.complete', 'emar.rounds.complete'] as $routeName) {
            $this->actingAs($this->actor)
                ->post(route($routeName, $round))
                ->assertSessionHasErrors('round');
        }

        $this->assertSame('in_progress', $round->fresh()->status);
        $this->assertNull($round->fresh()->completed_at);
    }

    public function test_guided_completion_blocks_a_controlled_pending_item_hidden_from_the_worker_projection(): void
    {
        $visibleMedication = $this->scheduledMedication($this->scopedClient);
        $this->scheduledMedication($this->scopedClient, controlled: true);
        $round = $this->round([
            'status' => 'in_progress',
            'started_by' => $this->actor->id,
            'started_at' => now()->subHour(),
        ]);
        $this->recordGiven($round, $this->scopedClient, $visibleMedication);

        $this->actingAs($this->actor)
            ->get(route('emar.rounds', ['date' => $this->workerToday()->toDateString(), 'guided' => $round->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('guidedRound.progress.pending', 0)
                ->where('guidedRound.can_complete', false)
                ->where('rounds.0.can_complete', false));

        foreach (['meds.round.complete', 'emar.rounds.complete'] as $routeName) {
            $this->actingAs($this->actor)
                ->post(route($routeName, $round))
                ->assertSessionHasErrors('round');
        }

        $this->assertSame('in_progress', $round->fresh()->status);
        $this->assertNull($round->fresh()->completed_at);
    }

    public function test_canonical_completed_round_transition_and_replay_are_allowed_and_idempotent(): void
    {
        $medication = $this->scheduledMedication($this->scopedClient);

        foreach (['meds.round.complete', 'emar.rounds.complete'] as $routeName) {
            $round = $this->round([
                'name' => 'Canonical completion through '.$routeName,
                'status' => 'in_progress',
                'started_by' => $this->actor->id,
                'started_at' => now()->subHour(),
            ]);
            $this->recordGiven($round, $this->scopedClient, $medication);

            $this->actingAs($this->actor)
                ->post(route($routeName, $round))
                ->assertRedirect();

            $completed = $round->fresh();
            $this->assertSame('completed', $completed->status);
            $this->assertSame($this->actor->id, (int) $completed->completed_by);
            $this->assertNotNull($completed->completed_at);
            $this->assertSame(1, (int) $completed->administered_count);
            $expected = $this->lifecycleSnapshot($completed);

            Carbon::setTestNow(now()->addMinutes(15));
            $this->actingAs($this->actor)
                ->post(route($routeName, $round))
                ->assertRedirect();

            $this->assertSame($expected, $this->lifecycleSnapshot($round->fresh()));
        }
    }

    public function test_completion_conceals_inactive_or_foreign_site_service_contexts_instead_of_accepting_an_empty_projection(): void
    {
        $foreignSite = Site::factory()->create([
            'is_active' => true,
            'archived' => false,
            'archived_at' => null,
        ]);
        $invalidContexts = [
            ServiceContext::factory()->create([
                'site_id' => $foreignSite->id,
                'is_active' => true,
            ]),
            ServiceContext::factory()->create([
                'site_id' => $this->site->id,
                'is_active' => false,
            ]),
        ];

        foreach ($invalidContexts as $index => $invalidContext) {
            $round = $this->round([
                'name' => 'Invalid context completion '.$index,
                'service_context_id' => $invalidContext->id,
                'status' => 'in_progress',
                'started_by' => $this->actor->id,
                'started_at' => now()->subHour(),
            ]);
            $expected = $this->lifecycleSnapshot($round);
            $this->assertFalse(app(GuidedRoundService::class)->canCompleteCanonicalRound($round));

            foreach (['meds.round.complete', 'emar.rounds.complete'] as $routeName) {
                $this->actingAs($this->actor)
                    ->post(route($routeName, $round))
                    ->assertNotFound();
            }

            $this->assertSame($expected, $this->lifecycleSnapshot($round->fresh()));
        }
    }

    public function test_completed_round_projection_does_not_adopt_a_medication_verified_after_completion(): void
    {
        $recordedMedication = $this->scheduledMedication($this->scopedClient);
        $lateMedication = $this->scheduledMedication($this->scopedClient);
        $lateMedication->forceFill([
            'approval_status' => 'pending_verification',
            'verified_by' => null,
            'verified_at' => null,
        ])->save();
        $round = $this->round([
            'status' => 'in_progress',
            'started_by' => $this->actor->id,
            'started_at' => now()->subHour(),
        ]);
        $this->recordGiven($round, $this->scopedClient, $recordedMedication);

        $this->actingAs($this->actor)
            ->post(route('emar.rounds.complete', $round))
            ->assertRedirect();
        $this->assertSame('completed', $round->fresh()->status);

        $lateMedication->forceFill([
            'approval_status' => 'verified',
            'verified_by' => $this->actor->id,
            'verified_at' => now()->addMinute(),
        ])->save();

        $items = app(GuidedRoundService::class)->items($round->fresh(), true);
        $this->assertSame([$recordedMedication->id], array_column($items, 'medication_id'));
        $this->assertSame([
            'total' => 1,
            'completed' => 1,
            'pending' => 0,
            'given' => 1,
            'refused' => 0,
            'held' => 0,
            'next_index' => null,
            'percent' => 100,
        ], app(GuidedRoundService::class)->summarise($items));
    }

    public function test_reassignment_revokes_the_previous_starter_from_get_administration_and_completion(): void
    {
        $starter = $this->staffAtSite(['medications.administer.record']);
        $newAssignee = $this->staffAtSite(['medications.administer.record']);
        Shift::factory()->create([
            'client_id' => $this->scopedClient->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->context->id,
            'user_id' => $starter->id,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHours(2),
            'actual_starts_at' => now()->subHour(),
            'actual_ends_at' => null,
            'started_by' => $starter->id,
            'created_by' => $starter->id,
            'status' => 'in_progress',
        ]);
        $medication = $this->scheduledMedication($this->scopedClient);
        $round = $this->round([
            'status' => 'in_progress',
            'assigned_to' => $starter->id,
            'started_by' => $starter->id,
            'started_at' => now()->subHour(),
        ]);

        $this->actingAs($this->actor)
            ->put(route('emar.rounds.assign', $round), ['assigned_to' => $newAssignee->id])
            ->assertRedirect();

        $this->assertSame($newAssignee->id, (int) $round->fresh()->assigned_to);
        $this->actingAs($starter)
            ->get(route('meds.round.show', $round))
            ->assertNotFound();
        $this->actingAs($starter)
            ->get(route('emar.rounds', ['date' => $this->workerToday()->toDateString(), 'guided' => $round->id]))
            ->assertNotFound();
        $this->actingAs($starter)
            ->post(route('meds.round.administer', [$round, $medication]), [
                'status' => 'given',
                'scheduled_for' => now()->setTime(10, 0)->toIso8601String(),
            ])
            ->assertNotFound();
        $this->actingAs($starter)
            ->post(route('meds.round.complete', $round))
            ->assertNotFound();

        $this->assertSame('in_progress', $round->fresh()->status);
        $this->assertDatabaseMissing('client_medication_administrations', [
            'medication_round_id' => $round->id,
        ]);
    }

    public function test_completed_round_cannot_be_reassigned_or_rewrite_terminal_evidence(): void
    {
        $newAssignee = $this->staffAtSite(['medications.administer.record']);
        $round = $this->round([
            'status' => 'completed',
            'started_by' => $this->actor->id,
            'started_at' => now()->subHours(2),
            'completed_by' => $this->originalActor->id,
            'completed_at' => now()->subHour(),
            'total_medications' => 3,
            'administered_count' => 2,
            'refused_count' => 1,
        ]);
        $expected = $this->lifecycleSnapshot($round);

        $this->actingAs($this->actor)
            ->put(route('emar.rounds.assign', $round), ['assigned_to' => $newAssignee->id])
            ->assertNotFound();

        $this->assertSame($expected, $this->lifecycleSnapshot($round->fresh()));
    }

    public function test_retirement_preserves_template_and_round_provenance_and_blocks_future_generation(): void
    {
        $template = $this->template();
        $round = $this->round([
            'round_template_id' => $template->id,
            'name' => 'Historical round',
        ]);

        $this->actingAs($this->actor)
            ->post(route('emar.rounds.templates.retire', $template))
            ->assertRedirect()
            ->assertSessionHas('success', 'Round template retired. Existing rounds were retained.');

        $retired = $template->fresh();
        $this->assertNotNull($retired);
        $this->assertFalse($retired->active);
        $this->assertNotNull($retired->retired_at);
        $this->assertSame($this->actor->id, (int) $retired->retired_by_user_id);
        $this->assertSame($template->id, (int) $round->fresh()->round_template_id);
        $this->assertSame($template->id, (int) $round->fresh()->template?->id);

        $result = app(MedicationRoundGenerationService::class)->generate(
            $template->id,
            $this->workerToday()->addDay(),
            true,
            [$this->site->id],
        );
        $this->assertSame(MedicationRoundGenerationService::STATUS_SKIPPED, $result['status']);
        $this->assertSame(MedicationRoundGenerationService::REASON_TEMPLATE_RETIRED, $result['reason']);
        $this->assertDatabaseMissing('medication_rounds', [
            'round_template_id' => $template->id,
            'round_date' => $this->workerToday()->addDay()->toDateString(),
        ]);

        $retiredAt = $retired->getRawOriginal('retired_at');
        Carbon::setTestNow(now()->addHour());
        $this->actingAs($this->actor)
            ->post(route('emar.rounds.templates.retire', $template))
            ->assertRedirect();
        $this->actingAs($this->actor)
            ->put(route('emar.rounds.templates.update', $template), ['active' => true])
            ->assertSessionHasErrors('template');

        $replayed = $template->fresh();
        $this->assertSame($retiredAt, $replayed->getRawOriginal('retired_at'));
        $this->assertFalse($replayed->active);
        $this->assertSame(1, AuditLog::query()
            ->where('action', 'medications.round_template.retired')
            ->where('auditable_id', $template->id)
            ->count());
    }

    public function test_round_template_hard_delete_is_rejected_and_retained(): void
    {
        $template = $this->template();

        try {
            $template->delete();
            $this->fail('Expected medication round-template deletion to be rejected.');
        } catch (UnexpectedValueException $exception) {
            $this->assertStringContainsString('historical medication-round evidence', $exception->getMessage());
        }

        $this->assertDatabaseHas('medication_round_templates', ['id' => $template->id]);
    }

    public function test_retirement_rolls_back_when_the_integrity_audit_cannot_be_written(): void
    {
        $template = $this->template();
        $injectFailure = true;
        AuditLog::creating(function (AuditLog $audit) use (&$injectFailure): void {
            if ($injectFailure && $audit->action === 'medications.round_template.retired') {
                throw new RuntimeException('Injected round-template retirement audit failure.');
            }
        });

        $this->withoutExceptionHandling();
        try {
            $this->actingAs($this->actor)
                ->post(route('emar.rounds.templates.retire', $template));
            $this->fail('Expected the strict retirement audit failure to escape.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected round-template retirement audit failure.', $exception->getMessage());
        } finally {
            $injectFailure = false;
        }

        $template->refresh();
        $this->assertTrue($template->active);
        $this->assertNull($template->retired_at);
        $this->assertNull($template->retired_by_user_id);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'medications.round_template.retired',
            'auditable_id' => $template->id,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function round(array $overrides = []): MedicationRound
    {
        return MedicationRound::query()->create(array_merge([
            'site_id' => $this->site->id,
            'service_context_id' => $this->context->id,
            'name' => 'Round lifecycle evidence',
            'round_type' => 'scheduled',
            'scheduled_time' => '10:00',
            'window_minutes' => 60,
            'round_date' => $this->workerToday(),
            'status' => 'pending',
            'assigned_to' => $this->actor->id,
            'total_medications' => 0,
            'administered_count' => 0,
            'refused_count' => 0,
            'withheld_count' => 0,
            'missed_count' => 0,
        ], $overrides));
    }

    private function scheduledMedication(Client $client, bool $controlled = false): ClientMedication
    {
        return ClientMedication::query()->create([
            'client_id' => $client->id,
            'name' => $controlled ? 'Controlled canonical dose' : 'Canonical round dose',
            'dosage' => '1 tablet',
            'frequency' => 'Once daily',
            'dose_times' => ['10:00'],
            'is_prn' => false,
            'controlled_drug' => $controlled,
            'active' => true,
            'state' => 'active',
            'approval_status' => 'verified',
            'start_date' => $this->workerToday()->subMonth(),
        ]);
    }

    private function recordGiven(
        MedicationRound $round,
        Client $client,
        ClientMedication $medication,
    ): ClientMedicationAdministration {
        $scheduledFor = now(config('app.worker_timezone', 'Pacific/Auckland'))->setTime(10, 0);

        return ClientMedicationAdministration::query()->create([
            'client_id' => $client->id,
            'client_medication_id' => $medication->id,
            'medication_round_id' => $round->id,
            'service_context_id' => $client->service_context_id,
            'administered_by' => $this->actor->id,
            'scheduled_for' => $scheduledFor->copy()->utc(),
            'administered_at' => $scheduledFor->copy()->utc(),
            'status' => 'given',
            'dose_given' => '1 tablet',
        ]);
    }

    private function template(): MedicationRoundTemplate
    {
        return MedicationRoundTemplate::query()->create([
            'site_id' => $this->site->id,
            'service_context_id' => $this->context->id,
            'name' => 'Retained template',
            'scheduled_time' => '10:00',
            'window_minutes' => 60,
            'days_of_week' => [5],
            'active' => true,
            'default_assigned_to' => $this->actor->id,
        ]);
    }

    /** @return array<string, mixed> */
    private function lifecycleSnapshot(MedicationRound $round): array
    {
        return [
            'status' => $round->status,
            'started_by' => $round->started_by !== null ? (int) $round->started_by : null,
            'started_at' => $round->getRawOriginal('started_at'),
            'completed_by' => $round->completed_by !== null ? (int) $round->completed_by : null,
            'completed_at' => $round->getRawOriginal('completed_at'),
            'total_medications' => (int) $round->total_medications,
            'administered_count' => (int) $round->administered_count,
            'refused_count' => (int) $round->refused_count,
            'withheld_count' => (int) $round->withheld_count,
            'missed_count' => (int) $round->missed_count,
            'updated_at' => $round->getRawOriginal('updated_at'),
        ];
    }

    /** @param array<int, string> $permissions */
    private function staffAtSite(array $permissions): User
    {
        $user = User::factory()->create([
            'role' => 'support_worker',
            'approved_at' => now(),
        ]);
        $permissionMap = Permission::query()
            ->whereIn('key', $permissions)
            ->pluck('id')
            ->mapWithKeys(fn (int $id) => [$id => ['allowed' => true]])
            ->all();
        $user->permissionOverrides()->sync($permissionMap);
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [],
            'start_date' => $this->workerToday()->subYear(),
            'end_date' => null,
            'is_active' => true,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        return $user;
    }

    private function workerToday(): Carbon
    {
        return Carbon::today(config('app.worker_timezone', 'Pacific/Auckland'));
    }
}
