<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_match_rules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->string('name');
            $table->integer('priority')->default(0);
            $table->enum('rule_type', ['exact_amount', 'reference_match', 'vendor_pattern', 'recurring_pattern', 'amount_tolerance']);
            $table->json('conditions')->nullable();
            $table->decimal('auto_confirm_threshold', 5, 2)->default(95.00);
            $table->boolean('is_active')->default(true);
            $table->integer('match_count')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_match_rules');
    }
};
