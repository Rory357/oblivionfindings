<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Safeguarding redesign — Step 3 (need-to-know).
 *
 * `SafeguardingConcernPolicy::viewSensitive` already exists but nothing marked a
 * concern as sensitive. This flag lets a raiser/lead mark an allegation sensitive
 * so the list/feed/detail redact the subject's identity for viewers without
 * `safeguarding.viewSensitive` (the "Restricted · need-to-know" treatment).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('safeguarding_concerns', function (Blueprint $table) {
            $table->boolean('is_sensitive')->default(false)->after('severity');
        });
    }

    public function down(): void
    {
        Schema::table('safeguarding_concerns', function (Blueprint $table) {
            $table->dropColumn('is_sensitive');
        });
    }
};
