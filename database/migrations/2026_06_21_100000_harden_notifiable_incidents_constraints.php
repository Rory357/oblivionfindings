<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Injuries & RTW redesign — deferred hygiene. DB-enforce the two invariants that
 * App\Observers\WorkplaceInjuryObserver currently maintains only in PHP when it
 * auto-registers a WorkSafe NotifiableIncident for a notifiable workplace injury.
 *
 *  1. submitted_by → nullable. The observer sets
 *     'submitted_by' => $injury->created_by ?? $injury->updated_by ?? auth()->id()
 *     because the column was NOT NULL. A seeded/imported injury with a null created_by
 *     flipped to worksafe_notifiable from the edit wizard — in a queued/CLI context with
 *     no auth() user — would otherwise fail the INSERT. Making the column nullable removes
 *     the need for the auth()->id() fallback to ever fire. The observer keeps the fallback
 *     too (belt and braces).
 *
 *  2. unique(workplace_injury_id). The observer is idempotent only via an exists() check,
 *     which can double-insert under concurrency. A unique index makes "one NotifiableIncident
 *     per injury" DB-enforced. Nulls are allowed — MySQL permits multiple NULLs in a unique
 *     index — so other notifiable rows (privacy breaches, client-incident links) with a null
 *     workplace_injury_id are unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('notifiable_incidents')) {
            return;
        }

        // 1. submitted_by → nullable (FK constraint is left intact by ->change()).
        Schema::table('notifiable_incidents', function (Blueprint $table) {
            $table->foreignId('submitted_by')->nullable()->change();
        });

        // 2. Unique index on workplace_injury_id (nullable column → multiple NULLs allowed).
        if (Schema::hasColumn('notifiable_incidents', 'workplace_injury_id')
            && ! $this->hasIndex('notifiable_incidents', 'notifiable_incidents_workplace_injury_id_unique')) {
            Schema::table('notifiable_incidents', function (Blueprint $table) {
                $table->unique('workplace_injury_id');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('notifiable_incidents')) {
            return;
        }

        if ($this->hasIndex('notifiable_incidents', 'notifiable_incidents_workplace_injury_id_unique')) {
            Schema::table('notifiable_incidents', function (Blueprint $table) {
                $table->dropUnique('notifiable_incidents_workplace_injury_id_unique');
            });
        }

        Schema::table('notifiable_incidents', function (Blueprint $table) {
            $table->foreignId('submitted_by')->nullable(false)->change();
        });
    }

    private function hasIndex(string $table, string $index): bool
    {
        foreach (Schema::getIndexes($table) as $existing) {
            if (($existing['name'] ?? null) === $index) {
                return true;
            }
        }

        return false;
    }
};
