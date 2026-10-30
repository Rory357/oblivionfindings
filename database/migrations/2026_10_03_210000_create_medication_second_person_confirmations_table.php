<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medication_second_person_confirmations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('administration_id')->unique('med_second_person_admin_unique')->constrained('client_medication_administrations', indexName: 'med_second_person_admin_fk');
            $table->foreignId('nominated_user_id')->constrained('users', indexName: 'med_second_person_nominee_fk');
            $table->foreignId('recorded_by')->constrained('users', indexName: 'med_second_person_recorder_fk');
            $table->foreignId('site_id')->constrained('sites', indexName: 'med_second_person_site_fk');
            $table->string('status', 24)->default('pending');
            $table->timestamp('due_at');
            $table->timestamp('responded_at')->nullable();
            $table->json('eligibility_evidence');
            $table->timestamps();
            $table->index(['status', 'due_at'], 'med_second_person_due_index');
            $table->index(['nominated_user_id', 'status'], 'med_second_person_owner_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medication_second_person_confirmations');
    }
};
