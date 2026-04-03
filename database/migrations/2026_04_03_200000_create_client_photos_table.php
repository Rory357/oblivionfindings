<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('storage_path');
            $table->string('thumbnail_path')->nullable();
            $table->string('original_name');
            $table->string('mime_type', 50);
            $table->unsignedInteger('size_bytes')->default(0);
            $table->string('caption', 500)->nullable();
            $table->json('tags')->nullable();
            $table->string('visibility', 30)->default('family'); // staff_only, family, all_portal_users
            $table->string('status', 30)->default('approved'); // approved, pending_approval, rejected
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index(['client_id', 'status']);
            $table->index(['client_id', 'visibility']);
        });

        Schema::table('family_portal_settings', function (Blueprint $table) {
            $table->boolean('allow_family_photo_upload')->default(false)->after('notify_incident');
            $table->boolean('require_photo_approval')->default(true)->after('allow_family_photo_upload');
            $table->boolean('show_location')->default(false)->after('require_photo_approval');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_photos');

        Schema::table('family_portal_settings', function (Blueprint $table) {
            $table->dropColumn(['allow_family_photo_upload', 'require_photo_approval', 'show_location']);
        });
    }
};
