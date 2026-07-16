<?php

namespace Tests\Unit\ControlRoom;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OperatorNotePurposeMigrationTest extends TestCase
{
    private string $database;

    private string $originalConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->database = tempnam(sys_get_temp_dir(), 'operator-note-purpose-');
        $this->originalConnection = (string) config('database.default');

        config([
            'database.default' => 'operator_note_purpose_test',
            'database.connections.operator_note_purpose_test' => [
                'driver' => 'sqlite',
                'database' => $this->database,
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
        ]);
        DB::purge('operator_note_purpose_test');

        Schema::create('control_room_operator_notes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('alert_id')->nullable();
            $table->unsignedBigInteger('shift_id')->nullable();
            $table->string('type')->default('note');
            $table->text('content');
            $table->unsignedBigInteger('user_id');
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        DB::disconnect('operator_note_purpose_test');
        DB::purge('operator_note_purpose_test');
        config(['database.default' => $this->originalConnection]);

        if (isset($this->database) && file_exists($this->database)) {
            unlink($this->database);
        }

        parent::tearDown();
    }

    public function test_migration_backfills_rolls_back_and_reapplies_operator_note_purposes(): void
    {
        DB::table('control_room_operator_notes')->insert([
            [
                'type' => 'note',
                'content' => 'General note',
                'user_id' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'type' => 'escalation',
                'content' => 'Escalation note',
                'user_id' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'type' => 'handover',
                'content' => 'Handover note',
                'user_id' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $migration = require database_path(
            'migrations/2026_07_16_000300_add_purpose_to_control_room_operator_notes.php',
        );

        $migration->up();

        $this->assertSame(
            ['general', 'escalation_handover', 'escalation_handover'],
            DB::table('control_room_operator_notes')->orderBy('id')->pluck('purpose')->all(),
        );
        $this->assertTrue(Schema::hasColumn('control_room_operator_notes', 'purpose'));
        $this->assertContains(
            'cr_operator_notes_alert_purpose_created_id_idx',
            collect(DB::select("PRAGMA index_list('control_room_operator_notes')"))
                ->pluck('name')
                ->all(),
        );

        $migration->down();
        $this->assertFalse(Schema::hasColumn('control_room_operator_notes', 'purpose'));

        $migration->up();
        $this->assertSame(
            ['general', 'escalation_handover', 'escalation_handover'],
            DB::table('control_room_operator_notes')->orderBy('id')->pluck('purpose')->all(),
        );
    }
}
