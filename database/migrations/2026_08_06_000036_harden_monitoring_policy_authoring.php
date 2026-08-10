<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->assertPolicyIdentitiesAreUnambiguous();
        $this->assertLegacyProfileRetentionReferencesAreValid();

        Schema::table('monitoring_profiles', function (Blueprint $table): void {
            $table->foreignId('legacy_data_retention_policy_id')->nullable()->after('retention_policy_id');
            $table->unsignedInteger('version')->default(1)->after('is_active');
            $table->foreignId('created_by_user_id')->nullable()->after('version');
            $table->foreignId('updated_by_user_id')->nullable()->after('created_by_user_id');
            $table->timestamp('deactivated_at')->nullable()->after('updated_by_user_id');
            $table->foreignId('deactivated_by_user_id')->nullable()->after('deactivated_at');
            $table->string('deactivation_reason', 500)->nullable()->after('deactivated_by_user_id');

            $table->foreign('legacy_data_retention_policy_id', 'monitoring_profile_legacy_retention_fk')
                ->references('id')->on('data_retention_policies')->nullOnDelete();
            $table->foreign('created_by_user_id', 'monitoring_profile_created_actor_fk')
                ->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by_user_id', 'monitoring_profile_updated_actor_fk')
                ->references('id')->on('users')->nullOnDelete();
            $table->foreign('deactivated_by_user_id', 'monitoring_profile_deactivated_actor_fk')
                ->references('id')->on('users')->nullOnDelete();
        });

        DB::table('monitoring_profiles')
            ->whereNotNull('retention_policy_id')
            ->update([
                'legacy_data_retention_policy_id' => DB::raw('retention_policy_id'),
                'retention_policy_id' => null,
            ]);

        Schema::table('monitoring_profiles', function (Blueprint $table): void {
            $table->dropForeign('monitoring_profiles_retention_policy_fk');
            $table->foreign('retention_policy_id', 'monitoring_profiles_retention_policy_fk')
                ->references('id')->on('monitoring_retention_policies')->nullOnDelete();
            $table->index(['is_active', 'version'], 'monitoring_profiles_active_version_idx');
        });

        Schema::table('monitoring_coverage_expectations', function (Blueprint $table): void {
            $table->char('identity_key', 64)->nullable()->after('capability');
            $table->unsignedInteger('version')->default(1)->after('is_active');
            $table->foreignId('created_by_user_id')->nullable()->after('version');
            $table->foreignId('updated_by_user_id')->nullable()->after('created_by_user_id');
            $table->timestamp('deactivated_at')->nullable()->after('updated_by_user_id');
            $table->foreignId('deactivated_by_user_id')->nullable()->after('deactivated_at');
            $table->string('deactivation_reason', 500)->nullable()->after('deactivated_by_user_id');

            $table->foreign('created_by_user_id', 'monitoring_coverage_created_actor_fk')
                ->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by_user_id', 'monitoring_coverage_updated_actor_fk')
                ->references('id')->on('users')->nullOnDelete();
            $table->foreign('deactivated_by_user_id', 'monitoring_coverage_deactivated_actor_fk')
                ->references('id')->on('users')->nullOnDelete();
        });

        foreach (DB::table('monitoring_coverage_expectations')->orderBy('id')->get() as $expectation) {
            DB::table('monitoring_coverage_expectations')->where('id', $expectation->id)->update([
                'identity_key' => $this->coverageIdentity(
                    $expectation->site_id === null ? null : (int) $expectation->site_id,
                    (string) $expectation->device_domain,
                    $expectation->device_category === null ? null : (string) $expectation->device_category,
                    (string) $expectation->capability,
                ),
            ]);
        }

        Schema::table('monitoring_coverage_expectations', function (Blueprint $table): void {
            $table->char('identity_key', 64)->nullable(false)->change();
            $table->unique('identity_key', 'monitoring_coverage_identity_key_uq');
            $table->index(['is_active', 'version'], 'monitoring_coverage_active_version_idx');
        });

        Schema::table('monitor_dependencies', function (Blueprint $table): void {
            $table->unsignedInteger('version')->default(1)->after('is_active');
            $table->foreignId('created_by_user_id')->nullable()->after('version');
            $table->foreignId('updated_by_user_id')->nullable()->after('created_by_user_id');
            $table->timestamp('deactivated_at')->nullable()->after('updated_by_user_id');
            $table->foreignId('deactivated_by_user_id')->nullable()->after('deactivated_at');
            $table->string('deactivation_reason', 500)->nullable()->after('deactivated_by_user_id');

            $table->foreign('created_by_user_id', 'monitor_dependency_created_actor_fk')
                ->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by_user_id', 'monitor_dependency_updated_actor_fk')
                ->references('id')->on('users')->nullOnDelete();
            $table->foreign('deactivated_by_user_id', 'monitor_dependency_deactivated_actor_fk')
                ->references('id')->on('users')->nullOnDelete();
            $table->index(['site_id', 'is_active', 'version'], 'monitor_dependency_lifecycle_idx');
        });

        Schema::table('monitoring_maintenance_windows', function (Blueprint $table): void {
            $table->string('timezone', 64)->default('UTC')->after('recurrence_until');
            $table->unsignedInteger('version')->default(1)->after('status');
            $table->foreignId('created_by_user_id')->nullable()->after('version');
            $table->foreignId('updated_by_user_id')->nullable()->after('created_by_user_id');
            $table->timestamp('cancelled_at')->nullable()->after('updated_by_user_id');
            $table->foreignId('cancelled_by_user_id')->nullable()->after('cancelled_at');
            $table->string('cancellation_reason', 500)->nullable()->after('cancelled_by_user_id');

            $table->foreign('created_by_user_id', 'monitoring_maintenance_created_actor_fk')
                ->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by_user_id', 'monitoring_maintenance_updated_actor_fk')
                ->references('id')->on('users')->nullOnDelete();
            $table->foreign('cancelled_by_user_id', 'monitoring_maintenance_cancelled_actor_fk')
                ->references('id')->on('users')->nullOnDelete();
            $table->index(['site_id', 'status', 'version'], 'monitoring_maintenance_lifecycle_idx');
        });

        Schema::table('monitoring_maintenance_windows', function (Blueprint $table): void {
            $table->string('reason', 500)->change();
        });

        Schema::table('monitoring_retention_policies', function (Blueprint $table): void {
            $table->char('identity_key', 64)->nullable()->after('privacy_class');
            $table->unsignedInteger('version')->default(1)->after('is_active');
            $table->string('change_reason', 500)->nullable()->after('version');
            $table->foreignId('updated_by_user_id')->nullable()->after('created_by_user_id');
            $table->timestamp('deactivated_at')->nullable()->after('updated_by_user_id');
            $table->foreignId('deactivated_by_user_id')->nullable()->after('deactivated_at');
            $table->string('deactivation_reason', 500)->nullable()->after('deactivated_by_user_id');

            $table->foreign('updated_by_user_id', 'monitoring_retention_updated_actor_fk')
                ->references('id')->on('users')->nullOnDelete();
            $table->foreign('deactivated_by_user_id', 'monitoring_retention_deactivated_actor_fk')
                ->references('id')->on('users')->nullOnDelete();
        });

        foreach (DB::table('monitoring_retention_policies')->orderBy('id')->get() as $policy) {
            DB::table('monitoring_retention_policies')->where('id', $policy->id)->update([
                'identity_key' => $this->retentionIdentity(
                    (string) $policy->scope_kind,
                    $policy->site_id === null ? null : (int) $policy->site_id,
                    $policy->device_id === null ? null : (int) $policy->device_id,
                    $policy->data_class === null ? null : (string) $policy->data_class,
                    $policy->privacy_class === null ? null : (string) $policy->privacy_class,
                ),
            ]);
        }

        Schema::table('monitoring_retention_policies', function (Blueprint $table): void {
            $table->char('identity_key', 64)->nullable(false)->change();
            $table->unique('identity_key', 'monitoring_retention_identity_key_uq');
            $table->index(['scope_kind', 'is_active', 'version'], 'monitoring_retention_lifecycle_idx');
        });
    }

    public function down(): void
    {
        if (DB::table('monitoring_profiles')->whereNotNull('retention_policy_id')->exists()) {
            throw new RuntimeException(
                'Native monitoring profile retention references must be reconciled before rolling back policy authoring.',
            );
        }

        Schema::table('monitoring_retention_policies', function (Blueprint $table): void {
            $table->dropUnique('monitoring_retention_identity_key_uq');
            $table->dropIndex('monitoring_retention_lifecycle_idx');
            $table->dropForeign('monitoring_retention_deactivated_actor_fk');
            $table->dropForeign('monitoring_retention_updated_actor_fk');
            $table->dropColumn([
                'identity_key', 'version', 'change_reason', 'updated_by_user_id', 'deactivated_at',
                'deactivated_by_user_id', 'deactivation_reason',
            ]);
        });

        Schema::table('monitoring_maintenance_windows', function (Blueprint $table): void {
            $table->dropIndex('monitoring_maintenance_lifecycle_idx');
            $table->dropForeign('monitoring_maintenance_cancelled_actor_fk');
            $table->dropForeign('monitoring_maintenance_updated_actor_fk');
            $table->dropForeign('monitoring_maintenance_created_actor_fk');
            $table->dropColumn([
                'timezone', 'version', 'created_by_user_id', 'updated_by_user_id',
                'cancelled_at', 'cancelled_by_user_id', 'cancellation_reason',
            ]);
        });
        Schema::table('monitoring_maintenance_windows', fn (Blueprint $table) => $table->string('reason', 128)->change());

        Schema::table('monitor_dependencies', function (Blueprint $table): void {
            $table->dropIndex('monitor_dependency_lifecycle_idx');
            $table->dropForeign('monitor_dependency_deactivated_actor_fk');
            $table->dropForeign('monitor_dependency_updated_actor_fk');
            $table->dropForeign('monitor_dependency_created_actor_fk');
            $table->dropColumn([
                'version', 'created_by_user_id', 'updated_by_user_id', 'deactivated_at',
                'deactivated_by_user_id', 'deactivation_reason',
            ]);
        });

        Schema::table('monitoring_coverage_expectations', function (Blueprint $table): void {
            $table->dropUnique('monitoring_coverage_identity_key_uq');
            $table->dropIndex('monitoring_coverage_active_version_idx');
            $table->dropForeign('monitoring_coverage_deactivated_actor_fk');
            $table->dropForeign('monitoring_coverage_updated_actor_fk');
            $table->dropForeign('monitoring_coverage_created_actor_fk');
            $table->dropColumn([
                'identity_key', 'version', 'created_by_user_id', 'updated_by_user_id',
                'deactivated_at', 'deactivated_by_user_id', 'deactivation_reason',
            ]);
        });

        Schema::table('monitoring_profiles', function (Blueprint $table): void {
            $table->dropIndex('monitoring_profiles_active_version_idx');
            $table->dropForeign('monitoring_profiles_retention_policy_fk');
        });

        DB::table('monitoring_profiles')
            ->whereNotNull('legacy_data_retention_policy_id')
            ->update(['retention_policy_id' => DB::raw('legacy_data_retention_policy_id')]);

        Schema::table('monitoring_profiles', function (Blueprint $table): void {
            $table->dropForeign('monitoring_profile_deactivated_actor_fk');
            $table->dropForeign('monitoring_profile_updated_actor_fk');
            $table->dropForeign('monitoring_profile_created_actor_fk');
            $table->dropForeign('monitoring_profile_legacy_retention_fk');
            $table->foreign('retention_policy_id', 'monitoring_profiles_retention_policy_fk')
                ->references('id')->on('data_retention_policies')->nullOnDelete();
            $table->dropColumn([
                'legacy_data_retention_policy_id', 'version', 'created_by_user_id',
                'updated_by_user_id', 'deactivated_at', 'deactivated_by_user_id',
                'deactivation_reason',
            ]);
        });
    }

    private function assertLegacyProfileRetentionReferencesAreValid(): void
    {
        $orphaned = DB::table('monitoring_profiles as profiles')
            ->leftJoin('data_retention_policies as policies', 'policies.id', '=', 'profiles.retention_policy_id')
            ->whereNotNull('profiles.retention_policy_id')
            ->whereNull('policies.id')
            ->exists();

        if ($orphaned) {
            throw new RuntimeException(
                'Monitoring profile legacy retention references require reconciliation before native policy authoring.',
            );
        }
    }

    private function assertPolicyIdentitiesAreUnambiguous(): void
    {
        $coverage = [];
        foreach (DB::table('monitoring_coverage_expectations')->orderBy('id')->get() as $expectation) {
            $identity = $this->coverageIdentity(
                $expectation->site_id === null ? null : (int) $expectation->site_id,
                (string) $expectation->device_domain,
                $expectation->device_category === null ? null : (string) $expectation->device_category,
                (string) $expectation->capability,
            );
            if (isset($coverage[$identity])) {
                throw new RuntimeException(
                    'Duplicate monitoring coverage identities require reconciliation before policy authoring.',
                );
            }
            $coverage[$identity] = true;
        }

        $retention = [];
        foreach (DB::table('monitoring_retention_policies')->orderBy('id')->get() as $policy) {
            $identity = $this->retentionIdentity(
                (string) $policy->scope_kind,
                $policy->site_id === null ? null : (int) $policy->site_id,
                $policy->device_id === null ? null : (int) $policy->device_id,
                $policy->data_class === null ? null : (string) $policy->data_class,
                $policy->privacy_class === null ? null : (string) $policy->privacy_class,
            );
            if (isset($retention[$identity])) {
                throw new RuntimeException(
                    'Duplicate monitoring retention identities require reconciliation before policy authoring.',
                );
            }
            $retention[$identity] = true;
        }
    }

    private function coverageIdentity(?int $siteId, string $domain, ?string $category, string $capability): string
    {
        return hash('sha256', implode('|', [
            $siteId === null ? '*' : (string) $siteId,
            strtolower(trim($domain)),
            $category === null ? '*' : strtolower(trim($category)),
            strtolower(trim($capability)),
        ]));
    }

    private function retentionIdentity(
        string $scopeKind,
        ?int $siteId,
        ?int $deviceId,
        ?string $dataClass,
        ?string $privacyClass,
    ): string {
        return hash('sha256', implode('|', [
            strtolower(trim($scopeKind)),
            $siteId === null ? '*' : (string) $siteId,
            $deviceId === null ? '*' : (string) $deviceId,
            $dataClass === null ? '*' : strtolower(trim($dataClass)),
            $privacyClass === null ? '*' : strtolower(trim($privacyClass)),
        ]));
    }
};
