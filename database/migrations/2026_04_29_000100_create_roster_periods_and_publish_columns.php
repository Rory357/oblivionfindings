<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('roster_periods')) {
            Schema::create('roster_periods', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('organization_id')->nullable()->index();
                $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
                $table->date('week_start')->index();
                $table->unsignedInteger('version')->default(1);
                $table->string('status', 32)->default('draft')->index();
                $table->timestamp('validating_at')->nullable();
                $table->timestamp('ready_at')->nullable();
                $table->timestamp('published_at')->nullable();
                $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('archived_at')->nullable();
                $table->string('archive_reason')->nullable();
                $table->json('snapshot')->nullable();
                $table->json('validation_summary')->nullable();
                $table->json('publish_meta')->nullable();
                $table->timestamp('last_validated_at')->nullable();
                $table->timestamps();

                $table->unique(
                    ['organization_id', 'site_id', 'week_start', 'version'],
                    'roster_period_scope_version_unique',
                );
            });
        }

        Schema::table('shifts', function (Blueprint $table) {
            if (! Schema::hasColumn('shifts', 'roster_period_id')) {
                $table->foreignId('roster_period_id')
                    ->nullable()
                    ->after('organization_id')
                    ->constrained('roster_periods')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('shifts', 'published_at')) {
                $table->timestamp('published_at')->nullable()->after('roster_period_id')->index();
            }

            if (! Schema::hasColumn('shifts', 'publish_dirty_at')) {
                $table->timestamp('publish_dirty_at')->nullable()->after('published_at')->index();
            }
        });

        DB::table('shifts')
            ->whereNull('published_at')
            ->whereNotNull('user_id')
            ->whereIn('status', ['scheduled', 'in_progress', 'completed', 'clocked_out', 'finished'])
            ->update([
                'published_at' => DB::raw('COALESCE(created_at, updated_at, CURRENT_TIMESTAMP)'),
            ]);

        DB::table('shifts')
            ->whereNotNull('published_at')
            ->whereNotNull('site_id')
            ->orderBy('id')
            ->chunkById(500, function ($shifts): void {
                $groups = [];

                foreach ($shifts as $shift) {
                    $weekStart = Carbon::parse($shift->starts_at, 'UTC')
                        ->timezone('Pacific/Auckland')
                        ->startOfWeek(Carbon::MONDAY)
                        ->toDateString();
                    $organizationId = $shift->organization_id ?: 1;
                    $key = "{$organizationId}:{$shift->site_id}:{$weekStart}";

                    $groups[$key]['organization_id'] = $organizationId;
                    $groups[$key]['site_id'] = $shift->site_id;
                    $groups[$key]['week_start'] = $weekStart;
                    $groups[$key]['shift_ids'][] = $shift->id;
                }

                foreach ($groups as $group) {
                    $periodId = DB::table('roster_periods')
                        ->where('organization_id', $group['organization_id'])
                        ->where('site_id', $group['site_id'])
                        ->where('week_start', $group['week_start'])
                        ->where('version', 1)
                        ->value('id');

                    if (! $periodId) {
                        $periodId = DB::table('roster_periods')->insertGetId([
                            'organization_id' => $group['organization_id'],
                            'site_id' => $group['site_id'],
                            'week_start' => $group['week_start'],
                            'version' => 1,
                            'status' => 'published',
                            'published_at' => now(),
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }

                    DB::table('shifts')
                        ->whereIn('id', $group['shift_ids'])
                        ->update(['roster_period_id' => $periodId]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            if (Schema::hasColumn('shifts', 'roster_period_id')) {
                $table->dropConstrainedForeignId('roster_period_id');
            }

            foreach (['publish_dirty_at', 'published_at'] as $column) {
                if (Schema::hasColumn('shifts', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::dropIfExists('roster_periods');
    }
};
