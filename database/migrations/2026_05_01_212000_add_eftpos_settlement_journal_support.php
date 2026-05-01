<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('fin_eftpos_batches')) {
            $hasJournalId = Schema::hasColumn('fin_eftpos_batches', 'journal_id');
            $hasGlPostedAt = Schema::hasColumn('fin_eftpos_batches', 'gl_posted_at');

            if (! $hasJournalId || ! $hasGlPostedAt) {
                Schema::table('fin_eftpos_batches', function (Blueprint $table) use ($hasJournalId, $hasGlPostedAt): void {
                    if (! $hasJournalId) {
                        $table->foreignId('journal_id')
                            ->nullable()
                            ->after('discrepancy_notes')
                            ->constrained('fin_journals')
                            ->nullOnDelete();
                    }

                    if (! $hasGlPostedAt) {
                        $table->datetime('gl_posted_at')->nullable()->after('journal_id');
                    }
                });
            }
        }

        $this->seedCardClearingAccount();
    }

    public function down(): void
    {
        if (! Schema::hasTable('fin_eftpos_batches')) {
            return;
        }

        if (Schema::hasColumn('fin_eftpos_batches', 'journal_id')) {
            Schema::table('fin_eftpos_batches', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('journal_id');
            });
        }

        if (Schema::hasColumn('fin_eftpos_batches', 'gl_posted_at')) {
            Schema::table('fin_eftpos_batches', function (Blueprint $table): void {
                $table->dropColumn('gl_posted_at');
            });
        }
    }

    private function seedCardClearingAccount(): void
    {
        if (! Schema::hasTable('fin_accounts')) {
            return;
        }

        $organizationIds = DB::table('fin_accounts')
            ->distinct()
            ->pluck('organization_id')
            ->filter(fn ($organizationId): bool => $organizationId !== null)
            ->values();

        if ($organizationIds->isEmpty()) {
            $organizationIds = collect([0]);
        }

        foreach ($organizationIds as $organizationId) {
            DB::table('fin_accounts')->updateOrInsert(
                [
                    'organization_id' => (int) $organizationId,
                    'code' => '1180',
                ],
                [
                    'organization_id' => (int) $organizationId,
                    'code' => '1180',
                    'name' => 'Card Clearing',
                    'type' => 'asset',
                    'sub_type' => 'current_asset',
                    'is_system' => true,
                    'is_active' => true,
                    'opening_balance' => 0,
                    'gst_applicable' => false,
                    'deleted_at' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }
    }
};
