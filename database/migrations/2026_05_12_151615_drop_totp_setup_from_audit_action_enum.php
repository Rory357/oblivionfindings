<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The earlier TOTP design generated a secret server-side and walked the
 * operator through a QR-scan + 6-digit verify ("totp_setup" audit row).
 * The shipped behaviour is that the operator pastes an existing Base32
 * secret directly into the credential form, so no setup step exists and
 * the enum value is dead. Drop it; any existing rows with that value
 * (there should be none in production) become "create" instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('site_credential_audit_logs')
            ->where('action', 'totp_setup')
            ->update(['action' => 'create']);

        DB::statement(
            "ALTER TABLE site_credential_audit_logs MODIFY COLUMN action ENUM("
            . "'view_list','reveal','copy','edit','rotate','delete',"
            . "'create','totp_remove','totp_code'"
            . ") NOT NULL"
        );
    }

    public function down(): void
    {
        DB::statement(
            "ALTER TABLE site_credential_audit_logs MODIFY COLUMN action ENUM("
            . "'view_list','reveal','copy','edit','rotate','delete',"
            . "'create','totp_setup','totp_remove','totp_code'"
            . ") NOT NULL"
        );
    }
};
