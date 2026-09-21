<?php

namespace Tests\Unit\Tracking;

use App\Domain\SecurityDevices\Management\Services\DeviceCommandAuditService;
use App\Domain\SecurityDevices\Management\Services\DeviceCommandContractVerifier;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\Support\CommittedDatabaseTestCase;
use Tests\Support\DeviceCommandAuditFixture;

class DeviceCommandAuditTailMigrationTest extends CommittedDatabaseTestCase
{
    public function test_empty_rollback_backfill_and_evidence_preserving_rollback(): void
    {
        Http::preventStrayRequests();
        Queue::fake();
        $this->assertSame(0, DB::transactionLevel());
        $this->assertIsolatedTestConnection(DB::connection());
        // Fixture restoration also permits this proof after other Unit families.
        // DDL is never wrapped in a RefreshDatabase transaction.
        $this->assertSame(0, DB::table('device_command_audit_events')->count());
        $migration = require base_path('database/migrations/2026_09_21_000004_add_device_command_audit_tail.php');
        $migration->down();
        $this->assertFalse(Schema::hasColumn('device_command_requests', 'audit_tail_event_id'));
        $migration->up();
        $empty = DeviceCommandAuditFixture::request();
        $populated = DeviceCommandAuditFixture::request();
        $audit = app(DeviceCommandAuditService::class);
        $audit->append($populated, null, 'original_first');
        $tail = $audit->append($populated, null, 'original_second');
        $eventsBefore = DB::table('device_command_audit_events')->orderBy('id')->get()->toJson();
        $signature = $populated->signature;
        // Recreate the pre-migration layout in this disposable schema only.
        Schema::table('device_command_requests', fn (Blueprint $table) => $table->dropColumn('audit_tail_event_id'));
        $migration->up();
        $this->assertNull($empty->fresh()->audit_tail_event_id);
        $this->assertSame($tail->id, $populated->fresh()->audit_tail_event_id);
        $this->assertSame($eventsBefore, DB::table('device_command_audit_events')->orderBy('id')->get()->toJson());
        $this->assertSame($signature, $populated->fresh()->signature);
        $this->assertTrue(app(DeviceCommandContractVerifier::class)->verify($populated->fresh()));
        $next = $audit->append($populated, null, 'after_backfill');
        $this->assertSame($tail->event_hash, $next->previous_hash);
        try {
            $migration->down();
            $this->fail('Rollback must retain audit-tail metadata when evidence exists.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('Retain audit tail metadata', $error->getMessage());
        }
        $this->assertTrue(Schema::hasColumn('device_command_requests', 'audit_tail_event_id'));
        $this->assertSame(3, $populated->auditEvents()->count());
        $this->assertSame($next->id, $populated->fresh()->audit_tail_event_id);
    }
}
