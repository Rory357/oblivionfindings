<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('governance_policies')) {
            Schema::table('governance_policies', function (Blueprint $table) {
                if (!Schema::hasColumn('governance_policies', 'requires_attestation')) {
                    $table->boolean('requires_attestation')->default(true)->after('status');
                }
                if (!Schema::hasColumn('governance_policies', 'attestation_frequency')) {
                    $table->string('attestation_frequency')->nullable()->after('requires_attestation');
                }
            });
        }

        if (Schema::hasTable('policy_attestations')) {
            Schema::table('policy_attestations', function (Blueprint $table) {
                if (!Schema::hasColumn('policy_attestations', 'policy_version')) {
                    $table->unsignedInteger('policy_version')->default(1)->after('acknowledged');
                }
                if (!Schema::hasColumn('policy_attestations', 'due_date')) {
                    $table->date('due_date')->nullable()->after('policy_version');
                }
            });
        }

        if (Schema::hasTable('resolutions')) {
            Schema::table('resolutions', function (Blueprint $table) {
                if (!Schema::hasColumn('resolutions', 'electorate_at_open')) {
                    $table->json('electorate_at_open')->nullable()->after('paper_snapshot');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('governance_policies')) {
            Schema::table('governance_policies', function (Blueprint $table) {
                if (Schema::hasColumn('governance_policies', 'attestation_frequency')) {
                    $table->dropColumn('attestation_frequency');
                }
                if (Schema::hasColumn('governance_policies', 'requires_attestation')) {
                    $table->dropColumn('requires_attestation');
                }
            });
        }

        if (Schema::hasTable('policy_attestations')) {
            Schema::table('policy_attestations', function (Blueprint $table) {
                if (Schema::hasColumn('policy_attestations', 'due_date')) {
                    $table->dropColumn('due_date');
                }
                if (Schema::hasColumn('policy_attestations', 'policy_version')) {
                    $table->dropColumn('policy_version');
                }
            });
        }

        if (Schema::hasTable('resolutions')) {
            Schema::table('resolutions', function (Blueprint $table) {
                if (Schema::hasColumn('resolutions', 'electorate_at_open')) {
                    $table->dropColumn('electorate_at_open');
                }
            });
        }
    }
};
