<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            if (!Schema::hasColumn('sites', 'accessibility_features')) {
                $table->json('accessibility_features')->nullable()->after('waitlist_count');
            }
            if (!Schema::hasColumn('sites', 'accessibility_notes')) {
                $table->text('accessibility_notes')->nullable()->after('accessibility_features');
            }
            if (!Schema::hasColumn('sites', 'accessibility_last_assessed')) {
                $table->date('accessibility_last_assessed')->nullable()->after('accessibility_notes');
            }
            if (!Schema::hasColumn('sites', 'accessibility_assessed_by')) {
                $table->string('accessibility_assessed_by', 255)->nullable()->after('accessibility_last_assessed');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $columns = [
                'accessibility_features',
                'accessibility_notes',
                'accessibility_last_assessed',
                'accessibility_assessed_by',
            ];
            foreach ($columns as $col) {
                if (Schema::hasColumn('sites', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
