<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['chat_uploads', 'chat_attachments'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->string('media_kind', 20)->default('file');
                $table->unsignedInteger('duration_ms')->nullable();
            });
        }
        Schema::table('chat_attachments', fn (Blueprint $table) => $table->string('media_sha256', 64)->nullable());
    }

    public function down(): void
    {
        Schema::table('chat_attachments', fn (Blueprint $table) => $table->dropColumn('media_sha256'));
        foreach (['chat_uploads', 'chat_attachments'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn(['media_kind', 'duration_ms']));
        }
    }
};
