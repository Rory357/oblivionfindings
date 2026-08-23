<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hs_events', function (Blueprint $table): void {
            $table->string('worksafe_decision_tree_version', 64)
                ->nullable()
                ->after('worksafe_decision_source');
            $table->date('worksafe_source_effective_date')
                ->nullable()
                ->after('worksafe_decision_tree_version');
        });
    }

    public function down(): void
    {
        Schema::table('hs_events', function (Blueprint $table): void {
            $table->dropColumn([
                'worksafe_decision_tree_version',
                'worksafe_source_effective_date',
            ]);
        });
    }
};
