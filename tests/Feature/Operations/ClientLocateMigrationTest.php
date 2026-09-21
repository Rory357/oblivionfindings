<?php

namespace Tests\Feature\Operations;

use App\Models\Queclink\QueclinkPendingCommand;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\Support\ClientLocateFixture;
use Tests\Support\CommittedDatabaseTestCase;

/** Actual additive DDL with committed-fixture cleanup in the generated schema. */
class ClientLocateMigrationTest extends CommittedDatabaseTestCase
{
    public function test_empty_rollback_backfill_and_evidence_retention(): void
    {
        Http::preventStrayRequests();
        Queue::fake();
        Mail::fake();
        Notification::fake();
        $origin = require database_path('migrations/2026_09_21_000002_add_origin_context_to_device_command_requests.php');
        $provenance = require database_path('migrations/2026_09_21_000003_record_queclink_governed_provenance.php');
        $this->assertSame(0, DB::table('device_command_requests')->count(), 'Previous cases must not leak committed fixtures.');
        $origin->down();
        $provenance->down();
        $this->assertFalse(Schema::hasColumn('device_command_requests', 'origin_context'));
        $this->assertFalse(Schema::hasColumn('queclink_pending_commands', 'was_governed'));
        $origin->up();
        $provenance->up();
        $f = ClientLocateFixture::awaitingDelivery();
        // Recreate the pre-migration shape using only this synthetic database.
        Schema::table('queclink_pending_commands', fn ($table) => $table->dropColumn('was_governed'));
        $legacy = QueclinkPendingCommand::query()->create([
            'queclink_device_id' => $f['providerDevice']->id, 'imei' => $f['providerDevice']->imei,
            'command_word' => 'GTRTO', 'raw_command' => 'AT+GTRTO=synthetic,1,,,,,0043$', 'serial_number' => '0043', 'status' => 'queued',
        ]);
        $provenance->up();
        $this->assertTrue($f['pending']->fresh()->was_governed);
        $this->assertFalse($legacy->fresh()->was_governed);
        $this->assertSame(1, $legacy->fresh()->governed_sequence);
        $this->assertSame('action', $legacy->fresh()->governed_role);
        foreach ([$origin, $provenance] as $migration) {
            try {
                $migration->down();
                $this->fail('Retained evidence must prevent destructive rollback.');
            } catch (\RuntimeException $error) {
                $this->assertStringContainsString('evidence', strtolower($error->getMessage()));
            }
        }
        $this->assertTrue(Schema::hasColumn('device_command_requests', 'origin_context'));
        $this->assertTrue(Schema::hasColumn('queclink_pending_commands', 'was_governed'));
    }
}
