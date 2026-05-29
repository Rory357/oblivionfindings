<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            // `archived` is distinct from both `is_active` (operational on/off)
            // and the SoftDeletes `deleted_at` (removed). Archived sites are
            // hidden from every index view by default but remain fully intact
            // and are surfaced via the dedicated "Archived" tab.
            if (! Schema::hasColumn('sites', 'archived')) {
                $table->boolean('archived')->default(false)->index()->after('is_active');
            }
            if (! Schema::hasColumn('sites', 'archived_at')) {
                $table->timestamp('archived_at')->nullable()->after('archived');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            foreach (['archived_at', 'archived'] as $col) {
                if (Schema::hasColumn('sites', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
