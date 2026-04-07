<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fleet_trips', function (Blueprint $table) {
            $table->boolean('is_personal')->default(false)->after('consent_blocked');
            $table->unsignedBigInteger('marked_personal_by')->nullable()->after('is_personal');
            $table->timestamp('marked_personal_at')->nullable()->after('marked_personal_by');

            $table->foreign('marked_personal_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('fleet_trips', function (Blueprint $table) {
            $table->dropForeign(['marked_personal_by']);
            $table->dropColumn(['is_personal', 'marked_personal_by', 'marked_personal_at']);
        });
    }
};
