<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_replacement_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->nullable()->index();
            $table->foreignId('shift_id')->constrained('shifts')->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('current_staff_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('replacement_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('requested')->index();
            $table->string('reason', 255);
            $table->text('notes')->nullable();
            $table->json('required_skills')->nullable();
            $table->dateTime('requested_at')->nullable();
            $table->dateTime('claimed_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['shift_id', 'status']);
        });

        Schema::table('shift_open_positions', function (Blueprint $table) {
            if (! Schema::hasColumn('shift_open_positions', 'replacement_request_id')) {
                $table->foreignId('replacement_request_id')
                    ->nullable()
                    ->after('shift_id')
                    ->constrained('shift_replacement_requests')
                    ->nullOnDelete();

                $table->index(['replacement_request_id', 'status'], 'sop_replacement_status_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('shift_open_positions', function (Blueprint $table) {
            if (Schema::hasColumn('shift_open_positions', 'replacement_request_id')) {
                $table->dropIndex('sop_replacement_status_idx');
                $table->dropConstrainedForeignId('replacement_request_id');
            }
        });

        Schema::dropIfExists('shift_replacement_requests');
    }
};
