<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_attachment_publications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('attachment_id')->index();
            $table->foreignId('actor_id')->constrained('users');
            $table->foreignId('client_id')->constrained('clients');
            $table->uuid('mutation_id');
            $table->string('request_hash', 64);
            $table->string('destination', 20);
            // Keep the receipt after normal deletion of the separate destination record.
            $table->unsignedBigInteger('record_id');
            $table->timestamp('created_at');
            $table->unique(['actor_id', 'mutation_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_attachment_publications');
    }
};
