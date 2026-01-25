<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('client_incidents', function (Blueprint $table) {
            $table->boolean('portal_visible')->default(false)->after('review_notes');
            $table->index(['client_id', 'portal_visible'], 'ci_client_portal_idx');
        });
    }

    public function down(): void
    {
        Schema::table('client_incidents', function (Blueprint $table) {
            $table->dropIndex('ci_client_portal_idx');
            $table->dropColumn('portal_visible');
        });
    }
};
