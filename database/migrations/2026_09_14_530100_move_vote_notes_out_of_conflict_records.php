<?php

use App\Domain\Governance\Services\GovernanceAuditService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Data repair for GOV plain-language audit P0-2.
 *
 * The ballot used to send a member's optional "vote note" as
 * `conflict_note`, and VotingService marked every such vote as
 * `conflict_declared`. For votes where no conflict of interest was ever
 * declared (no ConflictDeclaration for that member on that resolution), the
 * note is moved to `vote_note` and the false conflict flag is cleared. Votes
 * backed by a real declaration are left untouched. Each repaired vote is
 * recorded in the governance change log (old and new values), which is also
 * what `down()` uses to put the rows back exactly as they were.
 *
 * Final-result copies frozen into `resolutions.vote_summary` are deliberately
 * not rewritten; the app no longer shows a conflict from a vote's flag, only
 * from real declarations.
 */
return new class extends Migration
{
    private const MARKER = 'vote_note_not_a_conflict_2026_09_14';

    public function up(): void
    {
        if (
            ! Schema::hasTable('votes')
            || ! Schema::hasColumn('votes', 'vote_note')
            || ! Schema::hasColumn('votes', 'conflict_note')
            || ! Schema::hasTable('conflict_declarations')
        ) {
            return;
        }

        DB::table('votes')
            ->whereNotNull('conflict_note')
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')
                    ->from('conflict_declarations')
                    ->whereColumn('conflict_declarations.resolution_id', 'votes.resolution_id')
                    ->whereColumn('conflict_declarations.board_member_id', 'votes.board_member_id');
            })
            ->orderBy('id')
            ->chunkById(200, function ($votes): void {
                foreach ($votes as $vote) {
                    $movedNote = $vote->vote_note ?? $vote->conflict_note;

                    DB::table('votes')->where('id', $vote->id)->update([
                        'vote_note' => $movedNote,
                        'conflict_note' => null,
                        'conflict_declared' => false,
                        'updated_at' => now(),
                    ]);

                    GovernanceAuditService::logChange(
                        'update',
                        'Vote',
                        (int) $vote->id,
                        'A reason given with a vote had been saved as a conflict of interest. It was moved to the vote reason and the conflict flag was removed.',
                        [
                            'conflict_declared' => (bool) $vote->conflict_declared,
                            'conflict_note' => $vote->conflict_note,
                            'vote_note' => $vote->vote_note,
                        ],
                        [
                            'conflict_declared' => false,
                            'conflict_note' => null,
                            'vote_note' => $movedNote,
                            'repair' => self::MARKER,
                        ],
                    );
                }
            });
    }

    public function down(): void
    {
        if (
            ! Schema::hasTable('governance_change_log')
            || ! Schema::hasTable('votes')
            || ! Schema::hasColumn('votes', 'vote_note')
        ) {
            return;
        }

        DB::table('governance_change_log')
            ->where('entity_type', 'Vote')
            ->where('change_type', 'update')
            ->orderBy('id')
            ->get(['id', 'entity_id', 'old_values', 'new_values'])
            ->filter(function ($row): bool {
                $new = json_decode((string) $row->new_values, true);

                return is_array($new) && ($new['repair'] ?? null) === self::MARKER;
            })
            ->each(function ($row): void {
                $old = json_decode((string) $row->old_values, true) ?: [];

                DB::table('votes')->where('id', $row->entity_id)->update([
                    'conflict_declared' => (bool) ($old['conflict_declared'] ?? false),
                    'conflict_note' => $old['conflict_note'] ?? null,
                    'vote_note' => $old['vote_note'] ?? null,
                ]);

                DB::table('governance_change_log')->where('id', $row->id)->delete();
            });
    }
};
