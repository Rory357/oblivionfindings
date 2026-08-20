<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('data_subject_requests', function (Blueprint $table) {
            $table->timestamp('refused_at')->nullable()->after('completion_notes');
            $table->foreignId('refused_by_user_id')
                ->nullable()
                ->after('refused_at')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('data_subject_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('refused_by_user_id');
            $table->dropColumn('refused_at');
        });
    }
};
