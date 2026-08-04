<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CREATOR_NAME_INDEX = 'hr_saved_reports_creator_name_uq';

    private const CREATOR_UPDATED_INDEX = 'hr_saved_reports_creator_updated_idx';

    private const TYPE_UPDATED_INDEX = 'hr_saved_reports_type_updated_idx';

    public function up(): void
    {
        $collision = DB::table('hr_saved_reports')
            ->select('created_by', 'name')
            ->groupBy('created_by', 'name')
            ->havingRaw('COUNT(*) > 1')
            ->first();

        if ($collision !== null) {
            throw new RuntimeException(
                'Cannot create the creator-owned saved report identity because duplicate creator/name definitions exist. Resolve the collision before retrying the migration.',
            );
        }

        $this->addIndex(
            self::CREATOR_NAME_INDEX,
            ['created_by', 'name'],
            true,
        );
        $this->addIndex(
            self::CREATOR_UPDATED_INDEX,
            ['created_by', 'updated_at'],
        );
        $this->addIndex(
            self::TYPE_UPDATED_INDEX,
            ['report_type', 'updated_at'],
        );

        foreach (array_keys($this->legacyIndexes()) as $name) {
            $this->dropIndex($name);
        }
    }

    public function down(): void
    {
        foreach ($this->legacyIndexes() as $name => $columns) {
            $this->addIndex($name, $columns);
        }

        $this->dropIndex(self::TYPE_UPDATED_INDEX);
        $this->dropIndex(self::CREATOR_UPDATED_INDEX);
        $this->dropIndex(self::CREATOR_NAME_INDEX);
    }

    /** @param list<string> $columns */
    private function addIndex(string $name, array $columns, bool $unique = false): void
    {
        if (Schema::hasIndex('hr_saved_reports', $name)) {
            return;
        }

        Schema::table('hr_saved_reports', function (Blueprint $table) use ($name, $columns, $unique): void {
            if ($unique) {
                $table->unique($columns, $name);

                return;
            }

            $table->index($columns, $name);
        });
    }

    private function dropIndex(string $name): void
    {
        if (! Schema::hasIndex('hr_saved_reports', $name)) {
            return;
        }

        Schema::table(
            'hr_saved_reports',
            fn (Blueprint $table) => $table->dropIndex($name),
        );
    }

    /** @return array<string, list<string>> */
    private function legacyIndexes(): array
    {
        return [
            'hr_saved_reports_tenant_id_index' => ['tenant_id'],
            'hr_saved_reports_tenant_id_report_type_index' => ['tenant_id', 'report_type'],
        ];
    }
};
