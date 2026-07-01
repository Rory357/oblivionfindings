<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_goal_cycles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('name'); // "FY26 Q3"
            $table->string('type')->default('quarter'); // quarter, half, year, custom
            $table->date('starts_at');
            $table->date('ends_at');
            $table->string('status')->default('active'); // upcoming, active, closed
            $table->foreignId('parent_cycle_id')->nullable()->constrained('hr_goal_cycles')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_goal_cycles');
    }
};
