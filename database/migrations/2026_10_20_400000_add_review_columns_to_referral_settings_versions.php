<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Referral settings review trail: the publisher's note, a discarded draft's
 * who/when/why, and the earlier publication a draft was restored from.
 * History is never edited in place; a restore always creates a new draft.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('care_referral_settings_versions', function (Blueprint $table): void {
            $table->text('publication_note')->nullable()->after('reason');
            $table->foreignId('discarded_by')->nullable()->after('published_by')->constrained('users')->nullOnDelete();
            $table->timestamp('discarded_at')->nullable()->after('effective_at');
            $table->text('discard_reason')->nullable()->after('discarded_at');
            $table->foreignId('restored_from_version_id')->nullable()->after('base_version_id')
                ->constrained('care_referral_settings_versions')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('care_referral_settings_versions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('restored_from_version_id');
            $table->dropConstrainedForeignId('discarded_by');
            $table->dropColumn(['publication_note', 'discarded_at', 'discard_reason']);
        });
    }
};
