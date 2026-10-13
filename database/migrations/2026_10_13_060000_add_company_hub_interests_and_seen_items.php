<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hub_preferences', function (Blueprint $table): void {
            $table->json('interests')->nullable();
            $table->unsignedInteger('interests_version')->default(1);
        });
        Schema::create('hub_update_seen', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('update_key', 100);
            $table->uuid('batch');
            $table->timestamp('seen_at');
            $table->unique(['user_id', 'update_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hub_update_seen');
        Schema::table('hub_preferences', fn (Blueprint $table) => $table->dropColumn(['interests', 'interests_version']));
    }
};
