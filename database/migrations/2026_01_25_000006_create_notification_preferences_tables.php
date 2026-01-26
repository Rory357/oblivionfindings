<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('role_notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->string('key', 120);
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->unique(['role_id', 'key']);
            $table->index(['key', 'enabled']);
        });

        Schema::create('user_notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('key', 120);
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->unique(['user_id', 'key']);
            $table->index(['key', 'enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_notification_preferences');
        Schema::dropIfExists('role_notification_preferences');
    }
};
