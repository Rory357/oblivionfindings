<?php

namespace Tests\Feature\Governance;

use App\Domain\Governance\Models\ConflictDeclaration;
use App\Domain\Governance\Models\Vote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\GovernanceTestHelpers;
use Tests\TestCase;

/**
 * GOV plain-language audit P0-2: the old ballot saved a member's reason for
 * their vote as `conflict_note` and flagged the vote as a declared conflict.
 * The repair migration moves those notes to `vote_note` — but only where no
 * conflict of interest was ever really declared.
 */
class VoteNoteRepairMigrationTest extends TestCase
{
    use GovernanceTestHelpers;
    use RefreshDatabase;

    public function test_notes_saved_as_false_conflicts_move_to_the_vote_reason_and_real_declarations_are_kept(): void
    {
        $this->seedGovernance();
        $admin = $this->createAdminUser();
        $noteOnly = $this->createBoardMember($this->createUserWithRole('board_member', ['email' => 'note-only@example.test']));
        $declared = $this->createBoardMember($this->createUserWithRole('board_member', ['email' => 'declared@example.test']));
        $resolution = $this->createResolution($admin, ['status' => 'closed', 'outcome' => 'carried']);

        // Rows as the old ballot recorded them.
        $falseConflict = Vote::create([
            'resolution_id' => $resolution->id,
            'board_member_id' => $noteOnly->id,
            'vote' => 'for',
            'voted_at' => now()->subDay(),
            'voting_method' => 'electronic',
            'conflict_declared' => true,
            'conflict_note' => 'I support this because it is in the budget.',
        ]);
        $realConflict = Vote::create([
            'resolution_id' => $resolution->id,
            'board_member_id' => $declared->id,
            'vote' => 'against',
            'voted_at' => now()->subDay(),
            'voting_method' => 'electronic',
            'conflict_declared' => true,
            'conflict_note' => 'Declared at the meeting.',
        ]);
        ConflictDeclaration::create([
            'resolution_id' => $resolution->id,
            'board_member_id' => $declared->id,
            'declaration_type' => 'material',
            'declaration_text' => 'Director of the supplier named in the paper.',
            'withdrew_from_voting' => false,
            'recorded_by' => $admin->id,
            'declared_at' => now()->subDays(2),
        ]);

        $migration = require database_path('migrations/2026_09_14_530100_move_vote_notes_out_of_conflict_records.php');
        $migration->up();

        $falseConflict->refresh();
        $this->assertSame('I support this because it is in the budget.', $falseConflict->vote_note);
        $this->assertNull($falseConflict->conflict_note);
        $this->assertFalse($falseConflict->conflict_declared);

        $realConflict->refresh();
        $this->assertTrue($realConflict->conflict_declared, 'A vote backed by a real declaration is left alone.');
        $this->assertSame('Declared at the meeting.', $realConflict->conflict_note);
        $this->assertNull($realConflict->vote_note);

        // Every repair is written to the governance change log with old and new values.
        $log = DB::table('governance_change_log')->where('entity_type', 'Vote')->get();
        $this->assertCount(1, $log);
        $this->assertSame($falseConflict->id, (int) $log->first()->entity_id);
        $this->assertTrue(json_decode((string) $log->first()->old_values, true)['conflict_declared']);

        // Running it again changes nothing.
        $migration->up();
        $this->assertSame(1, DB::table('governance_change_log')->where('entity_type', 'Vote')->count());

        // down() puts the rows back exactly as they were.
        $migration->down();
        $falseConflict->refresh();
        $this->assertTrue($falseConflict->conflict_declared);
        $this->assertSame('I support this because it is in the budget.', $falseConflict->conflict_note);
        $this->assertNull($falseConflict->vote_note);
        $this->assertSame(0, DB::table('governance_change_log')->where('entity_type', 'Vote')->count());
    }
}
