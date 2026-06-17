<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Safeguarding redesign — Step 1 (W1/W2).
 *
 * Adds the terminal `no_action_required` status to the safeguarding_concerns
 * status enum (the "No further action" triage outcome surfaced in the prototype),
 * and defensively backfills any null/empty status to 'reported'.
 *
 * MySQL stores `status` as an ENUM, so widening it requires a raw MODIFY. The
 * sqlite path is a no-op (enum is emulated as a plain string column there).
 */
return new class extends Migration
{
    private const STATUSES_NEW = [
        'reported',
        'triaged',
        'investigating',
        'action_plan',
        'monitoring',
        'closed',
        'referred_external',
        'no_action_required',
    ];

    private const STATUSES_OLD = [
        'reported',
        'triaged',
        'investigating',
        'action_plan',
        'monitoring',
        'closed',
        'referred_external',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('safeguarding_concerns')) {
            return;
        }

        // Defensive backfill (column is NOT NULL DEFAULT 'reported', so normally a no-op).
        DB::table('safeguarding_concerns')
            ->whereNull('status')
            ->orWhere('status', '')
            ->update(['status' => 'reported']);

        if (DB::getDriverName() === 'mysql') {
            DB::statement(sprintf(
                "ALTER TABLE safeguarding_concerns MODIFY status ENUM(%s) NOT NULL DEFAULT 'reported'",
                $this->enumList(self::STATUSES_NEW),
            ));
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('safeguarding_concerns')) {
            return;
        }

        // Fold the new terminal value back into 'closed' before narrowing the enum,
        // so MySQL doesn't truncate rows to '' on the MODIFY.
        DB::table('safeguarding_concerns')
            ->where('status', 'no_action_required')
            ->update(['status' => 'closed']);

        if (DB::getDriverName() === 'mysql') {
            DB::statement(sprintf(
                "ALTER TABLE safeguarding_concerns MODIFY status ENUM(%s) NOT NULL DEFAULT 'reported'",
                $this->enumList(self::STATUSES_OLD),
            ));
        }
    }

    private function enumList(array $values): string
    {
        return implode(',', array_map(static fn (string $v): string => "'".$v."'", $values));
    }
};
