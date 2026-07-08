<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §P.7 — the IT knowledge base. Lite but real: markdown articles that deflect
 * repeat tickets. Categories reuse the ticket categories; slug is unique per
 * tenant; view/helpful counters power the requester "Was this helpful?" vote
 * and the agent Helpful% column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('it_kb_articles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('title');
            $table->string('slug');
            $table->string('category'); // reuses ticket categories: hardware|account|network|other
            $table->longText('body')->nullable();
            $table->string('status')->default('draft'); // draft | published
            $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('view_count')->default(0);
            $table->unsignedInteger('helpful_yes')->default(0);
            $table->unsignedInteger('helpful_no')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'slug'], 'it_kb_articles_tenant_slug_uq');
            $table->index(['tenant_id', 'status'], 'it_kb_articles_tenant_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('it_kb_articles');
    }
};
