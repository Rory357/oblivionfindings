<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('client_incidents', function (Blueprint $table) {
            $table->string('closed_outcome', 120)->nullable()->after('closed_at');
            $table->text('closed_notes')->nullable()->after('closed_outcome');
        });
    }

    public function down(): void
    {
        Schema::table('client_incidents', function (Blueprint $table) {
            $table->dropColumn(['closed_outcome', 'closed_notes']);
        });
    }
};
