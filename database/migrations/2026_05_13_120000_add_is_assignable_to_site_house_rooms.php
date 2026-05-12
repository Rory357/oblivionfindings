<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('site_house_rooms', 'is_assignable')) {
            Schema::table('site_house_rooms', function (Blueprint $table) {
                $table->boolean('is_assignable')->default(true)->after('is_active');
            });
        }

        // Best-effort backfill: rooms whose name reads as a shared / communal /
        // utility space get marked non-assignable. Users can override via the
        // Edit dialog if any of these are actually bedrooms.
        $communalPatterns = [
            'kitchen', 'lounge', 'bathroom', 'bath', 'shower', 'toilet',
            'hallway', 'corridor', 'garage', 'laundry', 'pantry',
            'utility', 'garden', 'exterior', 'living', 'dining',
            'office', 'storage', 'common', 'communal', 'staff',
            'workshop', 'shed',
        ];

        foreach ($communalPatterns as $pattern) {
            DB::table('site_house_rooms')
                ->whereRaw('LOWER(name) LIKE ?', ['%' . $pattern . '%'])
                ->update(['is_assignable' => false]);
        }

        // Also unset any stale client assignment on non-assignable rooms so the
        // occupancy counters line up with the new semantics.
        DB::table('site_house_rooms')
            ->where('is_assignable', false)
            ->update([
                'assigned_client_id' => null,
                'assigned_from' => null,
                'assigned_until' => null,
            ]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('site_house_rooms', 'is_assignable')) {
            Schema::table('site_house_rooms', function (Blueprint $table) {
                $table->dropColumn('is_assignable');
            });
        }
    }
};
