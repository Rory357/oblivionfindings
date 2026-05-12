<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            "ALTER TABLE site_credential_audit_logs MODIFY COLUMN action ENUM("
            . "'view_list','reveal','copy','edit','rotate','delete',"
            . "'create','totp_setup','totp_remove','totp_code'"
            . ") NOT NULL"
        );
    }

    public function down(): void
    {
        DB::statement(
            "ALTER TABLE site_credential_audit_logs MODIFY COLUMN action ENUM("
            . "'view_list','reveal','copy','edit','rotate','delete'"
            . ") NOT NULL"
        );
    }
};
