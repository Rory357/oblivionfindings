<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hub_draft_discards', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hub_page_id')->constrained('hub_pages')->cascadeOnDelete();
            $table->json('content');
            $table->unsignedInteger('draft_version');
            $table->foreignId('discarded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('restored_at')->nullable();
            $table->foreignId('restored_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['hub_page_id', 'id']);
        });
        Schema::table('hub_pages', function (Blueprint $table): void {
            $table->timestamp('draft_discarded_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('hub_pages', fn (Blueprint $table) => $table->dropColumn('draft_discarded_at'));
        Schema::dropIfExists('hub_draft_discards');
    }
};
