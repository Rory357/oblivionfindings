<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('client_incidents', function (Blueprint $table) {
            $table->unsignedBigInteger('reopened_by')->nullable()->after('closed_notes');
            $table->dateTime('reopened_at')->nullable()->after('reopened_by');
            $table->text('reopened_reason')->nullable()->after('reopened_at');

            $table->foreign('reopened_by', 'ci_reopened_by_fk')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('client_incidents', function (Blueprint $table) {
            $table->dropForeign('ci_reopened_by_fk');
            $table->dropColumn(['reopened_by', 'reopened_at', 'reopened_reason']);
        });
    }
};
