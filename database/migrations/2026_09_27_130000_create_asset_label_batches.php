<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The same source-owned table ships with PKG-06A register workflows.
        if (Schema::hasTable('asset_label_batches')) {
            return;
        }
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

    public function down(): void
    {
        // Batch history may already be owned by PKG-06A; retain it on rollback.
    }
};
