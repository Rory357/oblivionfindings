<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\MedicationSelfAdminAssessment;
use App\Models\MedicationSupportAgreement;
use App\Models\MedicationSupportChange;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Medication\Audit\MedicationEventRecorder;
use App\Services\Medication\DoseSlots\DoseSlotGenerator;
use App\Services\Medication\DoseSlots\DoseSlotProjection;
use App\Services\Medication\DoseSlots\DoseSlotReaderScope;
use App\Services\Medication\Support\MedicationSupport;
use App\Services\Medication\Support\SupportFollowupAdapter;
use App\Services\Medication\Support\SupportTime;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use Tests\TestCase;

/** Core P03 domain tests; canonical P08a adapter is mocked until Main integrates its prerequisite. */
class MedicationSupportWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private Client $person;

    private ClientMedication $medicine;

    private Site $site;

    private $followups;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-03 07:00:00', 'Pacific/Auckland')->utc());
        $this->seed(RbacSeeder::class);
        $this->site = Site::factory()->create(['type' => 'house', 'is_active' => true]);
        $this->actor = $this->staff();
        $this->person = Client::factory()->create(['site_id' => $this->site->id, 'status' => 'active']);
        $this->followups = Mockery::mock(SupportFollowupAdapter::class);
        $this->followups->shouldReceive('open')->andReturn(collect())->byDefault();
        $this->followups->shouldReceive('request')->andReturnNull()->byDefault();
        $this->followups->shouldReceive('completed')->andReturnNull()->byDefault();
        $this->app->instance(SupportFollowupAdapter::class, $this->followups);
        $this->medicine = ClientMedication::factory()->create([
            'client_id' => $this->person->id, 'name' => 'Synthetic vitamin', 'controlled_drug' => false,
            'active' => true, 'state' => 'active', 'is_prn' => false, 'approval_status' => 'verified',
            'start_date' => '2026-10-02', 'end_date' => null, 'dose_times' => ['08:00'], 'frequency' => 'Once daily',
        ]);
    }

    private function staff(): User
    {
        $user = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
        $user->roles()->syncWithoutDetaching([Role::query()->where('name', 'admin')->value('id')]);
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $this->site->id, 'start_date' => today()->subMonth(), 'end_date' => null, 'is_active' => true]);

        return $user;
    }

    private function payload(string $mode = 'staff_given'): array
    {
        return [
            'client_id' => $this->person->id, 'wishes_to_self_administer' => true, 'people_involved' => ['The person'],
            'cognitive_capacity' => 5, 'physical_dexterity' => 5, 'vision_ability' => 5, 'swallowing_ability' => 5, 'understanding_score' => 5,
            'can_identify_medications' => true, 'can_read_labels' => true, 'can_open_packaging' => true, 'can_manage_timing' => true, 'can_store_safely' => true, 'willing_to_self_admin' => true,
            'storage_location' => 'own_drawer', 'reassessment_interval_months' => 12, 'confirmed_with_person' => true,
            'confirm_loosening' => true, 'med_scope' => [['med_id' => $this->medicine->id, 'scope' => $mode]],
        ];
    }

    private function assess(string $mode = 'staff_given'): MedicationSelfAdminAssessment
    {
        $this->actingAs($this->actor)->post('/emar/self-admin', $this->payload($mode))->assertSessionHasNoErrors()->assertRedirect();

        return MedicationSelfAdminAssessment::query()->sole();
    }

    private function agree(MedicationSelfAdminAssessment $assessment): void
    {
        $witness = $this->staff();
        $this->actingAs($this->actor)->post('/emar/self-admin/'.$assessment->id.'/agreement', [
            'agreed_by_role' => 'person', 'method' => 'verbal', 'witness_id' => $witness->id,
            'ordering_responsibility' => 'person', 'person_responsibilities' => 'Takes the vitamin.',
            'staff_responsibilities' => 'Checks supply weekly.', 'confirm_loosening' => true,
        ])->assertSessionHasNoErrors()->assertRedirect();
    }

    public function test_no_assessment_person_is_in_register_with_truthful_staff_support(): void
    {
        $this->actingAs($this->actor)->get('/emar/self-admin')->assertInertia(fn (Assert $p) => $p
            ->component('emar/SelfAdmin')->has('register', 1)->where('register.0.state', 'none')
            ->where('register.0.medicines.0.mode', 'staff_given')->where('register.0.medicines.0.requested_mode', null));
    }

    public function test_pending_independence_does_not_take_effect_before_agreement(): void
    {
        $assessment = $this->assess('self_managed');
        $this->assertSame('independent', $assessment->outcome);
        $this->assertSame('staff_given', app(MedicationSupport::class)->mode($this->medicine));
        $this->actingAs($this->actor)->get('/emar/self-admin/clients/'.$this->person->id)->assertInertia(fn (Assert $p) => $p->where('support.agreement_needed', true));
        $this->agree($assessment);
        $this->assertSame('self_managed', app(MedicationSupport::class)->mode($this->medicine));
        $this->assertSame($this->person->full_name, MedicationSupportAgreement::query()->sole()->agreed_by_name);
        $this->assertDatabaseCount('medication_events', 2);
    }

    public function test_reassessment_preserves_support_and_agreement_and_keeps_earlier_record(): void
    {
        $prior = $this->assess('prompted');
        $this->agree($prior);
        $this->followups->shouldReceive('completed')->once()->withArgs(fn ($a, $actor, $id) => $a->id === $prior->id && $actor->id === $this->actor->id && $id !== $prior->id);
        $payload = $this->payload('prompted');
        $payload['supersedes_id'] = $prior->id;
        unset($payload['med_scope']);
        $this->actingAs($this->actor)->post('/emar/self-admin', $payload)->assertSessionHasNoErrors();
        $current = app(MedicationSupport::class)->current($this->person->id);
        $this->assertNotSame($prior->id, $current->id);
        $this->assertSame($prior->fresh()->support_agreement_id, $current->support_agreement_id);
        $this->assertSame('prompted', $current->med_scope[0]['scope']);
        $this->assertDatabaseCount('medication_self_admin_assessments', 2);
    }

    public function test_changed_storage_requires_new_agreement_and_staff_support_until_it_is_recorded(): void
    {
        $prior = $this->assess('self_managed');
        $this->agree($prior);
        $this->actingAs($this->actor)->post('/emar/self-admin', [...$this->payload('self_managed'), 'supersedes_id' => $prior->id, 'storage_location' => 'office'])->assertSessionHasNoErrors();
        $this->assertNull(app(MedicationSupport::class)->current($this->person->id)->support_agreement_id);
        $this->assertSame('staff_given', app(MedicationSupport::class)->mode($this->medicine));
        $this->assertDatabaseCount('medication_support_agreements', 1);
    }

    public function test_scores_and_controlled_rules_are_enforced_by_server(): void
    {
        $low = $this->payload('self_managed');
        foreach (MedicationSupport::SCORES as $key) {
            $low[$key] = 3;
        }
        $this->actingAs($this->actor)->post('/emar/self-admin', $low)->assertSessionHasErrors('med_scope');
        $this->medicine->update(['controlled_drug' => true]);
        $this->actingAs($this->actor)->post('/emar/self-admin', $this->payload('prompted'))->assertSessionHasErrors('med_scope');
        $this->assertDatabaseCount('medication_self_admin_assessments', 0);
        $this->assertDatabaseCount('medication_support_changes', 0);
    }

    public function test_existing_medicine_cannot_become_more_independent_outside_reassessment(): void
    {
        $a = $this->assess();
        $this->agree($a);
        $this->actingAs($this->actor)->put('/emar/self-admin/'.$a->id, ['med_scope' => [['med_id' => $this->medicine->id, 'scope' => 'prompted']], 'confirm_loosening' => true])->assertSessionHasErrors('med_scope');
        $this->assertSame('staff_given', app(MedicationSupport::class)->mode($this->medicine));
    }

    public function test_new_medicine_defaults_administer_and_can_be_set_within_cap_and_agreement(): void
    {
        $a = $this->assess();
        $this->agree($a);
        $new = ClientMedication::factory()->create(['client_id' => $this->person->id, 'active' => true, 'state' => 'active', 'controlled_drug' => false, 'end_date' => null]);
        $this->assertSame('staff_given', app(MedicationSupport::class)->mode($new));
        $this->actingAs($this->actor)->put('/emar/self-admin/'.$a->id, ['med_scope' => [['med_id' => $new->id, 'scope' => 'prompted']], 'confirm_loosening' => true])->assertSessionHasNoErrors();
        $this->assertSame('prompted', app(MedicationSupport::class)->mode($new));
    }

    public function test_withdrawal_is_immediate_and_independence_request_does_not_loosen_support(): void
    {
        $a = $this->assess('self_managed');
        $this->agree($a);
        $this->followups->shouldReceive('request')->twice()->withArgs(fn ($assessment, $kind, $key, $reason) => $assessment->id === $a->id && $kind === 'asked');
        $this->actingAs($this->actor)->post('/emar/self-admin/'.$a->id.'/consent', ['direction' => 'less', 'said' => 'Please give it to me.', 'occurred_at' => '2026-10-03T06:55+13:00'])->assertSessionHasNoErrors();
        $this->assertSame('staff_given', app(MedicationSupport::class)->mode($this->medicine));
        $this->actingAs($this->actor)->post('/emar/self-admin/'.$a->id.'/consent', ['direction' => 'more', 'said' => 'I want to do more.', 'occurred_at' => '2026-10-03T06:56+13:00'])->assertSessionHasNoErrors();
        $this->assertSame('staff_given', app(MedicationSupport::class)->mode($this->medicine));
        $this->assertSame('independence_requested', MedicationSupportChange::query()->latest('id')->first()->reason);
    }

    public function test_clinical_reader_without_controlled_access_can_reassess_with_hidden_scope_preserved(): void
    {
        $cd = ClientMedication::factory()->create(['client_id' => $this->person->id, 'name' => 'Concealed synthetic medicine', 'controlled_drug' => true, 'active' => true, 'state' => 'active', 'end_date' => null]);
        $a = $this->assess();
        foreach (Permission::query()->whereIn('key', ['medications.controlled.view', 'medications.controlled.record'])->pluck('id') as $id) {
            $this->actor->permissionOverrides()->syncWithoutDetaching([$id => ['allowed' => false]]);
        }
        $this->actor->unsetRelation('permissionOverrides')->unsetRelation('roles');
        $this->actingAs($this->actor)->get('/emar/self-admin/clients/'.$this->person->id)->assertInertia(fn (Assert $p) => $p->where('support.concealed_count', 1)->has('support.medicines', 1));
        $this->actingAs($this->actor)->post('/emar/self-admin', [...$this->payload(), 'supersedes_id' => $a->id])->assertSessionHasNoErrors();
        $this->assertSame('staff_given', collect(app(MedicationSupport::class)->current($this->person->id)->med_scope)->keyBy('med_id')->get($cd->id)['scope']);
        $this->actingAs($this->actor)->put('/emar/self-admin/'.app(MedicationSupport::class)->current($this->person->id)->id, ['med_scope' => [['med_id' => $cd->id, 'scope' => 'staff_given']]])->assertNotFound();
    }

    public function test_foreign_order_and_stale_assessment_cannot_mutate_support(): void
    {
        $a = $this->assess();
        $other = ClientMedication::factory()->create(['active' => true, 'state' => 'active', 'end_date' => null]);
        $this->actingAs($this->actor)->put('/emar/self-admin/'.$a->id, ['med_scope' => [['med_id' => $other->id, 'scope' => 'staff_given']]])->assertNotFound();
        $this->actingAs($this->actor)->post('/emar/self-admin', [...$this->payload(), 'supersedes_id' => $a->id])->assertSessionHasNoErrors();
        $this->actingAs($this->actor)->put('/emar/self-admin/'.$a->id, ['med_scope' => [['med_id' => $this->medicine->id, 'scope' => 'staff_given']]])->assertStatus(409);
    }

    public function test_signed_form_and_independent_witness_are_required(): void
    {
        Storage::fake('private');
        $a = $this->assess('prompted');
        $base = ['agreed_by_role' => 'guardian', 'agreed_by_name' => 'Synthetic guardian', 'ordering_responsibility' => 'service', 'person_responsibilities' => 'Takes medicine.', 'staff_responsibilities' => 'Prompts.', 'confirm_loosening' => true];
        $this->actingAs($this->actor)->post('/emar/self-admin/'.$a->id.'/agreement', [...$base, 'method' => 'signed'])->assertSessionHasErrors('attachment');
        $this->actingAs($this->actor)->post('/emar/self-admin/'.$a->id.'/agreement', [...$base, 'method' => 'verbal', 'witness_id' => $this->actor->id])->assertNotFound();
        $this->assertDatabaseCount('medication_support_agreements', 0);
    }

    public function test_self_managed_slot_is_informational_and_withdrawal_makes_todays_owed_slot_actionable(): void
    {
        $a = $this->assess('self_managed');
        $this->agree($a);
        $this->travelTo(Carbon::parse('2026-10-03 10:00', 'Pacific/Auckland')->utc());
        DB::transaction(fn () => app(DoseSlotGenerator::class)->generateAhead($this->medicine));
        $scope = DoseSlotReaderScope::internal([$this->person->id]);
        $projection = app(DoseSlotProjection::class);
        $rows = $projection->rows($scope, '2026-10-03', '2026-10-03', CarbonImmutable::now('UTC'));
        $this->assertSame('self_managed', $rows->sole()['state']);
        $this->assertSame(0, $projection->totals($scope, '2026-10-03', '2026-10-03', CarbonImmutable::now('UTC'))['due']);
        $this->actingAs($this->actor)->post('/emar/self-admin/'.$a->id.'/consent', ['direction' => 'less', 'said' => 'Staff please.', 'occurred_at' => '2026-10-03T09:59+13:00'])->assertSessionHasNoErrors();
        $this->assertSame('late', $projection->rows($scope, '2026-10-03', '2026-10-03', CarbonImmutable::now('UTC'))->sole()['state']);
    }

    public function test_canonical_model_refuses_fabricated_self_managed_refusal(): void
    {
        $a = $this->assess('self_managed');
        $this->agree($a);
        $this->expectException(ValidationException::class);
        ClientMedicationAdministration::create(['client_id' => $this->person->id, 'client_medication_id' => $this->medicine->id, 'status' => 'refused', 'administered_at' => now(), 'administered_by' => $this->actor->id]);
    }

    public function test_audit_failure_rolls_back_assessment_and_support_changes(): void
    {
        $recorder = Mockery::mock(MedicationEventRecorder::class);
        $recorder->shouldReceive('append')->once()->andThrow(new \RuntimeException('Synthetic audit failure'));
        $this->app->instance(MedicationEventRecorder::class, $recorder);
        $this->withoutExceptionHandling();
        try {
            $this->actingAs($this->actor)->post('/emar/self-admin', $this->payload());
            $this->fail('Expected audit failure');
        } catch (\RuntimeException $error) {
            $this->assertSame('Synthetic audit failure', $error->getMessage());
        }
        $this->assertDatabaseCount('medication_self_admin_assessments', 0);
        $this->assertDatabaseCount('medication_support_changes', 0);
    }

    public function test_nz_dst_gap_and_ambiguous_minute_are_not_silently_shifted(): void
    {
        foreach (['2026-09-27T02:30', '2026-04-05T02:30'] as $input) {
            try {
                SupportTime::parse($input);
                $this->fail('Expected explicit time correction');
            } catch (ValidationException $error) {
                $this->assertArrayHasKey('occurred_at', $error->errors());
            }
        }
        $this->assertSame(3600, SupportTime::parse('2026-04-05T02:30+12:00')->getTimestamp() - SupportTime::parse('2026-04-05T02:30+13:00')->getTimestamp());
    }
}
