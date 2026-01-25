<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('client_incidents', function (Blueprint $table) {
            $table->timestamp('submitted_at')->nullable()->after('status');
            $table->boolean('requires_followup')->default(false)->after('description');
            $table->text('immediate_action_taken')->nullable()->after('requires_followup');
            $table->text('witnesses')->nullable()->after('immediate_action_taken');
            $table->text('review_notes')->nullable()->after('reviewed_at');
        });
    }

    public function down(): void
    {
        Schema::table('client_incidents', function (Blueprint $table) {
            $table->dropColumn([
                'submitted_at',
                'requires_followup',
                'immediate_action_taken',
                'witnesses',
                'review_notes',
            ]);
        });
    }
};
