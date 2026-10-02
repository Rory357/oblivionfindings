<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A person can have no work email: recruitment hires sign in with their
     * personal address, and HR can clear a work email. The sign-in email is
     * never copied in as a stand-in, so the column must accept "none".
     * Non-destructive: no stored value changes.
     */
    public function up(): void
    {
        Schema::table('hr_employee_profiles', function (Blueprint $table): void {
            $table->string('work_email')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Restoring NOT NULL would need a value for every "no work email" row,
        // and the only stand-in available is the sign-in email, which can be
        // personal. Leave the column nullable rather than invent addresses.
        if (DB::table('hr_employee_profiles')->whereNull('work_email')->exists()) {
            $message = 'hr_employee_profiles.work_email left nullable: rows with no work email exist. '
                .'HR must set them before NOT NULL can be restored.';
            Log::warning($message);
            if (defined('STDERR')) {
                fwrite(STDERR, $message.PHP_EOL);
            }

            return;
        }

        Schema::table('hr_employee_profiles', function (Blueprint $table): void {
            $table->string('work_email')->nullable(false)->change();
        });
    }
};
