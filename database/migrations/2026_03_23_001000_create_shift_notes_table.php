<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_notes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->nullable()->index();
            $table->foreignId('shift_id')->constrained('shifts')->cascadeOnDelete();
            $table->foreignId('author_id')->constrained('users')->cascadeOnDelete();
            $table->string('note_type')->default('general');
            $table->text('content');
            $table->boolean('is_private')->default(false);
            $table->json('attachments')->nullable();
            $table->timestamps();

            $table->index(['shift_id', 'note_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_notes');
    }
};
