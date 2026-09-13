<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('it_reply_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('audience', 20)->default('public');
            $table->text('body');
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('review_due_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('lock_version')->default(1);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['audience', 'is_active']);
        });
        Schema::create('it_reply_template_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('template_id')->constrained('it_reply_templates')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('name', 120);
            $table->string('audience', 20);
            $table->text('body');
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at');
            $table->unique(['template_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('it_reply_template_versions');
        Schema::dropIfExists('it_reply_templates');
    }
};
