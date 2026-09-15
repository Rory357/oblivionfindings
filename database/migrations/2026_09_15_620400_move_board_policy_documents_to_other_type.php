<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Documents no longer has a "Board policy" type: policies the board approves
 * live in Policies, where members read and confirm them. Files already saved
 * with that type move to "Other" so nothing is lost.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('governance_documents')) {
            return;
        }

        DB::table('governance_documents')->where('document_type', 'policy')->update(['document_type' => 'other']);
    }

    public function down(): void
    {
        if (! Schema::hasTable('governance_documents')) {
            return;
        }

        DB::table('governance_documents')->where('document_type', 'other')->update(['document_type' => 'policy']);
    }
};
