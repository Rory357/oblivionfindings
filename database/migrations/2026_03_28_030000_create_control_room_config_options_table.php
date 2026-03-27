<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('control_room_config_options', function (Blueprint $table) {
            $table->id();
            $table->string('group'); // e.g. 'category', 'resolution_code', 'task_priority', 'discussion_type'
            $table->string('value');
            $table->string('label');
            $table->string('color')->nullable(); // optional color code for badges
            $table->string('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['group', 'value']);
            $table->index(['group', 'is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('control_room_config_options');
    }
};
