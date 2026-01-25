<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('client_incidents', function (Blueprint $table) {
            $table->foreignId('template_id')->nullable()->after('shift_id')
                ->constrained('incident_templates')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('client_incidents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('template_id');
        });
    }
};
