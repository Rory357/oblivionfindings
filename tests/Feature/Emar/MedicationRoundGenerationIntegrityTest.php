<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\MedicationRound;
use App\Models\MedicationRoundTemplate;
use App\Models\ServiceContext;
use App\Models\Site;
use App\Models\User;
use App\Services\Medication\MedicationRoundGenerationService;
use Carbon\Carbon;
use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MedicationRoundGenerationIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private const ROUND_DATE = '2026-08-30';

    private Site $site;

    private ServiceContext $context;

    private User $assignee;

    private HrEmployeeProfile $profile;

    private MedicationRoundTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-30 08:00:00', config('app.worker_timezone', 'Pacific/Auckland')));
        $this->site = Site::factory()->create([
            'is_active' => true,
            'archived' => false,
            'archived_at' => null,
        ]);
        $this->context = ServiceContext::factory()->create([
            'site_id' => $this->site->id,
            'is_active' => true,
        ]);
        $this->assignee = User::factory()->create([
            'role' => 'support_worker',
            'approved_at' => now(),
        ]);
        $this->profile = HrEmployeeProfile::factory()->create([
            'user_id' => $this->assignee->id,
            'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [],
            'start_date' => $this->roundDate()->subYear(),
            'end_date' => null,
            'is_active' => true,
            'created_by' => $this->assignee->id,
            'updated_by' => $this->assignee->id,
        ]);
        $this->template = MedicationRoundTemplate::query()->create([
            'site_id' => $this->site->id,
            'service_context_id' => $this->context->id,
            'name' => 'Sunday morning integrity round',
            'scheduled_time' => '08:00',
            'window_minutes' => 60,
            'days_of_week' => [7],
            'active' => true,
            'default_assigned_to' => $this->assignee->id,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_generation_locks_canonical_parents_then_materializes_and_replays_one_round(): void
    {
        [$created, $lockedTables] = $this->captureLockQueries(fn (): array => $this->service()->generate(
            $this->template->id,
            $this->roundDate(),
            false,
            [$this->site->id],
        ));

        $this->assertSame(
            MedicationRoundGenerationService::REASON_CREATED,
            $created['reason'],
            json_encode($created, JSON_THROW_ON_ERROR),
        );
        $this->assertSame(MedicationRoundGenerationService::STATUS_CREATED, $created['status']);
        $this->assertNotNull($created['round_id']);
        $this->assertMysqlLockOrder($lockedTables, [
            'service_contexts',
            'users',
            'hr_employee_profiles',
            'sites',
            'medication_round_templates',
        ]);

        $round = MedicationRound::query()->findOrFail($created['round_id']);
        $this->assertSame($this->template->id, (int) $round->round_template_id);
        $this->assertSame($this->site->id, (int) $round->site_id);
        $this->assertSame($this->context->id, (int) $round->service_context_id);
        $this->assertSame($this->assignee->id, (int) $round->assigned_to);

        $replay = $this->service()->generate(
            $this->template->id,
            $this->roundDate(),
            false,
            [$this->site->id],
        );
        $this->assertSame(MedicationRoundGenerationService::STATUS_ALREADY_EXISTS, $replay['status']);
        $this->assertSame($created['round_id'], $replay['round_id']);
        $this->assertSame(1, MedicationRound::query()
            ->where('round_template_id', $this->template->id)
            ->where('round_date', self::ROUND_DATE)
            ->count());
    }

    public function test_generation_fail_closed_re_resolves_template_site_context_and_assignee_state(): void
    {
        $foreignSite = Site::factory()->create([
            'is_active' => true,
            'archived' => false,
            'archived_at' => null,
        ]);
        $foreignContext = ServiceContext::factory()->create([
            'site_id' => $foreignSite->id,
            'is_active' => true,
        ]);
        $foreignAssignee = User::factory()->create([
            'role' => 'support_worker',
            'approved_at' => now(),
        ]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $foreignAssignee->id,
            'primary_site_id' => $foreignSite->id,
            'secondary_site_ids' => [],
            'start_date' => $this->roundDate()->subYear(),
            'end_date' => null,
            'is_active' => true,
            'created_by' => $foreignAssignee->id,
            'updated_by' => $foreignAssignee->id,
        ]);

        $this->template->update([
            'site_id' => null,
            'service_context_id' => null,
            'default_assigned_to' => null,
        ]);
        $this->assertReason(MedicationRoundGenerationService::REASON_LEGACY_NULL_SITE);

        $this->template->update(['site_id' => $this->site->id, 'active' => false]);
        $this->assertReason(MedicationRoundGenerationService::REASON_TEMPLATE_INACTIVE);

        $this->template->update(['active' => true]);
        $this->site->update(['is_active' => false]);
        $this->assertReason(MedicationRoundGenerationService::REASON_SITE_INACTIVE);

        $this->site->update(['is_active' => true]);
        $this->template->update(['service_context_id' => $foreignContext->id]);
        $this->assertReason(MedicationRoundGenerationService::REASON_CONTEXT_SITE_MISMATCH);

        $this->context->update(['is_active' => false]);
        $this->template->update(['service_context_id' => $this->context->id]);
        $this->assertReason(MedicationRoundGenerationService::REASON_CONTEXT_INACTIVE);

        $this->context->update(['is_active' => true]);
        $this->profile->update(['end_date' => $this->roundDate()->subDay()]);
        $this->template->update(['default_assigned_to' => $this->assignee->id]);
        $this->assertReason(MedicationRoundGenerationService::REASON_ASSIGNEE_NOT_CURRENT);

        $this->profile->update(['end_date' => null]);
        $this->template->update(['default_assigned_to' => $foreignAssignee->id]);
        $this->assertReason(MedicationRoundGenerationService::REASON_ASSIGNEE_SITE_MISMATCH);

        $this->template->update([
            'default_assigned_to' => $this->assignee->id,
            'days_of_week' => [1],
        ]);
        $this->assertReason(MedicationRoundGenerationService::REASON_NOT_SCHEDULED);

        $this->template->update(['days_of_week' => [7]]);
        $result = $this->service()->generate(
            $this->template->id,
            $this->roundDate(),
            false,
            [$foreignSite->id],
        );
        $this->assertSame(MedicationRoundGenerationService::REASON_SITE_OUT_OF_SCOPE, $result['reason']);

        $missing = $this->service()->generate(PHP_INT_MAX, $this->roundDate());
        $this->assertSame(MedicationRoundGenerationService::REASON_TEMPLATE_MISSING, $missing['reason']);
        $this->assertDatabaseCount('medication_rounds', 0);
    }

    public function test_generation_rejects_every_canonical_site_archive_marker(): void
    {
        $this->site->forceFill([
            'archived' => false,
            'archived_at' => now()->subMinute(),
        ])->save();
        $this->assertReason(MedicationRoundGenerationService::REASON_SITE_INACTIVE);

        $this->site->forceFill([
            'archived' => true,
            'archived_at' => null,
        ])->save();
        $this->assertReason(MedicationRoundGenerationService::REASON_SITE_INACTIVE);

        $this->assertDatabaseCount('medication_rounds', 0);
    }

    public function test_concrete_site_template_accepts_an_active_application_wide_context(): void
    {
        $this->context->update(['site_id' => null]);

        $created = $this->service()->generate(
            $this->template->id,
            $this->roundDate(),
            false,
            [$this->site->id],
        );

        $this->assertSame(MedicationRoundGenerationService::STATUS_CREATED, $created['status']);
        $this->assertDatabaseHas('medication_rounds', [
            'id' => $created['round_id'],
            'site_id' => $this->site->id,
            'service_context_id' => $this->context->id,
        ]);
    }

    public function test_assignee_employment_date_boundaries_are_inclusive_for_the_round_date(): void
    {
        $this->profile->update([
            'start_date' => self::ROUND_DATE,
            'end_date' => self::ROUND_DATE,
        ]);

        $created = $this->service()->generate($this->template->id, self::ROUND_DATE);

        $this->assertSame(MedicationRoundGenerationService::STATUS_CREATED, $created['status']);
        $this->assertDatabaseHas('medication_rounds', [
            'id' => $created['round_id'],
            'round_date' => self::ROUND_DATE,
        ]);
    }

    public function test_existing_round_must_match_the_current_template_site_and_context_identity(): void
    {
        $created = $this->service()->generate($this->template->id, $this->roundDate());
        $round = MedicationRound::query()->findOrFail($created['round_id']);
        $foreignSite = Site::factory()->create([
            'is_active' => true,
            'archived' => false,
            'archived_at' => null,
        ]);
        $foreignContext = ServiceContext::factory()->create([
            'site_id' => $foreignSite->id,
            'is_active' => true,
        ]);

        $round->update(['site_id' => $foreignSite->id]);
        $siteMismatch = $this->service()->generate($this->template->id, $this->roundDate());
        $this->assertSame(MedicationRoundGenerationService::STATUS_SKIPPED, $siteMismatch['status']);
        $this->assertSame(
            MedicationRoundGenerationService::REASON_EXISTING_ROUND_SCOPE_MISMATCH,
            $siteMismatch['reason'],
        );

        $round->update([
            'site_id' => $this->site->id,
            'service_context_id' => $foreignContext->id,
        ]);
        $contextMismatch = $this->service()->generate($this->template->id, $this->roundDate());
        $this->assertSame(MedicationRoundGenerationService::STATUS_SKIPPED, $contextMismatch['status']);
        $this->assertSame(
            MedicationRoundGenerationService::REASON_EXISTING_ROUND_SCOPE_MISMATCH,
            $contextMismatch['reason'],
        );
        $this->assertDatabaseCount('medication_rounds', 1);
    }

    public function test_command_and_direct_caller_converge_on_the_same_round(): void
    {
        $this->artisan('emar:generate-rounds', [
            '--date' => self::ROUND_DATE,
        ])
            ->expectsOutput('Generated 1 rounds; 0 already existed; skipped 0.')
            ->assertExitCode(0);

        $round = MedicationRound::query()
            ->where('round_template_id', $this->template->id)
            ->where('round_date', self::ROUND_DATE)
            ->firstOrFail();
        $result = $this->service()->generate(
            $this->template->id,
            $this->roundDate(),
            false,
            [$this->site->id],
        );

        $this->assertSame(MedicationRoundGenerationService::STATUS_ALREADY_EXISTS, $result['status']);
        $this->assertSame($round->id, $result['round_id']);

        $this->artisan('emar:generate-rounds', [
            '--date' => self::ROUND_DATE,
        ])
            ->expectsOutput('Generated 0 rounds; 1 already existed; skipped 0.')
            ->assertExitCode(0);
        $this->assertDatabaseCount('medication_rounds', 1);
    }

    public function test_command_default_uses_the_worker_timezone_calendar_date(): void
    {
        $this->assertSame('2026-08-29', now('UTC')->toDateString());
        $this->assertSame(self::ROUND_DATE, now(config('app.worker_timezone'))->toDateString());

        $this->artisan('emar:generate-rounds')
            ->expectsOutput('Generated 1 rounds; 0 already existed; skipped 0.')
            ->assertExitCode(0);

        $this->assertDatabaseHas('medication_rounds', [
            'round_template_id' => $this->template->id,
            'round_date' => self::ROUND_DATE,
        ]);
    }

    public function test_stale_candidate_ids_do_not_survive_deactivation_retarget_or_assignee_end_races(): void
    {
        $candidateId = (int) MedicationRoundTemplate::query()
            ->active()
            ->whereIn('site_id', [$this->site->id])
            ->value('id');

        $this->template->update(['active' => false]);
        $deactivated = $this->service()->generate(
            $candidateId,
            $this->roundDate(),
            false,
            [$this->site->id],
        );
        $this->assertSame(MedicationRoundGenerationService::REASON_TEMPLATE_INACTIVE, $deactivated['reason']);

        $foreignSite = Site::factory()->create([
            'is_active' => true,
            'archived' => false,
            'archived_at' => null,
        ]);
        $foreignContext = ServiceContext::factory()->create([
            'site_id' => $foreignSite->id,
            'is_active' => true,
        ]);
        $this->template->update([
            'active' => true,
            'site_id' => $foreignSite->id,
            'service_context_id' => $foreignContext->id,
            'default_assigned_to' => null,
        ]);
        $retargeted = $this->service()->generate(
            $candidateId,
            $this->roundDate(),
            false,
            [$this->site->id],
        );
        $this->assertSame(MedicationRoundGenerationService::REASON_SITE_OUT_OF_SCOPE, $retargeted['reason']);

        $this->template->update([
            'site_id' => $this->site->id,
            'service_context_id' => $this->context->id,
            'default_assigned_to' => $this->assignee->id,
        ]);
        $this->profile->update(['end_date' => $this->roundDate()->subDay()]);
        $endedAssignee = $this->service()->generate(
            $candidateId,
            $this->roundDate(),
            false,
            [$this->site->id],
        );
        $this->assertSame(MedicationRoundGenerationService::REASON_ASSIGNEE_NOT_CURRENT, $endedAssignee['reason']);
        $this->assertDatabaseCount('medication_rounds', 0);
    }

    public function test_database_identity_rejects_a_second_direct_template_date_row(): void
    {
        $result = $this->service()->generate($this->template->id, $this->roundDate());
        $round = MedicationRound::query()->findOrFail($result['round_id']);

        $this->expectException(QueryException::class);
        DB::table('medication_rounds')->insert([
            ...$round->getAttributes(),
            'id' => $round->id + 1000,
        ]);
    }

    private function service(): MedicationRoundGenerationService
    {
        return app(MedicationRoundGenerationService::class);
    }

    private function roundDate(): Carbon
    {
        return Carbon::parse(
            self::ROUND_DATE,
            config('app.worker_timezone', 'Pacific/Auckland'),
        );
    }

    private function assertReason(string $reason): void
    {
        $result = $this->service()->generate(
            $this->template->id,
            $this->roundDate(),
            false,
            [$this->site->id],
        );

        $this->assertSame(MedicationRoundGenerationService::STATUS_SKIPPED, $result['status']);
        $this->assertSame($reason, $result['reason']);
        $this->assertNull($result['round_id']);
        $this->assertDatabaseCount('medication_rounds', 0);
    }

    /** @return array{0: array<string, mixed>, 1: array<int, string>} */
    private function captureLockQueries(Closure $callback): array
    {
        $lockedTables = [];
        $capturing = true;
        DB::listen(function (QueryExecuted $query) use (&$lockedTables, &$capturing): void {
            if (! $capturing || ! str_contains(strtolower($query->sql), 'for update')) {
                return;
            }

            if (preg_match('/\bfrom\s+[`"]?([a-z0-9_]+)/i', $query->sql, $matches) === 1) {
                $lockedTables[] = strtolower($matches[1]);
            }
        });

        try {
            $result = $callback();
        } finally {
            $capturing = false;
        }

        return [$result, $lockedTables];
    }

    /** @param array<int, string> $lockedTables @param array<int, string> $expected */
    private function assertMysqlLockOrder(array $lockedTables, array $expected): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->addToAssertionCount(1);

            return;
        }

        $relevantLocks = array_values(array_filter(
            $lockedTables,
            fn (string $table): bool => in_array($table, $expected, true),
        ));

        $this->assertSame($expected, $relevantLocks);
    }
}
