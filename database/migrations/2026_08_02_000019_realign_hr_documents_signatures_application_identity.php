<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TEMPLATE_NAME_KEY = 'application_name_key';

    private const TEMPLATE_NAME_UNIQUE = 'hr_document_templates_name_key_uq';

    private const TEMPLATE_ACTIVE_INDEX = 'hr_document_templates_active_name_idx';

    private const DOCUMENT_PROFILE_INDEX = 'hr_documents_profile_created_idx';

    private const SIGNATURE_ACTIVE_KEY = 'active_document_signer_key';

    private const SIGNATURE_ACTIVE_UNIQUE = 'hr_document_signatures_active_signer_uq';

    private const SIGNATURE_SIGNER_INDEX = 'hr_document_signatures_signer_status_requested_idx';

    private const SIGNATURE_DUE_INDEX = 'hr_document_signatures_status_due_idx';

    public function up(): void
    {
        $this->assertApplicationIdentityCanBeEnforced();
        $this->normalizeTemplateNames();
        $this->addTemplateIdentity();
        $this->addActiveSignatureIdentity();

        $this->addIndex(
            'hr_document_templates',
            self::TEMPLATE_ACTIVE_INDEX,
            fn (Blueprint $table) => $table->index(['is_active', 'name'], self::TEMPLATE_ACTIVE_INDEX),
        );
        $this->addIndex(
            'hr_documents',
            self::DOCUMENT_PROFILE_INDEX,
            fn (Blueprint $table) => $table->index(['employee_profile_id', 'created_at'], self::DOCUMENT_PROFILE_INDEX),
        );
        $this->addIndex(
            'hr_document_signatures',
            self::SIGNATURE_SIGNER_INDEX,
            fn (Blueprint $table) => $table->index(
                ['signer_user_id', 'status', 'requested_at'],
                self::SIGNATURE_SIGNER_INDEX,
            ),
        );
        $this->addIndex(
            'hr_document_signatures',
            self::SIGNATURE_DUE_INDEX,
            fn (Blueprint $table) => $table->index(['status', 'due_at'], self::SIGNATURE_DUE_INDEX),
        );

        foreach (array_keys($this->legacyIndexes()) as $table) {
            foreach (array_keys($this->legacyIndexes()[$table]) as $index) {
                $this->dropIndex($table, $index);
            }
        }
    }

    public function down(): void
    {
        foreach ($this->legacyIndexes() as $table => $indexes) {
            foreach ($indexes as $name => $columns) {
                $this->addIndex(
                    $table,
                    $name,
                    fn (Blueprint $blueprint) => $blueprint->index($columns, $name),
                );
            }
        }

        $this->dropIndex('hr_document_signatures', self::SIGNATURE_DUE_INDEX);
        $this->dropIndex('hr_document_signatures', self::SIGNATURE_SIGNER_INDEX);
        $this->dropIndex('hr_documents', self::DOCUMENT_PROFILE_INDEX);
        $this->dropIndex('hr_document_templates', self::TEMPLATE_ACTIVE_INDEX);
        $this->dropGeneratedIdentity(
            'hr_document_signatures',
            self::SIGNATURE_ACTIVE_KEY,
            self::SIGNATURE_ACTIVE_UNIQUE,
        );
        $this->dropGeneratedIdentity(
            'hr_document_templates',
            self::TEMPLATE_NAME_KEY,
            self::TEMPLATE_NAME_UNIQUE,
        );
    }

    private function assertApplicationIdentityCanBeEnforced(): void
    {
        if (Schema::hasTable('hr_document_templates')) {
            if (DB::table('hr_document_templates')->whereRaw("TRIM(COALESCE(name, '')) = ''")->exists()) {
                throw new RuntimeException('Cannot enforce application document template identity while blank names exist.');
            }

            $duplicateTemplate = DB::table('hr_document_templates')
                ->selectRaw('LOWER(TRIM(name)) AS canonical_name, COUNT(*) AS duplicate_count')
                ->groupByRaw('LOWER(TRIM(name))')
                ->havingRaw('COUNT(*) > 1')
                ->first();
            if ($duplicateTemplate !== null) {
                throw new RuntimeException('Cannot enforce application document template identity while duplicate names exist.');
            }
        }

        if (Schema::hasTable('hr_document_signatures')) {
            $duplicateSignature = DB::table('hr_document_signatures')
                ->selectRaw('document_id, signer_user_id, COUNT(*) AS duplicate_count')
                ->where('status', '!=', 'cancelled')
                ->groupBy('document_id', 'signer_user_id')
                ->havingRaw('COUNT(*) > 1')
                ->first();
            if ($duplicateSignature !== null) {
                throw new RuntimeException('Cannot enforce active document signer identity while duplicate requests exist.');
            }
        }
    }

    private function normalizeTemplateNames(): void
    {
        if (Schema::hasTable('hr_document_templates')) {
            DB::table('hr_document_templates')->update(['name' => DB::raw('TRIM(name)')]);
        }
    }

    private function addTemplateIdentity(): void
    {
        if (! Schema::hasTable('hr_document_templates')) {
            return;
        }

        if (! Schema::hasColumn('hr_document_templates', self::TEMPLATE_NAME_KEY)) {
            $expression = in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)
                ? 'lower(trim(`name`))'
                : 'lower(trim(name))';
            Schema::table('hr_document_templates', function (Blueprint $table) use ($expression): void {
                $table->string(self::TEMPLATE_NAME_KEY)->nullable()->virtualAs($expression);
            });
        }

        $this->addIndex(
            'hr_document_templates',
            self::TEMPLATE_NAME_UNIQUE,
            fn (Blueprint $table) => $table->unique(self::TEMPLATE_NAME_KEY, self::TEMPLATE_NAME_UNIQUE),
        );
    }

    private function addActiveSignatureIdentity(): void
    {
        if (! Schema::hasTable('hr_document_signatures')) {
            return;
        }

        if (! Schema::hasColumn('hr_document_signatures', self::SIGNATURE_ACTIVE_KEY)) {
            $driver = Schema::getConnection()->getDriverName();
            $expression = match (true) {
                in_array($driver, ['mysql', 'mariadb'], true) => "if(`status` = 'cancelled', null, concat(`document_id`, ':', `signer_user_id`))",
                $driver === 'pgsql' => "case when status = 'cancelled' then null else document_id::text || ':' || signer_user_id::text end",
                default => "case when status = 'cancelled' then null else cast(document_id as text) || ':' || cast(signer_user_id as text) end",
            };
            Schema::table('hr_document_signatures', function (Blueprint $table) use ($expression): void {
                $table->string(self::SIGNATURE_ACTIVE_KEY, 64)->nullable()->virtualAs($expression);
            });
        }

        $this->addIndex(
            'hr_document_signatures',
            self::SIGNATURE_ACTIVE_UNIQUE,
            fn (Blueprint $table) => $table->unique(self::SIGNATURE_ACTIVE_KEY, self::SIGNATURE_ACTIVE_UNIQUE),
        );
    }

    private function dropGeneratedIdentity(string $table, string $column, string $unique): void
    {
        $this->dropIndex($table, $unique, unique: true);

        if (Schema::hasTable($table) && Schema::hasColumn($table, $column)) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropColumn($column));
        }
    }

    private function addIndex(string $table, string $name, callable $callback): void
    {
        if (Schema::hasTable($table) && ! Schema::hasIndex($table, $name)) {
            Schema::table($table, $callback);
        }
    }

    private function dropIndex(string $table, string $name, bool $unique = false): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasIndex($table, $name)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($name, $unique): void {
            $unique ? $blueprint->dropUnique($name) : $blueprint->dropIndex($name);
        });
    }

    /** @return array<string, array<string, list<string>>> */
    private function legacyIndexes(): array
    {
        return [
            'hr_document_templates' => [
                'hr_document_templates_tenant_id_index' => ['tenant_id'],
                'hr_document_templates_tenant_id_category_is_active_index' => ['tenant_id', 'category', 'is_active'],
            ],
            'hr_documents' => [
                'hr_documents_tenant_id_index' => ['tenant_id'],
                'hr_documents_tenant_id_category_index' => ['tenant_id', 'category'],
            ],
            'hr_document_signatures' => [
                'hr_document_signatures_tenant_id_index' => ['tenant_id'],
                'hr_document_signatures_tenant_id_status_index' => ['tenant_id', 'status'],
            ],
        ];
    }
};
