<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_controlled_drug_entries', function (Blueprint $table): void {
            // Existing entries retain an unknown dose identity; never infer one.
            $table->unsignedBigInteger('client_medication_administration_id')->nullable();
            $table->index('client_medication_administration_id', 'cd_entries_admin_idx');
            $table->foreign('client_medication_administration_id', 'cd_entries_admin_fk')
                ->references('id')->on('client_medication_administrations')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('client_controlled_drug_entries')->whereNotNull('client_medication_administration_id')->exists()) {
            throw new LogicException('Cannot remove populated controlled-dose provenance. Use an evidence-preserving forward migration.');
        }
        Schema::table('client_controlled_drug_entries', function (Blueprint $table): void {
            $table->dropForeign('cd_entries_admin_fk');
            $table->dropIndex('cd_entries_admin_idx');
            $table->dropColumn('client_medication_administration_id');
        });
    }
};
