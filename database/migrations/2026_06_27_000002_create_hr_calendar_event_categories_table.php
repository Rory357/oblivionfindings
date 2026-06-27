<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Formalise the previously free `event_type` string into a categories table
     * (system rows are tenant-null; tenants can later add their own). Each event
     * gets a `category_id` FK, backfilled from its `event_type` key. The
     * `event_type` column is kept as the canonical key + back-compat for the
     * feed's colour map.
     */
    public function up(): void
    {
        Schema::create('hr_calendar_event_categories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('key');
            $table->string('label');
            $table->string('icon')->nullable();
            $table->string('color_token');
            $table->boolean('is_system')->default(false);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'key']);
        });

        $now = now();
        $systemCategories = [
            ['key' => 'company', 'label' => 'Company', 'icon' => 'Building2', 'color_token' => 'category-hr', 'sort' => 1],
            ['key' => 'team', 'label' => 'Team', 'icon' => 'Users', 'color_token' => 'category-ops', 'sort' => 2],
            ['key' => 'training', 'label' => 'Training', 'icon' => 'GraduationCap', 'color_token' => 'status-info', 'sort' => 3],
            ['key' => 'social', 'label' => 'Social', 'icon' => 'PartyPopper', 'color_token' => 'category-finance', 'sort' => 4],
            ['key' => 'holiday', 'label' => 'Holiday / closure', 'icon' => 'CalendarRange', 'color_token' => 'status-warning', 'sort' => 5],
        ];

        foreach ($systemCategories as $category) {
            DB::table('hr_calendar_event_categories')->insert([
                ...$category,
                'tenant_id' => null,
                'is_system' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        Schema::table('hr_calendar_events', function (Blueprint $table) {
            $table->foreignId('category_id')
                ->nullable()
                ->after('event_type')
                ->constrained('hr_calendar_event_categories')
                ->nullOnDelete();
        });

        // Backfill category_id from the event_type key (system categories).
        $categoryIdByKey = DB::table('hr_calendar_event_categories')
            ->whereNull('tenant_id')
            ->pluck('id', 'key');

        foreach ($categoryIdByKey as $key => $id) {
            DB::table('hr_calendar_events')
                ->where('event_type', $key)
                ->update(['category_id' => $id]);
        }
    }

    public function down(): void
    {
        Schema::table('hr_calendar_events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('category_id');
        });
        Schema::dropIfExists('hr_calendar_event_categories');
    }
};
