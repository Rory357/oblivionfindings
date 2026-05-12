<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_credential_audit_logs', function (Blueprint $table) {
            // Replace the cascading FK with nullOnDelete so audit history
            // survives credential deletion (compliance / forensics).
            $table->dropForeign(['credential_id']);
            $table->unsignedBigInteger('credential_id')->nullable()->change();
            $table->foreign('credential_id')
                ->references('id')
                ->on('site_credentials')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('site_credential_audit_logs', function (Blueprint $table) {
            $table->dropForeign(['credential_id']);
            // NB: rows with credential_id = NULL must be cleaned before
            // re-tightening the column; this is a best-effort revert.
            $table->unsignedBigInteger('credential_id')->nullable(false)->change();
            $table->foreign('credential_id')
                ->references('id')
                ->on('site_credentials')
                ->cascadeOnDelete();
        });
    }
};
