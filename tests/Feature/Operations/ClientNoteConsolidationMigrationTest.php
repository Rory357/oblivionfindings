<?php

use App\Models\CarePlan;
use App\Models\CarePlanGoal;
use App\Models\Client;
use App\Models\ClientNote;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function clientNoteConsolidationMigration(): object
{
    return require database_path(
        'migrations/2026_07_10_000002_add_legacy_fields_to_client_notes_table.php',
    );
}

function makeClientNoteMigrationFixture(array $attributes = []): ClientNote
{
    $user = User::factory()->create(['organization_id' => 1]);
    $client = Client::factory()->create(['organization_id' => 1]);
    $plan = CarePlan::query()->create([
        'organization_id' => 1,
        'client_id' => $client->id,
        'title' => 'Rollback-safe plan',
        'status' => 'draft',
        'plan_type' => 'support',
    ]);
    $goal = CarePlanGoal::query()->create([
        'organization_id' => 1,
        'care_plan_id' => $plan->id,
        'client_id' => $client->id,
        'title' => 'Rollback-safe goal',
        'category' => 'daily_living',
        'priority' => 'medium',
        'created_by' => $user->id,
    ]);

    return ClientNote::withoutEvents(fn () => ClientNote::query()->create(array_merge([
        'legacy_progress_note_id' => 741852,
        'organization_id' => 1,
        'client_id' => $client->id,
        'care_plan_goal_id' => $goal->id,
        'user_id' => $user->id,
        'type' => 'progress_note',
        'category' => 'goal_progress',
        'body' => 'Rollback-safe note',
        'visibility' => 'internal',
        'ai_summary' => 'Preserve this summary',
        'attachments' => ['existing' => 'attachment metadata'],
    ], $attributes)));
}

it('refuses a rollback that would resurrect a soft-deleted client note', function () {
    $note = makeClientNoteMigrationFixture();
    ClientNote::withoutEvents(fn () => $note->delete());

    expect(fn () => clientNoteConsolidationMigration()->down())
        ->toThrow(RuntimeException::class, 'soft-deleted client notes');

    expect(Schema::hasColumn('client_notes', 'deleted_at'))->toBeTrue()
        ->and(ClientNote::withTrashed()->findOrFail($note->id)->trashed())->toBeTrue();
});

it('preserves consolidation metadata through a safe down and re-up cycle', function () {
    $note = makeClientNoteMigrationFixture();
    $migration = clientNoteConsolidationMigration();

    $migration->down();

    expect(Schema::hasColumn('client_notes', 'legacy_progress_note_id'))->toBeFalse()
        ->and(Schema::hasColumn('client_notes', 'care_plan_goal_id'))->toBeFalse()
        ->and(Schema::hasColumn('client_notes', 'ai_summary'))->toBeFalse()
        ->and(Schema::hasColumn('client_notes', 'deleted_at'))->toBeFalse();

    $rollbackMetadata = json_decode(
        (string) DB::table('client_notes')->where('id', $note->id)->value('attachments'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect(data_get($rollbackMetadata, 'existing'))->toBe('attachment metadata')
        ->and(data_get($rollbackMetadata, '_client_note_consolidation.legacy_progress_note_id'))
        ->toBe(741852)
        ->and(data_get($rollbackMetadata, '_client_note_consolidation.care_plan_goal_id'))
        ->toBe($note->care_plan_goal_id)
        ->and(data_get($rollbackMetadata, '_client_note_consolidation.ai_summary'))
        ->toBe('Preserve this summary');

    $migration->up();

    $restored = DB::table('client_notes')->where('id', $note->id)->first();
    expect((int) $restored->legacy_progress_note_id)->toBe(741852)
        ->and((int) $restored->care_plan_goal_id)->toBe($note->care_plan_goal_id)
        ->and($restored->ai_summary)->toBe('Preserve this summary');
});
