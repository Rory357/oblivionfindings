<?php

namespace Tests\Feature\FleetAssets;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Jobs\DispatchFleetSignalOutbox;
use App\Models\Asset;
use App\Models\ControlRoom\Signal;
use App\Models\ControlRoom\SignalSource;
use App\Models\ControlRoomAlert;
use App\Models\FleetSignal;
use App\Models\FleetSignalOutbox;
use App\Models\FleetTelemetryEvent;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use App\Services\ControlRoom\SignalProcessingService;
use App\Services\Fleet\FleetSignalService;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * PKG-02B: the data migration that provisions the Fleet safety signal source,
 * so a recorded vehicle event sent from the vehicle profile reaches Control
 * Room on a database that was never seeded. An existing source, including an
 * admin's decision to switch it off, is never changed.
 */
class Pkg02bFleetSignalSourceProvisioningTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = '2026_09_24_000100_pkg02b_provision_fleet_safety_signal_source.php';

    private const ZONE = 'Pacific/Auckland';

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->seed(SecurityDevicesPermissionsSeeder::class);
        $this->travelTo(Carbon::parse('2026-09-22 09:30:00', self::ZONE)->utc());
        $this->site = Site::factory()->create(['name' => 'Kōwhai House', 'is_active' => true]);
        // The migration already ran when the test database was built. Start
        // from a database that was never seeded with the source.
        DB::table('control_room_signal_sources')->where('slug', 'queclink_fleet')->delete();
    }

    public function test_the_migration_provisions_the_active_fleet_source_when_it_is_missing(): void
    {
        $this->assertFalse(SignalSource::query()->where('slug', 'queclink_fleet')->exists());

        $this->migration()->up();

        $source = SignalSource::query()->where('slug', 'queclink_fleet')->sole();
        // The same row ControlRoomSeeder creates.
        $this->assertSame('Queclink Fleet', $source->name);
        $this->assertSame('queclink', $source->vendor);
        $this->assertSame('active', $source->status);
        $this->assertNull($source->config);
        $this->assertNull($source->capabilities);
        $this->assertSame(0, (int) $source->signal_count_24h);
        $this->assertNull($source->last_heartbeat_at);
        $this->assertNull($source->last_signal_at);
        $this->assertTrue($source->created_at->equalTo(now()));
        $this->assertTrue($source->updated_at->equalTo(now()));
    }

    public function test_running_the_migration_again_changes_nothing(): void
    {
        $migration = $this->migration();
        $migration->up();
        $provisioned = $this->sourceRows();
        $sources = DB::table('control_room_signal_sources')->count();
        $this->assertCount(1, $provisioned);

        // A later run, as a re-deploy would load it, neither duplicates nor touches the row.
        $this->travel(2)->hours();
        $migration->up();
        $this->migration()->up();

        $this->assertSame($provisioned, $this->sourceRows());
        $this->assertSame($sources, DB::table('control_room_signal_sources')->count());
    }

    /** @param  array<string,string>|null  $config */
    #[DataProvider('existingSourceProvider')]
    public function test_an_existing_source_is_left_exactly_as_it_was(string $status, string $name, ?array $config, string $delivery): void
    {
        Queue::fake();
        DB::table('control_room_signal_sources')->insert([
            'name' => $name,
            'slug' => 'queclink_fleet',
            'vendor' => 'queclink',
            'status' => $status,
            'config' => $config === null ? null : json_encode($config),
            'capabilities' => null,
            'last_signal_at' => '2026-08-14 21:00:00',
            'signal_count_24h' => 4,
            'created_at' => '2026-08-01 01:00:00',
            'updated_at' => '2026-08-15 03:00:00',
        ]);
        $before = $this->sourceRows();
        $sources = DB::table('control_room_signal_sources')->count();

        $this->migration()->up();
        $this->migration()->up();

        $this->assertSame($before, $this->sourceRows());
        $this->assertSame($sources, DB::table('control_room_signal_sources')->count());

        // The admin's setting still decides delivery: a source switched off or
        // under maintenance keeps vehicle signals out of Control Room.
        $signal = app(FleetSignalService::class)->emit([
            'asset_id' => $this->vehicle()->id,
            'signal_type' => 'vehicle.power_disconnected',
            'severity_hint' => 'medium',
            'occurred_at' => now(),
            'idempotency_key' => hash('sha256', 'pkg02b-provisioning-existing-'.$status),
        ]);
        $this->deliver($signal);
        $this->assertSame($delivery, $this->outbox($signal)->status);
        $this->assertSame($delivery === 'sent' ? 1 : 0, ControlRoomAlert::query()->count());
    }

    /** @return array<string,array{0:string,1:string,2:array<string,string>|null,3:string}> */
    public static function existingSourceProvider(): array
    {
        return [
            'switched off by an admin' => ['inactive', 'Queclink Fleet', null, 'unroutable'],
            'paused for maintenance' => ['maintenance', 'Queclink Fleet', null, 'unroutable'],
            'active and renamed by an admin' => ['active', 'Vehicle trackers', ['note' => 'Owned by the fleet team'], 'sent'],
        ];
    }

    public function test_rolling_back_keeps_the_source(): void
    {
        $migration = $this->migration();
        $migration->up();
        $provisioned = $this->sourceRows();

        $migration->down();

        $this->assertCount(1, $provisioned);
        $this->assertSame($provisioned, $this->sourceRows());
    }

    public function test_a_vehicle_event_sent_from_the_profile_reaches_control_room_through_the_migrated_source(): void
    {
        Queue::fake();
        $sender = $this->siteUser(['fleet.viewAny', 'fleet.manage']);
        $responder = $this->siteUser(['fleet.viewAny', 'controlRoom.alerts.view']);
        $vehicle = $this->vehicle();
        $powerOff = $this->telemetry($vehicle, '2026-09-22 07:10', 'power_off');
        $lowVoltage = $this->telemetry($vehicle, '2026-09-22 08:10', 'external_power');
        $route = "/fleet-assets/vehicles/{$vehicle->id}/alerts/route";

        // Without the source the event is kept as unroutable: the fault this migration fixes.
        $this->assertFalse(SignalSource::query()->where('slug', 'queclink_fleet')->exists());
        $before = $this->actingAs($sender)->withHeader('Idempotency-Key', 'route-before-migration')
            ->postJson($route, ['kind' => 'telemetry', 'event_id' => $powerOff->id])->assertOk()
            ->assertJsonPath('delivery', 'pending');
        $unrouted = FleetSignal::query()->findOrFail($before->json('signal_id'));
        $this->deliver($unrouted);
        $this->assertSame('unroutable', $this->outbox($unrouted)->status);
        $this->assertSame('Fleet safety signal has no active signal source.', $this->outbox($unrouted)->last_error);
        $this->assertSame(0, ControlRoomAlert::query()->count());

        // The migration alone provisions the source; nothing is seeded.
        $this->migration()->up();
        $source = SignalSource::query()->where('slug', 'queclink_fleet')->sole();

        $sent = $this->actingAs($sender)->withHeader('Idempotency-Key', 'route-after-migration')
            ->postJson($route, ['kind' => 'telemetry', 'event_id' => $lowVoltage->id])->assertOk()
            ->assertJsonPath('duplicate', false)
            ->assertJsonPath('delivery', 'pending');
        $signal = FleetSignal::query()->findOrFail($sent->json('signal_id'));
        $this->assertSame('vehicle.low_voltage', $signal->signal_type);
        $outboxId = $this->outbox($signal)->id;
        Queue::assertPushed(DispatchFleetSignalOutbox::class, fn (DispatchFleetSignalOutbox $job): bool => $job->outboxId === $outboxId);
        $this->deliver($signal);

        $outbox = $this->outbox($signal);
        $this->assertSame('sent', $outbox->status);
        $this->assertNull($outbox->last_error);
        $controlSignal = Signal::query()->where('external_ref', 'fleet_signal_'.$signal->id)->sole();
        $this->assertSame($source->id, (int) $controlSignal->signal_source_id);
        $this->assertSame('fleet_vehicle_low_voltage', $controlSignal->signal_type_code);
        $this->assertSame('processed', $controlSignal->status);
        $alert = ControlRoomAlert::query()->sole();
        $this->assertSame($controlSignal->id, (int) $alert->origin_signal_id);
        $this->assertSame('queclink_fleet', $alert->source);
        $this->assertSame('open', $alert->status);
        $this->assertSame($vehicle->id, (int) $alert->asset_id);
        $this->assertSame($this->site->id, (int) $alert->site_id);
        $this->assertSame('fleet_vehicle_low_voltage', data_get($alert->context, 'signal_type_code'));

        // The response shows on the vehicle profile for someone who may read
        // Control Room. The earlier failure is not replayed by the migration;
        // it stays listed for an explicit retry.
        $items = collect($this->actingAs($responder)->getJson("/fleet-assets/vehicles/{$vehicle->id}/alerts")->assertOk()
            ->assertJsonPath('counts.open', 2)
            ->json('items'));
        $this->assertSame([$alert->id], $items->where('type', 'response')->pluck('id')->all());
        $this->assertSame(['Low vehicle voltage'], $items->where('type', 'response')->pluck('kind')->all());
        $this->assertSame(['signal:'.$unrouted->id], $items->where('type', 'delivery')->pluck('id')->all());
        $this->assertSame('unroutable', $this->outbox($unrouted)->status);
    }

    /** The migration as `migrate` loads it. */
    private function migration(): Migration
    {
        return require database_path('migrations/'.self::MIGRATION);
    }

    /** @return list<array<string,mixed>> */
    private function sourceRows(): array
    {
        return DB::table('control_room_signal_sources')->where('slug', 'queclink_fleet')->orderBy('id')->get()
            ->map(fn (object $row): array => (array) $row)->all();
    }

    /** @param  list<string>  $permissions */
    private function siteUser(array $permissions): User
    {
        $user = User::factory()->create(['approved_at' => now(), 'role' => 'manager']);
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id, 'primary_site_id' => $this->site->id, 'secondary_site_ids' => [],
            'start_date' => today()->subYear(), 'end_date' => null, 'is_active' => true,
            'created_by' => $user->id, 'updated_by' => $user->id,
        ]);
        $user->permissionOverrides()->syncWithoutDetaching(collect($permissions)->mapWithKeys(fn (string $key): array => [
            Permission::query()->firstOrCreate(['key' => $key], [
                'description' => $key, 'group' => str($key)->before('.')->value(), 'module' => str($key)->before('.')->value(),
            ])->id => ['allowed' => true],
        ])->all());
        $user->unsetRelation('permissionOverrides');

        return $user;
    }

    private function vehicle(): Asset
    {
        return Asset::factory()->vehicle()->create([
            'site_id' => $this->site->id, 'home_site_id' => $this->site->id, 'name' => 'Kōwhai van', 'status' => 'active',
        ]);
    }

    /** A tracker power report at an Auckland wall time. */
    private function telemetry(Asset $vehicle, string $wallTime, string $eventType): FleetTelemetryEvent
    {
        $at = CarbonImmutable::parse($wallTime, self::ZONE)->utc();

        return FleetTelemetryEvent::query()->create([
            'asset_id' => $vehicle->id,
            'vendor' => 'queclink',
            'vendor_message_id' => Str::uuid()->toString(),
            'occurred_at' => $at,
            'received_at' => $at,
            'latitude' => -41.2850,
            'longitude' => 174.7750,
            'speed_kph' => 0,
            'event_type' => $eventType,
            'idempotency_key' => hash('sha256', Str::uuid()->toString()),
            'raw_payload' => [],
            'consent_blocked' => false,
        ]);
    }

    private function outbox(FleetSignal $signal): FleetSignalOutbox
    {
        return FleetSignalOutbox::query()->where('fleet_signal_id', $signal->id)->sole();
    }

    /** Run the canonical outbox worker for a Fleet signal, as the queue would. */
    private function deliver(FleetSignal $signal): void
    {
        (new DispatchFleetSignalOutbox($this->outbox($signal)->id))->handle(app(SignalProcessingService::class));
    }
}
