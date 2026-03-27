<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_account_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained('fin_consolidation_groups')->cascadeOnDelete();
            $table->foreignId('entity_id')->constrained('fin_consolidation_entities')->cascadeOnDelete();
            $table->foreignId('source_account_id')->constrained('fin_accounts')->cascadeOnDelete();
            $table->string('consolidated_account_code');
            $table->string('consolidated_account_name');
            $table->boolean('is_elimination_account')->default(false);
            $table->timestamps();

            $table->unique(
                ['group_id', 'entity_id', 'source_account_id'],
                'fin_acct_map_group_entity_source_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_account_mappings');
    }
};
