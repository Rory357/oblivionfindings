<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hs_investigations', function (Blueprint $table): void {
            $table->foreignId('submitted_by_id')->nullable()->after('lessons_learned')
                ->constrained('users')->restrictOnDelete();
            $table->timestamp('submitted_at')->nullable()->after('submitted_by_id');
        });

        DB::table('hs_investigations')
            ->whereIn('status', ['under_review', 'completed'])
            ->whereNull('submitted_by_id')
            ->update([
                'submitted_by_id' => DB::raw('COALESCE(lead_investigator_id, created_by)'),
                'submitted_at' => DB::raw('COALESCE(updated_at, created_at)'),
            ]);
    }

    public function down(): void
    {
        Schema::table('hs_investigations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('submitted_by_id');
            $table->dropColumn('submitted_at');
        });
    }
};
