<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Keep the file name a person uploaded, so documents show and download as
 * "Trust deed 2025.pdf" instead of the random name used for storage.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('governance_documents') || Schema::hasColumn('governance_documents', 'original_name')) {
            return;
        }

        Schema::table('governance_documents', function (Blueprint $table) {
            $table->string('original_name')->nullable()->after('file_path');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('governance_documents') || ! Schema::hasColumn('governance_documents', 'original_name')) {
            return;
        }

        Schema::table('governance_documents', function (Blueprint $table) {
            $table->dropColumn('original_name');
        });
    }
};
