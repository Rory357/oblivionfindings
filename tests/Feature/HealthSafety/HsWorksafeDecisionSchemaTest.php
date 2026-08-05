<?php

namespace Tests\Feature\HealthSafety;

use App\Models\HsEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class HsWorksafeDecisionSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_open_events_default_to_an_undecided_worksafe_state(): void
    {
        $event = HsEvent::factory()->create();

        $this->assertNull($event->fresh()->worksafe_notifiable);
        $this->assertNull($event->worksafe_status);
    }

    public function test_schema_exposes_explicit_worksafe_decision_metadata(): void
    {
        $this->assertTrue(Schema::hasColumns('hs_events', [
            'worksafe_decided_at',
            'worksafe_decided_by_user_id',
            'worksafe_decision_reason',
            'worksafe_decision_source',
        ]));

        $column = collect(Schema::getColumns('hs_events'))
            ->firstWhere('name', 'worksafe_notifiable');

        $this->assertNotNull($column);
        $this->assertTrue((bool) ($column['nullable'] ?? false));
        $this->assertNull($column['default'] ?? null);
    }

    public function test_migration_conservatively_classifies_legacy_rows(): void
    {
        $migrationPath = database_path(
            'migrations/2026_07_16_000100_make_hs_worksafe_decision_explicit.php'
        );

        $this->assertFileExists($migrationPath);

        $migration = require $migrationPath;
        $migration->down();

        $creator = User::factory()->create();
        $base = [
            'source_type' => HsEvent::class,
            'event_category' => HsEvent::CATEGORY_INCIDENT,
            'severity' => HsEvent::SEVERITY_HIGH,
            'occurred_at' => now(),
            'reported_at' => now(),
            'investigation_required' => false,
            'created_by' => $creator->id,
            'created_at' => now()->subDay(),
            'updated_at' => now()->subHour(),
        ];

        $openFalseId = DB::table('hs_events')->insertGetId([
            ...$base,
            'reference_number' => 'HS-LEGACY-OPEN',
            'source_id' => 1_900_001,
            'status' => HsEvent::STATUS_OPEN,
            'worksafe_notifiable' => false,
            'worksafe_status' => null,
            'idempotency_key' => hash('sha256', 'legacy-open-false'),
        ]);
        $closedFalseId = DB::table('hs_events')->insertGetId([
            ...$base,
            'reference_number' => 'HS-LEGACY-CLOSED',
            'source_id' => 1_900_002,
            'status' => HsEvent::STATUS_CLOSED,
            'worksafe_notifiable' => false,
            'worksafe_status' => null,
            'closed_at' => now()->subMinutes(30),
            'closed_by' => $creator->id,
            'closure_summary' => 'Legacy event was already closed.',
            'idempotency_key' => hash('sha256', 'legacy-closed-false'),
        ]);
        $trueId = DB::table('hs_events')->insertGetId([
            ...$base,
            'reference_number' => 'HS-LEGACY-TRUE',
            'source_id' => 1_900_003,
            'status' => HsEvent::STATUS_INVESTIGATING,
            'worksafe_notifiable' => true,
            'worksafe_status' => HsEvent::WORKSAFE_PENDING,
            'idempotency_key' => hash('sha256', 'legacy-true'),
        ]);
        $notifiedId = DB::table('hs_events')->insertGetId([
            ...$base,
            'reference_number' => 'HS-LEGACY-NOTIFIED',
            'source_id' => 1_900_004,
            'status' => HsEvent::STATUS_INVESTIGATING,
            'worksafe_notifiable' => false,
            'worksafe_status' => HsEvent::WORKSAFE_NOTIFIED,
            'worksafe_notified_at' => now()->subMinutes(20),
            'worksafe_method' => 'online',
            'idempotency_key' => hash('sha256', 'legacy-notified'),
        ]);

        $migration->up();

        $openFalse = DB::table('hs_events')->find($openFalseId);
        $closedFalse = DB::table('hs_events')->find($closedFalseId);
        $true = DB::table('hs_events')->find($trueId);
        $notified = DB::table('hs_events')->find($notifiedId);

        $this->assertNull($openFalse->worksafe_notifiable);
        $this->assertNull($openFalse->worksafe_decided_at);
        $this->assertSame(0, (int) $closedFalse->worksafe_notifiable);
        $this->assertNull($closedFalse->worksafe_decision_source);

        foreach ([$true, $notified] as $explicitNotifiable) {
            $this->assertSame(1, (int) $explicitNotifiable->worksafe_notifiable);
            $this->assertNotNull($explicitNotifiable->worksafe_decided_at);
            $this->assertSame($creator->id, (int) $explicitNotifiable->worksafe_decided_by_user_id);
            $this->assertSame('migration', $explicitNotifiable->worksafe_decision_source);
            $this->assertNotSame('', trim((string) $explicitNotifiable->worksafe_decision_reason));
        }
    }
}
