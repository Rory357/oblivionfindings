<?php

namespace Tests\Feature\ControlRoom;

use App\Jobs\DispatchFleetSignalOutbox;
use App\Models\Asset;
use App\Models\FleetSignal;
use App\Models\FleetSignalOutbox;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Tests\TestCase;

class SignalOutboxControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $supportWorker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->admin = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
        $this->admin->roles()->attach(Role::where('name', 'admin')->first());

        $this->supportWorker = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $this->supportWorker->roles()->attach(Role::where('name', 'support_worker')->first());
    }

    public function test_settings_index_exposes_failed_signal_outbox_rows(): void
    {
        $outbox = $this->createOutbox(['status' => 'dead_letter']);

        $this->actingAs($this->admin)
            ->get('/control-room/settings?tab=signal-outbox')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('control-room/settings')
                ->has('signalOutbox', 1)
                ->where('signalOutbox.0.id', $outbox->id)
                ->where('signalOutbox.0.status', 'dead_letter')
                ->where('signalOutbox.0.can_retry', true)
                ->where('signalOutbox.0.signal.id', $outbox->fleet_signal_id)
            );
    }

    public function test_retry_requires_manage_permission(): void
    {
        $outbox = $this->createOutbox();

        $this->actingAs($this->supportWorker)
            ->post("/control-room/settings/signal-outbox/{$outbox->id}/retry")
            ->assertForbidden();
    }

    public function test_retry_resets_outbox_dispatches_job_and_audits(): void
    {
        Bus::fake();
        $outbox = $this->createOutbox([
            'status' => 'failed',
            'last_error' => 'Timeout',
            'last_attempt_at' => now()->subMinutes(10),
        ]);

        $this->actingAs($this->admin)
            ->post("/control-room/settings/signal-outbox/{$outbox->id}/retry")
            ->assertRedirect('/control-room/settings?tab=signal-outbox');

        $outbox->refresh();

        $this->assertSame('pending', $outbox->status);
        $this->assertNull($outbox->last_error);

        Bus::assertDispatched(DispatchFleetSignalOutbox::class, fn ($job) => $job->outboxId === $outbox->id);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'controlRoom.signalOutbox.retry',
            'auditable_type' => $outbox->getMorphClass(),
            'auditable_id' => $outbox->id,
            'user_id' => $this->admin->id,
        ]);
    }

    public function test_retry_is_throttled_for_recent_attempt(): void
    {
        Bus::fake();
        $outbox = $this->createOutbox([
            'status' => 'failed',
            'last_attempt_at' => now()->subMinute(),
        ]);

        $this->actingAs($this->admin)
            ->post("/control-room/settings/signal-outbox/{$outbox->id}/retry")
            ->assertStatus(429);

        $this->assertSame('failed', $outbox->fresh()->status);
        Bus::assertNotDispatched(DispatchFleetSignalOutbox::class);
    }

    private function createOutbox(array $overrides = []): FleetSignalOutbox
    {
        $asset = Asset::factory()->vehicle()->create();
        $signal = FleetSignal::create([
            'asset_id' => $asset->id,
            'signal_type' => 'vehicle.speeding',
            'severity_hint' => 'high',
            'occurred_at' => now(),
            'idempotency_key' => 'test-signal-'.Str::uuid(),
            'payload' => ['speed' => 88],
        ]);

        return FleetSignalOutbox::create(array_merge([
            'fleet_signal_id' => $signal->id,
            'status' => 'failed',
            'attempts' => 2,
            'last_attempt_at' => now()->subMinutes(6),
            'last_error' => 'Processor unavailable',
        ], $overrides));
    }
}
