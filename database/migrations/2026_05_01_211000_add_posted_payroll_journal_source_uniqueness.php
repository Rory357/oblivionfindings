<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX = 'fin_journals_payroll_posted_source_unique';

    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            Schema::table('fin_journals', function (Blueprint $table) {
                $table->unsignedBigInteger('posted_payroll_source_id')
                    ->nullable()
                    ->storedAs("case when `type` = 'payroll' and `source_type` = 'payroll_run' and `status` = 'posted' then `source_id` else null end");

                $table->unique(['organization_id', 'posted_payroll_source_id'], self::INDEX);
            });

            return;
        }

        if ($driver === 'pgsql') {
            DB::statement(
                'CREATE UNIQUE INDEX '.self::INDEX.' ON fin_journals (organization_id, source_id) '.
                "WHERE type = 'payroll' AND source_type = 'payroll_run' AND status = 'posted' AND source_id IS NOT NULL"
            );

            return;
        }

        if ($driver === 'sqlite') {
            DB::statement(
                'CREATE UNIQUE INDEX '.self::INDEX.' ON fin_journals (organization_id, source_id) '.
                "WHERE type = 'payroll' AND source_type = 'payroll_run' AND status = 'posted' AND source_id IS NOT NULL"
            );
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            Schema::table('fin_journals', function (Blueprint $table) {
                $table->dropUnique(self::INDEX);
                $table->dropColumn('posted_payroll_source_id');
            });

            return;
        }

        if (in_array($driver, ['pgsql', 'sqlite'], true)) {
            DB::statement('DROP INDEX IF EXISTS '.self::INDEX);
        }
    }
};
