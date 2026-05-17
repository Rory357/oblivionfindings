<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('site_meal_plan_entries', function (Blueprint $table) {
            $table->text('allergen_override_reason')->nullable()->after('notes');
            $table->foreignId('allergen_override_by')->nullable()->after('allergen_override_reason')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('allergen_override_at')->nullable()->after('allergen_override_by');
        });
    }

    public function down(): void
    {
        Schema::table('site_meal_plan_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('allergen_override_by');
            $table->dropColumn(['allergen_override_reason', 'allergen_override_at']);
        });
    }
};
