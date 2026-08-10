<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TYPE_KEY_UNIQUE = 'credential_types_key_uq';

    private const TYPE_CATALOGUE_INDEX = 'credential_types_active_sort_idx';

    private const CREDENTIAL_ROTATION_INDEX = 'site_credentials_site_rotation_idx';

    private const VENDOR_DIRECTORY_INDEX = 'site_vendors_site_active_service_idx';

    private const AUDIT_SITE_INDEX = 'site_credential_audit_site_created_idx';

    private const AUDIT_CREDENTIAL_INDEX = 'site_credential_audit_credential_created_idx';

    private const AUDIT_SITE_FOREIGN = 'site_credential_audit_logs_site_id_foreign';

    public function up(): void
    {
        $collision = DB::table('credential_types')
            ->select('key', DB::raw('COUNT(*) AS duplicate_count'))
            ->groupBy('key')
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('key')
            ->first();

        if ($collision !== null) {
            throw new RuntimeException(
                'Cannot enforce application credential type identity: duplicate keys exist.',
            );
        }

        Schema::table('site_credential_audit_logs', function (Blueprint $table): void {
            $table->unsignedBigInteger('site_id')->nullable()->after('credential_id');
            $table->string('credential_label')->nullable()->after('site_id');
            $table->string('credential_type', 30)->nullable()->after('credential_label');
        });

        DB::table('site_credential_audit_logs')
            ->whereNotNull('credential_id')
            ->whereNull('site_id')
            ->orderBy('id')
            ->chunkById(500, function ($logs): void {
                $credentialIds = $logs->pluck('credential_id')->filter()->unique()->values();
                $credentials = DB::table('site_credentials')
                    ->whereIn('id', $credentialIds)
                    ->get(['id', 'site_id', 'label', 'credential_type'])
                    ->keyBy('id');

                foreach ($logs as $log) {
                    $credential = $credentials->get($log->credential_id);
                    if ($credential === null) {
                        continue;
                    }

                    DB::table('site_credential_audit_logs')
                        ->where('id', $log->id)
                        ->update([
                            'site_id' => $credential->site_id,
                            'credential_label' => $credential->label,
                            'credential_type' => $credential->credential_type,
                        ]);
                }
            });

        foreach ($this->applicationIndexes() as $table => $indexes) {
            foreach ($indexes as [$name, $type, $columns]) {
                $this->addIndex($table, $name, function (Blueprint $table) use ($columns, $name, $type): void {
                    $type === 'unique'
                        ? $table->unique($columns, $name)
                        : $table->index($columns, $name);
                });
            }
        }

        Schema::table('site_credential_audit_logs', function (Blueprint $table): void {
            $table->foreign('site_id', self::AUDIT_SITE_FOREIGN)
                ->references('id')
                ->on('sites')
                ->nullOnDelete();
        });

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
                $this->addIndex($table, $name, function (Blueprint $table) use ($columns, $name, $type): void {
                    $type === 'unique'
                        ? $table->unique($columns, $name)
                        : $table->index($columns, $name);
                });
            }
        }

        Schema::table('site_credential_audit_logs', function (Blueprint $table): void {
            $table->dropForeign(['site_id']);
        });

        foreach ($this->applicationIndexes() as $table => $indexes) {
            foreach (array_reverse($indexes) as [$name, $type]) {
                $this->dropIndex($table, $name, $type);
            }
        }

        Schema::table('site_credential_audit_logs', function (Blueprint $table): void {
            $table->dropColumn(['site_id', 'credential_label', 'credential_type']);
        });
    }

    /** @return array<string, list<array{string, string, list<string>}>> */
    private function applicationIndexes(): array
    {
        return [
            'credential_types' => [
                [self::TYPE_KEY_UNIQUE, 'unique', ['key']],
                [self::TYPE_CATALOGUE_INDEX, 'index', ['active', 'sort_order', 'label']],
            ],
            'site_credentials' => [
                [self::CREDENTIAL_ROTATION_INDEX, 'index', ['site_id', 'last_rotated_at']],
            ],
            'site_vendors' => [
                [self::VENDOR_DIRECTORY_INDEX, 'index', ['site_id', 'is_active', 'service_type']],
            ],
            'site_credential_audit_logs' => [
                [self::AUDIT_SITE_INDEX, 'index', ['site_id', 'created_at']],
                [self::AUDIT_CREDENTIAL_INDEX, 'index', ['credential_id', 'created_at']],
            ],
        ];
    }

    /** @return array<string, list<array{string, string, list<string>}>> */
    private function legacyIndexes(): array
    {
        return [
            'credential_types' => [
                ['credential_types_tenant_id_key_unique', 'unique', ['tenant_id', 'key']],
                ['credential_types_tenant_id_index', 'index', ['tenant_id']],
            ],
            'site_credentials' => [
                ['site_credentials_tenant_id_index', 'index', ['tenant_id']],
            ],
            'site_credential_audit_logs' => [
                ['site_credential_audit_logs_tenant_id_index', 'index', ['tenant_id']],
            ],
            'site_vendors' => [
                ['site_vendors_tenant_id_index', 'index', ['tenant_id']],
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
