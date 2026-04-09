<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_eligibility_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->comment('Staff member being assigned');
            $table->foreignId('overridden_by')->constrained('users')->comment('Manager who authorised the override');
            $table->text('override_reason');
            $table->json('rules_overridden');
            $table->json('acknowledged_warnings');
            $table->timestamps();

            $table->index(['shift_id', 'user_id']);
            $table->index('overridden_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_eligibility_overrides');
    }
};
