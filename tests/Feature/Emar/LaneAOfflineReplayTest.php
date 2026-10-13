<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\MedicationCompetencyAssessment;
use App\Models\MedicationFollowup;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Services\Medication\Recording\OfflineReplayReview;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\Support\RecordsDosesLikeTheDialog;

/**
 * eMAR audit Lane A3 (EA-004, EA-047, EA-049, EA-050, EA-131): a dose saved
 * on a device and sent later is never silently discarded, never recorded
 * under another worker, never accepted when it is too old, and every refusal
 * leaves a lead follow-up with what the device captured.
 */
class LaneAOfflineReplayTest extends TestCase
{
    use RefreshDatabase;
    use RecordsDosesLikeTheDialog;

    private Site $site;

    private Client $client;

    private User $worker;

    private User $colleague;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock('2026-04-30 09:30');
        $this->seed(RbacSeeder::class);
        Cache::flush();
        $this->site = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $context = ServiceContext::factory()->create(['type' => 'residential', 'is_active' => true]);
        $this->client = Client::factory()->create(['site_id' => $this->site->id, 'service_context_id' => $context->id, 'status' => 'active',
            'first_name' => 'Mary', 'preferred_name' => 'Mary']);
        $this->worker = $this->staff('Worker A');
        $this->colleague = $this->staff('Worker B');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** EA-004: B recorded the dose first; A's queued "given" is a conflict for a lead, not "sent". */
    public function test_a_queued_dose_someone_else_recorded_meanwhile_is_a_conflict_with_a_lead_follow_up(): void
    {
        $order = $this->order(['name' => 'Synthetic valproate', 'dosage' => '1 tablet', 'dose_amount' => 1, 'dose_unit' => 'tablet']);
        $this->actingAs($this->colleague)->postJson('/meds/today/record', $this->dose($order, ['client_request_uuid' => (string) Str::uuid()]))
            ->assertOk();

        $queued = $this->queued($this->dose($order, ['status' => 'given']), $this->worker);
        $this->actingAs($this->worker)->postJson('/meds/today/record', $queued)
            ->assertStatus(409)
            ->assertJsonPath('sync.status', 'conflict')
            ->assertJsonPath('duplicate_of.by', 'Worker B')
            ->assertJsonPath('error', 'Someone else recorded this dose while you were offline — a lead must review it.');

        $this->assertSame(1, ClientMedicationAdministration::count());
        $followup = MedicationFollowup::sole();
        $this->assertSame('offline_review', $followup->type);
        $this->assertSame($order->id, (int) $followup->client_medication_id);
        $this->assertNull($followup->owner_id);
        $this->assertSame('duplicate', $followup->context['reason_key']);
        $this->assertSame('given', $followup->context['outcome']);
        $this->assertSame($this->worker->id, (int) $followup->context['captured_by']);
        $this->assertSame($queued['client_request_uuid'], $followup->context['client_request_uuid']);

        // The same queued item sent again does not raise a second follow-up.
        $this->postJson('/meds/today/record', $queued)->assertStatus(409);
        $this->assertSame(1, MedicationFollowup::count());

        // Walk finding: the device then reports the parked item. The server
        // already raised the follow-up, so the report is accepted, not a 404.
        $this->postJson('/meds/today/offline-review', [
            'client_request_uuid' => $queued['client_request_uuid'], 'client_medication_id' => $order->id,
            'captured_by_user_id' => $this->worker->id, 'status' => 'given', 'http_status' => 409,
            'reason' => 'Someone else recorded this dose while you were offline — a lead must review it.',
            'scheduled_for' => $queued['scheduled_for'], 'captured_offline_at' => $queued['captured_offline_at'],
        ])->assertOk()->assertJsonPath('status', 'reported');
        $this->assertSame(1, MedicationFollowup::count());
    }

    /** EA-004: the worker's own queued item coming back is still confirmed as theirs. */
    public function test_the_workers_own_queued_dose_replayed_again_is_still_confirmed(): void
    {
        $order = $this->order(['name' => 'Synthetic valproate', 'dosage' => '1 tablet', 'dose_amount' => 1, 'dose_unit' => 'tablet']);
        $queued = $this->queued($this->dose($order), $this->worker);
        $this->actingAs($this->worker)->postJson('/meds/today/record', $queued)->assertOk()->assertJsonPath('sync.status', 'synced');
        $this->postJson('/meds/today/record', $queued)->assertOk()->assertJsonPath('replayed', true);
        $this->assertSame(1, ClientMedicationAdministration::count());
        $this->assertSame(0, MedicationFollowup::count());
    }

    /** EA-047: a stale tab sends A's queued dose with B's session; it is refused, not recorded as B's. */
    public function test_a_queued_dose_captured_by_another_worker_is_refused(): void
    {
        $order = $this->order(['name' => 'Synthetic valproate', 'dosage' => '1 tablet', 'dose_amount' => 1, 'dose_unit' => 'tablet']);
        $queued = $this->queued($this->dose($order), $this->worker);

        $this->actingAs($this->colleague)->postJson('/meds/today/record', $queued)
            ->assertUnprocessable()->assertJsonValidationErrors('captured_by_user_id');
        $this->actingAs($this->colleague)->postJson('/meds/today/prn', [...$queued, 'reason' => 'Pain'])
            ->assertUnprocessable()->assertJsonValidationErrors('captured_by_user_id');
        $this->assertSame(0, ClientMedicationAdministration::count());

        // A can still send it when signed in.
        $this->actingAs($this->worker)->postJson('/meds/today/record', $queued)->assertOk();
        $this->assertSame($this->worker->id, (int) ClientMedicationAdministration::sole()->administered_by);
    }

    /** EA-131: a dose saved on a device long ago needs reviewed paper recovery, not a silent insert. */
    public function test_a_queued_dose_older_than_the_replay_limit_is_refused_for_review(): void
    {
        $order = $this->order(['name' => 'Synthetic valproate', 'dosage' => '1 tablet', 'dose_amount' => 1, 'dose_unit' => 'tablet']);
        $captured = CarbonImmutable::parse('2026-04-27 09:30', 'Pacific/Auckland');
        $queued = $this->queued($this->dose($order, [
            'scheduled_for' => $captured->toIso8601String(), 'administered_at' => $captured->format('Y-m-d\TH:i:s'),
        ]), $this->worker, $captured);

        $this->actingAs($this->worker)->postJson('/meds/today/record', $queued)
            ->assertUnprocessable()
            ->assertJsonPath('error_field', 'queued_offline')
            ->assertJsonPath('sync.status', 'rejected');
        $this->assertStringContainsString('Too old to sync', $this->postJson('/meds/today/record', $queued)->json('error'));
        $this->assertSame(0, ClientMedicationAdministration::count());
        $this->assertSame('too_old', MedicationFollowup::sole()->context['reason_key']);
    }

    /** EA-049: a given dose the server refuses on replay (PRN interval) raises a lead follow-up. */
    public function test_a_queued_as_needed_dose_refused_on_replay_raises_a_lead_follow_up(): void
    {
        $prn = $this->order(['name' => 'Synthetic ibuprofen', 'dosage' => '1 tablet', 'dose_amount' => 1, 'dose_unit' => 'tablet',
            'frequency' => 'As needed', 'dose_times' => [], 'is_prn' => true, 'prn_reason' => 'Pain', 'min_hours_between_doses' => 6]);
        $this->actingAs($this->colleague)->postJson('/meds/today/prn', [
            'client_medication_id' => $prn->id, 'client_request_uuid' => (string) Str::uuid(), 'reason' => 'Pain',
            'administered_at' => '2026-04-30T09:00:00', 'amount_mode' => 'as_ordered',
        ])->assertOk();

        $queued = $this->queued(['client_medication_id' => $prn->id, 'reason' => 'Pain', 'administered_at' => '2026-04-30T09:20:00',
            'amount_mode' => 'as_ordered'], $this->worker, CarbonImmutable::parse('2026-04-30 09:20', 'Pacific/Auckland'));
        $this->actingAs($this->worker)->postJson('/meds/today/prn', $queued)
            ->assertUnprocessable()->assertJsonPath('sync.status', 'rejected');

        // (The colleague's dose has its own effect-check follow-up.)
        $followup = MedicationFollowup::where('type', 'offline_review')->sole();
        $this->assertSame('refused', $followup->context['reason_key']);
        $this->assertSame('given', $followup->context['outcome']);
        $this->assertStringContainsString('INTERVAL NOT ELAPSED', $followup->context['reason']);
        $this->assertSame(1, ClientMedicationAdministration::count());
    }

    /** EA-050: an order superseded after the dose was queued gets a clear refusal, not a bare 404. */
    public function test_a_queued_dose_for_a_superseded_order_is_refused_with_its_reason(): void
    {
        $order = $this->order(['name' => 'Synthetic valproate', 'dosage' => '1 tablet', 'dose_amount' => 1, 'dose_unit' => 'tablet']);
        $queued = $this->queued($this->dose($order), $this->worker);
        $order->fresh()->createVersion($this->colleague->id, 'Dose changed');

        $this->actingAs($this->worker)->postJson('/meds/today/record', $queued)
            ->assertUnprocessable()
            ->assertJsonPath('error_field', 'client_medication_id')
            ->assertJsonPath('sync.status', 'rejected');
        $this->assertSame(0, ClientMedicationAdministration::count());
        $this->assertSame('order_changed', MedicationFollowup::sole()->context['reason_key']);
    }

    /** EA-049/EA-050: the device reports a refusal the server could not record itself (e.g. access ended). */
    public function test_the_device_reports_a_parked_dose_once_for_a_lead(): void
    {
        $order = $this->order(['name' => 'Synthetic valproate', 'dosage' => '1 tablet', 'dose_amount' => 1, 'dose_unit' => 'tablet']);
        $uuid = (string) Str::uuid();
        $report = [
            'client_request_uuid' => $uuid, 'client_medication_id' => $order->id, 'captured_by_user_id' => $this->worker->id,
            'captured_offline_at' => '2026-04-30T08:05:00+12:00', 'scheduled_for' => '2026-04-30T09:30:00+12:00',
            'status' => 'given', 'administered_at' => '2026-04-30T08:05:00', 'http_status' => 403,
            'reason' => 'You do not have a current assignment for this person.',
        ];

        $this->actingAs($this->colleague)->postJson('/meds/today/offline-review', $report)
            ->assertUnprocessable()->assertJsonValidationErrors('captured_by_user_id');
        $this->actingAs($this->worker)->postJson('/meds/today/offline-review', $report)
            ->assertOk()->assertJsonPath('status', 'reported');
        $this->postJson('/meds/today/offline-review', $report)->assertOk()->assertJsonPath('status', 'reported');

        $followup = MedicationFollowup::sole();
        $this->assertSame('offline_review', $followup->type);
        $this->assertSame('access', $followup->context['reason_key']);
        // Review item 4: the device's words are kept as its report.
        $this->assertSame('As reported by the device: You do not have a current assignment for this person.', $followup->context['reason']);
        $this->assertTrue($followup->context['reported_by_device']);
        $this->assertSame(0, ClientMedicationAdministration::count());
    }

    /** Review item 4: only someone who may record for the person now can raise lead work from a device. */
    public function test_a_device_report_needs_current_authority_and_controlled_record_for_a_controlled_order(): void
    {
        $order = $this->order(['name' => 'Synthetic valproate', 'dosage' => '1 tablet', 'dose_amount' => 1, 'dose_unit' => 'tablet']);
        $report = fn (ClientMedication $for): array => [
            'client_request_uuid' => (string) Str::uuid(), 'client_medication_id' => $for->id, 'captured_by_user_id' => $this->worker->id,
            'captured_offline_at' => '2026-04-30T08:05:00+12:00', 'status' => 'given', 'http_status' => 422, 'reason' => 'Refused.',
        ];

        // A controlled order needs controlled.record (the support worker role
        // has it; this worker's is withdrawn).
        $controlled = $this->order(['name' => 'Synthetic morphine', 'dosage' => '5 mg', 'dose_amount' => 5, 'dose_unit' => 'mg',
            'controlled_drug' => true, 'nz_controlled_class' => 'B', 'controlled_class_source' => 'Synthetic reviewed test class']);
        $this->worker->permissionOverrides()->syncWithoutDetaching([
            Permission::where('key', 'medications.controlled.record')->sole()->id => ['allowed' => false],
        ]);
        Cache::flush();
        $this->assertFalse($this->worker->fresh()->canDo('medications.controlled.record'));
        $this->actingAs($this->worker->fresh())->postJson('/meds/today/offline-review', $report($controlled))->assertNotFound();
        // The same report for an ordinary order is taken (authority is current).
        $this->postJson('/meds/today/offline-review', $report($order))->assertOk()->assertJsonPath('status', 'reported');
        $this->assertSame(1, MedicationFollowup::count());

        // Clocked out: the house is still in their access, but they can't record for the person now.
        Shift::query()->where('user_id', $this->worker->id)->update([
            'actual_ends_at' => now()->subHour()->utc(), 'status' => 'completed',
        ]);
        Cache::flush();
        $this->actingAs($this->worker->fresh())->postJson('/meds/today/offline-review', $report($order))->assertNotFound();
        $this->assertSame(1, MedicationFollowup::count());
    }

    /**
     * Review P1: a save that timed out online is kept as an ordinary dose and
     * can come back days later. Every replay says when the device saved it,
     * and the age limit applies whatever the flag says.
     */
    public function test_a_replayed_online_save_older_than_the_limit_is_refused_whatever_its_flag(): void
    {
        $order = $this->order(['name' => 'Synthetic valproate', 'dosage' => '1 tablet', 'dose_amount' => 1, 'dose_unit' => 'tablet']);
        $online = [...$this->dose($order), 'client_request_uuid' => (string) Str::uuid(), 'queued_offline' => false];
        $saved = CarbonImmutable::now('Pacific/Auckland')->subHours(13)->toIso8601String();

        $this->actingAs($this->worker)
            ->postJson('/meds/today/record', $online, [OfflineReplayReview::REPLAY_HEADER => $saved])
            ->assertUnprocessable()
            ->assertJsonPath('error_field', 'queued_offline');
        $this->assertSame(0, ClientMedicationAdministration::count());
        $this->assertSame('too_old', MedicationFollowup::where('type', 'offline_review')->sole()->context['reason_key']);

        // A replay saved an hour ago is recorded as usual.
        $recent = CarbonImmutable::now('Pacific/Auckland')->subHour()->toIso8601String();
        $this->postJson('/meds/today/record', [...$online, 'client_request_uuid' => (string) Str::uuid()], [OfflineReplayReview::REPLAY_HEADER => $recent])
            ->assertOk();
        $this->assertSame(1, ClientMedicationAdministration::count());
    }

    /** Review P1: the mobile API refuses an old queued dose the same way. */
    public function test_the_mobile_api_refuses_a_queued_dose_older_than_the_limit(): void
    {
        $order = $this->order(['name' => 'Synthetic valproate', 'dosage' => '1 tablet', 'dose_amount' => 1, 'dose_unit' => 'tablet']);
        $captured = CarbonImmutable::parse('2026-04-27 09:30', 'Pacific/Auckland');
        $queued = $this->queued($this->dose($order, [
            'scheduled_for' => $captured->toIso8601String(), 'administered_at' => $captured->format('Y-m-d\TH:i:s'),
        ]), $this->worker, $captured);

        $this->actingAs($this->worker)
            ->postJson("/api/medications/clients/{$this->client->id}/medications/{$order->id}/administrations", $queued)
            ->assertUnprocessable()
            ->assertJsonPath('error_field', 'queued_offline');
        $this->assertSame(0, ClientMedicationAdministration::count());
        $this->assertSame('too_old', MedicationFollowup::where('type', 'offline_review')->sole()->context['reason_key']);
    }

    /**
     * Review item 5 (EA-047): a controlled register entry, balance check,
     * destruction, loss report or stock update saved on the device by another
     * worker is refused under this session, before any handler runs.
     */
    public function test_a_queued_register_or_stock_action_saved_by_another_worker_is_refused(): void
    {
        foreach (['/emar/controlled/entries', '/emar/controlled/balance-check', '/emar/destructions',
            '/emar/controlled/loss-reports', '/emar/stock/adjust', '/emar/stock/receive'] as $url) {
            $this->actingAs($this->worker)->postJson($url, [
                'client_request_uuid' => (string) Str::uuid(), 'queued_offline' => true, 'captured_by_user_id' => $this->colleague->id,
            ])->assertUnprocessable()
                ->assertJsonValidationErrors(['captured_by_user_id' => 'saved on this device by another worker']);
        }

        // The worker's own saved action passes the guard (and meets the handler's own rules).
        $this->actingAs($this->worker)->postJson('/emar/stock/adjust', ['captured_by_user_id' => $this->worker->id])
            ->assertJsonMissingValidationErrors('captured_by_user_id');
    }

    /** Review item 7: medication pages encrypt their history, so Back after sign-out shows nothing. */
    public function test_medication_pages_encrypt_their_browser_history(): void
    {
        $page = $this->actingAs($this->worker)->get('/meds/today')->assertOk()->viewData('page');
        $this->assertTrue($page['encryptHistory']);
    }

    /** A dose that did reach the chart is never reported as a parked dose. */
    public function test_a_report_for_a_dose_already_on_the_chart_creates_nothing(): void
    {
        $order = $this->order(['name' => 'Synthetic valproate', 'dosage' => '1 tablet', 'dose_amount' => 1, 'dose_unit' => 'tablet']);
        $queued = $this->queued($this->dose($order), $this->worker);
        $this->actingAs($this->worker)->postJson('/meds/today/record', $queued)->assertOk();

        $this->postJson('/meds/today/offline-review', [
            'client_request_uuid' => $queued['client_request_uuid'], 'client_medication_id' => $order->id,
            'captured_by_user_id' => $this->worker->id, 'status' => 'given', 'http_status' => 500, 'reason' => 'Server error',
        ])->assertOk()->assertJsonPath('status', 'recorded');
        $this->assertSame(0, MedicationFollowup::count());
    }

    private function clock(string $nz): void
    {
        $at = CarbonImmutable::parse($nz, 'Pacific/Auckland')->utc();
        Carbon::setTestNow($at);
        CarbonImmutable::setTestNow($at);
    }

    private function order(array $attributes): ClientMedication
    {
        $now = Carbon::getTestNow();
        $this->clock('2026-04-20 00:00');
        $order = ClientMedication::create([
            'client_id' => $this->client->id, 'frequency' => 'Daily', 'dose_times' => ['09:30'], 'is_prn' => false,
            'controlled_drug' => false, 'active' => true, 'state' => 'active', 'approval_status' => 'verified', 'version' => 1,
            ...$attributes,
        ]);
        Carbon::setTestNow($now);
        CarbonImmutable::setTestNow($now);

        return $order;
    }

    private function dose(ClientMedication $order, array $fields = []): array
    {
        return [
            'client_medication_id' => $order->id,
            'scheduled_for' => '2026-04-30T09:30:00+12:00',
            'status' => 'given',
            'administered_at' => '2026-04-30T09:30:00',
            'amount_mode' => 'as_ordered',
            ...$fields,
        ];
    }

    private function queued(array $payload, User $capturedBy, ?CarbonImmutable $capturedAt = null): array
    {
        return [
            ...$payload,
            'client_request_uuid' => (string) Str::uuid(),
            'queued_offline' => true,
            'captured_offline_at' => ($capturedAt ?? CarbonImmutable::now('Pacific/Auckland'))->toIso8601String(),
            'origin_device_id' => 'synthetic-house-laptop',
            'captured_by_user_id' => $capturedBy->id,
        ];
    }

    private function staff(string $name): User
    {
        $user = User::factory()->create(['name' => $name, 'role' => 'support_worker', 'approved_at' => now()]);
        $user->roles()->syncWithoutDetaching([Role::where('name', 'support_worker')->sole()->id]);
        $keys = ['medications.view', 'medications.administer.record'];
        $permissions = Permission::whereIn('key', $keys)->get();
        $user->permissionOverrides()->syncWithoutDetaching($permissions->mapWithKeys(fn ($p) => [$p->id => ['allowed' => true]])->all());
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $this->site->id, 'secondary_site_ids' => [],
            'start_date' => now()->subYear(), 'end_date' => null, 'is_active' => true]);
        MedicationCompetencyAssessment::create(['user_id' => $user->id, 'assessor_id' => User::factory()->create()->id, 'assessment_type' => 'annual', 'status' => 'passed',
            'assessment_date' => now()->subMonths(2)->toDateString(), 'expiry_date' => now()->addYear()->toDateString(),
            'assessor_declared_at' => now()->subMonths(2), 'staff_acknowledged_at' => now()->subMonths(2)->addMinute(),
            'can_administer_unsupervised' => true, 'can_witness_controlled' => true, 'controlled_drugs' => true, 'restricted' => false, 'not_seen_areas' => []]);
        Shift::factory()->create(['user_id' => $user->id, 'client_id' => $this->client->id, 'site_id' => $this->site->id,
            'service_context_id' => $this->client->service_context_id, 'starts_at' => now()->subDays(4)->utc(), 'ends_at' => now()->addHours(8)->utc(),
            'actual_starts_at' => now()->subDays(4)->addMinutes(5)->utc(), 'actual_ends_at' => null, 'status' => 'in_progress']);
        Cache::flush();

        return $user->fresh();
    }
}
