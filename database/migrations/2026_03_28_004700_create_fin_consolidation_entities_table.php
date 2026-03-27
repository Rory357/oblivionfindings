<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_consolidation_entities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained('fin_consolidation_groups')->cascadeOnDelete();
            $table->unsignedBigInteger('organization_id')->index();
            $table->string('entity_name');
            $table->decimal('ownership_percentage', 5, 2)->default(100.00);
            $table->enum('consolidation_method', ['full', 'proportional', 'equity'])->default('full');
            $table->char('currency_code', 3)->default('NZD');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['group_id', 'organization_id'], 'fin_consol_entities_group_org_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_consolidation_entities');
    }
};
