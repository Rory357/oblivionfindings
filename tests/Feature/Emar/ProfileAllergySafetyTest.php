<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientMedicalProfile;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\MedicationAllergy;
use App\Models\MedicationCompetencyAssessment;
use App\Models\MedicationEvent;
use App\Models\MedicationOrderRevision;
use App\Models\MedicationOrderVersion;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Services\Medication\ClientAllergyRecordService;
use App\Services\Medication\MedicationSafetyPolicySettings;
use App\Services\Medication\OrderAllergyMatcher;
use App\Services\Medication\Recording\DoseRecordingRequirements;
use App\Services\MedicationSafetyService;
use Carbon\Carbon;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * EM-07: allergies staff record on the health profile must reach the
 * medication safety check and the recording wizards, and an empty record
 * must never read as "no known allergies".
 */
class ProfileAllergySafetyTest extends TestCase
{
    use RefreshDatabase;

    protected User $worker;

    protected Client $client;

    protected Site $site;

    protected ServiceContext $serviceContext;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-04-30 09:30:00', config('app.worker_timezone', 'Pacific/Auckland'))->utc());
        $this->seed(RbacSeeder::class);
        Cache::flush();

        $this->worker = User::factory()->create([
            'role' => 'support_worker',
            'approved_at' => now(),
        ]);
        $role = Role::query()->where('name', 'support_worker')->first();
        if ($role) {
            $this->worker->roles()->syncWithoutDetaching([$role->id]);
        }
        $this->worker->permissionOverrides()->syncWithoutDetaching(
            Permission::query()
                ->whereIn('key', ['medications.administer.record'])
                ->pluck('id')
                ->mapWithKeys(fn (int $id) => [$id => ['allowed' => true]])
                ->all(),
        );

        $this->site = Site::factory()->create(['is_active' => true]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $this->worker->id,
            'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [],
            'start_date' => now()->subMonth(),
            'end_date' => null,
            'is_active' => true,
        ]);
        $assessor = User::factory()->create(['role' => 'manager', 'approved_at' => now()]);
        MedicationCompetencyAssessment::query()->create([
            'user_id' => $this->worker->id,
            'assessor_id' => $assessor->id,
            'assessment_type' => 'annual',
            'status' => 'passed',
            'assessment_date' => now()->subMonth()->toDateString(),
            'expiry_date' => now()->addYear()->toDateString(),
            'assessor_declared_at' => now()->subMonth(),
            'staff_acknowledged_at' => now()->subMonth()->addMinute(),
            'can_administer_unsupervised' => true,
        ]);

        $this->serviceContext = ServiceContext::factory()->create([
            'name' => 'Profile allergy',
            'type' => 'residential',
            'is_active' => true,
        ]);
        $this->client = $this->clientOnShift('Mere', 'Tawhiri');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_profile_penicillin_allergy_flags_amoxicillin_without_blocking_by_default(): void
    {
        ClientMedicalProfile::query()->create([
            'client_id' => $this->client->id,
            'allergies' => ['penicillin'],
        ]);
        $amoxicillin = $this->order('Amoxicillin 500mg capsule', ['09:30']);
        $paracetamol = $this->order('Paracetamol 500mg', ['09:30']);
        $safety = app(MedicationSafetyService::class);

        $check = $safety->performSafetyCheck($this->client, $amoxicillin);
        $allergyWarnings = collect($check['warnings'])->where('type', 'allergy')->values();

        $this->assertFalse($check['blocked']);
        $this->assertCount(1, $allergyWarnings);
        $this->assertSame('Penicillin', $allergyWarnings[0]['details']['allergen']);
        $this->assertSame('health_profile', $allergyWarnings[0]['details']['source']);
        $this->assertNull($allergyWarnings[0]['details']['severity']);
        $this->assertSame(
            'Possible allergy match — Amoxicillin 500mg capsule matches recorded Penicillin allergy (health profile)',
            $allergyWarnings[0]['message'],
        );

        $this->assertCount(0, collect(
            $safety->performSafetyCheck($this->client, $paracetamol)['warnings'],
        )->where('type', 'allergy'));
    }

    public function test_block_setting_refuses_giving_a_medicine_that_matches_a_profile_allergy(): void
    {
        ClientMedicalProfile::query()->create([
            'client_id' => $this->client->id,
            'allergies' => ['penicillin'],
        ]);
        $amoxicillin = $this->order('Amoxicillin 500mg capsule', ['09:30']);
        app(MedicationSafetyPolicySettings::class)->save([
            MedicationSafetyPolicySettings::PROFILE_ALLERGY_MATCH => 'block',
        ]);

        $check = app(MedicationSafetyService::class)->performSafetyCheck($this->client, $amoxicillin);
        $this->assertTrue($check['blocked']);
        $this->assertStringStartsWith(
            'Possible allergy match — Amoxicillin 500mg capsule matches recorded Penicillin allergy (health profile).',
            (string) $check['block_reason'],
        );

        $this->actingAs($this->worker)
            ->from('/meds/today')
            ->post('/meds/today/record', [
                'client_medication_id' => $amoxicillin->id,
                'scheduled_for' => now()->toIso8601String(),
                'status' => 'given',
                'administered_at' => now()->toIso8601String(),
            ])
            ->assertRedirect('/meds/today')
            ->assertSessionHasErrors();

        $this->assertDatabaseCount('client_medication_administrations', 0);
    }

    public function test_warn_setting_still_records_the_dose(): void
    {
        ClientMedicalProfile::query()->create([
            'client_id' => $this->client->id,
            'allergies' => ['penicillin'],
        ]);
        $amoxicillin = $this->order('Amoxicillin 500mg capsule', ['09:30']);

        $this->actingAs($this->worker)
            ->from('/meds/today')
            ->post('/meds/today/record', [
                'client_medication_id' => $amoxicillin->id,
                'scheduled_for' => now()->toIso8601String(),
                'status' => 'given',
                'administered_at' => now()->toIso8601String(),
            ])
            ->assertRedirect('/meds/today')
            ->assertSessionHas('success');

        $this->assertDatabaseCount('client_medication_administrations', 1);
    }

    public function test_meds_today_lists_profile_and_register_allergies_and_never_implies_none_known(): void
    {
        ClientMedicalProfile::query()->create([
            'client_id' => $this->client->id,
            'allergies' => ['penicillin', 'Kiwifruit'],
        ]);
        MedicationAllergy::query()->create([
            'client_id' => $this->client->id,
            'allergen' => 'Codeine',
            'severity' => 'moderate',
            'recorded_by' => $this->worker->id,
        ]);
        $withoutAllergies = $this->clientOnShift('Hemi', 'Parata');
        $this->order('Amoxicillin 500mg capsule', ['09:30']);
        $this->order('Paracetamol 500mg', ['09:30'], $withoutAllergies);

        $this->actingAs($this->worker)
            ->get('/meds/today')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('meds/today/index')
                ->where('clients', function ($clients) use ($withoutAllergies): bool {
                    $byId = collect($clients)->keyBy('id');
                    $recorded = $byId[$this->client->id];
                    $none = $byId[$withoutAllergies->id];

                    return $recorded['allergies'] === ['Codeine', 'Penicillin', 'Kiwifruit']
                        && $recorded['allergy_status'] === 'recorded'
                        && $none['allergies'] === []
                        && $none['allergy_status'] === 'none_recorded';
                }));
    }

    public static function severeCanonicalAllergies(): array
    {
        $cases = [];
        foreach (['severe', 'life_threatening'] as $severity) {
            foreach (['new', 'copied'] as $origin) {
                foreach (['warn', 'block'] as $policy) {
                    $cases[$severity.' '.$origin.' '.$policy] = [$severity, $origin, $policy];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('severeCanonicalAllergies')]
    public function test_canonical_severity_blocks_given_through_safety_requirements_and_save(string $severity, string $origin, string $policy): void
    {
        app(MedicationSafetyPolicySettings::class)->save([MedicationSafetyPolicySettings::PROFILE_ALLERGY_MATCH => $policy]);
        if ($origin === 'copied') {
            $source = MedicationAllergy::create(['client_id' => $this->client->id, 'allergen' => 'Penicillin', 'severity' => $severity, 'reaction' => 'Anaphylaxis', 'recorded_by' => $this->worker->id]);
            $evidence = $source->fresh()->getAttributes();
            DB::transaction(fn () => app(ClientAllergyRecordService::class)->copyLegacy(Client::whereKey($this->client->id)->lockForUpdate()->firstOrFail()));
            $this->assertSame($evidence, $source->fresh()->getAttributes());
        } else {
            ClientMedicalProfile::create(['client_id' => $this->client->id, 'allergies_canonical_at' => now(), 'allergy_records' => [
                ['key' => (string) Str::uuid(), 'allergen' => 'Penicillin', 'severity' => $severity, 'reaction' => 'Anaphylaxis'],
            ]]);
        }
        $order = $this->order('Amoxicillin 500mg capsule', ['09:30']);
        $check = app(MedicationSafetyService::class)->performSafetyCheck($this->client, $order);
        $warning = collect($check['warnings'])->firstWhere('type', 'allergy');
        $this->assertTrue($check['blocked']);
        $this->assertSame($severity, $warning['details']['severity']);
        $this->assertSame('Anaphylaxis', $warning['details']['reaction']);
        $this->assertSame('health_profile', $warning['details']['source']);
        $this->assertSame('danger', $warning['severity']);
        $this->assertCount(1, app(ClientAllergyRecordService::class)->forClient($this->client));

        $requirements = app(DoseRecordingRequirements::class)->forScheduledDose($this->worker, $order, now());
        $this->assertNull($requirements['block_all']);
        $this->assertSame(DoseRecordingRequirements::BLOCK_ALLERGY, $requirements['block_given']['key']);
        $this->assertSame($severity, $requirements['allergy']['match']['severity']);
        $this->actingAs($this->worker)->from('/meds/today')->post('/meds/today/record', [
            'client_medication_id' => $order->id, 'scheduled_for' => now()->toIso8601String(),
            'status' => 'given', 'administered_at' => now()->toIso8601String(),
        ])->assertRedirect('/meds/today')->assertSessionHasErrors();
        $this->assertDatabaseCount('client_medication_administrations', 0);
    }

    public function test_an_unrated_canonical_entry_keeps_its_reaction_and_uses_the_profile_policy(): void
    {
        ClientMedicalProfile::create(['client_id' => $this->client->id, 'allergies_canonical_at' => now(), 'allergy_records' => [
            ['key' => (string) Str::uuid(), 'allergen' => 'Penicillin', 'reaction' => 'Recorded reaction; severity not yet assessed'],
        ]]);
        $order = $this->order('Amoxicillin 500mg capsule', ['09:30']);
        $check = app(MedicationSafetyService::class)->performSafetyCheck($this->client, $order);
        $warning = collect($check['warnings'])->firstWhere('type', 'allergy');
        $this->assertFalse($check['blocked']);
        $this->assertNull($warning['details']['severity']);
        $this->assertSame('Recorded reaction; severity not yet assessed', $warning['details']['reaction']);
        app(MedicationSafetyPolicySettings::class)->save([MedicationSafetyPolicySettings::PROFILE_ALLERGY_MATCH => 'block']);
        $this->assertTrue(app(MedicationSafetyService::class)->performSafetyCheck($this->client, $order)['blocked']);
    }

    public function test_a_checked_prescriber_confirmation_applies_only_to_its_version_and_unchanged_canonical_evidence(): void
    {
        $entry = ['key' => (string) Str::uuid(), 'allergen' => 'Amoxicillin', 'severity' => 'life_threatening', 'reaction' => 'Anaphylaxis'];
        $profile = ClientMedicalProfile::create(['client_id' => $this->client->id, 'allergies_canonical_at' => now(), 'allergy_records' => [$entry]]);
        $order = $this->order('Amoxicillin 500mg capsule', ['09:30']);
        $order->forceFill(['approval_status' => 'verified', 'version' => 1])->save();
        $inspection = app(OrderAllergyMatcher::class)->inspect($this->client, $order->name);
        $confirmation = ['match_sha256' => $inspection['match_sha256'], 'matches' => $inspection['matches'], 'instruction' => 'Recorded prescriber instruction for this checked order'];
        $version = MedicationOrderVersion::create([
            'client_id' => $this->client->id, 'client_medication_id' => $order->id, 'version_number' => 1,
            'name' => $order->name, 'dosage' => $order->dosage, 'frequency' => 'Daily', 'dose_times' => ['09:30'],
            'active' => true, 'state' => 'active', 'changed_by' => $this->worker->id, 'changed_at' => now()->subHour(),
        ]);
        MedicationOrderRevision::create([
            'client_id' => $this->client->id, 'client_medication_id' => $order->id, 'medication_order_version_id' => $version->id,
            'base_version' => 1, 'status' => 'checked', 'entered_by' => $this->worker->id, 'checked_by' => $this->worker->id,
            'checked_at' => now()->subHour(), 'allergy_confirmation' => $confirmation,
        ]);
        $check = app(MedicationSafetyService::class)->performSafetyCheck($this->client, $order);
        $this->assertFalse($check['blocked']);
        $this->assertEquals($confirmation, collect($check['warnings'])->firstWhere('type', 'allergy')['details']['prescriber_confirmation']);
        $this->assertNull(app(DoseRecordingRequirements::class)->forScheduledDose($this->worker, $order, now())['block_given']);

        $profile->forceFill(['allergy_records' => [[...$entry, 'reaction' => 'Changed reaction evidence']]])->save();
        $this->assertTrue(app(MedicationSafetyService::class)->performSafetyCheck($this->client, $order)['blocked']);
        $profile->forceFill(['allergy_records' => [$entry]])->save();
        $order->forceFill(['version' => 2])->save();
        $this->assertTrue(app(MedicationSafetyService::class)->performSafetyCheck($this->client, $order)['blocked']);
    }

    public function test_recording_status_confirms_a_saved_prn_even_when_its_interval_now_blocks_recording(): void
    {
        [$order, $uuid, $record] = $this->prnForRecovery();
        $this->getJson('/meds/today/prn/'.$order->id.'/requirements')->assertOk()
            ->assertJsonPath('block_all.key', DoseRecordingRequirements::BLOCK_PRN_LIMIT);
        $events = MedicationEvent::count();
        $receipts = DB::table('medication_idempotency_results')->count();
        $response = $this->getJson('/meds/today/recording-status?'.http_build_query([
            'client_medication_id' => $order->id, 'client_request_uuid' => $uuid,
        ]))->assertOk()->assertJsonPath('status', 'recorded')->assertJsonPath('administration_id', $record->id);
        parse_str(parse_url($response->json('chart_url'), PHP_URL_QUERY), $chart);
        $this->assertSame('/emar/mar', parse_url($response->json('chart_url'), PHP_URL_PATH));
        $this->assertSame((string) $this->client->id, $chart['client_id']);
        $this->assertSame((string) $this->site->id, $chart['site_id']);
        $this->assertSame('2026-04-30', $chart['date']);
        $this->assertSame('history', $chart['tab']);
        $this->assertSame((string) $record->id, $chart['dose_id']);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertDatabaseCount('client_medication_administrations', 1);
        $this->assertSame($events, MedicationEvent::count());
        $this->assertSame($receipts, DB::table('medication_idempotency_results')->count());
    }

    public static function retainedRecoveryOrderStates(): array
    {
        return [
            'replaced order' => ['superseded'],
            'retained legacy archived order' => ['soft_deleted'],
            'stopped order' => ['stopped'],
            'expired order' => ['expired'],
        ];
    }

    #[DataProvider('retainedRecoveryOrderStates')]
    public function test_recording_status_recovers_the_original_receipt_after_the_order_is_retired(string $state): void
    {
        [$order, $uuid, $record] = $this->prnForRecovery();
        $this->retireRecoveryOrder($order, $state);
        $originalOrder = ClientMedication::withTrashed()->findOrFail($order->id)->getRawOriginal();
        $originalRecord = $record->fresh()->getRawOriginal();
        $events = MedicationEvent::count();
        $receipts = DB::table('medication_idempotency_results')->count();

        $response = $this->getJson('/meds/today/recording-status?'.http_build_query([
            'client_medication_id' => $order->id, 'client_request_uuid' => $uuid,
        ]))->assertOk()->assertJsonPath('status', 'recorded')->assertJsonPath('administration_id', $record->id);
        parse_str(parse_url($response->json('chart_url'), PHP_URL_QUERY), $chart);
        $this->assertSame((string) $this->client->id, $chart['client_id']);
        $this->assertSame((string) $this->site->id, $chart['site_id']);
        $this->assertSame('2026-04-30', $chart['date']);
        $this->assertSame('history', $chart['tab']);
        $this->assertSame((string) $record->id, $chart['dose_id']);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame($originalOrder, ClientMedication::withTrashed()->findOrFail($order->id)->getRawOriginal());
        $this->assertSame($originalRecord, $record->fresh()->getRawOriginal());
        $this->assertDatabaseCount('client_medication_administrations', 1);
        $this->assertSame($events, MedicationEvent::count());
        $this->assertSame($receipts, DB::table('medication_idempotency_results')->count());
        config(['medications.person_record' => 'p02']);
        $this->get($response->json('chart_url'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('emar/record/show')->where('person.id', $this->client->id));
        $this->getJson(route('emar.record.dose', ['client' => $this->client->id, 'administration' => $record->id], false))
            ->assertOk()->assertJsonPath('dose.id', $record->id);
    }

    #[DataProvider('retainedRecoveryOrderStates')]
    public function test_historical_recording_status_preserves_current_read_scope_and_request_privacy(string $state): void
    {
        [$order, $uuid, $record] = $this->prnForRecovery();
        $order->forceFill(['controlled_drug' => true])->save();
        $this->retireRecoveryOrder($order, $state);
        $url = '/meds/today/recording-status?'.http_build_query([
            'client_medication_id' => $order->id, 'client_request_uuid' => $uuid,
        ]);
        $this->getJson($url)->assertOk()->assertJsonPath('status', 'recorded');
        $this->getJson('/meds/today/recording-status?'.http_build_query([
            'client_medication_id' => $order->id, 'client_request_uuid' => (string) Str::uuid(),
        ]))->assertOk()->assertExactJson(['status' => 'unconfirmed']);
        $other = $this->order('Another recovery medicine', []);
        $this->getJson('/meds/today/recording-status?'.http_build_query([
            'client_medication_id' => $other->id, 'client_request_uuid' => $uuid,
        ]))->assertOk()->assertExactJson(['status' => 'unconfirmed']);
        $otherActor = User::factory()->create(['approved_at' => now()]);
        DB::table('client_medication_administrations')->where('id', $record->id)->update(['administered_by' => $otherActor->id]);
        $this->getJson($url)->assertOk()->assertExactJson(['status' => 'unconfirmed']);
        DB::table('client_medication_administrations')->where('id', $record->id)->update(['administered_by' => $this->worker->id]);
        $foreignClient = Client::factory()->create(['site_id' => $this->site->id, 'status' => 'active']);
        DB::table('client_medication_administrations')->where('id', $record->id)->update(['client_id' => $foreignClient->id]);
        $this->getJson($url)->assertOk()->assertExactJson(['status' => 'unconfirmed']);
        DB::table('client_medication_administrations')->where('id', $record->id)->update(['client_id' => $this->client->id]);

        $this->worker->permissionOverrides()->syncWithoutDetaching(Permission::where('key', 'medications.controlled.view')->pluck('id')
            ->mapWithKeys(fn ($id) => [$id => ['allowed' => false]])->all());
        $this->worker = $this->worker->fresh();
        $this->assertFalse($this->worker->canDo('medications.controlled.view'));
        $this->assertTrue($this->worker->canDo('medications.controlled.record'));
        $this->actingAs($this->worker)->getJson($url)->assertNotFound();
        $this->worker->permissionOverrides()->syncWithoutDetaching(Permission::where('key', 'medications.controlled.view')->pluck('id')
            ->mapWithKeys(fn ($id) => [$id => ['allowed' => true]])->all());
        $this->worker = $this->worker->fresh();
        $this->actingAs($this->worker)->getJson($url)->assertOk()->assertJsonPath('status', 'recorded');

        $this->client->forceFill(['site_id' => Site::factory()->create(['is_active' => true])->id])->save();
        $this->getJson($url)->assertNotFound();
        $this->client->forceFill(['site_id' => $this->site->id])->save();
        $this->getJson($url)->assertOk()->assertJsonPath('status', 'recorded');
        $shift = Shift::where('client_id', $this->client->id)->where('user_id', $this->worker->id)->sole();
        $shift->forceFill(['status' => 'completed', 'actual_ends_at' => now()->subMinute()])->save();
        $this->assertFalse($this->worker->can('viewMedications', $this->client->fresh()));
        $this->getJson($url)->assertNotFound();
        $shift->forceFill(['status' => 'in_progress', 'actual_ends_at' => null])->save();
        $this->getJson($url)->assertOk()->assertJsonPath('status', 'recorded');
        $this->worker->permissionOverrides()->syncWithoutDetaching(Permission::where('key', 'medications.view')->pluck('id')
            ->mapWithKeys(fn ($id) => [$id => ['allowed' => false]])->all());
        $this->worker = $this->worker->fresh();
        $this->assertFalse($this->worker->canDo('medications.view'));
        $this->actingAs($this->worker)->getJson($url)->assertNotFound();
        $this->assertDatabaseCount('client_medication_administrations', 1);
    }

    public function test_recording_status_does_not_reveal_foreign_unknown_or_other_medicine_requests(): void
    {
        [$order, $uuid, $record] = $this->prnForRecovery();
        $status = fn (int $medicine, string $request) => $this->getJson('/meds/today/recording-status?'.http_build_query([
            'client_medication_id' => $medicine, 'client_request_uuid' => $request,
        ]))->assertOk()->assertExactJson(['status' => 'unconfirmed']);
        $status($order->id, (string) Str::uuid());
        $other = $this->order('Another medicine', ['09:30']);
        $status($other->id, $uuid);
        $someoneElse = User::factory()->create(['approved_at' => now()]);
        DB::table('client_medication_administrations')->where('id', $record->id)->update(['administered_by' => $someoneElse->id]);
        $status($order->id, $uuid);
        $this->assertDatabaseCount('client_medication_administrations', 1);
    }

    public function test_recording_status_rechecks_current_person_site_module_and_controlled_access(): void
    {
        [$order, $uuid] = $this->prnForRecovery();
        $url = '/meds/today/recording-status?'.http_build_query([
            'client_medication_id' => $order->id, 'client_request_uuid' => $uuid,
        ]);
        $order->forceFill(['controlled_drug' => true])->save();
        // The frontline role can read controlled medicines; deny that grant explicitly.
        $this->assertTrue($this->worker->canDo('medications.controlled.view'));
        $this->getJson($url)->assertOk()->assertJsonPath('status', 'recorded');
        $this->worker->permissionOverrides()->syncWithoutDetaching(Permission::where('key', 'medications.controlled.view')->pluck('id')
            ->mapWithKeys(fn ($id) => [$id => ['allowed' => false]])->all());
        $this->worker = $this->worker->fresh();
        $this->assertFalse($this->worker->canDo('medications.controlled.view'));
        $this->assertTrue($this->worker->canDo('medications.controlled.record'));
        $this->actingAs($this->worker)->getJson($url)->assertNotFound();
        $order->forceFill(['controlled_drug' => false])->save();
        $this->getJson($url)->assertOk()->assertJsonPath('status', 'recorded');
        $this->client->forceFill(['site_id' => Site::factory()->create(['is_active' => true])->id])->save();
        $this->getJson($url)->assertNotFound();
        $this->client->forceFill(['site_id' => $this->site->id])->save();
        $this->getJson($url)->assertOk()->assertJsonPath('status', 'recorded');
        $this->worker->permissionOverrides()->syncWithoutDetaching(Permission::where('key', 'medications.view')->pluck('id')
            ->mapWithKeys(fn ($id) => [$id => ['allowed' => false]])->all());
        $this->worker = $this->worker->fresh();
        $this->assertFalse($this->worker->canDo('medications.view'));
        $this->actingAs($this->worker)->getJson($url)->assertNotFound();
        $this->assertDatabaseCount('client_medication_administrations', 1);
    }

    /** A committed worker request whose HTTP result the caller may have lost. */
    private function prnForRecovery(): array
    {
        $order = $this->order('Paracetamol 500mg', []);
        $order->forceFill(['is_prn' => true, 'min_hours_between_doses' => 6])->save();
        // Prescription changes correctly reset verification; approve only after those changes are saved.
        $order->forceFill(['approval_status' => 'verified', 'verified_at' => now()]);
        $this->assertFalse($order->isDirty(ClientMedication::verificationSensitiveFields()));
        $order->save();
        $this->assertSame('verified', $order->fresh()->approval_status);
        $uuid = (string) Str::uuid();
        $this->actingAs($this->worker)->postJson('/meds/today/prn', [
            'client_medication_id' => $order->id, 'client_request_uuid' => $uuid,
            'administered_at' => now()->toIso8601String(), 'reason' => 'Pain',
        ])->assertOk()->assertJsonPath('success', true);
        $record = ClientMedicationAdministration::where('client_request_uuid', $uuid)->sole();

        return [$order, $uuid, $record];
    }

    private function retireRecoveryOrder(ClientMedication $order, string $state): void
    {
        match ($state) {
            'superseded' => $order->forceFill(['superseded_by' => $this->order('Replacement recovery medicine', [])->id])->save(),
            // Synthetic retained legacy evidence: production deletion remains prohibited.
            'soft_deleted' => DB::table('client_medications')->where('id', $order->id)->update(['deleted_at' => now()]),
            'stopped' => $order->forceFill([
                'state' => 'ceased', 'active' => false, 'ceased_at' => now(),
                'ceased_reason' => 'Stopped after the committed dose', 'ceased_by' => $this->worker->id,
            ])->save(),
            'expired' => $order->forceFill(['end_date' => now()->subDay()->toDateString()])->save(),
        };
    }

    private function clientOnShift(string $first, string $last): Client
    {
        $client = Client::factory()->create([
            'first_name' => $first,
            'last_name' => $last,
            'service_context_id' => $this->serviceContext->id,
            'site_id' => $this->site->id,
            'status' => 'active',
        ]);

        Shift::factory()->create([
            'client_id' => $client->id,
            'site_id' => $this->site->id,
            'service_context_id' => $this->serviceContext->id,
            'user_id' => $this->worker->id,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHours(3),
            'actual_starts_at' => now()->subMinutes(30),
            'actual_ends_at' => null,
            'status' => 'in_progress',
        ]);

        return $client;
    }

    private function order(string $name, array $doseTimes, ?Client $client = null): ClientMedication
    {
        return ClientMedication::query()->create([
            'client_id' => ($client ?? $this->client)->id,
            'name' => $name,
            'dosage' => '1 capsule',
            'frequency' => 'Daily',
            'dose_times' => $doseTimes,
            'is_prn' => false,
            'active' => true,
            'state' => 'active',
        ]);
    }
}
