<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('client_incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('shift_id')->nullable()->constrained('shifts')->nullOnDelete();

            $table->string('type')->index(); // e.g. injury, behaviour, medication, safeguarding
            $table->string('severity')->default('low')->index(); // low|medium|high|critical
            $table->string('status')->default('draft')->index(); // draft|submitted|reviewed|closed

            $table->timestamp('occurred_at')->nullable()->index();
            $table->string('location')->nullable();

            $table->string('title');
            $table->text('description')->nullable();
            $table->text('immediate_action')->nullable();
            $table->text('follow_up_required')->nullable();

            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();

            $table->timestamps();

            $table->index(['client_id', 'occurred_at'], 'ci_client_occurred_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_incidents');
    }
};
