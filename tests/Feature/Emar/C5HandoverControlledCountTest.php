<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\MedicationCompetencyAssessment;
use App\Models\MedicationFollowup;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\ShiftHandover;
use App\Models\Site;
use App\Models\User;
use App\Services\Emar\HandoverControlledCountFollowup;
use App\Services\Medication\Followups\MedicationFollowupService;
use Database\Factories\UserFactory;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * eMAR audit Lane C5 (EA-034): "Discrepancy found" on the handover's
 * witnessed controlled-drug count reaches the register's lead as ONE
 * follow-up per handover — with what the two workers found, a link to the
 * register, and the controlled-drug floor on who may see it.
 */
class C5HandoverControlledCountTest extends TestCase
{
    use RefreshDatabase;

    private User $worker;

    private User $witness;

    private Site $site;

    private ServiceContext $context;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);

        $this->site = Site::factory()->create(['type' => 'house', 'name' => 'Tui House', 'is_active' => true]);
        $this->context = ServiceContext::factory()->create(['name' => 'Residential', 'type' => 'residential', 'is_active' => true]);
        $this->client = Client::factory()->create(['site_id' => $this->site->id, 'service_context_id' => $this->context->id, 'status' => 'active']);
        $this->worker = $this->staff('admin', [
            'medications.view', 'handovers.create', 'handovers.viewAny', 'shifts.update', 'shifts.manageAny', 'clients.update',
            'medications.controlled.view', 'medications.controlled.record',
        ]);
        $this->witness = $this->staff('support_worker', ['shifts.update', 'medications.controlled.witness']);
        MedicationCompetencyAssessment::query()->create([
            'user_id' => $this->witness->id, 'assessor_id' => $this->worker->id, 'assessment_type' => 'annual', 'status' => 'passed',
            'assessment_date' => now()->subMonth()->toDateString(), 'expiry_date' => now()->addYear()->toDateString(),
            'assessor_declared_at' => now()->subMonth(), 'staff_acknowledged_at' => now()->subMonth(),
            'controlled_drugs' => true, 'can_witness_controlled' => true,
        ]);
        // The witness is on shift at the house (presence for the attestation).
        Shift::factory()->create([
            'client_id' => Client::factory()->create(['site_id' => $this->site->id, 'service_context_id' => $this->context->id, 'status' => 'active'])->id,
            'site_id' => $this->site->id, 'service_context_id' => $this->context->id, 'user_id' => $this->witness->id,
            'starts_at' => now()->subHour(), 'ends_at' => now()->addHour(), 'actual_starts_at' => now()->subHour(),
            'status' => 'in_progress', 'started_by' => $this->witness->id, 'created_by' => $this->witness->id,
        ]);
    }

    public function test_a_count_that_does_not_match_gives_the_house_lead_a_register_follow_up(): void
    {
        $shift = $this->outgoingShift();
        $this->actingAs($this->worker)->post('/emar/handovers', [
            'shift_id' => $shift->id,
            'handover_notes' => 'Counted the CD cupboard with the incoming worker.',
            'cd_result' => 'discrepancy',
            'cd_witness_id' => $this->witness->id,
            'cd_witness_credential' => UserFactory::TEST_WITNESS_PIN,
            'cd_notes' => 'Morphine 10 mg is 2 tablets short of the register.',
            'submit' => false,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $handover = ShiftHandover::query()->where('outgoing_shift_id', $shift->id)->sole();
        $row = MedicationFollowup::query()->where('type', HandoverControlledCountFollowup::TYPE)->sole();
        $this->assertSame('cd-handover:'.$handover->id, $row->source_key);
        $this->assertSame($this->client->id, (int) $row->client_id);
        $this->assertNull($row->client_medication_id, 'The handover count is for the cupboard, not one medicine.');
        $this->assertNull($row->owner_id, 'House leads own it.');
        $this->assertNull($row->completed_at);
        $this->assertTrue($row->due_at->lessThanOrEqualTo(now()), 'A count that does not match is looked at now.');
        $this->assertContains(HandoverControlledCountFollowup::TYPE, MedicationFollowupService::LEAD_TYPES);
        $this->assertStringContainsString('2 tablets short', (string) $row->context['reason']);
        $this->assertStringContainsString($this->witness->name, (string) $row->context['reason']);
        $this->assertSame($handover->id, (int) $row->context['handover_id']);
        $this->assertSame('/emar/controlled?site_id='.$this->site->id.'&client_id='.$this->client->id, $row->context['source_url']);

        // The controlled-drug floor: a lead without controlled-drug access never sees it.
        $lead = $this->lead();
        $noControlled = $this->lead(deny: ['medications.controlled.view', 'medications.controlled.record']);
        $service = app(MedicationFollowupService::class);
        $this->assertContains($row->id, $service->visibleQuery($lead)->pluck('id')->all());
        $this->assertNotContains($row->id, $service->visibleQuery($noControlled)->pluck('id')->all());
        $this->actingAs($noControlled)->getJson('/medication-followups/'.$row->id)->assertNotFound();

        // The house lead signs it off with what they did.
        $this->actingAs($lead)->postJson('/medication-followups/'.$row->id.'/transition', [
            'action' => 'signoff', 'outcome' => 'Recounted with the lead; register discrepancy opened for Morphine.',
            'revision' => $row->revision, 'request_uuid' => (string) Str::uuid(),
        ])->assertOk();
        $this->assertNotNull($row->fresh()->completed_at);
    }

    public function test_counts_verified_raise_nothing_and_a_replacement_discrepancy_raises_one_per_handover(): void
    {
        $shift = $this->outgoingShift();
        $this->actingAs($this->worker)->post('/emar/handovers', [
            'shift_id' => $shift->id, 'handover_notes' => 'Counted together.',
            'cd_result' => 'verified', 'cd_witness_id' => $this->witness->id,
            'cd_witness_credential' => UserFactory::TEST_WITNESS_PIN, 'cd_notes' => 'All counts matched.', 'submit' => false,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(0, MedicationFollowup::query()->where('type', HandoverControlledCountFollowup::TYPE)->count());

        // Edited: the recount found a shortfall (ShiftHandoverService::applyEdit).
        $handover = ShiftHandover::query()->where('outgoing_shift_id', $shift->id)->sole();
        foreach (['Oxycodone 5 mg is 1 short.', 'Oxycodone 5 mg is 1 short; found the empty blister.'] as $notes) {
            $handover->refresh();
            $this->actingAs($this->worker)->put('/emar/handovers/'.$handover->id, [
                'handover_notes' => 'Recounted.', 'cd_result' => 'discrepancy', 'cd_witness_id' => $this->witness->id,
                'cd_witness_credential' => UserFactory::TEST_WITNESS_PIN, 'cd_notes' => $notes,
                'version' => $handover->version, 'submit' => false,
            ])->assertRedirect()->assertSessionHasNoErrors();
        }

        // One follow-up for the handover, telling the lead the latest finding.
        $row = MedicationFollowup::query()->where('type', HandoverControlledCountFollowup::TYPE)->sole();
        $this->assertSame('cd-handover:'.$handover->id, $row->source_key);
        $this->assertStringContainsString('found the empty blister', (string) $row->context['reason']);

        // Signed off, then another recount that doesn't match: a new one, not lost on the closed one.
        $lead = $this->lead();
        $this->actingAs($lead)->postJson('/medication-followups/'.$row->id.'/transition', [
            'action' => 'signoff', 'outcome' => 'Investigated with the pharmacist.',
            'revision' => $row->revision, 'request_uuid' => (string) Str::uuid(),
        ])->assertOk();
        $handover->refresh();
        $this->actingAs($this->worker)->put('/emar/handovers/'.$handover->id, [
            'handover_notes' => 'Recounted again.', 'cd_result' => 'discrepancy', 'cd_witness_id' => $this->witness->id,
            'cd_witness_credential' => UserFactory::TEST_WITNESS_PIN, 'cd_notes' => 'Now 2 short.',
            'version' => $handover->version, 'submit' => false,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $open = MedicationFollowup::query()->where('type', HandoverControlledCountFollowup::TYPE)->whereNull('completed_at')->sole();
        $this->assertSame('cd-handover:'.$handover->id.':2', $open->source_key);
        $this->assertStringContainsString('Now 2 short.', (string) $open->context['reason']);
    }

    private function outgoingShift(): Shift
    {
        return Shift::factory()->create([
            'client_id' => $this->client->id, 'site_id' => $this->site->id, 'service_context_id' => $this->context->id,
            'user_id' => $this->worker->id, 'starts_at' => now()->subHours(4), 'ends_at' => now()->subMinutes(15),
            'actual_starts_at' => now()->subHours(4), 'status' => 'in_progress',
            'started_by' => $this->worker->id, 'created_by' => $this->worker->id,
        ]);
    }

    /** @param list<string> $deny */
    private function lead(array $deny = []): User
    {
        $lead = $this->staff('team_lead', ['medications.view', 'medications.administer.record', 'medications.followups.manage', 'clients.viewAny',
            'medications.controlled.view', 'medications.controlled.record'], $deny);

        return $lead->fresh();
    }

    /**
     * @param  list<string>  $grant
     * @param  list<string>  $deny
     */
    private function staff(string $role, array $grant, array $deny = []): User
    {
        $user = User::factory()->create(['role' => $role, 'approved_at' => now()]);
        if ($roleId = Role::query()->where('name', $role)->value('id')) {
            $user->roles()->syncWithoutDetaching([$roleId]);
        }
        $user->permissionOverrides()->syncWithoutDetaching(
            Permission::query()->whereIn('key', array_diff($grant, $deny))->pluck('id')->mapWithKeys(fn (int $id) => [$id => ['allowed' => true]])->all()
            + Permission::query()->whereIn('key', $deny)->pluck('id')->mapWithKeys(fn (int $id) => [$id => ['allowed' => false]])->all(),
        );
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id, 'primary_site_id' => $this->site->id, 'secondary_site_ids' => [], 'is_active' => true,
            'start_date' => now()->subMonth()->toDateString(), 'end_date' => null,
        ]);

        return $user;
    }
}
