<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('it_attachment_storage_intents', function (Blueprint $table): void {
            $table->id();
            $table->uuid('intent_uuid')->unique();
            $table->string('path')->unique();
            // Technical reservations commit before their parent transaction.
            // Foreign keys would wait on its User/parent locks and defeat that boundary.
            $table->unsignedBigInteger('actor_user_id')->index();
            $table->string('parent_type');
            $table->unsignedBigInteger('parent_id');
            $table->char('content_sha256', 64);
            $table->char('original_name_sha256', 64);
            $table->unsignedBigInteger('size');
            $table->string('state', 32)->default('reserved')->index();
            $table->unsignedBigInteger('attachment_id')->nullable()->unique();
            $table->unsignedInteger('revision')->default(0);
            $table->unsignedInteger('cleanup_attempts')->default(0);
            $table->timestamp('cleanup_requested_at')->nullable();
            $table->timestamp('last_cleanup_attempt_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->string('cleanup_error_code', 64)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        if (DB::table('it_attachment_storage_intents')->exists()) {
            throw new RuntimeException('Storage intent evidence must be reconciled before its schema can be removed.');
        }
        Schema::dropIfExists('it_attachment_storage_intents');
    }
};
