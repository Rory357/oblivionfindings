<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Records that a worker has read & understood a controlled procedure, stamped
     * with the version they acknowledged. One row per (procedure, user) — re-reading
     * a new version updates the stamp. Drives the "Acknowledge" affordance on the
     * applicable-procedures panels and the detail modal.
     */
    public function up(): void
    {
        Schema::create('procedure_acknowledgements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('safe_work_procedure_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedSmallInteger('version_acknowledged')->nullable();
            $table->dateTime('acknowledged_at');
            $table->timestamps();

            $table->foreign('safe_work_procedure_id')->references('id')->on('safe_work_procedures')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->unique(['safe_work_procedure_id', 'user_id'], 'proc_ack_procedure_user_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('procedure_acknowledgements');
    }
};
