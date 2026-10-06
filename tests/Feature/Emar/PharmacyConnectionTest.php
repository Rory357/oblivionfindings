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
use App\Services\Medication\PharmacyConnect\DispatchPharmacyOrder;
use App\Services\Medication\PharmacyConnect\PharmacyDispatchService;
use App\Services\Medication\Stock\MedicationStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
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
        $this->actingAs($this->fixture['actor'])->putJson($this->orderUrl(), ['quantity_ordered' => 5])->assertUnprocessable();
        $this->postJson('/emar/stock/packs/commands', ['action' => 'order_update', 'client_medication_id' => $this->fixture['medication']->id,
            'request_uuid' => (string) Str::uuid(), 'order_id' => $this->fixture['order']->id, 'next' => 'cancelled', 'reason' => 'Synthetic local cancellation'])->assertOk();
        $this->assertSame('cancelled', $dispatch->fresh()->state);
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
