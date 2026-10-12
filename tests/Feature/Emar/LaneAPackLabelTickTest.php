<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AppSetting;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\ClientMedicationStock;
use App\Models\MedicationCompetencyAssessment;
use App\Models\MedicationFollowup;
use App\Models\MedicationStockLot;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Services\Medication\Recording\PackPhotoSettings;
use App\Services\Medication\Stock\PackPhotoWriter;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\RecordsDosesLikeTheDialog;
use Tests\TestCase;

/**
 * eMAR audit Lane A11 (Main, 13 Oct): the "I've checked the label" tick is
 * checked on the server, not only in the dialog. When the organisation asks
 * for it (Medicine photos › At recording › When there's no photo › Ask for
 * a tick) and the worker sees no photo of the pack, a "given" dose without
 * the tick is refused like any other recording-contract error and nothing
 * is saved. The tick is kept with the dose it was given for.
 */
class LaneAPackLabelTickTest extends TestCase
{
    use RefreshDatabase;
    use RecordsDosesLikeTheDialog;

    private Site $site;

    private Client $client;

    private User $worker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock('2026-04-30 09:30');
        $this->seed(RbacSeeder::class);
        Cache::flush();
        Storage::fake('private');
        $this->site = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $context = ServiceContext::factory()->create(['type' => 'residential', 'is_active' => true]);
        $this->client = Client::factory()->create([
            'site_id' => $this->site->id, 'service_context_id' => $context->id, 'status' => 'active',
            'first_name' => 'Tama', 'preferred_name' => 'Tama',
        ]);
        $this->worker = $this->staff();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_a_given_dose_without_the_required_tick_is_refused_and_the_tick_is_kept_with_the_dose(): void
    {
        $this->setting(PackPhotoSettings::NO_PHOTO, 'tick');
        $order = $this->order(['name' => 'Synthetic quetiapine', 'dosage' => '50 mg', 'dose_amount' => 50, 'dose_unit' => 'mg']);

        $this->record($order)
            ->assertUnprocessable()
            ->assertJsonPath('error_field', 'label_checked')
            ->assertJsonPath('error', PackPhotoSettings::LABEL_TICK_MESSAGE)
            ->assertJsonPath('errors.label_checked.0', PackPhotoSettings::LABEL_TICK_MESSAGE)
            ->assertJsonPath('sync.status', 'rejected');
        $this->record($order, ['label_checked' => false])->assertUnprocessable()->assertJsonPath('error_field', 'label_checked');
        $this->assertSame(0, ClientMedicationAdministration::count());

        $this->record($order, ['label_checked' => true])->assertOk()->assertJsonPath('sync.status', 'processed');
        $dose = ClientMedicationAdministration::sole();
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'medications.dose.label_checked', 'user_id' => $this->worker->id,
            'auditable_type' => $dose->getMorphClass(), 'auditable_id' => $dose->id,
        ]);
    }

    public function test_the_tick_is_not_needed_with_a_warning_a_refusal_photos_off_or_a_photo_of_the_pack(): void
    {
        $order = $this->order(['name' => 'Synthetic quetiapine', 'dosage' => '50 mg', 'dose_amount' => 50, 'dose_unit' => 'mg']);

        // The default: a warning, which never stops recording.
        $this->record($order)->assertOk();

        $this->setting(PackPhotoSettings::NO_PHOTO, 'tick');
        // A refusal documents what happened; there's no label to check.
        $refusedOrder = $this->order(['name' => 'Synthetic olanzapine', 'dosage' => '5 mg', 'dose_amount' => 5, 'dose_unit' => 'mg']);
        $this->record($refusedOrder, ['status' => 'refused', 'reason_code' => 'refused', 'amount_mode' => null,
            'follow_up_due_at' => '2026-04-30T10:30:00'])->assertOk();

        // Photos off at recording: the dialog shows no card, so no tick.
        $second = $this->order(['name' => 'Synthetic sertraline', 'dosage' => '50 mg', 'dose_amount' => 50, 'dose_unit' => 'mg']);
        $this->setting(PackPhotoSettings::SHOW, 'no');
        $this->record($second)->assertOk();

        // A photo of the supplied pack: the worker compares it with the label.
        $this->setting(PackPhotoSettings::SHOW, 'yes');
        $third = $this->order(['name' => 'Synthetic lithium', 'dosage' => '250 mg', 'dose_amount' => 250, 'dose_unit' => 'mg']);
        $this->photograph($third);
        $this->record($third)->assertOk();

        $this->assertSame(4, ClientMedicationAdministration::count());
        $this->assertDatabaseMissing('audit_logs', ['action' => 'medications.dose.label_checked']);
    }

    public function test_an_as_needed_dose_needs_the_tick_too(): void
    {
        $this->setting(PackPhotoSettings::NO_PHOTO, 'tick');
        $prn = $this->order(['name' => 'Synthetic paracetamol', 'dosage' => '1 tablet', 'dose_amount' => 1, 'dose_unit' => 'tablet',
            'frequency' => 'As needed', 'dose_times' => [], 'is_prn' => true, 'prn_reason' => 'Pain', 'min_hours_between_doses' => 4]);
        $body = ['client_medication_id' => $prn->id, 'reason' => 'Pain', 'administered_at' => '2026-04-30T09:20:00', 'amount_mode' => 'as_ordered'];

        $this->actingAs($this->worker->fresh())->postJson('/meds/today/prn', [...$body, 'client_request_uuid' => (string) Str::uuid()])
            ->assertUnprocessable()
            ->assertJsonPath('error_field', 'label_checked');
        $this->assertSame(0, ClientMedicationAdministration::count());

        $this->postJson('/meds/today/prn', [...$body, 'client_request_uuid' => (string) Str::uuid(), 'label_checked' => true])->assertOk();
        $this->assertSame(1, ClientMedicationAdministration::count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'medications.dose.label_checked']);
    }

    /** A dose saved on a device without the tick is refused on replay, and a lead is told. */
    public function test_a_queued_dose_without_the_required_tick_is_parked_for_a_lead(): void
    {
        $this->setting(PackPhotoSettings::NO_PHOTO, 'tick');
        $order = $this->order(['name' => 'Synthetic quetiapine', 'dosage' => '50 mg', 'dose_amount' => 50, 'dose_unit' => 'mg']);

        $this->record($order, [
            'queued_offline' => true, 'captured_offline_at' => CarbonImmutable::now('Pacific/Auckland')->toIso8601String(),
            'origin_device_id' => 'synthetic-house-laptop', 'captured_by_user_id' => $this->worker->id,
        ])->assertUnprocessable()->assertJsonPath('error_field', 'label_checked')->assertJsonPath('sync.status', 'rejected');
        $this->assertSame(0, ClientMedicationAdministration::count());
        $this->assertSame(1, MedicationFollowup::query()->where('type', 'offline_review')->count());
    }

    private function clock(string $nz): void
    {
        $at = CarbonImmutable::parse($nz, 'Pacific/Auckland')->utc();
        Carbon::setTestNow($at);
        CarbonImmutable::setTestNow($at);
    }

    private function setting(string $key, string $value): void
    {
        AppSetting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }

    private function order(array $attributes): ClientMedication
    {
        $now = Carbon::getTestNow();
        $this->clock('2026-04-29 00:00');
        $order = ClientMedication::create([
            'client_id' => $this->client->id, 'frequency' => 'Daily', 'dose_times' => ['09:30'], 'is_prn' => false,
            'controlled_drug' => false, 'active' => true, 'state' => 'active', 'approval_status' => 'verified',
            'verified_by' => $this->worker->id, 'verified_at' => now(), 'version' => 1,
            ...$attributes,
        ]);
        Carbon::setTestNow($now);
        CarbonImmutable::setTestNow($now);

        return $order;
    }

    /** A staff photo of the pack, as Lane C's receive dialog takes it. */
    private function photograph(ClientMedication $order): void
    {
        $stock = ClientMedicationStock::query()->firstOrCreate(['client_medication_id' => $order->id], ['on_hand' => '0.00', 'unit' => 'tablets']);
        $lot = MedicationStockLot::query()->create([
            'client_medication_stock_id' => $stock->id, 'batch_number' => 'SYN-1', 'expiry_date' => now()->addYear()->toDateString(),
            'quantity_received' => '10.00', 'quantity_remaining' => '10.00', 'state' => 'open', 'source' => 'pharmacy',
            'source_reference' => 'Synthetic delivery', 'received_by' => $this->worker->id, 'received_at' => now(),
        ]);
        DB::transaction(function () use ($order, $lot): void {
            $path = null;
            app(PackPhotoWriter::class)->create($this->worker, $this->client, $order, $lot,
                UploadedFile::fake()->image('pack.jpg'), (string) Str::uuid(), null, false, $path);
        });
    }

    private function record(ClientMedication $order, array $fields = [])
    {
        return $this->actingAs($this->worker->fresh())->postJson('/meds/today/record', array_filter([
            'client_medication_id' => $order->id,
            'client_request_uuid' => (string) Str::uuid(),
            'scheduled_for' => '2026-04-30T09:30:00+12:00',
            'status' => 'given',
            'administered_at' => '2026-04-30T09:30:00',
            'amount_mode' => 'as_ordered',
            ...$fields,
        ], fn ($value) => $value !== null));
    }

    private function staff(): User
    {
        $user = User::factory()->create(['name' => 'Priya Walk', 'role' => 'support_worker', 'approved_at' => now()]);
        $user->roles()->syncWithoutDetaching([Role::where('name', 'support_worker')->sole()->id]);
        $keys = ['medications.view', 'medications.stock.update', 'medications.administer.record'];
        $permissions = Permission::whereIn('key', $keys)->get();
        $user->permissionOverrides()->syncWithoutDetaching($permissions->mapWithKeys(fn ($p) => [$p->id => ['allowed' => true]])->all());
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $this->site->id, 'secondary_site_ids' => [],
            'start_date' => now()->subYear(), 'end_date' => null, 'is_active' => true]);
        MedicationCompetencyAssessment::create(['user_id' => $user->id, 'assessor_id' => User::factory()->create()->id, 'assessment_type' => 'annual', 'status' => 'passed',
            'assessment_date' => now()->subMonth()->toDateString(), 'expiry_date' => now()->addYear()->toDateString(),
            'assessor_declared_at' => now()->subMonth(), 'staff_acknowledged_at' => now()->subMonth()->addMinute(),
            'can_administer_unsupervised' => true, 'can_witness_controlled' => true, 'controlled_drugs' => true, 'restricted' => false, 'not_seen_areas' => []]);
        Shift::factory()->create(['user_id' => $user->id, 'client_id' => $this->client->id, 'site_id' => $this->site->id,
            'service_context_id' => $this->client->service_context_id, 'starts_at' => now()->subHour()->utc(), 'ends_at' => now()->addHours(8)->utc(),
            'actual_starts_at' => now()->subMinutes(30)->utc(), 'actual_ends_at' => null, 'status' => 'in_progress']);
        Cache::flush();

        return $user->fresh();
    }
}
