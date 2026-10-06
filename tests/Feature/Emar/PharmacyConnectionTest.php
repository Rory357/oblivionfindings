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
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
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
        $this->assertSame('local_supply_closed_before_send', $dispatch->fresh()->result_code);
        $this->assertSame(0, $dispatch->fresh()->attempt_count);
        app(PharmacyDispatchService::class)->send($dispatch->id);
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
