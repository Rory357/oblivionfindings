<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\HsCommittee;
use App\Models\HsCommitteeMeeting;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Notifications\HealthSafety\CommitteeMeetingScheduled;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/** Actual route writes and injected persistence failures; no substituted persistence/admission. Native UNRUN. */
final class WorkerParticipationCreationAtomicityTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;
    private Site $site;
    private HsCommittee $committee;
    private HsCommitteeMeeting $history;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.timezone' => 'UTC', 'app.worker_timezone' => 'Pacific/Auckland']);
        $this->travelTo(Carbon::parse('2026-01-01T00:00:00Z'));
        $this->seed(RbacSeeder::class);
        $this->site = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
        $this->actor = User::factory()->create(['role' => 'health_safety_officer', 'approved_at' => now(), 'external_clinical_account' => false]);
        $this->actor->roles()->attach(Role::where('name', 'health_safety_officer')->sole());
        HrEmployeeProfile::factory()->create(['user_id' => $this->actor->id, 'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [], 'is_active' => true, 'start_date' => '2025-01-01', 'end_date' => null]);
        $this->committee = HsCommittee::create(['name' => 'Retained committee', 'site_id' => $this->site->id, 'meeting_frequency' => 'monthly',
            'established_at' => '2025-01-01', 'members' => [$this->actor->id], 'status' => 'active']);
        $this->history = $this->committee->meetings()->create(['scheduled_at' => '2025-12-01 00:30:00', 'status' => 'completed']);
        $this->history->attendeeUsers()->attach($this->actor->id, ['attended' => true]);
        Notification::fake();
        $this->actingAs($this->actor);
    }

    private function create(string $path, array $overrides = [])
    {
        $values = [...['scheduled_at' => '2026-01-15T12:30', 'scheduled_timezone' => 'Pacific/Auckland', 'attendees' => [$this->actor->id]], ...$overrides];
        if ($path === 'inline') {
            return $this->postJson('/health-safety/worker-participation/committees', [...$values, 'name' => 'Atomic new committee',
                'site_id' => $this->site->id, 'meeting_frequency' => 'monthly', 'established_at' => '2026-01-01',
                'members' => [$this->actor->id], 'schedule_meeting' => true]);
        }
        return $this->postJson('/health-safety/worker-participation/committees/'.$this->committee->id.'/meetings', $values);
    }

    public static function persistenceFailures(): array
    {
        return [['inline', 'meeting'], ['inline', 'attendee'], ['existing', 'meeting'], ['existing', 'attendee']];
    }

    #[DataProvider('persistenceFailures')]
    public function test_real_persistence_failure_rolls_back_all_new_rows_and_never_notifies(string $path, string $stage): void
    {
        $this->withoutExceptionHandling();
        $armed = true; $fired = false; $pivotRowsAtFailure = null;
        $initialLevel = DB::connection()->transactionLevel();
        DB::connection()->beforeExecuting(function (string $sql) use ($stage, &$armed, &$fired): void {
            if ($armed && $stage === 'meeting' && preg_match('/^insert into `hs_committee_meetings`/i', ltrim($sql))) {
                $fired = true; throw new RuntimeException('Injected meeting persistence failure');
            }
        });
        // Fire AFTER the actual pivot INSERT to prove even persisted attendee rows are rolled back.
        DB::listen(function (QueryExecuted $query) use ($stage, &$armed, &$fired, &$pivotRowsAtFailure): void {
            if ($armed && $stage === 'attendee' && preg_match('/^insert into `hs_meeting_attendees`/i', ltrim($query->sql))) {
                $fired = true; $pivotRowsAtFailure = DB::table('hs_meeting_attendees')->count();
                throw new RuntimeException('Injected attendee persistence failure');
            }
        });
        try {
            $this->create($path); $this->fail('The actual persistence failure must propagate.');
        } catch (RuntimeException $error) {
            $this->assertSame('Injected '.$stage.' persistence failure', $error->getMessage());
        } finally { $armed = false; }
        $this->assertTrue($fired);
        if ($stage === 'attendee') { $this->assertSame(2, $pivotRowsAtFailure); }
        $this->assertSame($initialLevel, DB::connection()->transactionLevel());
        $this->assertSame(1, HsCommittee::withTrashed()->count());
        $this->assertSame(1, HsCommitteeMeeting::withTrashed()->count());
        $this->assertSame(1, DB::table('hs_meeting_attendees')->count());
        $this->assertSame('Retained committee', $this->committee->fresh()->name);
        $this->assertSame('completed', $this->history->fresh()->status);
        $this->assertDatabaseHas('hs_meeting_attendees', ['meeting_id' => $this->history->id, 'user_id' => $this->actor->id, 'attended' => true]);
        Notification::assertNothingSent();
    }

    public static function createPaths(): array { return [['inline'], ['existing']]; }

    #[DataProvider('createPaths')]
    public function test_success_keeps_parent_meeting_attendees_and_original_notice_flow(string $path): void
    {
        $initialLevel = DB::connection()->transactionLevel();
        $this->create($path)->assertRedirect();
        $meeting = HsCommitteeMeeting::whereKeyNot($this->history->id)->sole();
        $this->assertSame('2026-01-14 23:30:00', $meeting->getRawOriginal('scheduled_at'));
        $this->assertSame('scheduled', $meeting->status);
        $this->assertSame($path === 'inline' ? 2 : 1, HsCommittee::count());
        $this->assertSame($path === 'inline' ? 'Atomic new committee' : 'Retained committee', $meeting->committee->name);
        $this->assertSame([$this->actor->id], $meeting->attendeeUsers()->pluck('users.id')->all());
        $this->assertSame(2, DB::table('hs_meeting_attendees')->count());
        $this->assertSame($initialLevel, DB::connection()->transactionLevel());
        Notification::assertSentTo($this->actor, CommitteeMeetingScheduled::class, fn ($notice) => $notice->meeting->id === $meeting->id);
    }

    public function test_committee_only_creation_retains_existing_redirect_and_creates_no_meeting_or_notice(): void
    {
        $response = $this->post('/health-safety/worker-participation/committees', ['name' => 'Committee only', 'site_id' => $this->site->id,
            'meeting_frequency' => 'quarterly', 'established_at' => '2026-01-01', 'members' => [$this->actor->id], 'schedule_meeting' => false]);
        $response->assertRedirect()->assertSessionHas('created_committee_id', HsCommittee::where('name', 'Committee only')->sole()->id);
        $this->assertSame(2, HsCommittee::count()); $this->assertSame(1, HsCommitteeMeeting::count());
        $this->assertSame(1, DB::table('hs_meeting_attendees')->count()); Notification::assertNothingSent();
    }

    public function test_normalization_failure_precedes_first_committee_insert(): void
    {
        $armed = true; $writes = 0;
        DB::connection()->beforeExecuting(function (string $sql) use (&$armed, &$writes): void {
            if ($armed && preg_match('/^insert into `hs_(committees|committee_meetings|meeting_attendees)`/i', ltrim($sql))) { $writes++; }
        });
        try { $this->create('inline', ['scheduled_at' => '2026-09-27T02:30'])->assertUnprocessable()->assertJsonValidationErrors(['scheduled_at']); }
        finally { $armed = false; }
        $this->assertSame(0, $writes); $this->assertSame(1, HsCommittee::count()); $this->assertSame(1, HsCommitteeMeeting::count());
        Notification::assertNothingSent();
    }
}
