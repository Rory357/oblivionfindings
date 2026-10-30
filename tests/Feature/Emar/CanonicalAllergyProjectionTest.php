<?php

namespace Tests\Feature\Emar;

use App\Domain\Clinical\Services\ClinicalDashboardService;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientCondition;
use App\Models\ClientEmergencyContact;
use App\Models\ClientMedicalProfile;
use App\Models\MedicationAllergy;
use App\Models\MedicationEvent;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\TimelineEvent;
use App\Models\User;
use App\Services\HealthClinical\HealthSummaryService;
use App\Services\Medication\Audit\MedicationEventRecorder;
use App\Services\Medication\ClientAllergyRecordService;
use App\Services\NotificationService;
use App\Services\Timeline\TimelineEmitter;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CommittedFixtureCleanup;
use Tests\TestCase;

/** Current allergy meaning must agree across the person's profile, MAR, API and clinical views. */
class CanonicalAllergyProjectionTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private Client $person;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->actor = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
        $this->actor->roles()->attach(Role::where('name', 'admin')->firstOrFail());
        $this->person = Client::factory()->create(['site_id' => Site::factory()->create(['is_active' => true])->id]);
        HrEmployeeProfile::factory()->create(['user_id' => $this->actor->id, 'primary_site_id' => $this->person->site_id,
            'start_date' => now()->subYear()->toDateString(), 'is_active' => true, 'secondary_site_ids' => []]);
        $this->actingAs($this->actor);
    }

    public function test_legacy_api_creates_a_canonical_unreviewed_entry_and_retries_once(): void
    {
        $summary = app(ClientAllergyRecordService::class)->summary($this->person);
        $this->review($summary, true)->assertOk()->assertJsonPath('status', 'no_known');
        $request = ['request_uuid' => (string) Str::uuid(), 'digest' => $summary['digest'],
            'allergen' => 'Penicillin', 'severity' => 'life_threatening', 'reaction' => 'Anaphylaxis',
            'notes' => 'Prescriber record', 'identified_date' => '2026-10-01', 'identified_by' => 'Dr Test'];
        $created = $this->postJson($this->api(), $request)->assertOk()
            ->assertJsonPath('allergy.key', $request['request_uuid'])
            ->assertJsonPath('allergy_record.reviewed', null)
            ->assertJsonPath('allergy_record.status', 'recorded');
        $this->postJson($this->api(), $request)->assertOk()->assertExactJson($created->json());
        $this->postJson($this->api(), [...$request, 'reaction' => 'Different evidence'])->assertConflict();
        $this->assertDatabaseCount('medication_allergies', 0);
        $this->assertSame(1, MedicationEvent::where('kind', 'allergy.edit')->count());
        $profile = ClientMedicalProfile::where('client_id', $this->person->id)->firstOrFail();
        $this->assertCount(1, $profile->allergy_records);
        $this->assertSame(['Penicillin'], $profile->allergies);
        $current = $this->assertCurrentMeaning('recorded', false);
        $this->assertSame('life_threatening', $current['entries'][0]['severity']);
        $this->assertSame('Anaphylaxis', $current['entries'][0]['reaction']);
        $event = MedicationEvent::where('kind', 'allergy.edit')->sole();
        $this->assertSame('no_known', $event->facts['before']['status']);
        $this->assertSame('Anaphylaxis', $event->facts['after']['entries'][0]['reaction']);
    }

    public function test_copied_source_is_preserved_while_current_api_tracks_edits_removal_and_review(): void
    {
        $source = MedicationAllergy::create(['client_id' => $this->person->id, 'allergen' => 'Penicillin',
            'severity' => 'severe', 'reaction' => 'Swelling', 'recorded_by' => $this->actor->id]);
        $sourceEvidence = $source->fresh()->getAttributes();
        $current = $this->assertCurrentMeaning('recorded', false);
        $this->review($current)->assertOk();
        $current = $this->assertCurrentMeaning('recorded', true);
        $profile = ClientMedicalProfile::where('client_id', $this->person->id)->firstOrFail();
        $this->assertSame([$source->id], $profile->allergy_records[0]['source_register_ids']);
        $this->assertEquals($sourceEvidence, $profile->allergy_records[0]['source_evidence'][0]);
        $this->getJson($this->api())->assertOk()->assertJsonPath('allergies.0.id', $source->id);

        $changed = [...$current['entries'][0], 'severity' => 'life_threatening', 'reaction' => 'Confirmed anaphylaxis'];
        $this->edit($current, [$changed])->assertOk()->assertJsonPath('reviewed', null);
        $current = $this->assertCurrentMeaning('recorded', false);
        $this->assertSame('Confirmed anaphylaxis', $current['entries'][0]['reaction']);
        $this->edit($current, [])->assertOk()->assertJsonPath('status', 'none');
        $current = $this->assertCurrentMeaning('none', false);
        $this->getJson($this->api())->assertOk()->assertJsonCount(0, 'allergies')->assertJsonCount(0, 'recorded_allergies');
        $this->assertSame($sourceEvidence, $source->fresh()->getAttributes());
        $removed = $profile->fresh()->allergy_records[0];
        $this->assertNotEmpty($removed['removed_at']);
        $this->assertEquals($sourceEvidence, $removed['source_evidence'][0]);
        $this->review($current, true)->assertOk();
        $this->assertCurrentMeaning('no_known', true);
    }

    public function test_no_record_remains_distinct_from_a_reviewed_no_known_record(): void
    {
        $summary = $this->assertCurrentMeaning('none', false);
        $health = app(HealthSummaryService::class)->forClient($this->actor, $this->person);
        $this->assertNull($health['medical_profile']);
        $this->assertSame('none', $health['allergy_record']['status']);
        $this->review($summary, true)->assertOk();
        $this->assertCurrentMeaning('no_known', true);
    }

    public function test_a_legacy_append_without_a_digest_or_uuid_preserves_existing_entries(): void
    {
        ClientMedicalProfile::create(['client_id' => $this->person->id, 'allergies' => ['Kiwifruit']]);
        $this->postJson($this->api(), ['allergen' => 'Penicillin', 'severity' => 'severe'])->assertOk();
        $summary = $this->assertCurrentMeaning('recorded', false);
        $this->assertSame(['Kiwifruit', 'Penicillin'], array_column($summary['entries'], 'allergen'));
        // An exact repeated label is one current entry, even while evidence is retained.
        $this->postJson($this->api(), ['allergen' => 'Penicillin', 'severity' => 'severe'])->assertOk()->assertJsonPath('allergy.allergen', 'Penicillin');
        $this->assertCount(2, app(ClientAllergyRecordService::class)->forClient($this->person));
        $this->assertDatabaseCount('medication_allergies', 0);
    }

    public function test_stale_legacy_api_digest_and_recorder_failure_leave_the_record_unchanged(): void
    {
        $before = app(ClientAllergyRecordService::class)->summary($this->person);
        $this->postJson($this->api(), ['request_uuid' => (string) Str::uuid(), 'digest' => str_repeat('0', 64), 'allergen' => 'Rejected allergy'])->assertConflict();
        $this->assertSame($before, app(ClientAllergyRecordService::class)->summary($this->person));
        $this->mock(MedicationEventRecorder::class)->shouldReceive('append')->once()->andThrow(new \RuntimeException('Synthetic allergy event failure'));
        $this->withoutExceptionHandling();
        try {
            $this->postJson($this->api(), ['request_uuid' => (string) Str::uuid(), 'digest' => $before['digest'], 'allergen' => 'Rolled back allergy']);
            $this->fail('The recorder failure must escape.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Synthetic allergy event failure', $error->getMessage());
        }
        $this->assertDatabaseMissing('client_medical_profiles', ['client_id' => $this->person->id]);
        $this->assertDatabaseCount('medication_idempotency_results', 0);
        $this->assertDatabaseCount('medication_allergies', 0);
    }

    public function test_medication_only_access_does_not_offer_an_inaccessible_profile_link(): void
    {
        $reader = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $reader->permissionOverrides()->sync(Permission::whereIn('key', ['medications.view', 'medications.stock.update'])->pluck('id')->mapWithKeys(fn ($id) => [$id => ['allowed' => true]])->all());
        HrEmployeeProfile::factory()->create(['user_id' => $reader->id, 'primary_site_id' => $this->person->site_id,
            'start_date' => now()->subYear()->toDateString(), 'is_active' => true, 'secondary_site_ids' => []]);
        $this->actingAs($reader)->getJson($this->canonical())->assertOk()->assertJsonPath('management_url', null);
        $this->getJson($this->api())->assertOk()->assertJsonPath('allergy_management_url', null);
        $this->assertNull(app(HealthSummaryService::class)->forClient($reader, $this->person)['allergy_management_url']);
        $this->assertNull(app(ClinicalDashboardService::class)->getClinicalCard($reader, $this->person)['allergy_management_url']);
        $this->postJson($this->api(), ['allergen' => 'Unauthorised change'])->assertForbidden();
        $this->assertDatabaseMissing('client_medical_profiles', ['client_id' => $this->person->id]);
    }

    public function test_idempotent_retries_recheck_access_to_the_profile_link(): void
    {
        $data = ['request_uuid' => (string) Str::uuid(), 'allergen' => 'Penicillin', 'severity' => 'severe'];
        $this->postJson($this->api(), $data)->assertOk()->assertJsonPath('allergy_record.management_url', '/operations/clients/'.$this->person->id.'?tab=medical#allergy-record');
        $this->actor->permissionOverrides()->syncWithoutDetaching(Permission::whereIn('key', ['clients.viewAny', 'clients.viewAssigned'])
            ->pluck('id')->mapWithKeys(fn ($id) => [$id => ['allowed' => false]])->all());
        $this->actingAs($this->actor->fresh())->postJson($this->api(), $data)->assertOk()->assertJsonPath('allergy_record.management_url', null);
        $this->assertSame(1, MedicationEvent::where('kind', 'allergy.edit')->count());
    }

    public static function canonicalCommitDuringLegacySave(): array
    {
        return [
            'copied/reviewed after legacy profile read' => [true, false],
            'copied/reviewed after absent profile read' => [false, false],
            'no-known review after legacy profile read' => [true, true],
            'no-known review after absent profile read' => [false, true],
        ];
    }

    #[DataProvider('canonicalCommitDuringLegacySave')]
    public function test_an_unchanged_legacy_form_uses_the_current_locked_profile_after_a_concurrent_review(bool $profileExisted, bool $noKnown): void
    {
        if ($profileExisted) {
            ClientMedicalProfile::create(['client_id' => $this->person->id, 'allergies' => []]);
        }
        $primary = DB::connection();
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame('mysql', $primary->getPdo()->getAttribute(\PDO::ATTR_DRIVER_NAME));
        $this->assertSame('127.0.0.1', $primary->getConfig('host'));
        $this->assertSame(self::$isolatedMysqlDatabase, $primary->getDatabaseName());
        $this->assertStringEndsWith('_'.getmypid(), $primary->getDatabaseName());
        $this->beforeApplicationDestroyed(CommittedFixtureCleanup::capture()->restore(...));
        DB::commit();
        $name = 'concurrent_canonical_allergy_writer';
        $config = $primary->getConfig();
        $config['name'] = $name;
        config(['database.connections.'.$name => $config]);
        $writer = DB::connection($name);
        $this->assertNotSame($primary->getPdo(), $writer->getPdo());
        $this->assertSame($primary->getDatabaseName(), $writer->getDatabaseName());
        $this->assertSame('REPEATABLE-READ', $primary->selectOne('SELECT @@SESSION.transaction_isolation AS isolation_level')->isolation_level);
        $entries = $noKnown ? [] : [['key' => (string) Str::uuid(), 'allergen' => 'Penicillin', 'severity' => 'severe', 'reaction' => 'Anaphylaxis']];
        $reviewedAt = now()->format('Y-m-d H:i:s');
        $record = [
            'allergies' => json_encode($noKnown ? [] : ['Penicillin'], JSON_THROW_ON_ERROR),
            'allergy_records' => json_encode($entries, JSON_THROW_ON_ERROR), 'allergies_canonical_at' => $reviewedAt,
            'allergies_reviewed_at' => $reviewedAt, 'allergies_reviewed_by' => $this->actor->id,
            'allergies_review_status' => $noKnown ? 'no_known' : 'reviewed', 'allergies_review_method' => 'Committed GP review',
            'allergies_review_digest' => app(ClientAllergyRecordService::class)->digest($entries),
        ];
        $primary->beginTransaction();
        try {
            // This is the older consistent read held by a medical-only form
            // save. The other session commits canonical meaning afterwards.
            $old = $primary->table('client_medical_profiles')->where('client_id', $this->person->id)->value('allergies_canonical_at');
            $this->assertNull($old);
            $writer->transaction(function () use ($writer, $record): void {
                $writer->table('clients')->where('id', $this->person->id)->lockForUpdate()->first();
                $writer->table('client_medical_profiles')->updateOrInsert(['client_id' => $this->person->id], $record);
            });
            $this->assertNull($primary->table('client_medical_profiles')->where('client_id', $this->person->id)->value('allergies_canonical_at'));
            $response = $this->put('/operations/clients/'.$this->person->id, [
                'first_name' => $this->person->first_name, 'last_name' => $this->person->last_name,
                'status' => $this->person->status, 'medical' => ['allergies' => []],
            ])->assertRedirect();
            if ($noKnown) {
                $response->assertSessionHas('success')->assertSessionMissing('error');
            } else {
                $response->assertSessionHas('error');
            }
            $current = ClientMedicalProfile::where('client_id', $this->person->id)->lockForUpdate()->firstOrFail();
            $this->assertSame($record['allergies_review_digest'], $current->allergies_review_digest);
            $this->assertSame('Committed GP review', $current->allergies_review_method);
            $this->assertNotNull($current->allergies_reviewed_at);
            $this->assertSame($noKnown ? 'no_known' : 'reviewed', $current->allergies_review_status);
            $this->assertSame($noKnown ? [] : ['Penicillin'], $current->allergies);
            $this->assertEquals($entries, $current->allergy_records);
        } finally {
            while ($primary->transactionLevel() > 0) {
                $primary->rollBack();
            }
            DB::purge($name);
        }
    }

    public static function medicalNotificationCommands(): array
    {
        return array_combine(
            ['profile', 'condition_create', 'condition_update', 'condition_remove', 'contact_create', 'contact_update', 'contact_remove'],
            array_map(fn ($action) => [$action], ['profile', 'condition_create', 'condition_update', 'condition_remove', 'contact_create', 'contact_update', 'contact_remove']),
        );
    }

    #[DataProvider('medicalNotificationCommands')]
    public function test_medical_save_is_successful_when_its_notification_delivery_fails(string $action): void
    {
        $base = '/operations/clients/'.$this->person->id.'/medical';
        $condition = in_array($action, ['condition_update', 'condition_remove'], true)
            ? ClientCondition::create(['client_id' => $this->person->id, 'label' => 'Original condition']) : null;
        $contact = in_array($action, ['contact_update', 'contact_remove'], true)
            ? ClientEmergencyContact::create(['client_id' => $this->person->id, 'name' => 'Original contact']) : null;
        $this->mock(NotificationService::class)->shouldReceive('notifyCrud')->once()
            ->andThrow(new \RuntimeException('Synthetic medical notification delivery failure'));
        $response = match ($action) {
            'profile' => $this->put($base.'/profile', ['medical_history' => 'Saved history']),
            'condition_create' => $this->post($base.'/conditions', ['label' => 'Saved condition']),
            'condition_update' => $this->put($base.'/conditions/'.$condition->id, ['label' => 'Saved condition']),
            'condition_remove' => $this->delete($base.'/conditions/'.$condition->id),
            'contact_create' => $this->post($base.'/emergency-contacts', ['name' => 'Saved contact']),
            'contact_update' => $this->put($base.'/emergency-contacts/'.$contact->id, ['name' => 'Saved contact']),
            'contact_remove' => $this->delete($base.'/emergency-contacts/'.$contact->id),
        };
        $response->assertRedirect()->assertSessionHas('success')->assertSessionMissing('error');
        if ($action === 'profile') {
            $this->assertDatabaseHas('client_medical_profiles', ['client_id' => $this->person->id, 'medical_history' => 'Saved history']);
        } elseif (str_starts_with($action, 'condition_')) {
            $this->assertSame($action === 'condition_remove' ? 0 : 1, ClientCondition::where('client_id', $this->person->id)->count());
            if ($action !== 'condition_remove') {
                $this->assertDatabaseHas('client_conditions', ['client_id' => $this->person->id, 'label' => 'Saved condition']);
            }
            if ($action === 'condition_create') {
                $this->assertSame(1, TimelineEvent::where('client_id', $this->person->id)->where('source_type', ClientCondition::class)->count());
            }
        } else {
            $this->assertSame($action === 'contact_remove' ? 0 : 1, ClientEmergencyContact::where('client_id', $this->person->id)->count());
            if ($action !== 'contact_remove') {
                $this->assertDatabaseHas('client_emergency_contacts', ['client_id' => $this->person->id, 'name' => 'Saved contact']);
            }
        }
    }

    public function test_medical_condition_and_timeline_both_roll_back_if_timeline_delivery_fails(): void
    {
        $emitter = app(TimelineEmitter::class);
        $this->mock(TimelineEmitter::class)->shouldReceive('record')->once()->andReturnUsing(function (array $payload) use ($emitter) {
            $emitter->record($payload);
            throw new \RuntimeException('Synthetic timeline failure after insertion');
        });
        $this->mock(NotificationService::class)->shouldNotReceive('notifyCrud');
        $this->post('/operations/clients/'.$this->person->id.'/medical/conditions', ['label' => 'Rolled back condition'])
            ->assertRedirect()->assertSessionHas('error')->assertSessionMissing('success');
        $this->assertDatabaseMissing('client_conditions', ['client_id' => $this->person->id]);
        $this->assertDatabaseMissing('timeline_events', ['client_id' => $this->person->id, 'source_type' => ClientCondition::class]);
    }

    public function test_medical_deep_links_require_the_medical_section_read_authority(): void
    {
        $this->actor->permissionOverrides()->syncWithoutDetaching(Permission::where('key', 'medications.view')->pluck('id')
            ->mapWithKeys(fn ($id) => [$id => ['allowed' => false]])->all());
        $actor = $this->actor->fresh();
        $this->assertTrue($actor->can('view', $this->person));
        $this->assertTrue($actor->can('update', $this->person));
        $this->assertFalse($actor->can('viewMedications', $this->person));
        $this->assertNull(app(ClientAllergyRecordService::class)->managementUrl($this->person, $actor));
        $this->assertNull(app(HealthSummaryService::class)->forClient($actor, $this->person)['allergy_management_url']);
        $this->assertNull(app(ClinicalDashboardService::class)->getClinicalCard($actor, $this->person)['allergy_management_url']);
        $this->actingAs($actor);
        foreach (['profile', 'conditions', 'emergency_contacts'] as $section) {
            $this->get('/clients/'.$this->person->id.'/medical?section='.$section)->assertForbidden();
            $this->get('/operations/clients/'.$this->person->id.'/medical?section='.$section)->assertForbidden();
        }
    }

    #[DataProvider('medicalNotificationCommands')]
    public function test_medical_write_rejects_client_editors_without_the_medical_section_read_authority(string $action): void
    {
        $condition = ClientCondition::create(['client_id' => $this->person->id, 'label' => 'Original condition']);
        $contact = ClientEmergencyContact::create(['client_id' => $this->person->id, 'name' => 'Original contact']);
        $this->actor->permissionOverrides()->syncWithoutDetaching(Permission::where('key', 'medications.view')->pluck('id')
            ->mapWithKeys(fn ($id) => [$id => ['allowed' => false]])->all());
        $this->actingAs($this->actor->fresh());
        $this->mock(NotificationService::class)->shouldNotReceive('notifyCrud');
        foreach (['/clients/', '/operations/clients/'] as $prefix) {
            $base = $prefix.$this->person->id.'/medical';
            $response = match ($action) {
                'profile' => $this->put($base.'/profile', ['medical_history' => 'Forbidden change']),
                'condition_create' => $this->post($base.'/conditions', ['label' => 'Forbidden condition']),
                'condition_update' => $this->put($base.'/conditions/'.$condition->id, ['label' => 'Forbidden condition']),
                'condition_remove' => $this->delete($base.'/conditions/'.$condition->id),
                'contact_create' => $this->post($base.'/emergency-contacts', ['name' => 'Forbidden contact']),
                'contact_update' => $this->put($base.'/emergency-contacts/'.$contact->id, ['name' => 'Forbidden contact']),
                'contact_remove' => $this->delete($base.'/emergency-contacts/'.$contact->id),
            };
            $response->assertForbidden();
        }
        $this->assertDatabaseMissing('client_medical_profiles', ['client_id' => $this->person->id]);
        $this->assertSame('Original condition', $condition->fresh()->label);
        $this->assertSame('Original contact', $contact->fresh()->name);
        $this->assertSame(1, ClientCondition::where('client_id', $this->person->id)->count());
        $this->assertSame(1, ClientEmergencyContact::where('client_id', $this->person->id)->count());
    }

    public static function broadMedicalKeys(): array
    {
        return ['medical' => ['medical'], 'conditions' => ['conditions'], 'emergency_contacts' => ['emergency_contacts']];
    }

    #[DataProvider('broadMedicalKeys')]
    public function test_broad_medical_writes_require_read_access_while_ordinary_client_updates_remain_allowed(string $key): void
    {
        $profile = ClientMedicalProfile::create(['client_id' => $this->person->id, 'medical_history' => 'Sensitive medical history']);
        $condition = ClientCondition::create(['client_id' => $this->person->id, 'label' => 'Sensitive condition']);
        $contact = ClientEmergencyContact::create(['client_id' => $this->person->id, 'name' => 'Sensitive contact']);
        $this->actor->permissionOverrides()->syncWithoutDetaching(Permission::where('key', 'medications.view')->pluck('id')
            ->mapWithKeys(fn ($id) => [$id => ['allowed' => false]])->all());
        $this->actingAs($this->actor->fresh());
        $ordinary = ['first_name' => $this->person->first_name, 'last_name' => $this->person->last_name, 'status' => $this->person->status, '_modal' => true];
        foreach (['/clients/', '/operations/clients/'] as $prefix) {
            $this->put($prefix.$this->person->id, [...$ordinary, 'first_name' => 'Forbidden change', $key => []])->assertForbidden();
        }
        $this->assertSame($ordinary['first_name'], $this->person->fresh()->first_name);
        $this->mock(NotificationService::class)->shouldReceive('notifyCrud')->once()
            ->andThrow(new \RuntimeException('Synthetic ordinary client notification failure'));
        $this->put('/operations/clients/'.$this->person->id, [...$ordinary, 'preferred_name' => 'Ordinary change'])
            ->assertRedirect()->assertSessionHas('success')->assertSessionMissing('error');
        $this->assertSame('Ordinary change', $this->person->fresh()->preferred_name);
        $this->assertSame('Sensitive medical history', $profile->fresh()->medical_history);
        $this->assertSame('Sensitive condition', $condition->fresh()->label);
        $this->assertSame('Sensitive contact', $contact->fresh()->name);
    }

    public function test_client_edit_payload_omits_medical_values_without_the_medical_section_read_authority(): void
    {
        ClientMedicalProfile::create(['client_id' => $this->person->id, 'medical_history' => 'Sensitive medical history']);
        ClientCondition::create(['client_id' => $this->person->id, 'label' => 'Sensitive condition']);
        ClientEmergencyContact::create(['client_id' => $this->person->id, 'name' => 'Sensitive contact']);
        $url = '/operations/clients/'.$this->person->id.'/edit?modal=1';
        $this->getJson($url)->assertOk()->assertJsonPath('can_edit_medical', true)
            ->assertJsonPath('initialValues.medical.medical_history', 'Sensitive medical history');
        $this->actor->permissionOverrides()->syncWithoutDetaching(Permission::where('key', 'medications.view')->pluck('id')
            ->mapWithKeys(fn ($id) => [$id => ['allowed' => false]])->all());
        $this->actingAs($this->actor->fresh())->getJson($url)->assertOk()->assertJsonPath('can_edit_medical', false)
            ->assertJsonMissingPath('initialValues.medical')->assertJsonMissingPath('initialValues.conditions')
            ->assertJsonMissingPath('initialValues.emergency_contacts');
    }

    private function assertCurrentMeaning(string $status, bool $reviewed): array
    {
        $summary = app(ClientAllergyRecordService::class)->summary($this->person);
        $this->assertSame($status, $summary['status']);
        $this->assertSame($reviewed, $summary['reviewed'] !== null);
        $canonical = $this->getJson($this->canonical())->assertOk();
        foreach ($summary as $field => $value) {
            $canonical->assertJsonPath($field, $value);
        }
        $api = $this->getJson($this->api())->assertOk();
        $this->assertSame($summary, $api->json('allergy_record'));
        $this->assertSame(array_column($summary['entries'], 'allergen'), array_column($api->json('allergies'), 'allergen'));
        $this->assertSame(array_column($summary['entries'], 'allergen'), array_column($api->json('recorded_allergies'), 'allergen'));
        $health = app(HealthSummaryService::class)->forClient($this->actor, $this->person);
        $card = app(ClinicalDashboardService::class)->getClinicalCard($this->actor, $this->person);
        $this->assertSame($summary, $health['allergy_record']);
        $this->assertSame($summary, $card['allergy_record']);
        $this->assertSame(array_column($summary['entries'], 'allergen'), $card['allergies']);
        $url = '/operations/clients/'.$this->person->id.'?tab=medical#allergy-record';
        $canonical->assertJsonPath('management_url', $url);
        $this->assertSame($url, $health['allergy_management_url']);
        $this->assertSame($url, $card['allergy_management_url']);

        return $summary;
    }

    private function canonical(): string
    {
        return '/emar/clients/'.$this->person->id.'/record/allergies';
    }

    private function api(): string
    {
        return route('api.medications.allergies.index', $this->person);
    }

    private function review(array $summary, bool $noKnown = false)
    {
        return $this->postJson($this->canonical(), ['action' => 'review', 'request_uuid' => (string) Str::uuid(),
            'digest' => $summary['digest'], 'method' => 'Checked the person and GP record', 'no_known' => $noKnown]);
    }

    private function edit(array $summary, array $entries)
    {
        return $this->postJson($this->canonical(), ['action' => 'edit', 'request_uuid' => (string) Str::uuid(),
            'digest' => $summary['digest'], 'records' => $entries]);
    }
}
