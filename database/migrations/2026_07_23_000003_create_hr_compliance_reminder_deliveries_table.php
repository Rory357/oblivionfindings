<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_compliance_reminder_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->char('delivery_key', 64)->unique('hr_comp_reminder_delivery_key_uq');
            $table->foreignId('recipient_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('initiated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind', 32);
            $table->string('source_type', 32);
            $table->unsignedBigInteger('source_id')->nullable();
            $table->json('payload');
            $table->string('status', 20)->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(
                ['status', 'attempts', 'updated_at'],
                'hr_comp_reminder_recovery_idx',
            );
            $table->index(
                ['recipient_user_id', 'created_at'],
                'hr_comp_reminder_recipient_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_compliance_reminder_deliveries');
    }
};
