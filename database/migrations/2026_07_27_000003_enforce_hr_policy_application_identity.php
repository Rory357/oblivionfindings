<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $collisions = [
            'policy slug' => DB::table('hr_policies')
                ->select('slug', DB::raw('COUNT(*) AS duplicate_count'))
                ->groupBy('slug')
                ->havingRaw('COUNT(*) > 1')
                ->orderBy('slug')
                ->first(),
            'policy version' => DB::table('hr_policy_versions')
                ->select('policy_id', 'version_number', DB::raw('COUNT(*) AS duplicate_count'))
                ->groupBy('policy_id', 'version_number')
                ->havingRaw('COUNT(*) > 1')
                ->orderBy('policy_id')
                ->orderBy('version_number')
                ->first(),
            'current policy version' => DB::table('hr_policy_versions')
                ->select('policy_id', DB::raw('COUNT(*) AS duplicate_count'))
                ->where('is_current', true)
                ->groupBy('policy_id')
                ->havingRaw('COUNT(*) > 1')
                ->orderBy('policy_id')
                ->first(),
            'policy attestation' => DB::table('hr_policy_attestations')
                ->select('user_id', 'policy_version_id', DB::raw('COUNT(*) AS duplicate_count'))
                ->groupBy('user_id', 'policy_version_id')
                ->havingRaw('COUNT(*) > 1')
                ->orderBy('user_id')
                ->orderBy('policy_version_id')
                ->first(),
        ];

        foreach ($collisions as $identity => $collision) {
            if ($collision !== null) {
                throw new RuntimeException(sprintf(
                    'Cannot enforce application %s identity: duplicate rows exist.',
                    $identity,
                ));
            }
        }

        foreach ($this->applicationIndexes() as $table => $indexes) {
            foreach ($indexes as [$name, $type, $columns]) {
                $this->addIndex($table, $name, function (Blueprint $table) use ($name, $type, $columns): void {
                    $type === 'unique'
                        ? $table->unique($columns, $name)
                        : $table->index($columns, $name);
                });
            }
        }

        foreach ($this->legacyIndexes() as $table => $indexes) {
            foreach ($indexes as [$name, $type]) {
                $this->dropIndex($table, $name, $type);
            }
        }
    }

    public function down(): void
    {
        foreach ($this->legacyIndexes() as $table => $indexes) {
            foreach ($indexes as [$name, $type, $columns]) {
                $this->addIndex($table, $name, function (Blueprint $table) use ($name, $type, $columns): void {
                    $type === 'unique'
                        ? $table->unique($columns, $name)
                        : $table->index($columns, $name);
                });
            }
        }

        foreach ($this->applicationIndexes() as $table => $indexes) {
            foreach (array_reverse($indexes) as [$name, $type]) {
                $this->dropIndex($table, $name, $type);
            }
        }
    }

    /** @return array<string, list<array{string, string, list<string>}>> */
    private function applicationIndexes(): array
    {
        return [
            'hr_policies' => [
                ['hr_policies_slug_uq', 'unique', ['slug']],
                ['hr_policies_catalogue_idx', 'index', ['category', 'is_active', 'title']],
            ],
            'hr_policy_versions' => [
                ['hr_policy_versions_number_uq', 'unique', ['policy_id', 'version_number']],
            ],
            'hr_policy_attestations' => [
                ['hr_policy_attestations_user_version_uq', 'unique', ['user_id', 'policy_version_id']],
                ['hr_policy_attestations_policy_date_idx', 'index', ['policy_id', 'attested_at']],
            ],
        ];
    }

    /** @return array<string, list<array{string, string, list<string>}>> */
    private function legacyIndexes(): array
    {
        return [
            'hr_policies' => [
                ['hr_policies_tenant_id_slug_unique', 'unique', ['tenant_id', 'slug']],
                ['hr_policies_tenant_id_index', 'index', ['tenant_id']],
                ['hr_policies_tenant_id_category_is_active_index', 'index', ['tenant_id', 'category', 'is_active']],
            ],
            'hr_policy_attestations' => [
                ['hr_policy_attestations_tenant_id_index', 'index', ['tenant_id']],
                ['hr_policy_attestations_tenant_id_policy_id_index', 'index', ['tenant_id', 'policy_id']],
            ],
        ];
    }

    private function addIndex(string $table, string $name, callable $callback): void
    {
        if (! Schema::hasIndex($table, $name)) {
            Schema::table($table, $callback);
        }
    }

    private function dropIndex(string $table, string $name, string $type): void
    {
        if (! Schema::hasIndex($table, $name)) {
            return;
        }

        Schema::table($table, function (Blueprint $table) use ($name, $type): void {
            $type === 'unique'
                ? $table->dropUnique($name)
                : $table->dropIndex($name);
        });
    }
};
