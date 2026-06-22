<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional image attachment on a community-wall post (the composer's "add a
 * photo" affordance). Stored on the PRIVATE disk and served through an
 * authenticated, hardened route (see ServesPrivateAttachments) — never a public
 * /storage URL. One attachment per post for now (hasOne).
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('hr_feed_attachments')) {
            return;
        }

        Schema::create('hr_feed_attachments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('feed_post_id')->constrained('hr_feed_posts')->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('disk')->default('private');
            $table->string('original_name');
            $table->string('path');
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->timestamps();

            $table->index('feed_post_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_feed_attachments');
    }
};
