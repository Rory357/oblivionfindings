<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('hr_check_ins', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('user_id')->constrained('users');
            $table->date('check_in_date');
            $table->string('mood'); // great, good, okay, struggling, bad
            $table->integer('energy_level')->nullable(); // 1-5
            $table->integer('workload_rating')->nullable(); // 1-5
            $table->text('notes')->nullable();
            $table->boolean('is_anonymous')->default(false);
            $table->timestamp('created_at')->nullable();

            $table->index(['tenant_id', 'user_id', 'check_in_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_check_ins');
    }
};
