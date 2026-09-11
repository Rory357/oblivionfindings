<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('it_tickets', function (Blueprint $table): void {
            // Historical resolutions remain unknown; do not fabricate checks.
            $table->text('resolution_verification')->nullable()->after('resolution_summary');
        });
    }

    public function down(): void
    {
        Schema::table('it_tickets', fn (Blueprint $table) => $table->dropColumn('resolution_verification'));
    }
};
