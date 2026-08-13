<?php

namespace Tests\Unit\ControlRoom;

use App\Models\ControlRoom\Signal;
use App\Models\ControlRoom\SignalSource;
use App\Models\ControlRoom\SignalType;
use App\Models\ControlRoomAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

class SignalAlertProvenanceMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_ambiguous_legacy_claims_keep_every_alert_and_record_deterministic_review(): void
    {
        $source = SignalSource::create([
            'name' => 'Legacy provenance source',
            'slug' => 'legacy-provenance-source',
            'category' => 'operations',
            'vendor' => 'internal',
            'status' => 'active',
        ]);
        $type = SignalType::create([
            'code' => 'legacy.provenance',
            'name' => 'Legacy provenance',
            'category' => 'operations',
            'default_severity' => 'high',
        ]);
        $signal = Signal::create([
            'signal_source_id' => $source->id,
            'signal_type_id' => $type->id,
            'signal_type_code' => $type->code,
            'idempotency_key' => 'legacy-ambiguous-provenance',
            'severity_hint' => 'high',
            'occurred_at' => now(),
            'status' => 'pending',
        ]);
        $terminal = ControlRoomAlert::factory()->create([
            'status' => 'closed',
            'context' => ['signal_id' => $signal->id],
        ]);
        $active = ControlRoomAlert::factory()->open()->create([
            'context' => ['signal_id' => $signal->id],
        ]);
        $migration = require database_path(
            'migrations/2026_08_14_000053_enforce_control_room_signal_alert_provenance.php',
        );
        $method = new ReflectionMethod($migration, 'reconcileContextClaims');
        $method->setAccessible(true);

        $method->invoke($migration, $signal->id, [
            [
                'alert_id' => $terminal->id,
                'status' => $terminal->status,
                'origin_signal_id' => null,
            ],
            [
                'alert_id' => $active->id,
                'status' => $active->status,
                'origin_signal_id' => null,
            ],
        ]);

        $this->assertDatabaseCount('control_room_alerts', 2);
        $this->assertSame($signal->id, $active->fresh()->origin_signal_id);
        $this->assertNull($terminal->fresh()->origin_signal_id);
        $this->assertDatabaseHas('control_room_signals', [
            'id' => $signal->id,
            'status' => 'processed',
            'alert_id' => $active->id,
            'correlated_alert_id' => null,
        ]);
        $this->assertDatabaseCount('control_room_signal_alert_provenance_reviews', 2);
        $this->assertDatabaseHas('control_room_signal_alert_provenance_reviews', [
            'signal_id' => $signal->id,
            'alert_id' => $active->id,
            'selected_alert_id' => $active->id,
            'reason' => 'ambiguous_context_origin_selected',
            'status' => 'pending',
        ]);
        $this->assertDatabaseHas('control_room_signal_alert_provenance_reviews', [
            'signal_id' => $signal->id,
            'alert_id' => $terminal->id,
            'selected_alert_id' => $active->id,
            'reason' => 'duplicate_context_origin_claim',
            'status' => 'pending',
        ]);
    }
}
