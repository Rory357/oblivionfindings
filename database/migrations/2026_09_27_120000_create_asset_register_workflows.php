<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_stocktakes', function (Blueprint $table) {
            $table->id();
            $table->uuid('request_id')->unique();
            $table->foreignId('site_id')->constrained()->restrictOnDelete();
            $table->foreignId('site_room_id')->nullable()->constrained('site_rooms')->restrictOnDelete();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('title', 160);
            $table->string('status', 24)->default('draft');
            $table->unsignedInteger('version')->default(1);
            $table->json('scope');
            $table->json('entries');
            $table->json('activity');
            $table->text('review_note')->nullable();
            $table->foreignId('follow_up_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('follow_up_name')->nullable();
            $table->timestamp('counted_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['site_id', 'status', 'updated_at']);
        });
        Schema::create('asset_stocktake_asset_refs', function (Blueprint $table) {
            $table->foreignId('asset_stocktake_id')->constrained('asset_stocktakes')->cascadeOnDelete();
            // Retain the identity even if the asset is removed; visibility then fails closed.
            $table->unsignedBigInteger('asset_id');
            $table->primary(['asset_stocktake_id', 'asset_id']);
            $table->index('asset_id');
        });
        Schema::create('asset_import_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('filename');
            $table->string('status', 24)->default('mapping');
            $table->unsignedInteger('version')->default(1);
            $table->json('headers');
            $table->json('mapping');
            $table->json('rows');
            $table->timestamps();
        });
        // PKG-06B preview databases may already contain this exact shared table.
        // Keep their stable identities and export history when adopting the register schema.
        if (! Schema::hasTable('asset_label_batches')) {
            Schema::create('asset_label_batches', function (Blueprint $table) {
                $table->id();
                $table->uuid('request_id')->unique();
                $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
                $table->string('status', 24)->default('ready');
                $table->json('asset_ids');
                $table->json('layout');
                $table->json('downloads');
                $table->timestamp('expires_at');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        // Shared by register and profile: never delete saved label history on rollback.
        Schema::dropIfExists('asset_import_batches');
        Schema::dropIfExists('asset_stocktake_asset_refs');
        Schema::dropIfExists('asset_stocktakes');
    }
};
