<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\MedicationDoseSlot;
use App\Models\MedicationError;
use App\Models\MedicationFollowup;
use App\Models\MedicationFollowupEvent;
use App\Models\MedicationIdempotencyResult;
use App\Models\MedicationSelfAdminAssessment;
use App\Models\MedicationSupportAgreement;
use App\Models\MedicationSupportChange;
use App\Models\MedicationSupportTriggerOutbox;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Services\Medication\Audit\MedicationEventRecorder;
use App\Services\Medication\DoseSlots\DoseSlotGenerator;
use App\Services\Medication\DoseSlots\DoseSlotProjection;
use App\Services\Medication\DoseSlots\DoseSlotReaderScope;
use App\Services\Medication\Support\MedicationSupport;
use App\Services\Medication\Support\SupportFollowupAdapter;
use App\Services\Medication\Support\SupportReviewDelivery;
use App\Services\Medication\Support\SupportTime;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use Tests\Support\CommittedFixtureCleanup;
use Tests\TestCase;

/** P03 policy regressions plus explicit real P08a batch integration and atomic rollback. */
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
        $this->followups->shouldReceive('openForClients')->andReturn(collect())->byDefault();
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

    private function mixedSupport(): array
    {
        $assessment = $this->assess('self_managed');
        $this->agree($assessment);
        $controlled = ClientMedication::factory()->create([
            'client_id' => $this->person->id, 'name' => 'Concealed synthetic medicine', 'controlled_drug' => true,
            'active' => true, 'state' => 'active', 'approval_status' => 'verified', 'start_date' => '2026-10-02', 'end_date' => null,
        ]);
        $this->actingAs($this->actor)->put('/emar/self-admin/'.$assessment->id, [
            'med_scope' => [['med_id' => $controlled->id, 'scope' => 'assisted']], 'confirm_loosening' => true,
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('self_managed', app(MedicationSupport::class)->mode($this->medicine));
        $this->assertSame('assisted', app(MedicationSupport::class)->mode($controlled));

        return [$assessment, $controlled];
    }

    private function denyPermission(string $key): void
    {
        $id = Permission::query()->where('key', $key)->sole()->id;
        $this->actor->permissionOverrides()->syncWithoutDetaching([$id => ['allowed' => false]]);
        $this->actor->unsetRelation('permissionOverrides')->unsetRelation('roles');
    }

    private function supportSnapshot(): array
    {
        $snapshot = [];
        foreach (['client_medications', 'medication_self_admin_assessments', 'medication_support_agreements',
            'medication_support_changes', 'medication_followups', 'medication_followup_events',
            'medication_support_trigger_outbox', 'medication_idempotency_results', 'medication_events'] as $table) {
            $snapshot[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }
        $snapshot['medication_event_heads'] = DB::table('medication_event_heads')->orderBy('site_id')->get()->map(fn ($row) => (array) $row)->all();

        return $snapshot;
    }

    private function signedAgreementPayload(string $uuid): array
    {
        $file = UploadedFile::fake()->createWithContent('agreement.pdf', "%PDF-1.4\nSynthetic support agreement.\n");
        $file->mimeTypeToReport = 'application/pdf';

        return [
            'client_request_uuid' => $uuid, 'agreed_by_role' => 'person', 'method' => 'signed', 'attachment' => $file,
            'ordering_responsibility' => 'person', 'person_responsibilities' => 'Takes vitamin.',
            'staff_responsibilities' => 'Checks supply.', 'confirm_loosening' => true,
        ];
    }

    private function spyPrivateWrites(array &$paths, int $writes = 1)
    {
        $disk = Storage::fake('private');
        $disk->put('unrelated.txt', 'Keep this existing file.');
        $spy = Mockery::mock($disk);
        $spy->shouldReceive('putFileAs')->times($writes)->andReturnUsing(function (...$args) use ($disk, &$paths) {
            $path = $disk->putFileAs(...$args);
            $paths[] = $path;

            return $path;
        });
        Storage::getFacadeRoot()->set('private', $spy);

        return $disk;
    }

    private function agreementDeadlock(): QueryException
    {
        return new QueryException('mysql', 'select 1', [], new \PDOException(
            'SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock', 40001,
        ));
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
        $this->medicine->forceFill(['controlled_drug' => true])->saveQuietly(); // Synthetic verified controlled fixture, not an order-edit workflow.
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

    public function test_concealed_controlled_consent_is_denied_for_the_entire_chosen_set_without_side_effects(): void
    {
        [$assessment, $controlled] = $this->mixedSupport();
        $foreign = ClientMedication::factory()->create(['active' => true, 'state' => 'active', 'controlled_drug' => false, 'end_date' => null]);
        $this->denyPermission('medications.controlled.view');
        $this->followups->shouldNotReceive('request');
        $before = $this->supportSnapshot();
        foreach (['less', 'more'] as $direction) {
            foreach ([[], ['client_medication_id' => null], ['client_medication_id' => $controlled->id],
                ['client_medication_id' => $foreign->id], ['client_medication_id' => $foreign->id + 100000]] as $selection) {
                $response = $this->actingAs($this->actor)->postJson('/emar/self-admin/'.$assessment->id.'/consent', [
                    ...$selection, 'direction' => $direction, 'said' => 'Please change my support.',
                    'occurred_at' => '2026-10-03T06:59+13:00', 'client_request_uuid' => (string) Str::uuid(),
                ])->assertNotFound();
                $this->assertStringNotContainsString($controlled->name, $response->getContent());
                $this->assertSame($before, $this->supportSnapshot());
            }
        }
        $this->actingAs($this->actor)->postJson('/emar/self-admin/'.$assessment->id.'/consent', [
            'said' => 'No direction selected.', 'occurred_at' => '2026-10-03T06:59+13:00',
        ])->assertUnprocessable()->assertJsonValidationErrors('direction');
        $this->assertSame($before, $this->supportSnapshot());
    }

    public function test_explicit_ordinary_consent_preserves_concealed_controlled_support_and_replay(): void
    {
        [$assessment, $controlled] = $this->mixedSupport();
        $this->denyPermission('medications.controlled.view');
        $controlledBefore = $controlled->fresh()->getRawOriginal();
        $changesBefore = MedicationSupportChange::query()->where('client_medication_id', $controlled->id)->get()->map->getRawOriginal()->all();
        $scopeBefore = collect($assessment->fresh()->med_scope)->keyBy('med_id')->get($controlled->id);
        $this->followups->shouldReceive('request')->twice();
        foreach (['more' => 'self_managed', 'less' => 'staff_given'] as $direction => $expectedMode) {
            $data = ['client_medication_id' => $this->medicine->id, 'direction' => $direction, 'said' => 'Please change vitamin support.',
                'occurred_at' => '2026-10-03T06:59+13:00', 'client_request_uuid' => (string) Str::uuid()];
            $this->actingAs($this->actor)->postJson('/emar/self-admin/'.$assessment->id.'/consent', $data)->assertOk()->assertJsonPath('sync.status', 'processed');
            $this->actingAs($this->actor)->postJson('/emar/self-admin/'.$assessment->id.'/consent', $data)->assertOk()->assertJsonPath('sync.status', 'duplicate');
            $this->assertSame($expectedMode, app(MedicationSupport::class)->mode($this->medicine));
            $this->assertSame('assisted', app(MedicationSupport::class)->mode($controlled));
            $this->assertSame($controlledBefore, $controlled->fresh()->getRawOriginal());
            $this->assertSame($changesBefore, MedicationSupportChange::query()->where('client_medication_id', $controlled->id)->get()->map->getRawOriginal()->all());
            $this->assertSame($scopeBefore, collect($assessment->fresh()->med_scope)->keyBy('med_id')->get($controlled->id));
        }
        $this->assertSame(2, MedicationSupportChange::query()->where('client_medication_id', $this->medicine->id)
            ->whereIn('reason', ['consent_withdrawn', 'independence_requested'])->count());
        $this->assertDatabaseCount('medication_idempotency_results', 2);
    }

    public function test_visible_controlled_consent_retains_its_existing_view_permission_contract(): void
    {
        [$assessment, $controlled] = $this->mixedSupport();
        $this->denyPermission('medications.controlled.record');
        $this->assertTrue($this->actor->canDo('medications.controlled.view'));
        $this->assertFalse($this->actor->canDo('medications.controlled.record'));
        $this->followups->shouldReceive('request')->times(4);
        foreach (['more' => 'assisted', 'less' => 'staff_given'] as $direction => $mode) {
            $this->actingAs($this->actor)->postJson('/emar/self-admin/'.$assessment->id.'/consent', [
                'client_medication_id' => $controlled->id, 'direction' => $direction, 'said' => 'Please change my support.',
                'occurred_at' => '2026-10-03T06:59+13:00',
            ])->assertOk();
            $this->assertSame($mode, app(MedicationSupport::class)->mode($controlled));
            $this->assertSame('self_managed', app(MedicationSupport::class)->mode($this->medicine));
        }
        foreach (['more' => 'self_managed', 'less' => 'staff_given'] as $direction => $mode) {
            $this->actingAs($this->actor)->postJson('/emar/self-admin/'.$assessment->id.'/consent', [
                'direction' => $direction, 'said' => 'Please change all my support.', 'occurred_at' => '2026-10-03T06:59+13:00',
            ])->assertOk();
            $this->assertSame($mode, app(MedicationSupport::class)->mode($this->medicine));
            $this->assertSame('staff_given', app(MedicationSupport::class)->mode($controlled));
        }
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

    public function test_legacy_slot_evidence_stays_informational_without_an_automatic_staff_give_instruction(): void
    {
        $this->travelTo(Carbon::parse('2026-10-03 10:00', 'Pacific/Auckland')->utc());
        foreach (['2026-10-02', '2026-10-03', '2026-10-04'] as $date) {
            MedicationDoseSlot::query()->updateOrCreate([
                'client_medication_id' => $this->medicine->id, 'nz_date' => $date, 'ordered_time' => '08:00',
            ], [
                'client_id' => $this->person->id,
                'due_at' => Carbon::parse($date.' 08:00', 'Pacific/Auckland')->utc(),
                'self_managed' => true, 'generated_at' => now(), 'reconstructed' => true,
            ]);
        }
        $support = app(MedicationSupport::class);
        $this->assertSame('self_managed', $support->mode($this->medicine));
        $summary = $support->summary($this->person, $this->actor);
        $this->assertSame('self_managed', $summary['medicines'][0]['mode']);
        $this->assertTrue($summary['legacy_review_required']);
        $this->assertNull($summary['agreement']);
        $rows = app(DoseSlotProjection::class)->rows(DoseSlotReaderScope::internal([$this->person->id]),
            '2026-10-02', '2026-10-04', CarbonImmutable::now('UTC'))->keyBy('nz_date');
        $this->assertSame('self_managed', $rows['2026-10-02']['state']);
        $this->assertSame('self_managed', $rows['2026-10-02']['support_mode']);
        $this->assertSame('self_managed', $rows['2026-10-03']['state']);
        $this->assertSame('self_managed', $rows['2026-10-03']['support_mode']);
        $this->assertSame('self_managed', $rows['2026-10-04']['state']);
        $this->assertSame('self_managed', $rows['2026-10-04']['support_mode']);
        // Opposite alias case: stored flags remain true but explicit withdrawal
        // produces the computed staff mode for today's/future owed doses.
        $legacy = MedicationSelfAdminAssessment::query()->create([
            ...$this->payload('self_managed'), 'status' => 'completed', 'outcome' => 'independent',
            'assessment_date' => '2026-10-02', 'assessed_by' => $this->actor->id,
            'agreement_signed_at' => now()->subDay(), 'agreement_signed_by' => $this->actor->id,
        ]);
        $this->actingAs($this->actor)->post('/emar/self-admin/'.$legacy->id.'/consent', [
            'direction' => 'less', 'said' => 'Staff please.', 'occurred_at' => '2026-10-03T09:59+13:00',
        ])->assertSessionHasNoErrors();
        $this->assertSame(3, MedicationDoseSlot::query()->where('self_managed', true)->count());
        $rows = app(DoseSlotProjection::class)->rows(DoseSlotReaderScope::internal([$this->person->id]),
            '2026-10-02', '2026-10-04', CarbonImmutable::now('UTC'))->keyBy('nz_date');
        $this->assertSame('self_managed', $rows['2026-10-02']['state']);
        $this->assertSame('late', $rows['2026-10-03']['state']);
        $this->assertSame('staff_given', $rows['2026-10-03']['support_mode']);
        $this->assertSame('not_due', $rows['2026-10-04']['state']);
        $this->assertSame('staff_given', $rows['2026-10-04']['support_mode']);
        $this->assertSame('staff_given', $support->mode($this->medicine));
    }

    public function test_existing_approved_per_medicine_plan_is_preserved_and_marked_for_formal_review(): void
    {
        $legacy = MedicationSelfAdminAssessment::query()->create([
            ...$this->payload('self_managed'), 'status' => 'completed', 'outcome' => 'independent',
            'assessment_date' => '2026-10-02', 'assessed_by' => $this->actor->id,
            'agreement_signed_at' => now()->subDay(), 'agreement_signed_by' => $this->actor->id,
            'ordering_responsibility' => 'self', 'agreement_responsibilities' => 'Existing recorded support.',
        ]);
        $legacyScopeBefore = $legacy->fresh()->med_scope;
        $this->travelTo(Carbon::parse('2026-10-03 10:00', 'Pacific/Auckland')->utc());
        DB::transaction(fn () => app(DoseSlotGenerator::class)->generateAhead($this->medicine));
        $support = app(MedicationSupport::class);
        $summary = $support->summary($this->person, $this->actor);
        $this->assertSame('self_managed', $summary['medicines'][0]['mode']);
        $this->assertTrue($summary['legacy_review_required']);
        $this->assertTrue($summary['agreement_needed']);
        $this->assertNull($summary['agreement']);
        $this->assertSame('reassess', $summary['state']);
        // Raw flag false -> computed mode true: the outer state CASE must read
        // the projected alias, never the stored slot flag.
        $this->assertFalse(MedicationDoseSlot::query()->where('nz_date', '2026-10-03')->sole()->self_managed);
        $this->assertSame('self_managed', app(DoseSlotProjection::class)->rows(DoseSlotReaderScope::internal([$this->person->id]), '2026-10-03', '2026-10-03', CarbonImmutable::now('UTC'))->sole()['state']);
        $newOrder = ClientMedication::factory()->create(['client_id' => $this->person->id, 'controlled_drug' => false, 'state' => 'active', 'active' => true, 'approval_status' => 'verified']);
        $this->assertSame('staff_given', $support->mode($newOrder));
        $this->assertDatabaseCount('medication_support_changes', 0);
        $this->assertDatabaseCount('medication_support_agreements', 0);
        try {
            ClientMedicationAdministration::create(['client_id' => $this->person->id, 'client_medication_id' => $this->medicine->id,
                'status' => 'given', 'administered_at' => now(), 'administered_by' => $this->actor->id]);
            $this->fail('A retained Self-managed medicine must not become a staff dose.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('status', $error->errors());
        }
        $this->assertDatabaseCount('client_medication_administrations', 0);
        $this->assertSame('self_managed', $support->mode($this->medicine));
        $this->assertSame($legacyScopeBefore, $legacy->fresh()->med_scope);
    }

    public function test_unchanged_legacy_support_survives_reassessment_until_real_agreement_or_explicit_withdrawal(): void
    {
        $legacy = MedicationSelfAdminAssessment::query()->create([
            ...$this->payload('self_managed'), 'status' => 'completed', 'outcome' => 'independent',
            'assessment_date' => '2026-10-02', 'assessed_by' => $this->actor->id,
            'agreement_signed_at' => now()->subDay(), 'agreement_signed_by' => $this->actor->id,
        ]);
        $this->actingAs($this->actor)->post('/emar/self-admin', [...$this->payload('self_managed'), 'supersedes_id' => $legacy->id])->assertSessionHasNoErrors();
        $current = app(MedicationSupport::class)->current((int) $this->person->id);
        $this->assertSame('self_managed', app(MedicationSupport::class)->mode($this->medicine));
        $this->assertSame('legacy_support_retained', MedicationSupportChange::query()->sole()->reason);
        $this->assertSame('self_managed', MedicationSupportChange::query()->sole()->previous_mode);
        $this->assertTrue(app(MedicationSupport::class)->summary($this->person, $this->actor)['legacy_review_required']);
        $this->assertDatabaseCount('medication_support_agreements', 0);
        $this->agree($current);
        $this->assertFalse(app(MedicationSupport::class)->summary($this->person, $this->actor)['legacy_review_required']);
        $this->assertSame('self_managed', app(MedicationSupport::class)->mode($this->medicine));
        $this->actingAs($this->actor)->post('/emar/self-admin/'.$current->id.'/consent', [
            'direction' => 'less', 'said' => 'Staff please.', 'occurred_at' => '2026-10-03T06:59+13:00',
        ])->assertSessionHasNoErrors();
        $this->assertSame('staff_given', app(MedicationSupport::class)->mode($this->medicine));
        $this->assertSame($legacy->agreement_signed_at->toIso8601String(), $legacy->fresh()->agreement_signed_at->toIso8601String());
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
        $recorder->shouldReceive('appendMany')->once()->andThrow(new \RuntimeException('Synthetic audit failure'));
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

    public function test_assessment_retry_is_durable_and_changed_retry_is_rejected(): void
    {
        $data = [...$this->payload(), 'client_request_uuid' => (string) Str::uuid()];
        $this->actingAs($this->actor)->post('/emar/self-admin', $data)->assertSessionHasNoErrors();
        $this->actingAs($this->actor)->post('/emar/self-admin', $data)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('medication_self_admin_assessments', 1);
        $this->assertDatabaseCount('medication_support_changes', 1);
        $this->actingAs($this->actor)->post('/emar/self-admin', [...$data, 'wishes_to_self_administer' => false])->assertSessionHasErrors('client_request_uuid');
        $this->assertDatabaseCount('medication_self_admin_assessments', 1);
    }

    public function test_real_followups_are_idempotent_and_reassessment_closes_the_owned_batch(): void
    {
        $this->app->forgetInstance(SupportFollowupAdapter::class);
        $a = $this->assess('assisted');
        DB::transaction(function () use ($a) {
            Client::query()->whereKey($this->person->id)->lockForUpdate()->firstOrFail();
            foreach (['hospital', 'hospital', 'decline'] as $kind) {
                app(MedicationSupport::class)->trigger($a, $kind, 'synthetic-'.$kind, 'Reassess support.');
            }
        });
        $this->assertDatabaseCount('medication_followups', 2);
        $this->actingAs($this->actor)->get('/emar/self-admin/clients/'.$this->person->id)->assertInertia(fn (Assert $p) => $p->where('support.state', 'reassess')->has('support.reviews', 2));
        $data = [...$this->payload('assisted'), 'supersedes_id' => $a->id, 'client_request_uuid' => (string) Str::uuid()];
        $this->actingAs($this->actor)->post('/emar/self-admin', $data)->assertSessionHasNoErrors();
        $this->actingAs($this->actor)->post('/emar/self-admin', $data)->assertSessionHasNoErrors();
        $this->assertSame(2, MedicationFollowup::query()->whereNotNull('completed_at')->count());
        $this->assertSame(2, MedicationFollowupEvent::query()->where('action', 'source_completed')->count());
        $this->assertDatabaseCount('medication_self_admin_assessments', 2);
    }

    public function test_real_followup_closure_rolls_back_with_the_assessment_on_audit_failure(): void
    {
        $this->app->forgetInstance(SupportFollowupAdapter::class);
        $a = $this->assess();
        DB::transaction(function () use ($a) {
            Client::query()->whereKey($this->person->id)->lockForUpdate()->firstOrFail();
            app(MedicationSupport::class)->trigger($a, 'asked', 'synthetic-request', 'Reassess support.');
        });
        $recorder = Mockery::mock(MedicationEventRecorder::class);
        $recorder->shouldReceive('appendMany')->once()->andThrow(new \RuntimeException('Synthetic batch failure'));
        $this->app->instance(MedicationEventRecorder::class, $recorder);
        $this->withoutExceptionHandling();
        try {
            $this->actingAs($this->actor)->post('/emar/self-admin', [...$this->payload(), 'supersedes_id' => $a->id]);
            $this->fail('Expected atomic audit failure');
        } catch (\RuntimeException $error) {
            $this->assertSame('Synthetic batch failure', $error->getMessage());
        }
        $this->assertDatabaseCount('medication_self_admin_assessments', 1);
        $this->assertSame(0, MedicationFollowup::query()->whereNotNull('completed_at')->count());
        $this->assertSame(0, MedicationFollowupEvent::query()->where('action', 'source_completed')->count());
    }

    public function test_archiving_keeps_the_record_and_immediately_restores_staff_support(): void
    {
        $a = $this->assess('self_managed');
        $this->agree($a);
        $this->actingAs($this->actor)->delete('/emar/self-admin/'.$a->id)->assertSessionHasNoErrors();
        $this->assertSoftDeleted('medication_self_admin_assessments', ['id' => $a->id]);
        $this->assertSame('staff_given', app(MedicationSupport::class)->mode($this->medicine));
        $this->assertNull(app(MedicationSupport::class)->current($this->person->id));
    }

    public function test_queued_consent_json_replay_does_not_duplicate_clinical_changes(): void
    {
        $a = $this->assess('self_managed');
        $this->agree($a);
        $data = ['direction' => 'less', 'said' => 'Staff please.', 'occurred_at' => '2026-10-03T06:59+13:00',
            'client_request_uuid' => (string) Str::uuid(), 'queued_offline' => true];
        $this->actingAs($this->actor)->postJson('/emar/self-admin/'.$a->id.'/consent', $data)->assertOk()->assertJsonPath('sync.status', 'processed');
        $this->actingAs($this->actor)->postJson('/emar/self-admin/'.$a->id.'/consent', $data)->assertOk()->assertJsonPath('sync.status', 'duplicate');
        $this->assertSame(1, MedicationSupportChange::query()->where('reason', 'consent_withdrawn')->count());
        $this->assertSame('staff_given', app(MedicationSupport::class)->mode($this->medicine));
    }

    public function test_signed_agreement_stores_once_across_outer_deadlock_retry_and_ordinary_replay(): void
    {
        $this->beforeApplicationDestroyed(CommittedFixtureCleanup::capture()->restore(...));
        $assessment = $this->assess('self_managed');
        $paths = [];
        $disk = $this->spyPrivateWrites($paths);
        $realRecorder = app(MedicationEventRecorder::class);
        $attempts = 0;
        $recorder = Mockery::mock(MedicationEventRecorder::class);
        $recorder->shouldReceive('appendMany')->twice()->andReturnUsing(function (array $events) use ($realRecorder, &$attempts): array {
            if (++$attempts === 1) {
                throw $this->agreementDeadlock();
            }

            return $realRecorder->appendMany($events);
        });
        $this->app->instance(MedicationEventRecorder::class, $recorder);
        $uuid = (string) Str::uuid();
        DB::commit(); // Exercise the controller's outer retry, outside RefreshDatabase's enclosing transaction.

        $this->actingAs($this->actor)->post('/emar/self-admin/'.$assessment->id.'/agreement', $this->signedAgreementPayload($uuid))
            ->assertSessionHasNoErrors()->assertRedirect();
        $agreement = MedicationSupportAgreement::query()->sole();
        $this->assertSame(2, $attempts);
        $this->assertSame([$agreement->attachment_path], $paths);
        $this->assertSame($this->actor->id, $agreement->recorded_by);
        $this->assertSame($assessment->id, $agreement->assessment_id);
        $this->assertSame($agreement->id, $assessment->fresh()->support_agreement_id);
        $this->assertSame('self_managed', app(MedicationSupport::class)->mode($this->medicine));
        $this->assertSame(1, MedicationSupportChange::query()->where('reason', 'agreement_recorded')->count());
        $receipt = MedicationIdempotencyResult::query()->sole();
        $this->assertSame($uuid, $receipt->request_uuid);
        $this->assertSame($agreement->id, $receipt->response_payload['agreement_id']);
        $this->assertNull($receipt->expires_at);
        $this->assertSame(1, DB::table('medication_events')->where('kind', 'support.agreement_recorded')->count());
        $this->assertSame("%PDF-1.4\nSynthetic support agreement.\n", $disk->get($agreement->attachment_path));
        $before = $this->supportSnapshot();
        $files = $disk->allFiles();

        $this->actingAs($this->actor)->post('/emar/self-admin/'.$assessment->id.'/agreement', $this->signedAgreementPayload($uuid))
            ->assertSessionHasNoErrors()->assertRedirect();
        $changed = $this->signedAgreementPayload($uuid);
        $changed['person_responsibilities'] = 'Different support details.';
        $this->actingAs($this->actor)->post('/emar/self-admin/'.$assessment->id.'/agreement', $changed)
            ->assertSessionHasErrors('client_request_uuid');
        $this->assertSame($before, $this->supportSnapshot());
        $this->assertSame($files, $disk->allFiles());
        $this->assertSame([$agreement->attachment_path], $paths);
        $this->assertSame('Keep this existing file.', $disk->get('unrelated.txt'));
    }

    public function test_exhausted_agreement_retries_remove_only_the_once_staged_attachment(): void
    {
        $this->beforeApplicationDestroyed(CommittedFixtureCleanup::capture()->restore(...));
        $assessment = $this->assess('self_managed');
        $paths = [];
        $disk = $this->spyPrivateWrites($paths);
        $attempts = 0;
        $recorder = Mockery::mock(MedicationEventRecorder::class);
        $recorder->shouldReceive('appendMany')->times(5)->andReturnUsing(function () use (&$attempts): void {
            $attempts++;
            throw $this->agreementDeadlock();
        });
        $this->app->instance(MedicationEventRecorder::class, $recorder);
        $before = $this->supportSnapshot();
        DB::commit();
        $this->withoutExceptionHandling();
        try {
            $this->actingAs($this->actor)->post('/emar/self-admin/'.$assessment->id.'/agreement', $this->signedAgreementPayload((string) Str::uuid()));
            $this->fail('Expected the fifth deadlock to terminate the agreement request.');
        } catch (DeadlockException $error) {
            $this->assertStringContainsString('1213 Deadlock found', $error->getMessage());
        }
        $this->assertSame(5, $attempts);
        $this->assertCount(1, $paths);
        $this->assertFalse($disk->exists($paths[0]));
        $this->assertSame(['unrelated.txt'], $disk->allFiles());
        $this->assertSame($before, $this->supportSnapshot());
    }

    public function test_agreement_retry_rechecks_current_authority_and_cleans_its_staged_bytes(): void
    {
        $this->beforeApplicationDestroyed(CommittedFixtureCleanup::capture()->restore(...));
        $assessment = $this->assess('self_managed');
        $paths = [];
        $disk = $this->spyPrivateWrites($paths);
        $attempts = 0;
        $revoked = false;
        $recorder = Mockery::mock(MedicationEventRecorder::class);
        $recorder->shouldReceive('appendMany')->once()->andReturnUsing(function () use (&$attempts): void {
            $attempts++;
            throw $this->agreementDeadlock();
        });
        $this->app->instance(MedicationEventRecorder::class, $recorder);
        DB::connection()->beforeStartingTransaction(function ($connection) use (&$attempts, &$revoked): void {
            if ($connection->transactionLevel() === 0 && $attempts === 1 && ! $revoked) {
                $revoked = true;
                $this->denyPermission('medications.orders.manage');
            }
        });
        $before = $this->supportSnapshot();
        DB::commit();

        $this->actingAs($this->actor)->post('/emar/self-admin/'.$assessment->id.'/agreement', $this->signedAgreementPayload((string) Str::uuid()))
            ->assertForbidden();
        $this->assertTrue($revoked);
        $this->assertSame(1, $attempts);
        $this->assertCount(1, $paths);
        $this->assertFalse($disk->exists($paths[0]));
        $this->assertSame(['unrelated.txt'], $disk->allFiles());
        $this->assertSame($before, $this->supportSnapshot());
    }

    public function test_agreement_retry_rechecks_the_current_assessment_and_cleans_its_staged_bytes(): void
    {
        $this->beforeApplicationDestroyed(CommittedFixtureCleanup::capture()->restore(...));
        $assessment = $this->assess('self_managed');
        $paths = [];
        $disk = $this->spyPrivateWrites($paths);
        $realRecorder = app(MedicationEventRecorder::class);
        $attempts = 0;
        $reassessed = false;
        $currentSnapshot = null;
        $recorder = Mockery::mock(MedicationEventRecorder::class);
        $recorder->shouldReceive('appendMany')->twice()->andReturnUsing(function (array $events) use ($realRecorder, &$attempts): array {
            if (++$attempts === 1) {
                throw $this->agreementDeadlock();
            }

            return $realRecorder->appendMany($events);
        });
        $this->app->instance(MedicationEventRecorder::class, $recorder);
        DB::connection()->beforeStartingTransaction(function ($connection) use ($assessment, &$attempts, &$reassessed, &$currentSnapshot): void {
            if ($connection->transactionLevel() === 0 && $attempts === 1 && ! $reassessed) {
                $reassessed = true;
                $this->actingAs($this->actor)->post('/emar/self-admin', [...$this->payload(), 'supersedes_id' => $assessment->id])
                    ->assertSessionHasNoErrors()->assertRedirect();
                $this->assertSame(0, $connection->transactionLevel());
                $currentSnapshot = $this->supportSnapshot();
            }
        });
        DB::commit();

        $this->actingAs($this->actor)->post('/emar/self-admin/'.$assessment->id.'/agreement', $this->signedAgreementPayload((string) Str::uuid()))
            ->assertStatus(409);
        $this->assertTrue($reassessed);
        $this->assertSame(2, $attempts);
        $this->assertNotSame($assessment->id, app(MedicationSupport::class)->current($this->person->id)->id);
        $this->assertCount(1, $paths);
        $this->assertFalse($disk->exists($paths[0]));
        $this->assertSame(['unrelated.txt'], $disk->allFiles());
        $this->assertSame($currentSnapshot, $this->supportSnapshot());
    }

    public function test_retry_replay_deletes_only_its_unused_attachment_and_preserves_the_competing_agreement(): void
    {
        $this->beforeApplicationDestroyed(CommittedFixtureCleanup::capture()->restore(...));
        $assessment = $this->assess('self_managed');
        $paths = [];
        $disk = $this->spyPrivateWrites($paths, 2);
        $realRecorder = app(MedicationEventRecorder::class);
        $attempts = 0;
        $winnerCommitted = false;
        $recorder = Mockery::mock(MedicationEventRecorder::class);
        $recorder->shouldReceive('appendMany')->twice()->andReturnUsing(function (array $events) use ($realRecorder, &$attempts): array {
            if (++$attempts === 1) {
                throw $this->agreementDeadlock();
            }

            return $realRecorder->appendMany($events);
        });
        $this->app->instance(MedicationEventRecorder::class, $recorder);
        $uuid = (string) Str::uuid();
        DB::connection()->beforeStartingTransaction(function ($connection) use ($assessment, $uuid, &$attempts, &$winnerCommitted): void {
            if ($connection->transactionLevel() === 0 && $attempts === 1 && ! $winnerCommitted) {
                $winnerCommitted = true;
                // The failed request released its locks. A second real request commits the same binding before its retry.
                $this->actingAs($this->actor)->post('/emar/self-admin/'.$assessment->id.'/agreement', $this->signedAgreementPayload($uuid))
                    ->assertSessionHasNoErrors()->assertRedirect();
                $this->assertSame(0, $connection->transactionLevel());
            }
        });
        DB::commit();

        $this->actingAs($this->actor)->post('/emar/self-admin/'.$assessment->id.'/agreement', $this->signedAgreementPayload($uuid))
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertTrue($winnerCommitted);
        $this->assertSame(2, $attempts);
        $this->assertCount(2, $paths);
        $this->assertNotSame($paths[0], $paths[1]);
        $agreement = MedicationSupportAgreement::query()->sole();
        $this->assertSame($paths[1], $agreement->attachment_path);
        $this->assertSame($this->actor->id, $agreement->recorded_by);
        $this->assertSame($assessment->id, $agreement->assessment_id);
        $this->assertSame($agreement->id, $assessment->fresh()->support_agreement_id);
        $this->assertSame('self_managed', app(MedicationSupport::class)->mode($this->medicine));
        $this->assertFalse($disk->exists($paths[0]));
        $this->assertTrue($disk->exists($paths[1]));
        $this->assertEqualsCanonicalizing(['unrelated.txt', $paths[1]], $disk->allFiles());
        $this->assertSame("%PDF-1.4\nSynthetic support agreement.\n", $disk->get($paths[1]));
        $this->assertSame(1, MedicationSupportChange::query()->where('reason', 'agreement_recorded')->count());
        $this->assertSame($agreement->id, MedicationIdempotencyResult::query()->sole()->response_payload['agreement_id']);
        $this->assertSame(1, DB::table('medication_events')->where('kind', 'support.agreement_recorded')->count());
    }

    public function test_failed_agreement_audit_removes_only_the_staged_attachment(): void
    {
        Storage::fake('private');
        $a = $this->assess('self_managed');
        $recorder = Mockery::mock(MedicationEventRecorder::class);
        $recorder->shouldReceive('appendMany')->once()->andThrow(new \RuntimeException('Synthetic agreement failure'));
        $this->app->instance(MedicationEventRecorder::class, $recorder);
        $this->withoutExceptionHandling();
        try {
            $this->actingAs($this->actor)->post('/emar/self-admin/'.$a->id.'/agreement', [
                'agreed_by_role' => 'person', 'method' => 'signed', 'attachment' => UploadedFile::fake()->create('agreement.pdf', 1, 'application/pdf'),
                'ordering_responsibility' => 'person', 'person_responsibilities' => 'Takes vitamin.', 'staff_responsibilities' => 'Checks supply.', 'confirm_loosening' => true]);
            $this->fail('Expected agreement audit failure');
        } catch (\RuntimeException $error) {
            $this->assertSame('Synthetic agreement failure', $error->getMessage());
        }
        $this->assertDatabaseCount('medication_support_agreements', 0);
        $this->assertNull($a->fresh()->support_agreement_id);
        $this->assertSame([], Storage::disk('private')->allFiles());
    }

    public function test_trigger_delivery_failure_keeps_the_source_and_receipt_and_retries_once(): void
    {
        $this->assess();
        Shift::factory()->create([
            'client_id' => $this->person->id, 'site_id' => $this->site->id,
            'service_context_id' => $this->person->service_context_id, 'user_id' => $this->actor->id,
            'starts_at' => now()->subHour(), 'ends_at' => now()->addHours(7),
            'actual_starts_at' => now()->subHour(), 'actual_ends_at' => null,
            'started_by' => $this->actor->id, 'status' => 'in_progress',
        ]);

        // Exercise the continuing canonical clinical source endpoint and its transaction.
        $this->actingAs($this->actor)->post(route('emar.errors.store'), [
            'client_id' => $this->person->id, 'client_medication_id' => $this->medicine->id,
            'error_type' => 'wrong_dose', 'severity' => 'minor', 'description' => 'Synthetic recorded error.',
            'reached_client' => 'yes', 'harm_level' => 'none',
            'occurred_at' => CarbonImmutable::now('Pacific/Auckland')->format('Y-m-d\TH:i'), 'report_token' => (string) Str::uuid(),
        ])->assertSessionHasNoErrors()->assertRedirect();
        $source = MedicationError::query()->sole();
        $receipt = MedicationSupportTriggerOutbox::query()->sole();
        $this->followups->shouldReceive('request')->once()->andThrow(new \RuntimeException('Synthetic ledger unavailable'));
        $this->artisan('emar:support-review-delivery')->expectsOutput('0 support triggers delivered.')->assertSuccessful();
        $this->assertSame('Synthetic recorded error.', $source->fresh()->description);
        $this->assertDatabaseCount('medication_errors', 1);
        $this->assertNull($receipt->fresh()->delivered_at);
        $this->assertSame(1, $receipt->fresh()->attempts);
        $this->app->forgetInstance(SupportFollowupAdapter::class);
        $this->travel(2)->minutes();
        $this->artisan('emar:support-review-delivery')->expectsOutput('1 support triggers delivered.')->assertSuccessful();
        $this->artisan('emar:support-review-delivery')->expectsOutput('0 support triggers delivered.')->assertSuccessful();
        $this->assertDatabaseCount('medication_followups', 1);
        $this->assertNotNull($receipt->fresh()->delivered_at);
        $work = MedicationFollowup::query()->sole();
        $this->assertTrue($work->due_at->equalTo($receipt->occurred_at->setTimezone('Pacific/Auckland')->addDays(7)->utc()));
    }

    public function test_trigger_receipt_failure_rolls_back_the_source_transaction(): void
    {
        $this->assess();
        Shift::factory()->create([
            'client_id' => $this->person->id, 'site_id' => $this->site->id,
            'service_context_id' => $this->person->service_context_id, 'user_id' => $this->actor->id,
            'starts_at' => now()->subHour(), 'ends_at' => now()->addHours(7),
            'actual_starts_at' => now()->subHour(), 'actual_ends_at' => null,
            'started_by' => $this->actor->id, 'status' => 'in_progress',
        ]);

        $old = $this->medicine->dosage;
        $event = 'eloquent.creating: '.MedicationSupportTriggerOutbox::class;
        // Fail actual receipt persistence, rather than bypassing the enqueue service.
        Event::listen($event, fn () => throw new \RuntimeException('Synthetic receipt unavailable'));
        $this->withoutExceptionHandling();
        try {
            $this->actingAs($this->actor)->post(route('emar.errors.store'), [
                'client_id' => $this->person->id, 'client_medication_id' => $this->medicine->id,
                'error_type' => 'wrong_dose', 'severity' => 'minor', 'description' => 'Synthetic rolled back error.',
                'reached_client' => 'yes', 'harm_level' => 'none',
                'occurred_at' => CarbonImmutable::now('Pacific/Auckland')->format('Y-m-d\TH:i'), 'report_token' => (string) Str::uuid(),
            ]);
            $this->fail('Expected receipt failure');
        } catch (\RuntimeException $error) {
            $this->assertSame('Synthetic receipt unavailable', $error->getMessage());
        } finally {
            Event::forget($event);
        }
        $this->assertSame($old, $this->medicine->fresh()->dosage);
        $this->assertSame('verified', $this->medicine->fresh()->approval_status);
        $this->assertDatabaseCount('medication_errors', 0);
        $this->assertDatabaseCount('medication_support_trigger_outbox', 0);
    }

    public function test_reconciliation_event_retries_preserve_one_canonical_work_identity(): void
    {
        $this->app->forgetInstance(SupportFollowupAdapter::class);
        $this->assess();
        // The named event contract is the immutable P04 MedicationReconciliationApplied class.
        // P04's full apply decision regression runs in Main's combined checkout.
        DB::transaction(function () {
            Client::query()->whereKey($this->person->id)->lockForUpdate()->firstOrFail();
            $event = (object) ['clientId' => $this->person->id, 'reconciliationId' => 1001, 'actorId' => $this->actor->id];
            Event::dispatch('App\\Events\\MedicationReconciliationApplied', [$event]);
            Event::dispatch('App\\Events\\MedicationReconciliationApplied', [$event]);
        });
        $this->assertDatabaseCount('medication_support_trigger_outbox', 1);
        $receipt = MedicationSupportTriggerOutbox::query()->sole();
        $this->assertTrue(app(SupportReviewDelivery::class)->deliver($receipt->id));
        $this->assertDatabaseCount('medication_followups', 1);
        $this->assertSame($receipt->assessment_id, MedicationFollowup::query()->sole()->context['support_assessment_id']);
    }

    public function test_source_retry_after_reassessment_keeps_the_original_receipt_and_is_covered(): void
    {
        $this->app->forgetInstance(SupportFollowupAdapter::class);
        $prior = $this->assess();
        $emit = function () {
            DB::transaction(function () {
                Client::query()->whereKey($this->person->id)->lockForUpdate()->firstOrFail();
                Event::dispatch('App\\Events\\MedicationReconciliationApplied', [(object) [
                    'clientId' => $this->person->id, 'reconciliationId' => 1002, 'actorId' => $this->actor->id,
                ]]);
            });
        };
        $emit();
        $receipt = MedicationSupportTriggerOutbox::query()->sole();
        $originalTime = $receipt->occurred_at->toIso8601String();
        $this->travel(1)->hours();
        $this->actingAs($this->actor)->post('/emar/self-admin', [...$this->payload(), 'supersedes_id' => $prior->id])
            ->assertSessionHasNoErrors()->assertRedirect();
        $emit();
        $this->assertDatabaseCount('medication_support_trigger_outbox', 1);
        $this->assertSame($prior->id, $receipt->fresh()->assessment_id);
        $this->assertSame($originalTime, $receipt->fresh()->occurred_at->toIso8601String());
        $this->artisan('emar:support-review-delivery')->expectsOutput('1 support triggers delivered.')->assertSuccessful();
        $this->assertNotNull($receipt->fresh()->delivered_at);
        $this->assertDatabaseCount('medication_followups', 0);
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
