<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shift_series', function (Blueprint $table) {
            $table->foreignId('service_context_id')
                ->nullable()
                ->after('client_id')
                ->constrained('service_contexts')
                ->nullOnDelete();

            $table->foreignId('user_id')->nullable()->change();
            $table->string('shift_type', 30)->default('standard')->after('status');
            $table->boolean('is_sleepover')->default(false)->after('shift_type');
            $table->boolean('is_on_call')->default(false)->after('is_sleepover');
            $table->unsignedSmallInteger('expected_break_minutes')->nullable()->after('is_on_call');

            $table->index(['service_context_id', 'start_date', 'end_date']);
        });
    }

    public function down(): void
    {
        Schema::table('shift_series', function (Blueprint $table) {
            $table->dropIndex(['service_context_id', 'start_date', 'end_date']);
            $table->dropConstrainedForeignId('service_context_id');
            $table->dropColumn([
                'shift_type',
                'is_sleepover',
                'is_on_call',
                'expected_break_minutes',
            ]);
            $table->foreignId('user_id')->nullable(false)->change();
        });
    }
};
