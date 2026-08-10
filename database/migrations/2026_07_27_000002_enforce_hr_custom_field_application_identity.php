<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const LEGACY_UNIQUE = 'hr_custom_field_definitions_tenant_id_field_key_unique';

    private const APPLICATION_UNIQUE = 'hr_custom_fields_field_key_uq';

    private const APPLICATION_READ_INDEX = 'hr_custom_fields_active_sort_idx';

    public function up(): void
    {
        $collision = DB::table('hr_custom_field_definitions')
            ->select('field_key', DB::raw('COUNT(*) AS duplicate_count'))
            ->groupBy('field_key')
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('field_key')
            ->first();

        if ($collision !== null) {
            throw new RuntimeException(
                'Cannot enforce application custom-field key identity: duplicate rows exist.',
            );
        }

        // Install the stronger identity before removing compatibility indexes,
        // so concurrent configuration writes are never left race-prone.
        $this->addIndex(
            self::APPLICATION_UNIQUE,
            fn (Blueprint $table) => $table->unique('field_key', self::APPLICATION_UNIQUE),
        );
        $this->addIndex(
            self::APPLICATION_READ_INDEX,
            fn (Blueprint $table) => $table->index(
                ['is_active', 'sort_order', 'name'],
                self::APPLICATION_READ_INDEX,
            ),
        );

        foreach ($this->legacyIndexes() as [$name, $type]) {
            $this->dropIndex($name, $type);
        }
    }

    public function down(): void
    {
        // Restore the exact compatibility indexes before removing the stronger
        // application identity so rollback also retains write integrity.
        foreach ($this->legacyIndexes() as [$name, $type, $columns]) {
            $this->addIndex($name, function (Blueprint $table) use ($name, $type, $columns): void {
                $type === 'unique'
                    ? $table->unique($columns, $name)
                    : $table->index($columns, $name);
            });
        }

        $this->dropIndex(self::APPLICATION_READ_INDEX, 'index');
        $this->dropIndex(self::APPLICATION_UNIQUE, 'unique');
    }

    /** @return list<array{string, string, list<string>}> */
    private function legacyIndexes(): array
    {
        return [
            [self::LEGACY_UNIQUE, 'unique', ['tenant_id', 'field_key']],
            ['hr_custom_field_definitions_tenant_id_index', 'index', ['tenant_id']],
        ];
    }

    private function addIndex(string $name, callable $callback): void
    {
        if (! Schema::hasIndex('hr_custom_field_definitions', $name)) {
            Schema::table('hr_custom_field_definitions', $callback);
        }
    }

    private function dropIndex(string $name, string $type): void
    {
        if (! Schema::hasIndex('hr_custom_field_definitions', $name)) {
            return;
        }

        Schema::table('hr_custom_field_definitions', function (Blueprint $table) use ($name, $type): void {
            $type === 'unique'
                ? $table->dropUnique($name)
                : $table->dropIndex($name);
        });
    }
};
