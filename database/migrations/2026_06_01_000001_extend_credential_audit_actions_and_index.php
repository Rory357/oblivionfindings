<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add step-up re-auth outcomes so failed unlock attempts can be
        // recorded (powers the Reveal & audit log's "Denied" view).
        DB::statement(
            'ALTER TABLE site_credential_audit_logs MODIFY COLUMN action ENUM('
            . "'view_list','reveal','copy','edit','rotate','delete',"
            . "'create','totp_setup','totp_remove','totp_code',"
            . "'reauth_failed','reauth_passed'"
            . ') NOT NULL'
        );

        // The cross-site audit feed orders by created_at DESC with a LIMIT; the
        // table only had a single-column tenant_id index, so the ordering was a
        // filesort. Add a created_at index to match the sibling audit tables.
        Schema::table('site_credential_audit_logs', function (Blueprint $table) {
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::table('site_credential_audit_logs', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
        });

        DB::statement(
            'ALTER TABLE site_credential_audit_logs MODIFY COLUMN action ENUM('
            . "'view_list','reveal','copy','edit','rotate','delete',"
            . "'create','totp_setup','totp_remove','totp_code'"
            . ') NOT NULL'
        );
    }
};
