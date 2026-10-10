<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Monitoring\Contracts\DnsResolver;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationStock;
use App\Models\MedicationEvent;
use App\Models\MedicationPharmacyConnection;
use App\Models\MedicationPharmacyDispatch;
use App\Models\MedicationPharmacyOrder;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use App\Services\Medication\Audit\MedicationEventRecorder;
use App\Services\Medication\PharmacyConnect\DispatchPharmacyOrder;
use App\Services\Medication\PharmacyConnect\PharmacyDispatchService;
use App\Services\Medication\Stock\MedicationStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ConnectedCareSwitches;
use Tests\TestCase;

final class PharmacyConnectionTest extends TestCase
{
    use RefreshDatabase;

    private array $fixture;

    private string $outboundSecret;

    private string $acknowledgmentSecret;

    protected function setUp(): void
    {
        parent::setUp();
        // D4: the bridge runs only while switched on (and configured below).
        ConnectedCareSwitches::on('pharmacy_bridge');
        foreach (['medications.view', 'medications.stock.update', 'medications.pharmacy.send', 'medications.settings.manage',
            'medications.pharmacy.connect.manage', 'medications.controlled.view', 'medications.controlled.record',
            'medications.audit.view', 'clinical.accessAllSites', 'sites.viewAll'] as $key) {
            Permission::firstOrCreate(['key' => $key], ['description' => 'Synthetic pharmacy test permission', 'group' => 'medications', 'module' => 'Clinical']);
        }
        Http::preventStrayRequests();
        Http::fake(['https://pharmacy.example.test/orders' => Http::response('Do not retain supplier body', 202)]);
        Bus::fake([DispatchPharmacyOrder::class]);
        $this->app->instance(DnsResolver::class, new class implements DnsResolver
        {
            public function resolve(string $host): array
            {
                return ['93.184.216.34'];
            }
        });
        $site = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $actor = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        HrEmployeeProfile::factory()->create(['user_id' => $actor->id, 'primary_site_id' => $site->id,
            'secondary_site_ids' => [], 'is_active' => true, 'start_date' => now()->subMonth(), 'end_date' => null]);
        foreach (['medications.view', 'medications.stock.update', 'medications.pharmacy.send', 'medications.settings.manage',
            'medications.pharmacy.connect.manage'] as $permission) {
            $this->grant($actor, $permission, true);
        }
        $client = Client::factory()->create(['site_id' => $site->id, 'first_name' => 'Synthetic', 'last_name' => 'Person',
            'nhi_number' => 'ZZZ1234', 'date_of_birth' => '1970-01-01', 'status' => 'active']);
        $medication = ClientMedication::query()->forceCreate(['client_id' => $client->id, 'name' => 'Synthetic medicine',
            'dosage' => '1 tablet', 'frequency' => 'Once daily', 'route' => 'oral', 'controlled_drug' => false,
            'active' => true, 'state' => 'active', 'approval_status' => 'verified', 'version' => 1]);
        $stock = ClientMedicationStock::create(['client_medication_id' => $medication->id, 'on_hand' => '10.00', 'unit' => 'tablets']);
        $order = MedicationPharmacyOrder::create(['client_id' => $client->id, 'client_medication_id' => $medication->id,
            'pharmacy_name' => 'Synthetic approved pharmacy', 'quantity_ordered' => 4, 'needed_by' => now()->addDays(3)->toDateString(),
            'status' => 'draft', 'ordered_by' => $actor->id]);
        $this->outboundSecret = str_repeat('synthetic-outbound-', 3);
        $this->acknowledgmentSecret = str_repeat('synthetic-acknowledgment-', 3);
        config(['emar-pharmacy-connect.enabled' => true, 'emar-pharmacy-connect.partners' => ['synthetic' => [
            'label' => 'Synthetic bridge', 'protocol' => 'oblivion-json-v1', 'endpoint' => 'https://pharmacy.example.test/orders',
            'outbound_token' => $this->outboundSecret, 'acknowledgment_secret' => $this->acknowledgmentSecret,
            'site_references' => [$site->id => 'SYNTHETIC-HOUSE'], 'supports_idempotency' => true, 'definitive_failure_statuses' => [422],
        ]], 'medications.stock_lots_enabled' => true]);
        $connection = MedicationPharmacyConnection::create(['name' => 'Synthetic connection', 'partner_key' => 'synthetic',
            'site_ids' => [$site->id], 'enabled' => true, 'created_by' => $actor->id, 'updated_by' => $actor->id]);
        $this->fixture = compact('actor', 'site', 'client', 'medication', 'stock', 'order', 'connection');
    }

    public function test_http_success_is_sent_then_authenticated_acceptance_confirms_without_stock_receipt(): void
    {
        $dispatch = $this->queue();
        $service = app(PharmacyDispatchService::class);
        $service->send($dispatch->id);
        $service->send($dispatch->id); // Duplicate worker cannot send twice.
        $this->assertSame('sent', $dispatch->fresh()->state);
        $this->assertSame('submitted', $this->fixture['order']->fresh()->status);
        $this->assertSame('secure_message', $this->fixture['order']->fresh()->communication_method);
        Http::assertSentCount(1);
        $this->ack($dispatch)->assertOk()->assertJsonPath('applied', true);
        $this->assertSame('accepted', $dispatch->fresh()->state);
        $this->assertSame('confirmed', $this->fixture['order']->fresh()->status);
        $this->assertSame('10.00', $this->fixture['stock']->fresh()->on_hand);
        $this->assertDatabaseCount('client_controlled_drug_entries', 0);
        $this->assertDatabaseCount('medication_stock_lots', 0);
        $this->assertSame(null, $this->fixture['order']->fresh()->quantity_received);
        $reader = $this->actingAs($this->fixture['actor'])->getJson($this->orderUrl().'/connection')->assertOk()->json();
        $encoded = json_encode($reader);
        foreach ([$this->outboundSecret, $this->acknowledgmentSecret, 'ZZZ1234', 'https://pharmacy.example.test/orders'] as $secret) {
            $this->assertStringNotContainsString($secret, $encoded);
        }
        $this->assertStringNotContainsString('ZZZ1234', DB::table('medication_pharmacy_dispatches')->where('id', $dispatch->id)->value('snapshot'));
        $audit = MedicationEvent::where('kind', 'like', 'pharmacy.%')->get()->toJson();
        $this->assertStringNotContainsString($this->outboundSecret, $audit);
        $this->assertStringNotContainsString('ZZZ1234', $audit);
    }

    public function test_timeout_stays_unknown_without_automatic_retry_and_signed_ack_resolves_contact(): void
    {
        Http::fake(fn () => throw new ConnectionException('Synthetic uncertain transport'));
        $dispatch = $this->queue();
        app(PharmacyDispatchService::class)->send($dispatch->id);
        $this->assertSame('unknown', $dispatch->fresh()->state);
        $this->assertSame('draft', $this->fixture['order']->fresh()->status);
        $this->actingAs($this->fixture['actor'])->postJson($this->dispatchUrl($dispatch).'/retry',
            ['request_uuid' => (string) Str::uuid(), 'expected_state' => 'unknown'])->assertConflict()->assertJsonPath('code', 'retry_not_safe');
        $this->ack($dispatch)->assertOk()->assertJsonPath('applied', true);
        $this->assertSame('confirmed', $this->fixture['order']->fresh()->status);
        $this->assertSame('10.00', $this->fixture['stock']->fresh()->on_hand);
    }

    public function test_retry_requires_recorded_non_receipt_and_preserves_idempotency_key(): void
    {
        Http::fake(fn () => throw new ConnectionException('Synthetic uncertain transport'));
        $dispatch = $this->queue();
        app(PharmacyDispatchService::class)->send($dispatch->id);
        $request = ['request_uuid' => (string) Str::uuid(), 'expected_state' => 'unknown', 'confirmed_not_received' => true, 'reference' => 'CALL-SYNTHETIC-1'];
        $this->actingAs($this->fixture['actor'])->postJson($this->dispatchUrl($dispatch).'/resolve', $request)->assertOk()->assertJsonPath('dispatch.state', 'failed');
        $this->postJson($this->dispatchUrl($dispatch).'/resolve', $request)->assertOk()->assertJsonPath('duplicate', true);
        $this->assertStringNotContainsString('CALL-SYNTHETIC-1', DB::table('medication_pharmacy_dispatch_commands')->value('result'));
        $retry = ['request_uuid' => (string) Str::uuid(), 'expected_state' => 'failed'];
        $this->postJson($this->dispatchUrl($dispatch).'/retry', $retry)->assertAccepted()->assertJsonPath('dispatch.uuid', $dispatch->uuid);
        $this->postJson($this->dispatchUrl($dispatch).'/retry', $retry)->assertAccepted()->assertJsonPath('duplicate', true);
        $this->assertSame(1, $dispatch->fresh()->attempt_count); // Saving either command sends nothing.
    }

    public static function originalSenderChanges(): array
    {
        return ['original employment ended' => ['employment', false],
            'original sending permission revoked' => ['permission', false],
            'retry delivery remains uncertain' => ['permission', true]];
    }

    #[DataProvider('originalSenderChanges')]
    public function test_current_retry_authoriser_sends_and_acknowledges_without_rewriting_original_request(string $revocation, bool $uncertain): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $attempts = [];
        Http::fake(function ($request) use (&$attempts, $uncertain) {
            $attempts[] = ['key' => $request->header('Idempotency-Key'), 'uuid' => $request['dispatch_uuid']];
            if (count($attempts) === 1) {
                return Http::response('Synthetic definitive refusal', 422);
            }
            if ($uncertain) {
                throw new ConnectionException('Synthetic uncertain retry');
            }

            return Http::response('Synthetic successful retry', 202);
        });
        $dispatch = $this->queue();
        $retainedKeys = array_flip(['requested_by', 'uuid', 'request_uuid', 'site_id', 'snapshot', 'snapshot_fingerprint', 'partner_fingerprint']);
        $retained = array_intersect_key($dispatch->getRawOriginal(), $retainedKeys);
        $service = app(PharmacyDispatchService::class);
        $service->send($dispatch->id);
        $this->assertSame('failed', $dispatch->fresh()->state);
        $this->revokeSender($this->fixture['actor'], $revocation);
        $replacement = $this->replacementSender();
        $retry = ['request_uuid' => (string) Str::uuid(), 'expected_state' => 'failed'];
        $this->actingAs($replacement)->postJson($this->dispatchUrl($dispatch).'/retry', $retry)->assertAccepted();
        $this->postJson($this->dispatchUrl($dispatch).'/retry', $retry)->assertAccepted()->assertJsonPath('duplicate', true);
        $this->assertSame(1, count($attempts));
        $this->assertDatabaseCount('medication_pharmacy_dispatch_commands', 1);
        $this->assertDatabaseHas('medication_pharmacy_dispatch_commands', ['dispatch_id' => $dispatch->id,
            'request_uuid' => $retry['request_uuid'], 'action' => 'retry', 'actor_id' => $replacement->id]);
        $service->send($dispatch->id);
        $service->send($dispatch->id);
        $this->assertSame($uncertain ? 'unknown' : 'sent', $dispatch->fresh()->state);
        $this->assertSame(2, $dispatch->fresh()->attempt_count);
        $this->assertSame(2, count($attempts));
        $this->assertSame(array_fill(0, 2, ['key' => [$dispatch->uuid], 'uuid' => $dispatch->uuid]), $attempts);
        $this->assertSame($retained, array_intersect_key($dispatch->fresh()->getRawOriginal(), $retainedKeys));
        $sent = MedicationEvent::where('kind', $uncertain ? 'pharmacy.dispatch.unknown' : 'pharmacy.dispatch.sent')->sole();
        $this->assertSame($replacement->id, $sent->actor_id);
        $this->assertSame($dispatch->site_id, $sent->site_id);
        $this->assertSame($this->fixture['actor']->id, MedicationEvent::where('kind', 'pharmacy.dispatch.queued')->sole()->actor_id);
        $this->assertSame($this->fixture['actor']->id, MedicationEvent::where('kind', 'pharmacy.dispatch.failed')->sole()->actor_id);
        $this->assertSame($replacement->id, MedicationEvent::where('kind', 'pharmacy.dispatch.retry')->sole()->actor_id);
        $this->postJson($this->dispatchUrl($dispatch).'/retry', $retry)->assertAccepted()->assertJsonPath('duplicate', true);
        $service->recover();
        $service->send($dispatch->id);
        $this->assertSame(2, count($attempts));
        if ($uncertain) {
            $this->postJson($this->dispatchUrl($dispatch).'/retry', ['request_uuid' => (string) Str::uuid(),
                'expected_state' => 'unknown'])->assertConflict()->assertJsonPath('code', 'retry_not_safe');
        }
        $this->ack($dispatch)->assertOk()->assertJsonPath('applied', true);
        $this->ack($dispatch)->assertOk()->assertJsonPath('duplicate', true)->assertJsonPath('applied', true);
        $this->assertSame('confirmed', $this->fixture['order']->fresh()->status);
        $this->assertSame($replacement->id, $this->fixture['order']->fresh()->communication_recorded_by);
        $this->assertSame($retained, array_intersect_key($dispatch->fresh()->getRawOriginal(), $retainedKeys));
        $this->assertDatabaseCount('medication_pharmacy_acknowledgments', 1);
        $this->assertDatabaseCount('medication_pharmacy_dispatch_commands', 1);
        $this->assertSame(2, count($attempts));
        $this->assertSame('10.00', $this->fixture['stock']->fresh()->on_hand);
        $this->assertNull($this->fixture['order']->fresh()->quantity_received);
        $this->assertDatabaseCount('client_controlled_drug_entries', 0);
        $this->assertDatabaseCount('medication_stock_lots', 0);
    }

    public static function retrySenderChanges(): array
    {
        return ['retry employment ended' => ['employment'], 'retry sending permission revoked' => ['permission']];
    }

    #[DataProvider('retrySenderChanges')]
    public function test_retry_authoriser_revoked_before_worker_prevents_send_even_if_original_requester_is_current(string $revocation): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['https://pharmacy.example.test/orders' => Http::response('Synthetic definitive refusal', 422)]);
        $dispatch = $this->queue();
        $service = app(PharmacyDispatchService::class);
        $service->send($dispatch->id);
        $replacement = $this->replacementSender();
        $retry = ['request_uuid' => (string) Str::uuid(), 'expected_state' => 'failed'];
        $this->actingAs($replacement)->postJson($this->dispatchUrl($dispatch).'/retry', $retry)->assertAccepted();
        $this->revokeSender($replacement, $revocation);
        $service->send($dispatch->id);
        $service->send($dispatch->id);
        $service->recover();
        Http::assertSentCount(1);
        $this->assertSame('cancelled', $dispatch->fresh()->state);
        $this->assertSame('authority_or_snapshot_revoked', $dispatch->fresh()->result_code);
        $this->assertSame($this->fixture['actor']->id, $dispatch->fresh()->requested_by);
        $this->assertSame('draft', $this->fixture['order']->fresh()->status);
        $this->ack($dispatch)->assertOk()->assertJsonPath('applied', false)->assertJsonPath('code', 'snapshot_or_connection_changed');
        $this->assertSame('draft', $this->fixture['order']->fresh()->status);
        $this->assertNull($this->fixture['order']->fresh()->communication_recorded_by);
        $this->assertDatabaseCount('medication_pharmacy_dispatch_commands', 1);
        $this->assertSame('10.00', $this->fixture['stock']->fresh()->on_hand);
        $this->assertNull($this->fixture['order']->fresh()->quantity_received);
        $this->assertDatabaseCount('client_controlled_drug_entries', 0);
        $this->assertDatabaseCount('medication_stock_lots', 0);
    }

    public function test_acknowledgment_rechecks_retry_authoriser_after_send_without_falling_back_to_current_original_requester(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['https://pharmacy.example.test/orders' => Http::sequence()->push('Synthetic refusal', 422)->push('Synthetic retry', 202)]);
        $dispatch = $this->queue();
        $service = app(PharmacyDispatchService::class);
        $service->send($dispatch->id);
        $replacement = $this->replacementSender();
        $this->actingAs($replacement)->postJson($this->dispatchUrl($dispatch).'/retry', ['request_uuid' => (string) Str::uuid(), 'expected_state' => 'failed'])->assertAccepted();
        $service->send($dispatch->id);
        $this->assertSame('sent', $dispatch->fresh()->state);
        $this->assertSame($replacement->id, $this->fixture['order']->fresh()->communication_recorded_by);
        $this->revokeSender($replacement, 'permission');
        $this->ack($dispatch)->assertOk()->assertJsonPath('applied', false)->assertJsonPath('code', 'snapshot_or_connection_changed');
        $this->ack($dispatch)->assertOk()->assertJsonPath('duplicate', true)->assertJsonPath('applied', false);
        $this->assertSame('submitted', $this->fixture['order']->fresh()->status);
        $this->assertSame($replacement->id, $this->fixture['order']->fresh()->communication_recorded_by);
        $this->assertSame($this->fixture['actor']->id, $dispatch->fresh()->requested_by);
        $this->assertDatabaseCount('medication_pharmacy_acknowledgments', 1);
        Http::assertSentCount(2);
        $this->assertSame('10.00', $this->fixture['stock']->fresh()->on_hand);
        $this->assertNull($this->fixture['order']->fresh()->quantity_received);
        $this->assertDatabaseCount('client_controlled_drug_entries', 0);
        $this->assertDatabaseCount('medication_stock_lots', 0);
    }

    public function test_latest_successful_retry_authoriser_survives_earlier_command_replay_and_failed_new_retry(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['https://pharmacy.example.test/orders' => Http::sequence()->push('Synthetic refusal', 422)
            ->push('Synthetic second refusal', 422)->push('Synthetic final retry', 202)]);
        $dispatch = $this->queue();
        $service = app(PharmacyDispatchService::class);
        $service->send($dispatch->id);
        $first = $this->replacementSender();
        $firstRetry = ['request_uuid' => (string) Str::uuid(), 'expected_state' => 'failed'];
        $this->actingAs($first)->postJson($this->dispatchUrl($dispatch).'/retry', $firstRetry)->assertAccepted();
        $service->send($dispatch->id);
        $this->assertSame('failed', $dispatch->fresh()->state);
        $latest = $this->replacementSender();
        $latestRetry = ['request_uuid' => (string) Str::uuid(), 'expected_state' => 'failed'];
        $this->actingAs($latest)->postJson($this->dispatchUrl($dispatch).'/retry', $latestRetry)->assertAccepted();
        $this->actingAs($first)->postJson($this->dispatchUrl($dispatch).'/retry', $firstRetry)->assertAccepted()->assertJsonPath('duplicate', true);
        $this->postJson($this->dispatchUrl($dispatch).'/retry', ['request_uuid' => (string) Str::uuid(),
            'expected_state' => 'queued'])->assertConflict()->assertJsonPath('code', 'retry_not_safe');
        $this->revokeSender($first, 'permission');
        $service->send($dispatch->id);
        $service->send($dispatch->id);
        $this->assertSame('sent', $dispatch->fresh()->state);
        $this->assertSame($latest->id, MedicationEvent::where('kind', 'pharmacy.dispatch.sent')->sole()->actor_id);
        $this->assertSame([$this->fixture['actor']->id, $first->id], MedicationEvent::where('kind', 'pharmacy.dispatch.failed')->orderBy('id')->pluck('actor_id')->all());
        $this->assertSame([$first->id, $latest->id], DB::table('medication_pharmacy_dispatch_commands')->where('dispatch_id', $dispatch->id)->orderBy('id')->pluck('actor_id')->all());
        $this->assertSame($this->fixture['actor']->id, $dispatch->fresh()->requested_by);
        $this->assertSame($this->fixture['site']->id, $dispatch->fresh()->site_id);
        $this->assertSame(3, $dispatch->fresh()->attempt_count);
        $this->ack($dispatch)->assertOk()->assertJsonPath('applied', true);
        $this->assertSame($latest->id, $this->fixture['order']->fresh()->communication_recorded_by);
        Http::assertSentCount(3);
        Http::assertSent(fn ($request) => $request->header('Idempotency-Key') === [$dispatch->uuid] && $request['dispatch_uuid'] === $dispatch->uuid);
        $this->assertSame('10.00', $this->fixture['stock']->fresh()->on_hand);
        $this->assertNull($this->fixture['order']->fresh()->quantity_received);
        $this->assertDatabaseCount('client_controlled_drug_entries', 0);
        $this->assertDatabaseCount('medication_stock_lots', 0);
    }

    private function replacementSender(): User
    {
        $actor = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        HrEmployeeProfile::factory()->create(['user_id' => $actor->id, 'primary_site_id' => $this->fixture['site']->id,
            'secondary_site_ids' => [], 'is_active' => true, 'start_date' => now()->subMonth(), 'end_date' => null]);
        foreach (['medications.view', 'medications.stock.update', 'medications.pharmacy.send'] as $key) {
            $this->grant($actor, $key, true);
        }

        return $actor;
    }

    private function revokeSender(User $actor, string $revocation): void
    {
        if ($revocation === 'employment') {
            $actor->hrEmployeeProfile()->firstOrFail()->update(['is_active' => false]);
        } else {
            $this->grant($actor, 'medications.pharmacy.send', false);
        }
    }

    public function test_revoked_sender_and_changed_patient_snapshot_never_send(): void
    {
        $dispatch = $this->queue();
        $this->grant($this->fixture['actor'], 'medications.pharmacy.send', false);
        app(PharmacyDispatchService::class)->send($dispatch->id);
        $this->assertSame('cancelled', $dispatch->fresh()->state);
        Http::assertNothingSent();
        $this->assertSame('draft', $this->fixture['order']->fresh()->status);
    }

    public function test_changed_person_snapshot_cancels_preflight_and_connection_revision_does_not_send(): void
    {
        $dispatch = $this->queue();
        $this->fixture['client']->update(['nhi_number' => 'ZZZ5678']);
        app(PharmacyDispatchService::class)->send($dispatch->id);
        $this->assertSame('cancelled', $dispatch->fresh()->state);
        $this->assertSame('snapshot_changed', $dispatch->fresh()->result_code);
        Http::assertNothingSent();
    }

    public function test_receipt_replay_is_authenticated_and_conflicting_event_is_rejected(): void
    {
        $dispatch = $this->queue();
        app(PharmacyDispatchService::class)->send($dispatch->id);
        $this->ack($dispatch, 'accepted', 'EVENT-1', false)->assertUnauthorized()->assertJsonPath('code', 'acknowledgment_unauthenticated');
        $this->assertSame('sent', $dispatch->fresh()->state);
        $this->ack($dispatch)->assertOk();
        $this->ack($dispatch)->assertOk()->assertJsonPath('duplicate', true)->assertJsonPath('applied', true);
        $this->ack($dispatch, 'rejected')->assertConflict()->assertJsonPath('code', 'acknowledgment_conflict');
        $this->assertDatabaseCount('medication_pharmacy_acknowledgments', 1);
    }

    public function test_revoked_requester_receipt_is_retained_without_changing_supply_status(): void
    {
        $dispatch = $this->queue();
        app(PharmacyDispatchService::class)->send($dispatch->id);
        $this->grant($this->fixture['actor'], 'medications.pharmacy.send', false);
        $this->ack($dispatch)->assertOk()->assertJsonPath('applied', false)->assertJsonPath('code', 'snapshot_or_connection_changed');
        $this->assertSame('accepted', $dispatch->fresh()->state);
        $this->assertSame('submitted', $this->fixture['order']->fresh()->status);
        $this->assertSame('10.00', $this->fixture['stock']->fresh()->on_hand);
    }

    public function test_unapproved_house_and_controlled_direct_identifiers_are_concealed(): void
    {
        $otherSite = Site::factory()->create(['is_active' => true, 'archived' => false]);
        $this->fixture['connection']->update(['site_ids' => [$otherSite->id]]);
        $this->actingAs($this->fixture['actor'])->postJson($this->orderUrl().'/dispatch', $this->sendInput())->assertNotFound();
        $this->fixture['medication']->forceFill(['controlled_drug' => true])->saveQuietly();
        $this->grant($this->fixture['actor'], 'medications.controlled.view', false);
        $this->getJson($this->orderUrl().'/connection')->assertNotFound();
        $this->postJson($this->orderUrl().'/dispatch', $this->sendInput())->assertNotFound();
        $this->assertDatabaseCount('medication_pharmacy_dispatches', 0);
    }

    public function test_settings_requires_exact_permission_and_approved_sites_and_rejects_url_or_secret_input(): void
    {
        $input = ['name' => 'Second synthetic connection', 'partner_key' => 'synthetic', 'site_ids' => [$this->fixture['site']->id], 'enabled' => true];
        $this->grant($this->fixture['actor'], 'medications.pharmacy.connect.manage', false);
        $this->actingAs($this->fixture['actor'])->postJson('/emar/pharmacy-connections', $input)->assertForbidden();
        $this->grant($this->fixture['actor'], 'medications.pharmacy.connect.manage', true);
        $this->postJson('/emar/pharmacy-connections', [...$input, 'endpoint' => 'https://unapproved.example/orders'])->assertUnprocessable();
        $this->postJson('/emar/pharmacy-connections', $input)->assertCreated()->assertJsonPath('connection.partner_key', 'synthetic');
        $connection = $this->fixture['connection'];
        $this->putJson('/emar/pharmacy-connections/'.$connection->id, [...$input, 'expected_version' => 1])->assertOk()->assertJsonPath('connection.version', 2);
        $this->putJson('/emar/pharmacy-connections/'.$connection->id, [...$input, 'expected_version' => 1])->assertConflict()->assertJsonPath('code', 'connection_changed');
        $data = $this->getJson('/emar/pharmacy-connections')->assertOk()->json();
        $this->assertStringNotContainsString($this->outboundSecret, json_encode($data));
        $this->assertStringNotContainsString('https://pharmacy.example.test/orders', json_encode($data));
        Http::assertNothingSent();
    }

    public function test_in_flight_content_is_frozen_but_local_cancellation_stops_queued_dispatch(): void
    {
        $dispatch = $this->queue();
        $queued = MedicationEvent::where('kind', 'pharmacy.dispatch.queued')->sole();
        $this->actingAs($this->fixture['actor'])->putJson($this->orderUrl(), ['quantity_ordered' => 5])->assertUnprocessable();
        [$url, $body] = $this->stopInput($dispatch, true);
        $this->grant($this->fixture['actor'], 'medications.stock.update', false);
        $this->postJson($url, $body)->assertForbidden();
        $this->assertSame('queued', $dispatch->fresh()->state);
        $this->assertDatabaseCount('medication_events', 1);
        $this->grant($this->fixture['actor'], 'medications.stock.update', true);
        $this->postJson($url, $body)->assertOk();
        $this->postJson($url, $body)->assertOk()->assertJsonPath('duplicate', true);
        $this->assertSame('cancelled', $dispatch->fresh()->state);
        $this->assertSame('local_supply_closed_before_send', $dispatch->fresh()->result_code);
        $this->assertSame(0, $dispatch->fresh()->attempt_count);
        $event = MedicationEvent::where('kind', 'pharmacy.dispatch.cancelled')->sole();
        $this->assertSame((int) $dispatch->site_id, $event->site_id);
        $this->assertSame($this->fixture['client']->id, $event->client_id);
        $this->assertSame($this->fixture['actor']->id, $event->actor_id);
        $this->assertSame('pharmacy_supply', $event->subject_type);
        $this->assertSame((string) $this->fixture['order']->id, $event->subject_id);
        $expectedFacts = ['dispatch_id' => $dispatch->id, 'connection_id' => $dispatch->connection_id,
            'state' => 'cancelled', 'result_code' => 'local_supply_closed_before_send', 'attempt_count' => 0];
        $facts = $event->facts;
        ksort($expectedFacts);
        ksort($facts);
        $this->assertSame($expectedFacts, $facts);
        $this->assertSame($queued->hash, $event->previous_hash);
        $this->assertTrue($event->hasValidFingerprint());
        $stockEvent = MedicationEvent::where('kind', 'stock.order_update')->sole();
        $this->assertSame($event->hash, $stockEvent->previous_hash);
        $this->assertSame($stockEvent->hash, DB::table('medication_event_heads')->where('site_id', $event->site_id)->value('hash'));
        app(PharmacyDispatchService::class)->send($dispatch->id);
        $this->assertSame('10.00', $this->fixture['stock']->fresh()->on_hand);
        $this->assertNull($this->fixture['order']->fresh()->quantity_received);
        $this->assertDatabaseCount('client_controlled_drug_entries', 0);
        $this->assertDatabaseCount('medication_stock_lots', 0);
        Http::assertNothingSent();
    }

    public function test_local_closure_after_person_moves_records_cancellation_at_the_captured_dispatch_site(): void
    {
        $dispatch = $this->queue();
        $queued = MedicationEvent::where('kind', 'pharmacy.dispatch.queued')->sole();
        $newSite = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->fixture['client']->update(['site_id' => $newSite->id]);
        $this->fixture['actor']->hrEmployeeProfile()->firstOrFail()->update(['secondary_site_ids' => [$newSite->id]]);
        // Closing local supply remains available to the currently authorised stock worker.
        $this->grant($this->fixture['actor'], 'medications.pharmacy.send', false);
        [$url, $body] = $this->stopInput($dispatch, true);
        $this->actingAs($this->fixture['actor']->fresh())->postJson($url, $body)->assertOk();
        $this->postJson($url, $body)->assertOk()->assertJsonPath('duplicate', true);

        $event = MedicationEvent::where('kind', 'pharmacy.dispatch.cancelled')->sole();
        $this->assertSame((int) $dispatch->site_id, $event->site_id);
        $this->assertSame($this->fixture['site']->id, $event->site_id);
        $this->assertNull($event->client_id);
        $this->assertSame($this->fixture['actor']->id, $event->actor_id);
        $this->assertSame('pharmacy_supply', $event->subject_type);
        $this->assertSame((string) $this->fixture['order']->id, $event->subject_id);
        $this->assertSame('cancelled', $event->facts['state']);
        $this->assertSame('local_supply_closed_before_send', $event->facts['result_code']);
        $this->assertSame(0, $event->facts['attempt_count']);
        $this->assertSame($queued->hash, $event->previous_hash);
        $this->assertTrue($event->hasValidFingerprint());
        $this->assertSame($event->hash, DB::table('medication_event_heads')->where('site_id', $dispatch->site_id)->value('hash'));
        $this->assertSame(0, MedicationEvent::where('site_id', $newSite->id)->where('kind', 'like', 'pharmacy.dispatch.%')->count());
        $stockEvent = MedicationEvent::where('kind', 'stock.order_update')->sole();
        $this->assertSame($newSite->id, $stockEvent->site_id);
        $this->assertSame($this->fixture['client']->id, $stockEvent->client_id);
        $evidence = MedicationPharmacyOrder::findOrFail($event->subject_id);
        $this->assertSame($this->fixture['client']->id, $evidence->client_id);
        $this->assertSame($this->fixture['medication']->id, $evidence->client_medication_id);
        $this->getJson($this->orderUrl().'/connection')->assertOk()->assertJsonPath('dispatch.state', 'cancelled');
        $service = app(PharmacyDispatchService::class);
        $service->send($dispatch->id);
        $this->assertSame(['unknown' => 0, 'queued' => 0], $service->recover());
        $this->assertSame(0, $dispatch->fresh()->attempt_count);
        $this->assertSame('10.00', $this->fixture['stock']->fresh()->on_hand);
        $this->assertNull($evidence->quantity_received);
        $this->assertDatabaseCount('client_controlled_drug_entries', 0);
        $this->assertDatabaseCount('medication_stock_lots', 0);
        Http::assertNothingSent();
    }

    public function test_local_closure_event_failure_rolls_back_dispatch_order_and_durable_receipt(): void
    {
        $dispatch = $this->queue();
        $beforeDispatch = $dispatch->getRawOriginal();
        $beforeOrder = $this->fixture['order']->fresh()->getRawOriginal();
        $beforeHead = DB::table('medication_event_heads')->where('site_id', $dispatch->site_id)->first();
        $beforeAuditCount = DB::table('audit_logs')->count();
        [$url, $body] = $this->stopInput($dispatch, true);
        $this->mock(MedicationEventRecorder::class, fn ($mock) => $mock->shouldReceive('appendMany')->once()
            ->andReturnUsing(function (array $events) use ($dispatch, $body): array {
                $this->assertSame(['pharmacy.dispatch.cancelled', 'stock.order_update'], array_map(fn ($event) => $event->kind, $events));
                $this->assertSame('cancelled', $dispatch->fresh()->state);
                $this->assertSame('cancelled', $this->fixture['order']->fresh()->status);
                $this->assertTrue(DB::table('medication_idempotency_results')->where('scope', 'emar-p06-command')->where('request_uuid', $body['request_uuid'])->exists());
                throw new \RuntimeException('Synthetic closure event failure');
            }));
        $this->withoutExceptionHandling();
        try {
            $this->postJson($url, $body);
            $this->fail('Local closure must not commit without its pharmacy event.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Synthetic closure event failure', $exception->getMessage());
        }
        $this->assertSame($beforeDispatch, $dispatch->fresh()->getRawOriginal());
        $this->assertSame($beforeOrder, $this->fixture['order']->fresh()->getRawOriginal());
        $this->assertEquals($beforeHead, DB::table('medication_event_heads')->where('site_id', $dispatch->site_id)->first());
        $this->assertSame($beforeAuditCount, DB::table('audit_logs')->count());
        $this->assertDatabaseCount('medication_events', 1);
        $this->assertDatabaseMissing('medication_idempotency_results', ['scope' => 'emar-p06-command', 'request_uuid' => $body['request_uuid']]);
        $this->assertSame('10.00', $this->fixture['stock']->fresh()->on_hand);
        Http::assertNothingSent();
    }

    public function test_explicitly_stopping_the_first_queue_retains_before_send_wording_and_request_replay(): void
    {
        $dispatch = $this->queue();
        [$url, $body] = $this->stopInput($dispatch, false);
        $this->postJson($url, $body)->assertOk()->assertJsonPath('dispatch.state', 'cancelled')
            ->assertJsonPath('dispatch.result_code', 'stopped_before_send');
        $this->postJson($url, $body)->assertOk()->assertJsonPath('duplicate', true);
        $this->assertSame(0, $dispatch->fresh()->attempt_count);
        $this->assertSame('draft', $this->fixture['order']->fresh()->status);
        $this->assertDatabaseCount('medication_pharmacy_dispatch_commands', 1);
        app(PharmacyDispatchService::class)->send($dispatch->id);
        Http::assertNothingSent();
    }

    public function test_accepted_order_still_requires_canonical_counted_physical_receipt(): void
    {
        $dispatch = $this->queue();
        app(PharmacyDispatchService::class)->send($dispatch->id);
        $this->ack($dispatch)->assertOk();
        DB::transaction(fn () => app(MedicationStockService::class)->startLots($this->fixture['stock'], $this->fixture['actor'], (string) Str::uuid()));
        $this->actingAs($this->fixture['actor'])->postJson('/emar/stock/packs/commands', ['action' => 'order_update',
            'client_medication_id' => $this->fixture['medication']->id, 'request_uuid' => (string) Str::uuid(), 'order_id' => $this->fixture['order']->id,
            'next' => 'dispensed', 'quantity_dispensed' => '4.00', 'expected_delivery' => now()->addDay()->toDateString()])->assertOk();
        $this->postJson('/emar/stock/packs/commands', ['action' => 'receive', 'client_medication_id' => $this->fixture['medication']->id,
            'request_uuid' => (string) Str::uuid(), 'pharmacy_order_id' => $this->fixture['order']->id, 'quantity' => '4.00',
            'source' => 'pharmacy', 'source_reference' => 'SYNTHETIC-DELIVERY-1', 'label_checked' => true,
            'batch_not_printed' => true, 'expiry_not_printed' => true])->assertOk();
        $this->assertSame('delivered', $this->fixture['order']->fresh()->status);
        $this->assertSame('4.00', $this->fixture['order']->fresh()->quantity_received);
        $this->assertSame('14.00', $this->fixture['stock']->fresh()->on_hand);
    }

    public function test_crash_recovery_marks_unknown_and_does_not_send_again(): void
    {
        $dispatch = $this->queue();
        $dispatch->forceFill(['state' => 'sending', 'claim_token' => (string) Str::uuid(), 'sending_at' => now()->subMinutes(10), 'attempt_count' => 1])->save();
        app(PharmacyDispatchService::class)->recover();
        $this->assertSame('unknown', $dispatch->fresh()->state);
        Http::assertNothingSent();
    }

    public function test_delayed_ack_after_person_moves_retains_the_dispatch_audit_site_without_applying_or_duplicating_acceptance(): void
    {
        $dispatch = $this->queue();
        app(PharmacyDispatchService::class)->send($dispatch->id);
        $newSite = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->fixture['client']->update(['site_id' => $newSite->id]);

        $this->ack($dispatch, valid: false)->assertUnauthorized();
        $this->ack($dispatch)->assertOk()->assertJsonPath('applied', false)->assertJsonPath('code', 'snapshot_or_connection_changed');
        $event = MedicationEvent::where('kind', 'pharmacy.dispatch.acknowledged')
            ->where('subject_id', (string) $this->fixture['order']->id)->sole();
        $this->assertSame((int) $dispatch->site_id, (int) $event->site_id);
        $this->assertSame((int) $this->fixture['site']->id, (int) $event->site_id);
        // The person now belongs to another house; the historical event cannot link their current chart.
        $this->assertNull($event->client_id);
        $this->assertSame($dispatch->id, $event->facts['dispatch_id']);
        $this->assertSame('pharmacy_supply', $event->subject_type);
        $evidence = MedicationPharmacyOrder::findOrFail($event->subject_id);
        $this->assertSame($this->fixture['client']->id, $evidence->client_id);
        $this->assertSame($this->fixture['medication']->id, $evidence->client_medication_id);
        $this->assertSame('accepted', $dispatch->fresh()->state);
        $this->assertSame('submitted', $this->fixture['order']->fresh()->status);
        $this->assertSame('10.00', $this->fixture['stock']->fresh()->on_hand);
        $this->assertNull($this->fixture['order']->fresh()->quantity_received);
        $this->assertSame(0, MedicationEvent::where('site_id', $newSite->id)->where('kind', 'like', 'pharmacy.dispatch.%')->count());

        $this->ack($dispatch)->assertOk()->assertJsonPath('duplicate', true)->assertJsonPath('applied', false);
        $this->assertSame(1, MedicationEvent::where('kind', 'pharmacy.dispatch.acknowledged')->count());
        $this->assertDatabaseCount('medication_pharmacy_acknowledgments', 1);
        Http::assertSentCount(1);

        // Current house authorization can inspect the same supply record and its historical delivery evidence.
        $this->fixture['actor']->hrEmployeeProfile()->firstOrFail()->update(['secondary_site_ids' => [$newSite->id]]);
        $this->actingAs($this->fixture['actor']->fresh())->getJson($this->orderUrl().'/connection')->assertOk()
            ->assertJsonPath('dispatch.id', $dispatch->id)->assertJsonPath('dispatch.uuid', $dispatch->uuid)
            ->assertJsonPath('dispatch.state', 'accepted')->assertJsonPath('dispatch.acknowledgment_applied', false);
    }

    public function test_recovery_after_person_moves_keeps_unknown_delivery_at_the_dispatch_audit_site_without_resending(): void
    {
        $dispatch = $this->queue();
        $dispatch->forceFill(['state' => 'sending', 'claim_token' => (string) Str::uuid(),
            'sending_at' => now()->subMinutes(10), 'attempt_count' => 1])->save();
        $newSite = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->fixture['client']->update(['site_id' => $newSite->id]);

        $service = app(PharmacyDispatchService::class);
        $this->assertSame(['unknown' => 1, 'queued' => 0], $service->recover());
        $event = MedicationEvent::where('kind', 'pharmacy.dispatch.unknown')
            ->where('subject_id', (string) $this->fixture['order']->id)->sole();
        $this->assertSame((int) $dispatch->site_id, (int) $event->site_id);
        $this->assertSame((int) $this->fixture['site']->id, (int) $event->site_id);
        $this->assertNull($event->client_id);
        $this->assertSame($dispatch->id, $event->facts['dispatch_id']);
        $this->assertSame('unknown', $dispatch->fresh()->state);
        $this->assertSame('worker_interrupted_delivery_unknown', $dispatch->fresh()->result_code);
        $this->assertNull($dispatch->fresh()->claim_token);
        $this->assertSame('draft', $this->fixture['order']->fresh()->status);
        $this->assertSame('10.00', $this->fixture['stock']->fresh()->on_hand);
        $this->assertSame(0, MedicationEvent::where('site_id', $newSite->id)->where('kind', 'like', 'pharmacy.dispatch.%')->count());

        $this->assertSame(['unknown' => 0, 'queued' => 0], $service->recover());
        $this->assertSame(1, MedicationEvent::where('kind', 'pharmacy.dispatch.unknown')->count());
        $this->assertSame(1, $dispatch->fresh()->attempt_count);
        Http::assertNothingSent();
    }

    public static function failedDeliveryStops(): array
    {
        return ['declined local closure' => [false, true], 'declined explicit stop' => [false, false],
            'confirmed non-receipt local closure' => [true, true], 'confirmed non-receipt explicit stop' => [true, false]];
    }

    #[DataProvider('failedDeliveryStops')]
    public function test_closing_or_stopping_failed_delivery_retains_factual_failure_and_cannot_resend(bool $resolved, bool $local): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $attempts = 0;
        Http::fake(function () use ($resolved, &$attempts) {
            $attempts++;
            if ($resolved) {
                throw new ConnectionException('Synthetic uncertain transport');
            }

            return Http::response('Synthetic definitive refusal', 422);
        });
        $dispatch = $this->queue();
        $service = app(PharmacyDispatchService::class);
        $service->send($dispatch->id);
        if ($resolved) {
            $this->assertSame('unknown', $dispatch->fresh()->state);
            $this->postJson($this->dispatchUrl($dispatch).'/resolve', ['request_uuid' => (string) Str::uuid(),
                'expected_state' => 'unknown', 'confirmed_not_received' => true, 'reference' => 'SYNTHETIC-NON-RECEIPT'])->assertOk();
        }
        $dispatch->refresh();
        $this->assertSame('failed', $dispatch->state);
        $this->assertSame($resolved ? 'pharmacy_confirmed_not_received' : 'partner_declined_transport', $dispatch->result_code);
        $before = $dispatch->getRawOriginal();
        $commandsBefore = DB::table('medication_pharmacy_dispatch_commands')->where('dispatch_id', $dispatch->id)->count();
        [$url, $body] = $this->stopInput($dispatch, $local);
        $this->grant($this->fixture['actor'], 'medications.stock.update', false);
        $this->postJson($url, $body)->assertForbidden();
        $this->assertSame($before, $dispatch->fresh()->getRawOriginal());
        $this->assertSame('draft', $this->fixture['order']->fresh()->status);
        $this->grant($this->fixture['actor'], 'medications.stock.update', true);
        $this->postJson($url, $body)->assertOk();
        $this->postJson($url, $body)->assertOk()->assertJsonPath('duplicate', true);

        $after = $dispatch->fresh()->getRawOriginal();
        $changed = $local ? [] : ['state' => true, 'updated_at' => true];
        $this->assertSame(array_diff_key($before, $changed), array_diff_key($after, $changed));
        $this->assertSame($local ? 'failed' : 'cancelled', $after['state']);
        $this->assertSame($local ? 'cancelled' : 'draft', $this->fixture['order']->fresh()->status);
        $this->assertSame($commandsBefore + ($local ? 0 : 1), DB::table('medication_pharmacy_dispatch_commands')->where('dispatch_id', $dispatch->id)->count());
        $this->assertSame(1, MedicationEvent::where('kind', $local ? 'stock.order_update' : 'pharmacy.dispatch.cancel')->count());
        $this->assertSame(0, MedicationEvent::where('kind', 'pharmacy.dispatch.cancelled')->count());
        $this->getJson($this->orderUrl().'/connection')->assertOk()->assertJsonPath('can_retry', false)
            ->assertJsonPath('local_order_closed', $local)->assertJsonPath('dispatch.result_code', $before['result_code'])
            ->assertJsonPath('dispatch.label', $local ? 'Not sent' : 'Sending stopped');
        $this->postJson($this->dispatchUrl($dispatch).'/retry', ['request_uuid' => (string) Str::uuid(), 'expected_state' => $after['state']])
            ->assertConflict()->assertJsonPath('code', $local ? 'supply_not_sendable' : 'retry_not_safe');
        $service->send($dispatch->id);
        $this->assertSame(['unknown' => 0, 'queued' => 0], $service->recover());
        $this->assertSame(1, $attempts);
        if (! $resolved) {
            Http::assertSentCount(1);
        }
        $this->assertSame(1, $dispatch->fresh()->attempt_count);
        $this->assertSame('10.00', $this->fixture['stock']->fresh()->on_hand);
        $this->assertNull($this->fixture['order']->fresh()->quantity_received);
        $this->assertDatabaseCount('client_controlled_drug_entries', 0);
        $this->assertDatabaseCount('medication_stock_lots', 0);
    }

    public static function queuedRetryStops(): array
    {
        return ['local closure' => [true], 'explicit stop' => [false]];
    }

    #[DataProvider('queuedRetryStops')]
    public function test_stopping_a_queued_retry_is_truthful_and_never_starts_another_attempt(bool $local): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['https://pharmacy.example.test/orders' => Http::response('Synthetic definitive refusal', 422)]);
        $dispatch = $this->queue();
        $service = app(PharmacyDispatchService::class);
        $service->send($dispatch->id);
        $retry = ['request_uuid' => (string) Str::uuid(), 'expected_state' => 'failed'];
        $this->postJson($this->dispatchUrl($dispatch).'/retry', $retry)->assertAccepted();
        $this->postJson($this->dispatchUrl($dispatch).'/retry', $retry)->assertAccepted()->assertJsonPath('duplicate', true);
        $dispatch->refresh();
        $this->assertSame('queued', $dispatch->state);
        $this->assertSame(1, $dispatch->attempt_count);
        [$url, $body] = $this->stopInput($dispatch, $local);
        $this->postJson($url, $body)->assertOk();
        $this->postJson($url, $body)->assertOk()->assertJsonPath('duplicate', true);
        $event = MedicationEvent::where('kind', $local ? 'pharmacy.dispatch.cancelled' : 'pharmacy.dispatch.cancel')->sole();
        $this->assertSame((int) $dispatch->site_id, $event->site_id);
        $this->assertSame($this->fixture['actor']->id, $event->actor_id);
        $this->assertSame('cancelled', $event->facts['state']);
        $this->assertSame($local ? 'local_supply_closed_before_retry' : 'retry_stopped', $event->facts['result_code']);
        $this->assertSame(1, $event->facts['attempt_count']);
        $retryEvent = MedicationEvent::where('kind', 'pharmacy.dispatch.retry')->sole();
        $this->assertSame($retryEvent->hash, $event->previous_hash);
        $this->assertTrue($event->hasValidFingerprint());
        $failedEvent = MedicationEvent::where('kind', 'pharmacy.dispatch.failed')->sole();
        $this->assertSame('partner_declined_transport', $failedEvent->facts['result_code']);
        $this->assertSame(1, $failedEvent->facts['attempt_count']);
        $this->getJson($this->orderUrl().'/connection')->assertOk()->assertJsonPath('can_retry', false)
            ->assertJsonPath('dispatch.state', 'cancelled')->assertJsonPath('dispatch.label', 'Sending stopped')
            ->assertJsonPath('dispatch.result_code', $local ? 'local_supply_closed_before_retry' : 'retry_stopped');
        $service->send($dispatch->id);
        $this->assertSame(['unknown' => 0, 'queued' => 0], $service->recover());
        $this->assertSame(1, $dispatch->fresh()->attempt_count);
        $this->assertSame('10.00', $this->fixture['stock']->fresh()->on_hand);
        $this->assertNull($this->fixture['order']->fresh()->quantity_received);
        $this->assertDatabaseCount('client_controlled_drug_entries', 0);
        $this->assertDatabaseCount('medication_stock_lots', 0);
        Http::assertSentCount(1);
    }

    private function stopInput(MedicationPharmacyDispatch $dispatch, bool $local): array
    {
        return $local ? ['/emar/stock/packs/commands', ['action' => 'order_update',
            'client_medication_id' => $this->fixture['medication']->id, 'request_uuid' => (string) Str::uuid(),
            'order_id' => $this->fixture['order']->id, 'next' => 'cancelled', 'reason' => 'Synthetic local closure']]
            : [$this->dispatchUrl($dispatch).'/cancel', ['request_uuid' => (string) Str::uuid(), 'expected_state' => $dispatch->state]];
    }

    /** EA-136: a late acceptance after staff settled the order by hand is kept as conflicting evidence. */
    public function test_b10_late_acceptance_never_overwrites_a_manual_check_and_raises_a_duplicate_supply_alert(): void
    {
        Http::fake(fn () => throw new ConnectionException('Synthetic uncertain transport'));
        $dispatch = $this->queue();
        app(PharmacyDispatchService::class)->send($dispatch->id);
        $this->actingAs($this->fixture['actor'])->postJson($this->dispatchUrl($dispatch).'/resolve', ['request_uuid' => (string) Str::uuid(),
            'expected_state' => 'unknown', 'confirmed_not_received' => true, 'reference' => 'CALL-SYNTHETIC-1'])->assertOk();
        $this->fixture['order']->forceFill(['communication_method' => 'phone', 'communication_reference' => 'PHONE-SYNTHETIC-1',
            'communication_recorded_by' => $this->fixture['actor']->id, 'communication_recorded_at' => now()])->save();

        $this->ack($dispatch)->assertOk()->assertJsonPath('applied', false)->assertJsonPath('code', 'contradicts_recorded_check');

        $order = $this->fixture['order']->fresh();
        $this->assertSame('phone', $order->communication_method);
        $this->assertSame('PHONE-SYNTHETIC-1', $order->communication_reference);
        $this->assertSame('failed', $dispatch->fresh()->state);
        $this->assertTrue(\App\Models\MedicationAlert::query()->where('type', 'pharmacyOrder')->where('client_id', $this->fixture['order']->client_id)->exists());
    }

    /** EA-137 (a): with the bridge switched off, a signed acceptance is evidence only. */
    public function test_b10_switched_off_bridge_keeps_acknowledgments_as_evidence_without_changing_orders(): void
    {
        $dispatch = $this->queue();
        app(PharmacyDispatchService::class)->send($dispatch->id);
        $this->assertSame('submitted', $this->fixture['order']->fresh()->status);
        config(['emar-pharmacy-connect.enabled' => false]);

        $this->ack($dispatch)->assertOk()->assertJsonPath('applied', false)->assertJsonPath('code', 'connection_disabled');

        $this->assertSame('submitted', $this->fixture['order']->fresh()->status);
        $this->assertDatabaseCount('medication_pharmacy_acknowledgments', 1);
    }

    /** EA-137 (b): nothing about the setup is revealed before the signature is checked. */
    public function test_b10_acknowledgment_endpoint_answers_one_401_for_unknown_connections_and_unready_partners(): void
    {
        $dispatch = $this->queue();
        $body = json_encode(['event_id' => 'EVENT-X', 'dispatch_uuid' => $dispatch->uuid, 'outcome' => 'accepted', 'supplier_reference' => 'X'], JSON_THROW_ON_ERROR);
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_PHARMACY_TIMESTAMP' => (string) time(), 'HTTP_X_PHARMACY_SIGNATURE' => 'v1=deadbeef'];
        $this->call('POST', '/api/emar/pharmacy-connections/999999/acknowledgments', [], [], [], $headers, $body)->assertUnauthorized();
        config(['emar-pharmacy-connect.partners' => []]);
        $this->call('POST', '/api/emar/pharmacy-connections/'.$dispatch->connection_id.'/acknowledgments', [], [], [], $headers, $body)->assertUnauthorized();
    }

    /** EA-138: a rejection reaches the people who update stock at the house. */
    public function test_b10_pharmacy_rejection_raises_an_alert(): void
    {
        $dispatch = $this->queue();
        app(PharmacyDispatchService::class)->send($dispatch->id);
        $this->ack($dispatch, 'rejected')->assertOk();
        $this->assertSame('rejected', $dispatch->fresh()->state);
        $alert = \App\Models\MedicationAlert::query()->where('type', 'pharmacyOrder')->where('client_id', $this->fixture['order']->client_id)->sole();
        $this->assertSame('/emar/stock?view=orders', $alert->action_url);
        $this->assertStringNotContainsString('ZZZ1234', (string) $alert->message.$alert->short_message);
    }

    private function queue(): MedicationPharmacyDispatch
    {
        $this->actingAs($this->fixture['actor'])->postJson($this->orderUrl().'/dispatch', $this->sendInput())
            ->assertAccepted()->assertJsonPath('dispatch.state', 'queued');

        return MedicationPharmacyDispatch::where('pharmacy_order_id', $this->fixture['order']->id)->firstOrFail();
    }

    private function ack(MedicationPharmacyDispatch $dispatch, string $outcome = 'accepted', string $eventId = 'EVENT-1', bool $valid = true)
    {
        $body = json_encode(['event_id' => $eventId, 'dispatch_uuid' => $dispatch->uuid, 'outcome' => $outcome, 'supplier_reference' => 'SYNTHETIC-REF-1'], JSON_THROW_ON_ERROR);
        $timestamp = (string) time();
        $signature = 'v1='.hash_hmac('sha256', $timestamp.'.'.$dispatch->connection_id.'.'.$body, $valid ? $this->acknowledgmentSecret : str_repeat('wrong-', 8));

        return $this->call('POST', '/api/emar/pharmacy-connections/'.$dispatch->connection_id.'/acknowledgments', [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_PHARMACY_TIMESTAMP' => $timestamp, 'HTTP_X_PHARMACY_SIGNATURE' => $signature], $body);
    }

    private function grant(User $actor, string $key, bool $allowed): void
    {
        $permission = Permission::where('key', $key)->firstOrFail();
        $actor->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => $allowed]]);
        $actor->unsetRelation('permissionOverrides');
    }

    private function sendInput(): array
    {
        return ['connection_id' => $this->fixture['connection']->id, 'request_uuid' => (string) Str::uuid()];
    }

    private function orderUrl(): string
    {
        return '/emar/stock/pharmacy-orders/'.$this->fixture['order']->id;
    }

    private function dispatchUrl(MedicationPharmacyDispatch $dispatch): string
    {
        return $this->orderUrl().'/dispatch/'.$dispatch->id;
    }
}
