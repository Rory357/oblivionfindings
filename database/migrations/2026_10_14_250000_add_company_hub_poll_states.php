<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hub_poll_states', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hub_page_id')->constrained('hub_pages')->cascadeOnDelete();
            $table->string('poll_key', 64);
            $table->foreignId('applied_revision_id')->constrained('hub_revisions')->cascadeOnDelete();
            $table->timestamp('manual_closed_at')->nullable();
            $table->dateTime('closes_at_override', 6)->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['hub_page_id', 'poll_key'], 'hub_poll_states_page_key_unique');
        });
    }

    public function down(): void
    {
        if (DB::table('hub_poll_states')->exists()) {
            throw new RuntimeException('Poll state records require a reviewed rollback.');
        }
        Schema::drop('hub_poll_states');
    }
};
