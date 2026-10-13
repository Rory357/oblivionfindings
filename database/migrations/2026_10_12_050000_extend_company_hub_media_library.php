<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hub_media_albums', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->string('name', 80);
            $table->timestamps();
            $table->unique(['owner_id', 'name']);
        });
        Schema::table('hub_media', function (Blueprint $table): void {
            $table->string('alt', 300)->default('');
            $table->foreignId('album_id')->nullable()->constrained('hub_media_albums')->nullOnDelete();
            $table->foreignId('source_media_id')->nullable()->constrained('hub_media')->nullOnDelete();
            $table->json('crop')->nullable();
            $table->unsignedInteger('version')->default(1);
        });
    }

    public function down(): void
    {
        Schema::table('hub_media', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('source_media_id');
            $table->dropConstrainedForeignId('album_id');
            $table->dropColumn(['alt', 'crop', 'version']);
        });
        Schema::dropIfExists('hub_media_albums');
    }
};
