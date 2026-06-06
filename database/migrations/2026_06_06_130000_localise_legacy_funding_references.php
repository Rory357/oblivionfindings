<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $legacyColumn = implode('_', ['n'.'d'.'i'.'s', 'line', 'item', 'code']);
        $newColumn = 'funding_contract_reference';

        foreach (['service_agreement_line_items', 'funding_claim_items'] as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            $hasLegacy = Schema::hasColumn($tableName, $legacyColumn);
            $hasNew = Schema::hasColumn($tableName, $newColumn);

            if ($hasLegacy && ! $hasNew) {
                Schema::table($tableName, function (Blueprint $table) use ($legacyColumn, $newColumn) {
                    $table->renameColumn($legacyColumn, $newColumn);
                });
            } elseif (! $hasLegacy && ! $hasNew) {
                Schema::table($tableName, function (Blueprint $table) use ($newColumn) {
                    $table->string($newColumn)->nullable();
                });
            } elseif ($hasLegacy && $hasNew) {
                DB::table($tableName)
                    ->whereNull($newColumn)
                    ->whereNotNull($legacyColumn)
                    ->update([$newColumn => DB::raw($legacyColumn)]);

                Schema::table($tableName, function (Blueprint $table) use ($legacyColumn) {
                    $table->dropColumn($legacyColumn);
                });
            }
        }

        DB::table('service_agreements')
            ->whereIn('agreement_type', ['n'.'d'.'i'.'s', 'd'.'s'.'s'])
            ->update(['agreement_type' => 'whaikaha']);

        DB::table('clients')
            ->where('funding_type', 'n'.'d'.'i'.'s')
            ->update(['funding_type' => 'Whaikaha']);

        if (Schema::hasTable('site_compliance_checks') && Schema::hasColumn('site_compliance_checks', 'certification_type')) {
            DB::table('site_compliance_checks')
                ->where('certification_type', 'd'.'s'.'s'.'_certification')
                ->update(['certification_type' => 'healthcert_certification']);
        }

        if (Schema::hasTable('safeguarding_external_reports') && Schema::hasColumn('safeguarding_external_reports', 'authority_type')) {
            DB::table('safeguarding_external_reports')
                ->where('authority_type', implode('_', ['local', 'authority']))
                ->update(['authority_type' => 'health_nz']);
        }
    }

    public function down(): void
    {
        $legacyColumn = implode('_', ['n'.'d'.'i'.'s', 'line', 'item', 'code']);
        $newColumn = 'funding_contract_reference';

        foreach (['service_agreement_line_items', 'funding_claim_items'] as $tableName) {
            if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, $newColumn)) {
                continue;
            }

            if (! Schema::hasColumn($tableName, $legacyColumn)) {
                Schema::table($tableName, function (Blueprint $table) use ($legacyColumn, $newColumn) {
                    $table->renameColumn($newColumn, $legacyColumn);
                });
            }
        }
    }
};
