<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function hrReportApplicationIndexMigration(): Migration
{
    return require database_path(
        'migrations/2026_07_28_000002_realign_hr_report_application_indexes.php',
    );
}

function withHrReportIndexDatabase(Closure $callback): void
{
    $connection = 'hr_report_index_test';
    $originalConnection = DB::getDefaultConnection();
    $databasePath = tempnam(sys_get_temp_dir(), 'oblivion-hr-report-index-');
    if ($databasePath === false) {
        throw new RuntimeException('Could not create a temporary HR report migration database.');
    }

    config()->set("database.connections.{$connection}", [
        'driver' => 'sqlite',
        'database' => $databasePath,
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
    DB::purge($connection);
    DB::setDefaultConnection($connection);

    try {
        Schema::create('hr_report_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('report_type');
            $table->boolean('is_active')->default(true);
            $table->dateTime('next_run_at')->nullable()->index();
            $table->index(['tenant_id', 'is_active', 'next_run_at'], 'hr_report_sub_active_next_idx');
            $table->index(['tenant_id', 'report_type'], 'hr_report_sub_tenant_type_idx');
        });

        Schema::create('hr_report_exports', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('subscription_id')->nullable();
            $table->string('report_type');
            $table->dateTime('generated_at')->index();
            $table->index(['tenant_id', 'generated_at'], 'hr_report_export_tenant_generated_idx');
        });

        $callback();
    } finally {
        DB::setDefaultConnection($originalConnection);
        DB::disconnect($connection);
        @unlink($databasePath);
    }
}

it('replaces and exactly restores HR report compatibility indexes', function (): void {
    withHrReportIndexDatabase(function (): void {
        $migration = hrReportApplicationIndexMigration();
        $migration->up();

        expect(Schema::hasIndex('hr_report_subscriptions', 'hr_report_sub_active_next_app_idx'))->toBeTrue()
            ->and(Schema::hasIndex('hr_report_subscriptions', 'hr_report_sub_type_active_idx'))->toBeTrue()
            ->and(Schema::hasIndex('hr_report_exports', 'hr_report_export_type_generated_idx'))->toBeTrue()
            ->and(Schema::hasIndex('hr_report_exports', 'hr_report_export_subscription_generated_idx'))->toBeTrue()
            ->and(Schema::hasIndex('hr_report_subscriptions', 'hr_report_subscriptions_tenant_id_index'))->toBeFalse()
            ->and(Schema::hasIndex('hr_report_exports', 'hr_report_export_tenant_generated_idx'))->toBeFalse();

        $migration->down();

        expect(Schema::hasIndex('hr_report_subscriptions', 'hr_report_sub_active_next_app_idx'))->toBeFalse()
            ->and(Schema::hasIndex('hr_report_exports', 'hr_report_export_type_generated_idx'))->toBeFalse()
            ->and(Schema::hasIndex('hr_report_subscriptions', 'hr_report_subscriptions_tenant_id_index'))->toBeTrue()
            ->and(Schema::hasIndex('hr_report_subscriptions', 'hr_report_sub_active_next_idx'))->toBeTrue()
            ->and(Schema::hasIndex('hr_report_subscriptions', 'hr_report_sub_tenant_type_idx'))->toBeTrue()
            ->and(Schema::hasIndex('hr_report_exports', 'hr_report_exports_tenant_id_index'))->toBeTrue()
            ->and(Schema::hasIndex('hr_report_exports', 'hr_report_export_tenant_generated_idx'))->toBeTrue();
    });
});
