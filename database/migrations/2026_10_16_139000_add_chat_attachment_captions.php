<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_attachments', function (Blueprint $table) {
            $table->text('caption')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('chat_attachments', fn (Blueprint $table) => $table->dropColumn('caption'));
    }
};
