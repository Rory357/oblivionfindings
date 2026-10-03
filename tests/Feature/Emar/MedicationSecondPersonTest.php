<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrAttendanceSession;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\ClientMedicationStock;
use App\Models\MedicationAdminRule;
use App\Models\MedicationCompetencyAssessment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Models\UserWitnessPin;
use App\Services\Medication\MedicationGovernanceScopeService;
use App\Services\Medication\MedicationSafetyPolicySettings;
use App\Services\Medication\MedicationSecondPersonService;
use App\Services\Medication\Recording\DoseRecordingRequirements;
use App\Services\Medication\Recording\RecordingContract;
use Carbon\Carbon;
use Database\Factories\UserFactory;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** NF19: ordinary confirmations must not borrow controlled-drug authority. */
class MedicationSecondPersonTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private ServiceContext $context;

    private Client $client;

    private User $recorder;

    private User $secondPerson;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-04-30 09:30', 'Pacific/Auckland')->utc());
        $this->seed(RbacSeeder::class);
        Cache::flush();
        $this->site = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->context = ServiceContext::factory()->create(['type' => 'residential', 'is_active' => true]);
        $this->client = Client::factory()->create([
            'site_id' => $this->site->id, 'service_context_id' => $this->context->id, 'status' => 'active',
        ]);
        $this->recorder = $this->staff('Recorder');
        $this->secondPerson = $this->staff('Second person');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_explicit_non_controlled_witness_records_without_any_controlled_keys(): void
    {
        $order = $this->order(['witness_required' => true]);
        $this->assertFalse($this->recorder->canDo('medications.controlled.record'));
        $this->assertFalse($this->secondPerson->canDo('medications.controlled.witness'));
        $this->assertFalse((bool) MedicationCompetencyAssessment::query()->where('user_id', $this->secondPerson->id)->value('can_witness_controlled'));

        $facts = $this->requirements($order)->assertOk()->json('second_person');
        $this->assertTrue($facts['anyone_available']);
        $this->assertSame(['id', 'name', 'can_confirm'], array_keys($facts['candidates'][0]));
        $this->assertTrue($facts['candidates'][0]['can_confirm']);
        $this->record($order, $this->confirmation())->assertOk();
        $administration = ClientMedicationAdministration::query()->sole();
        $this->assertSame($this->secondPerson->id, (int) $administration->witnessed_by);
        $this->assertSame(RecordingContract::SECOND_WITNESS, $administration->second_person_kind);
        $this->assertSame('witness_pin', $administration->witness_method);
    }

    public function test_non_controlled_rule_countersign_uses_an_administrator_and_blocks_false_unavailable(): void
    {
        $this->rule();
        $order = $this->order();
        $this->record($order, ['second_person_unavailable' => true])
            ->assertStatus(422)->assertJsonPath('error_field', 'witnessed_by');
        $this->assertDatabaseCount('client_medication_administrations', 0);
        $this->record($order, $this->confirmation())->assertOk();
        $this->assertSame(RecordingContract::SECOND_RULE, ClientMedicationAdministration::query()->sole()->second_person_kind);
    }

    public function test_non_controlled_smaller_amount_is_confirmed_without_controlled_authority(): void
    {
        $order = $this->order(['dose_amount' => 2, 'dose_unit' => 'tablet', 'dosage' => '2 tablets']);
        $this->record($order, [...$this->confirmation(), 'amount_mode' => 'less', 'quantity_given' => 1, 'amount_reason' => 'part_taken'])
            ->assertOk();
        $this->assertSame(RecordingContract::SECOND_AMOUNT, ClientMedicationAdministration::query()->sole()->second_person_kind);
    }

    public function test_restricted_non_controlled_recorder_uses_an_unrestricted_ordinary_cosigner(): void
    {
        app(MedicationSafetyPolicySettings::class)->save([MedicationSafetyPolicySettings::RESTRICTED_COMPETENCY => 'cosigner']);
        MedicationCompetencyAssessment::query()->where('user_id', $this->recorder->id)->update(['restricted' => true]);
        $this->record($this->order(), $this->confirmation())->assertOk();
        $this->assertSame(RecordingContract::SECOND_COSIGNER, ClientMedicationAdministration::query()->sole()->second_person_kind);
    }

    public function test_a_restricted_second_person_is_hidden_from_the_eligible_pool_and_denied_on_write(): void
    {
        MedicationCompetencyAssessment::query()->where('user_id', $this->secondPerson->id)->update(['restricted' => true]);
        $order = $this->order(['witness_required' => true]);
        $this->requirements($order)->assertOk()->assertJsonPath('second_person.anyone_available', false);
        $this->record($order, $this->confirmation())->assertNotFound();
        $this->assertDatabaseCount('client_medication_administrations', 0);
    }

    public function test_missing_pin_never_bypasses_an_explicit_witness_but_rule_fallback_is_preserved(): void
    {
        UserWitnessPin::query()->where('user_id', $this->secondPerson->id)->delete();
        $explicit = $this->order(['witness_required' => true]);
        $this->record($explicit, ['second_person_unavailable' => true])->assertStatus(422);
        $this->record($explicit, $this->confirmation())->assertStatus(422)->assertJsonPath('error_field', 'witness_credential');
        $this->assertDatabaseCount('client_medication_administrations', 0);

        $this->rule();
        $this->record($this->order(), ['second_person_unavailable' => true])->assertOk();
        $administration = ClientMedicationAdministration::query()->sole();
        $this->assertSame(RecordingContract::SECOND_NOT_CONFIRMED, $administration->second_person_status);
        $this->assertTrue((bool) $administration->review_required);
    }

    public function test_self_confirmation_and_login_password_do_not_authenticate(): void
    {
        $order = $this->order(['witness_required' => true]);
        $this->record($order, [...$this->confirmation(), 'witnessed_by' => $this->recorder->id])->assertStatus(422);
        $this->record($order, [...$this->confirmation(), 'witness_credential' => 'password'])
            ->assertStatus(422)->assertJsonPath('error_field', 'witness_credential');
        $this->assertDatabaseCount('client_medication_administrations', 0);
    }

    public function test_foreign_house_and_off_shift_second_people_cannot_confirm(): void
    {
        HrEmployeeProfile::query()->where('user_id', $this->secondPerson->id)->update([
            'primary_site_id' => Site::factory()->create()->id,
        ]);
        $order = $this->order(['witness_required' => true]);
        $this->record($order, $this->confirmation())->assertNotFound();
        HrEmployeeProfile::query()->where('user_id', $this->secondPerson->id)->update(['primary_site_id' => $this->site->id]);
        Shift::query()->where('user_id', $this->secondPerson->id)->update(['status' => 'cancelled']);
        $this->record($order, $this->confirmation())->assertNotFound();
        $this->assertDatabaseCount('client_medication_administrations', 0);
    }

    public function test_attendance_presence_returns_administration_provenance_under_governing_locks(): void
    {
        Shift::query()->where('user_id', $this->secondPerson->id)->update(['status' => 'cancelled']);
        $attendance = HrAttendanceSession::query()->create([
            'user_id' => $this->secondPerson->id, 'site_id' => $this->site->id,
            'clock_in_at' => now()->subHour(), 'clock_out_at' => null, 'status' => 'open',
        ]);
        $at = now('Pacific/Auckland');
        $result = DB::transaction(function () use ($at): array {
            $governance = app(MedicationGovernanceScopeService::class);
            $ids = [$this->recorder->id, $this->secondPerson->id];
            $shifts = $governance->lockControlledWitnessPresenceShifts($ids, $this->site->id, $at);
            $users = $governance->lockControlledWitnessUsers($ids);

            return app(MedicationSecondPersonService::class)->authenticate(
                $this->recorder, $this->site->id, $this->secondPerson->id, UserFactory::TEST_WITNESS_PIN, $at, $users, $shifts,
            );
        });
        $this->assertSame('medications.administer.record', $result['authority_permission']);
        $this->assertSame('attendance_session', $result['presence_source']);
        $this->assertSame($attendance->id, $result['presence_record_id']);
        $this->assertNotNull($result['presence_started_at']);
        $this->assertSame('valid', $result['competency_state']);
    }

    public function test_expired_assessment_and_inactive_employment_are_never_eligible(): void
    {
        $assessment = MedicationCompetencyAssessment::query()->where('user_id', $this->secondPerson->id)->sole();
        $assessment->update(['expiry_date' => now()->subDay()->toDateString()]);
        $service = app(MedicationSecondPersonService::class);
        $this->assertTrue($service->candidatesForSite($this->site->id, now(), $this->recorder->id)->isEmpty());
        $assessment->update(['expiry_date' => now()->addYear()->toDateString()]);
        HrEmployeeProfile::query()->where('user_id', $this->secondPerson->id)->update(['is_active' => false]);
        $this->assertTrue($service->candidatesForSite($this->site->id, now(), $this->recorder->id)->isEmpty());
    }

    public function test_controlled_doses_keep_controlled_authority_and_their_own_cached_pool(): void
    {
        $controlled = $this->order(['controlled_drug' => true, 'dose_amount' => 1, 'dose_unit' => 'tablet']);
        ClientMedicationStock::query()->create(['client_medication_id' => $controlled->id, 'on_hand' => 5, 'unit' => 'tablet']);
        $response = $this->record($controlled, [...$this->confirmation(), 'quantity_administered' => 1, 'cd_balance' => 4]);
        $this->assertContains($response->status(), [403, 404]);
        $this->permissions($this->recorder, ['medications.controlled.record' => true, 'medications.controlled.view' => true]);
        $ordinary = $this->order(['witness_required' => true]);
        $due = Carbon::parse('2026-04-30 09:30', 'Pacific/Auckland');
        foreach ([[$ordinary, $controlled], [$controlled, $ordinary]] as $orders) {
            $rows = app(DoseRecordingRequirements::class)->forBoard($this->recorder->fresh(), array_map(
                fn (ClientMedication $order): array => ['order' => $order->fresh(), 'due_at' => $due], $orders,
            ));
            $this->assertTrue($rows[DoseRecordingRequirements::boardKey($ordinary->id, $due)]['witness_available']);
            $this->assertFalse($rows[DoseRecordingRequirements::boardKey($controlled->id, $due)]['witness_available']);
        }
        $this->record($controlled, [...$this->confirmation(), 'quantity_administered' => 1, 'cd_balance' => 4])->assertNotFound();
        $this->assertDatabaseCount('client_medication_administrations', 0);
    }

    private function staff(string $name): User
    {
        $user = User::factory()->create(['role' => 'support_worker', 'approved_at' => now(), 'name' => $name]);
        $user->roles()->syncWithoutDetaching([Role::query()->where('name', 'support_worker')->sole()->id]);
        $this->permissions($user, [
            'medications.administer.record' => true, 'medications.controlled.record' => false,
            'medications.controlled.view' => false, 'medications.controlled.witness' => false,
        ]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id, 'primary_site_id' => $this->site->id, 'secondary_site_ids' => [],
            'start_date' => now()->subMonth(), 'end_date' => null, 'is_active' => true,
        ]);
        MedicationCompetencyAssessment::query()->create([
            'user_id' => $user->id, 'assessor_id' => User::factory()->create()->id, 'assessment_type' => 'annual',
            'status' => 'passed', 'assessment_date' => now()->subMonth()->toDateString(), 'expiry_date' => now()->addYear()->toDateString(),
            'assessor_declared_at' => now()->subMonth(), 'staff_acknowledged_at' => now()->subMonth()->addMinute(),
            'can_administer_unsupervised' => true, 'can_witness_controlled' => false, 'restricted' => false,
        ]);
        Shift::factory()->create([
            'user_id' => $user->id, 'client_id' => $this->client->id, 'site_id' => $this->site->id,
            'service_context_id' => $this->context->id, 'starts_at' => now()->subHour(), 'ends_at' => now()->addHours(3),
            'actual_starts_at' => now()->subMinutes(30), 'actual_ends_at' => null, 'status' => 'in_progress',
        ]);

        return $user;
    }

    private function permissions(User $user, array $permissions): void
    {
        $user->permissionOverrides()->syncWithoutDetaching(Permission::query()->whereIn('key', array_keys($permissions))->get()
            ->mapWithKeys(fn (Permission $permission): array => [$permission->id => ['allowed' => $permissions[$permission->key]]])->all());
        $user->unsetRelation('permissionOverrides')->unsetRelation('roles');
        Cache::flush();
    }

    private function order(array $attributes = []): ClientMedication
    {
        $now = Carbon::getTestNow();
        Carbon::setTestNow(Carbon::now('Pacific/Auckland')->startOfDay()->utc());
        $order = ClientMedication::query()->create([
            'client_id' => $this->client->id, 'name' => 'Ordinary medicine', 'dosage' => '1 tablet', 'frequency' => 'Daily',
            'dose_times' => ['09:30'], 'is_prn' => false, 'active' => true, 'state' => 'active', ...$attributes,
        ]);
        Carbon::setTestNow($now);

        return $order;
    }

    private function rule(): void
    {
        MedicationAdminRule::query()->create([
            'site_id' => null, 'match_type' => 'medicine_name', 'match_value' => 'Ordinary medicine',
            'requires_countersign' => true, 'required_observations' => [], 'active' => true,
        ]);
    }

    private function confirmation(): array
    {
        return ['witnessed_by' => $this->secondPerson->id, 'witness_credential' => UserFactory::TEST_WITNESS_PIN];
    }

    private function record(ClientMedication $order, array $attributes)
    {
        return $this->actingAs($this->recorder->fresh())->postJson('/meds/today/record', [
            'client_medication_id' => $order->id, 'scheduled_for' => '2026-04-30T09:30:00+12:00', 'status' => 'given', ...$attributes,
        ]);
    }

    private function requirements(ClientMedication $order)
    {
        return $this->actingAs($this->recorder->fresh())->getJson('/meds/today/doses/requirements?'.http_build_query([
            'client_medication_id' => $order->id, 'scheduled_for' => '2026-04-30T09:30:00+12:00',
        ]));
    }
}
