<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hub_collections', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 60);
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
        Schema::create('hub_collection_legacy_pages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('collection_id')->constrained('hub_collections')->cascadeOnDelete();
            $table->foreignId('hub_page_id')->unique('hub_collection_legacy_page_unique')->constrained('hub_pages')->cascadeOnDelete();
            $table->string('legacy_label', 60);
            $table->foreignId('adopted_revision_id')->constrained('hub_revisions')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hub_collection_legacy_pages');
        Schema::dropIfExists('hub_collections');
    }
};
