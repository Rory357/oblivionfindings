<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('board_packs', function (Blueprint $table) {
            if (! Schema::hasColumn('board_packs', 'revision_number')) {
                $table->unsignedInteger('revision_number')->default(1)->after('governance_meeting_id');
            }
            if (! Schema::hasColumn('board_packs', 'supersedes_id')) {
                $table->unsignedBigInteger('supersedes_id')->nullable()->after('revision_number');
            }
            if (! Schema::hasColumn('board_packs', 'build_status')) {
                $table->string('build_status')->default('published')->after('supersedes_id');
            }
            if (! Schema::hasColumn('board_packs', 'error_reference')) {
                $table->text('error_reference')->nullable()->after('build_status');
            }
            if (! Schema::hasColumn('board_packs', 'is_current')) {
                $table->boolean('is_current')->default(true)->after('error_reference');
            }
        });

        // Manage indexes: drop single-column unique on governance_meeting_id, add compound unique on meeting + revision
        $driver = DB::getDriverName();
        if ($driver === 'mysql') {
            $hasCompound = ! empty(DB::select("SHOW INDEXES FROM board_packs WHERE Key_name = 'board_packs_meeting_revision_unique'"));
            $hasOldUnique = ! empty(DB::select("SHOW INDEXES FROM board_packs WHERE Key_name = 'board_packs_governance_meeting_id_unique'"));

            if (! $hasCompound) {
                Schema::table('board_packs', function (Blueprint $table) {
                    $table->unique(['governance_meeting_id', 'revision_number'], 'board_packs_meeting_revision_unique');
                });
            }

            if ($hasOldUnique) {
                Schema::table('board_packs', function (Blueprint $table) {
                    $table->dropUnique('board_packs_governance_meeting_id_unique');
                });
            }
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();
        if ($driver === 'mysql') {
            $hasCompound = ! empty(DB::select("SHOW INDEXES FROM board_packs WHERE Key_name = 'board_packs_meeting_revision_unique'"));
            if ($hasCompound) {
                Schema::table('board_packs', function (Blueprint $table) {
                    $table->dropUnique('board_packs_meeting_revision_unique');
                });
            }
        }

        Schema::table('board_packs', function (Blueprint $table) {
            $columnsToDrop = [];
            foreach (['is_current', 'error_reference', 'build_status', 'supersedes_id', 'revision_number'] as $col) {
                if (Schema::hasColumn('board_packs', $col)) {
                    $columnsToDrop[] = $col;
                }
            }
            if (! empty($columnsToDrop)) {
                $table->dropColumn($columnsToDrop);
            }
        });
    }
};
