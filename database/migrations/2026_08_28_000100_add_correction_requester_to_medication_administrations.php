<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_medication_administrations', function (Blueprint $table): void {
            $table->unsignedBigInteger('correction_requested_by')
                ->nullable()
                ->after('correction_reason');
            $table->foreign('correction_requested_by', 'cma_correction_requester_fk')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('client_medication_administrations')
            ->whereNotNull('correction_requested_by')
            ->exists()) {
            throw new RuntimeException(
                'Cannot remove medication-correction requester provenance while attributed correction evidence exists.',
            );
        }

        Schema::table('client_medication_administrations', function (Blueprint $table): void {
            $table->dropForeign('cma_correction_requester_fk');
            $table->dropColumn('correction_requested_by');
        });
    }
};
