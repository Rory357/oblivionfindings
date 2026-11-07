<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Several private staff conversations may be granted under one case
 * reference and reason in a single logged step (approved 22 October 2026).
 * Each conversation keeps its own grant row, so expiry, ending and the
 * access log stay per conversation; `case_batch` links the rows that were
 * granted together. Earlier single-conversation grants keep a null batch.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_investigation_grants', function (Blueprint $table): void {
            $table->uuid('case_batch')->nullable()->after('case_reference');
            $table->index(['case_batch', 'investigator_id']);
        });
    }

    public function down(): void
    {
        Schema::table('chat_investigation_grants', function (Blueprint $table): void {
            $table->dropIndex(['case_batch', 'investigator_id']);
            $table->dropColumn('case_batch');
        });
    }
};
