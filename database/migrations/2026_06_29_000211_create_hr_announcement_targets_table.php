<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('hr_announcement_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('announcement_id')->constrained('hr_announcements')->cascadeOnDelete();
            $table->string('type', 16);            // all|site|department|role|user
            $table->string('value')->nullable();   // id or name; null when type=all
            $table->timestamps();

            $table->index(['announcement_id', 'type']);
        });

        // Migrate existing single-segment targeting into the join table.
        $rows = DB::table('hr_announcements')->select('id', 'target_audience', 'target_value')->get();
        $now = now();
        foreach ($rows as $row) {
            $type = $row->target_audience ?: 'all';
            DB::table('hr_announcement_targets')->insert([
                'announcement_id' => $row->id,
                'type' => $type,
                'value' => $type === 'all' ? null : ($row->target_value ?: null),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_announcement_targets');
    }
};
