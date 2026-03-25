<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->json('tags')->nullable();
            $table->boolean('is_group_shift')->default(false);
            $table->unsignedTinyInteger('max_clients')->nullable();
        });

        Schema::create('shift_clients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_id')->constrained('shifts')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['shift_id', 'client_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_clients');

        Schema::table('shifts', function (Blueprint $table) {
            $table->dropColumn(['tags', 'is_group_shift', 'max_clients']);
        });
    }
};
