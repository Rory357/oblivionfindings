<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Raw clock records are part of the statutory wages-and-time record
     * (Employment Relations Act 2000 s130) — completes the retention set from
     * 2026_06_10_000001_restrict_user_delete_for_hr_retention_records.
     */
    public function up(): void
    {
        $this->replaceUserForeignKey('hr_time_entries', 'user_id', 'restrict');
    }

    public function down(): void
    {
        $this->replaceUserForeignKey('hr_time_entries', 'user_id', 'cascade');
    }

    private function replaceUserForeignKey(string $table, string $column, string $onDelete): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }

        Schema::table($table, function (Blueprint $table) use ($column, $onDelete) {
            $table->dropForeign([$column]);

            $foreign = $table->foreign($column)->references('id')->on('users');

            if ($onDelete === 'cascade') {
                $foreign->cascadeOnDelete();
            } else {
                $foreign->restrictOnDelete();
            }
        });
    }
};
