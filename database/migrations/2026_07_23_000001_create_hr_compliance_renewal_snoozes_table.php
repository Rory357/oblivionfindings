<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_compliance_renewal_snoozes', function (Blueprint $table): void {
            $table->id();
            $table->string('entity_type', 32);
            $table->unsignedBigInteger('entity_id');
            $table->timestamp('snoozed_until');
            $table->foreignId('snoozed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['entity_type', 'entity_id'], 'hr_compliance_renewal_snooze_entity_unique');
            $table->index('snoozed_until');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_compliance_renewal_snoozes');
    }
};
