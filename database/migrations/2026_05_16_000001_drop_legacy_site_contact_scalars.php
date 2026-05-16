<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Backfills legacy people-like site contact fields into site_contacts, then
     * drops the duplicated scalar columns. The data backfill is transactional;
     * the column drops run outside the transaction because MySQL commits DDL
     * implicitly. Rollback only restores empty columns and intentionally does
     * not copy data back out of site_contacts.
     */
    public function up(): void
    {
        if (! Schema::hasTable('sites') || ! Schema::hasTable('site_contacts')) {
            return;
        }

        $hasTenantId = Schema::hasColumn('site_contacts', 'tenant_id');

        DB::transaction(function () use ($hasTenantId) {
            if (Schema::hasColumn('sites', 'manager_name') || Schema::hasColumn('sites', 'manager_phone')) {
                DB::table('sites')
                    ->where(function ($query) {
                        $query->whereNotNull('manager_name')
                            ->orWhereNotNull('manager_phone');
                    })
                    ->orderBy('id')
                    ->chunkById(200, function ($sites) use ($hasTenantId) {
                        foreach ($sites as $site) {
                            $hasManager = DB::table('site_contacts')
                                ->where('site_id', $site->id)
                                ->where('type', 'manager')
                                ->exists();

                            if ($hasManager) {
                                continue;
                            }

                            $row = [
                                'site_id' => $site->id,
                                'type' => 'manager',
                                'name' => $site->manager_name ?: 'Manager',
                                'role' => null,
                                'phone' => $site->manager_phone,
                                'email' => null,
                                'is_primary' => false,
                                'notes' => null,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ];

                            if ($hasTenantId) {
                                $row['tenant_id'] = $site->tenant_id ?? null;
                            }

                            DB::table('site_contacts')->insert($row);
                        }
                    });
            }

            if (Schema::hasColumn('sites', 'after_hours_phone')) {
                DB::table('sites')
                    ->whereNotNull('after_hours_phone')
                    ->orderBy('id')
                    ->chunkById(200, function ($sites) use ($hasTenantId) {
                        foreach ($sites as $site) {
                            $hasEmergency = DB::table('site_contacts')
                                ->where('site_id', $site->id)
                                ->where('type', 'emergency')
                                ->exists();

                            if ($hasEmergency) {
                                continue;
                            }

                            $row = [
                                'site_id' => $site->id,
                                'type' => 'emergency',
                                'name' => 'After-hours contact',
                                'role' => 'After hours',
                                'phone' => $site->after_hours_phone,
                                'email' => null,
                                'is_primary' => false,
                                'notes' => null,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ];

                            if ($hasTenantId) {
                                $row['tenant_id'] = $site->tenant_id ?? null;
                            }

                            DB::table('site_contacts')->insert($row);
                        }
                    });
            }
        });

        $columnsToDrop = array_values(array_filter(
            ['manager_name', 'manager_phone', 'after_hours_phone'],
            fn (string $column) => Schema::hasColumn('sites', $column),
        ));

        if ($columnsToDrop !== []) {
            Schema::table('sites', function (Blueprint $table) use ($columnsToDrop) {
                $table->dropColumn($columnsToDrop);
            });
        }
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            if (! Schema::hasColumn('sites', 'manager_name')) {
                $table->string('manager_name')->nullable();
            }
            if (! Schema::hasColumn('sites', 'manager_phone')) {
                $table->string('manager_phone')->nullable();
            }
            if (! Schema::hasColumn('sites', 'after_hours_phone')) {
                $table->string('after_hours_phone')->nullable();
            }
        });
    }
};
