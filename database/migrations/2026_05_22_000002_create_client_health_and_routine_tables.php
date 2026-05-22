<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_bowel_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->unsignedBigInteger('organization_id')->nullable()->index();
            $table->dateTime('occurred_at')->index();
            $table->unsignedTinyInteger('bristol_type');
            $table->string('volume')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['client_id', 'occurred_at']);
        });

        Schema::create('client_fluid_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->unsignedBigInteger('organization_id')->nullable()->index();
            $table->dateTime('occurred_at')->index();
            $table->string('direction');
            $table->string('fluid_type')->nullable();
            $table->unsignedInteger('volume_ml');
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['client_id', 'occurred_at']);
            $table->index(['client_id', 'direction', 'occurred_at']);
        });

        Schema::create('client_seizure_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->unsignedBigInteger('organization_id')->nullable()->index();
            $table->dateTime('occurred_at')->index();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->string('seizure_type')->nullable();
            $table->string('trigger')->nullable();
            $table->text('response_taken')->nullable();
            $table->text('recovery_notes')->nullable();
            $table->boolean('escalated')->default(false);
            $table->string('follow_up_action')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['client_id', 'occurred_at']);
            $table->index(['client_id', 'escalated']);
        });

        Schema::create('client_routines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->unsignedBigInteger('organization_id')->nullable()->index();
            $table->string('time_block');
            $table->text('body')->nullable();
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['client_id', 'time_block'], 'client_routines_client_block_unique');
            $table->index(['client_id', 'display_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_routines');
        Schema::dropIfExists('client_seizure_entries');
        Schema::dropIfExists('client_fluid_entries');
        Schema::dropIfExists('client_bowel_entries');
    }
};
