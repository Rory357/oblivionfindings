<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Compliance evidence can be opened and downloaded by the board: keep the
 * name the file had when it was uploaded, its type and size, so the file
 * downloads as "Annual return receipt.pdf" rather than a storage code.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('compliance_evidence')) {
            return;
        }

        Schema::table('compliance_evidence', function (Blueprint $table) {
            if (! Schema::hasColumn('compliance_evidence', 'original_name')) {
                $table->string('original_name')->nullable()->after('file_path');
            }
            if (! Schema::hasColumn('compliance_evidence', 'mime_type')) {
                $table->string('mime_type')->nullable()->after('original_name');
            }
            if (! Schema::hasColumn('compliance_evidence', 'file_size')) {
                $table->unsignedBigInteger('file_size')->nullable()->after('mime_type');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('compliance_evidence')) {
            return;
        }

        foreach (['file_size', 'mime_type', 'original_name'] as $column) {
            if (Schema::hasColumn('compliance_evidence', $column)) {
                Schema::table('compliance_evidence', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
