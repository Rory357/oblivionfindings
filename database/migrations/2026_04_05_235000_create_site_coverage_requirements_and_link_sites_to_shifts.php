<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            if (! Schema::hasColumn('shifts', 'site_id')) {
                $table->foreignId('site_id')
                    ->nullable()
                    ->after('client_id')
                    ->constrained('sites')
                    ->nullOnDelete();
            }
        });

        $this->ensureIndex('shifts', 'shifts_site_starts_idx', ['site_id', 'starts_at']);

        Schema::table('shift_series', function (Blueprint $table) {
            if (! Schema::hasColumn('shift_series', 'site_id')) {
                $table->foreignId('site_id')
                    ->nullable()
                    ->after('client_id')
                    ->constrained('sites')
                    ->nullOnDelete();
            }
        });

        $this->ensureIndex('shift_series', 'shift_series_site_dates_idx', ['site_id', 'start_date', 'end_date']);

        if (! Schema::hasTable('site_coverage_requirements')) {
            Schema::create('site_coverage_requirements', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('organization_id')->nullable()->index();
                $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
                $table->foreignId('service_context_id')->nullable()->constrained('service_contexts')->nullOnDelete();
                $table->string('name');
                $table->string('coverage_type', 20)->default('custom');
                $table->string('day_of_week', 3);
                $table->string('starts_time', 5);
                $table->string('ends_time', 5);
                $table->unsignedSmallInteger('minimum_staff')->default(1);
                $table->string('shift_type', 30)->nullable();
                $table->text('notes')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        $this->ensureIndex('site_coverage_requirements', 'scr_site_day_active_idx', ['site_id', 'day_of_week', 'is_active']);
        $this->ensureIndex('site_coverage_requirements', 'scr_site_service_day_idx', ['site_id', 'service_context_id', 'day_of_week']);

        DB::table('shifts')
            ->join('clients', 'clients.id', '=', 'shifts.client_id')
            ->whereNull('shifts.site_id')
            ->update([
                'shifts.site_id' => DB::raw('clients.site_id'),
            ]);

        DB::table('shift_series')
            ->join('clients', 'clients.id', '=', 'shift_series.client_id')
            ->whereNull('shift_series.site_id')
            ->update([
                'shift_series.site_id' => DB::raw('clients.site_id'),
            ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('site_coverage_requirements');

        Schema::table('shift_series', function (Blueprint $table) {
            if (Schema::hasColumn('shift_series', 'site_id')) {
                $table->dropIndex('shift_series_site_dates_idx');
                $table->dropConstrainedForeignId('site_id');
            }
        });

        Schema::table('shifts', function (Blueprint $table) {
            if (Schema::hasColumn('shifts', 'site_id')) {
                $table->dropIndex('shifts_site_starts_idx');
                $table->dropConstrainedForeignId('site_id');
            }
        });
    }

    /**
     * @param  array<int, string>  $columns
     */
    protected function ensureIndex(string $table, string $indexName, array $columns): void
    {
        $indexExists = collect(DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = '{$indexName}'"))
            ->isNotEmpty();

        if ($indexExists) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($columns, $indexName) {
            $blueprint->index($columns, $indexName);
        });
    }
};
