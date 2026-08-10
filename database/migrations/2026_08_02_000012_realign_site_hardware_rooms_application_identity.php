<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $collision = DB::table('site_rooms')
            ->select(['site_id', 'name'])
            ->groupBy('site_id', 'name')
            ->havingRaw('COUNT(*) > 1')
            ->first();

        if ($collision !== null) {
            throw new RuntimeException(
                'Cannot enforce canonical Site hardware-room identity while a Site has duplicate room names.',
            );
        }

        $this->addIndex(
            'site_rooms_site_name_uq',
            fn (Blueprint $table) => $table->unique(['site_id', 'name'], 'site_rooms_site_name_uq'),
        );
        $this->addIndex(
            'site_rooms_site_sort_idx',
            fn (Blueprint $table) => $table->index(
                ['site_id', 'sort_order', 'id'],
                'site_rooms_site_sort_idx',
            ),
        );
        $this->dropIndex('site_rooms_tenant_id_index');
    }

    public function down(): void
    {
        $this->addIndex(
            'site_rooms_tenant_id_index',
            fn (Blueprint $table) => $table->index('tenant_id', 'site_rooms_tenant_id_index'),
        );
        $this->dropIndex('site_rooms_site_sort_idx');
        $this->dropIndex('site_rooms_site_name_uq', unique: true);
    }

    private function addIndex(string $name, callable $callback): void
    {
        if (! Schema::hasIndex('site_rooms', $name)) {
            Schema::table('site_rooms', $callback);
        }
    }

    private function dropIndex(string $name, bool $unique = false): void
    {
        if (! Schema::hasIndex('site_rooms', $name)) {
            return;
        }

        Schema::table('site_rooms', function (Blueprint $table) use ($name, $unique): void {
            $unique
                ? $table->dropUnique($name)
                : $table->dropIndex($name);
        });
    }
};
