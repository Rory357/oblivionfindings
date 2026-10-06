<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A permitted incoming handover retains its complete encrypted allergy evidence.
        Schema::table('medication_provider_transfer_events', fn (Blueprint $table) => $table->longText('evidence')->change());
    }

    public function down(): void
    {
        if (DB::table('medication_provider_transfer_events')->whereRaw('OCTET_LENGTH(evidence) > 65535')->exists()) {
            throw new RuntimeException('Retain full encrypted provider transfer evidence before reducing storage capacity.');
        }

        Schema::table('medication_provider_transfer_events', fn (Blueprint $table) => $table->text('evidence')->change());
    }
};
