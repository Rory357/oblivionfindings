<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Prospective reported-fact storage only. No legacy value backfill or retrospective reporting evidence. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('substance_exposure_records', function (Blueprint $table) {
            $table->boolean('medical_attention_sought')->nullable()->default(null)->change();
            $table->boolean('incident_reported')->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        // Include soft-deleted rows. Unknown must never be converted to a manufactured negative.
        $unknown = DB::table('substance_exposure_records')->where(function ($query) {
            $query->whereNull('medical_attention_sought')->orWhereNull('incident_reported');
        })->exists();
        // Present null/malformed contracts are meaningful evidence too, not an absent legacy contract.
        $retained = Schema::hasTable('chemical_exposure_source_versions')
            && DB::table('chemical_exposure_source_versions')
                ->whereJsonContainsKey('content->facts->reported_facts')->exists();
        if ($unknown || $retained) {
            throw new RuntimeException('Retained reported-fact evidence prevents destructive exposure schema rollback.');
        }
        Schema::table('substance_exposure_records', function (Blueprint $table) {
            $table->boolean('medical_attention_sought')->nullable(false)->default(false)->change();
            $table->boolean('incident_reported')->nullable(false)->default(false)->change();
        });
    }
};
