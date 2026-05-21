<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('board_packs', function (Blueprint $table) {
            if (! Schema::hasColumn('board_packs', 'supplementary_attachments')) {
                $table->json('supplementary_attachments')->nullable()->after('read_tracking');
            }
        });
    }

    public function down(): void
    {
        Schema::table('board_packs', function (Blueprint $table) {
            if (Schema::hasColumn('board_packs', 'supplementary_attachments')) {
                $table->dropColumn('supplementary_attachments');
            }
        });
    }
};
