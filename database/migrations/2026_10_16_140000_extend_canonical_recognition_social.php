<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_kudos_replies', function (Blueprint $table): void {
            $table->foreignId('parent_id')->nullable()->constrained('hr_kudos_replies')->nullOnDelete();
            $table->timestamp('edited_at')->nullable();
            $table->timestamp('hidden_at')->nullable();
            $table->foreignId('moderated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('moderation_reason', 500)->nullable();
        });
        Schema::create('hr_recognition_mentions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('kudos_id')->constrained('hr_kudos')->cascadeOnDelete();
            $table->foreignId('reply_id')->nullable()->constrained('hr_kudos_replies')->cascadeOnDelete();
            $table->string('source_key', 40);
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['kudos_id', 'source_key', 'user_id'], 'hr_recognition_mention_source_user');
        });
        Schema::create('hr_recognition_images', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('kudos_id')->constrained('hr_kudos')->cascadeOnDelete();
            $table->foreignId('reply_id')->nullable()->constrained('hr_kudos_replies')->cascadeOnDelete();
            $table->foreignId('uploaded_by')->constrained('users')->cascadeOnDelete();
            $table->string('path');
            $table->string('mime', 40);
            $table->unsignedInteger('size');
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });
        Schema::create('hr_recognition_send_receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->uuid('request_id');
            $table->string('payload_hash', 64);
            $table->json('receipt');
            $table->timestamps();
            $table->unique(['user_id', 'request_id']);
        });
    }

    public function down(): void
    {
        if (DB::table('hr_recognition_mentions')->exists() || DB::table('hr_recognition_images')->exists()
            || DB::table('hr_recognition_send_receipts')->exists()
            || DB::table('hr_kudos_replies')->whereNotNull('parent_id')->orWhereNotNull('edited_at')->orWhereNotNull('hidden_at')->exists()) {
            throw new RuntimeException('Recognition participation records require a reviewed rollback.');
        }
        Schema::drop('hr_recognition_send_receipts');
        Schema::drop('hr_recognition_images');
        Schema::drop('hr_recognition_mentions');
        Schema::table('hr_kudos_replies', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('parent_id');
            $table->dropConstrainedForeignId('moderated_by');
            $table->dropColumn(['edited_at', 'hidden_at', 'moderation_reason']);
        });
    }
};
