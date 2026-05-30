<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('queclink_presets', function (Blueprint $table) {
            $table->id();
            // Null tenant_id => a shared "system" preset visible to every tenant.
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            // Which fleet this preset is intended for: personal_tracker (GL30
            // pendants), vehicle_tracker (GV500CG), or all.
            $table->string('target_category', 32)->default('personal_tracker');
            // section => { field => value } map, keyed by the same section names
            // the configuration write path understands (server, tracking, pin, …).
            $table->json('payload');
            $table->boolean('is_system')->default(false);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'slug']);
            $table->index(['tenant_id', 'is_system']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('queclink_presets');
    }
};
