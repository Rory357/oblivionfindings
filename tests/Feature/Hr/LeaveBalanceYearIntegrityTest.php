<?php

namespace Tests\Feature\Hr;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrLeaveBalance;
use App\Domain\Hr\Models\HrLeaveBalanceLedger;
use App\Domain\Hr\Models\HrLeaveRequest;
use App\Domain\Hr\Services\LeaveService;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\StaffTimeOff;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LeaveBalanceYearIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private User $staff;

    private User $reviewer;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.worker_timezone' => 'Pacific/Auckland']);
        $this->travelTo(now()->setDate(2026, 10, 5)->startOfDay());
        Queue::fake();
        Notification::fake();
        $this->site = Site::factory()->create();
        $this->staff = $this->actor([], $this->site);
        $this->reviewer = $this->actor(['hr.leave.approve', 'hr.leave.manage'], $this->site);
        $this->balance(2026, 100, 12, 4);
        $this->balance(2027, 200, 16, 2);
    }

    #[DataProvider('newYearTransitions')]
    public function test_new_year_decline_and_cancellation_release_the_same_local_year_as_reservation(
        string $timezone,
        string $transition,
    ): void {
        config(['app.worker_timezone' => $timezone]);
        $previous = $this->balanceFor(2026)->getRawOriginal();
        $service = app(LeaveService::class);
        $request = $this->submit('2027-01-01');
        $start = $request->getRawOriginal('starts_at');
        $end = $request->getRawOriginal('ends_at');
        $this->assertStringStartsWith('2026-12-31', $start);
        $this->assertSame(10.0, (float) $this->balanceFor(2027)->pending_hours);

        if ($transition === 'approved cancellation') {
            $request = $service->approveRequest($request, $this->reviewer, 'Existing approval authority');
            $this->assertSame(24.0, (float) $this->balanceFor(2027)->used_hours);
            $this->assertSame(2.0, (float) $this->balanceFor(2027)->pending_hours);
            $this->assertDatabaseHas('staff_time_offs', ['id' => $request->time_off_id, 'hr_leave_request_id' => $request->id]);
            $request = $service->cancelRequest($request, $this->reviewer);
        } elseif ($transition === 'pending cancellation') {
            $request = $service->cancelRequest($request, $this->staff);
        } else {
            $request = $service->declineRequest($request, $this->reviewer, 'Request declined');
        }

        $this->assertSame($transition === 'decline' ? 'declined' : 'cancelled', $request->status);
        $this->assertSame(16.0, (float) $this->balanceFor(2027)->used_hours);
        $this->assertSame(2.0, (float) $this->balanceFor(2027)->pending_hours);
        $this->assertSame($previous, $this->balanceFor(2026)->getRawOriginal());
        $this->assertSame($start, $request->getRawOriginal('starts_at'));
        $this->assertSame($end, $request->getRawOriginal('ends_at'));
        $ledger = HrLeaveBalanceLedger::query()->where('source_type', $request->getMorphClass())
            ->where('source_id', $request->id)->orderBy('id')->get();
        $this->assertCount($transition === 'approved cancellation' ? 3 : 2, $ledger);
        $this->assertSame([2027], $ledger->pluck('year')->unique()->values()->all());
        $this->assertSame($transition === 'decline' ? 'released' : 'cancelled', $ledger->last()->entry_type);
        $this->assertSame(2.0, (float) $ledger->last()->pending_hours_after);
        $this->assertFalse(StaffTimeOff::query()->where('hr_leave_request_id', $request->id)->exists());
    }

    public static function newYearTransitions(): array
    {
        $cases = [];
        foreach (['Pacific/Auckland', 'Pacific/Kiritimati'] as $timezone) {
            foreach (['decline', 'pending cancellation', 'approved cancellation'] as $transition) {
                $cases[$timezone.' '.$transition] = [$timezone, $transition];
            }
        }

        return $cases;
    }

    public function test_preview_and_saved_context_use_local_balance_year_without_mutating_dates_or_records(): void
    {
        $service = app(LeaveService::class);
        $before = $this->balanceFor(2027)->getRawOriginal();
        $preview = $service->previewRequest($this->staff, ['leave_type' => 'annual',
            'starts_at' => '2027-01-01', 'ends_at' => '2027-01-01', 'hours_requested' => 8]);
        $this->assertSame(182.0, (float) $preview['available_before']);
        $this->assertSame(174.0, (float) $preview['projected_remaining']);
        $this->assertSame($before, $this->balanceFor(2027)->getRawOriginal());
        $this->assertDatabaseCount('hr_leave_requests', 0);

        $request = $this->submit('2027-01-01');
        $raw = $request->getRawOriginal();
        $balances = HrLeaveBalance::query()->orderBy('id')->get()->map->getRawOriginal()->all();
        $ledgerCount = HrLeaveBalanceLedger::query()->count();
        $context = $service->annotateRequestsContext(collect([$request]), $this->reviewer);
        $this->assertSame(['remaining_before' => 182.0, 'projected_after' => 174.0, 'insufficient' => false], $context[$request->id]['balanceImpact']);
        $this->assertSame($raw, $request->fresh()->getRawOriginal());
        $this->assertSame('UTC', $request->starts_at->timezoneName);
        $this->assertSame($balances, HrLeaveBalance::query()->orderBy('id')->get()->map->getRawOriginal()->all());
        $this->assertSame($ledgerCount, HrLeaveBalanceLedger::query()->count());
    }

    public function test_a_request_spanning_new_year_keeps_the_existing_whole_request_start_year_policy(): void
    {
        $nextYear = $this->balanceFor(2027)->getRawOriginal();
        $request = $this->submit('2026-12-31', '2027-01-02');
        $this->assertSame(12.0, (float) $this->balanceFor(2026)->pending_hours);
        app(LeaveService::class)->cancelRequest($request, $this->staff);
        $this->assertSame(4.0, (float) $this->balanceFor(2026)->pending_hours);
        $this->assertSame($nextYear, $this->balanceFor(2027)->getRawOriginal());
        $this->assertSame([2026], HrLeaveBalanceLedger::query()->where('source_id', $request->id)->pluck('year')->unique()->values()->all());
    }

    public function test_same_utc_and_local_year_retains_ordinary_decline_behavior(): void
    {
        $request = $this->submit('2027-01-04');
        $this->assertStringStartsWith('2027-01-03', $request->getRawOriginal('starts_at'));
        app(LeaveService::class)->declineRequest($request, $this->reviewer, 'Ordinary decline');
        $this->assertSame(2.0, (float) $this->balanceFor(2027)->pending_hours);
        $this->assertSame([2027], HrLeaveBalanceLedger::query()->where('source_id', $request->id)->pluck('year')->unique()->values()->all());
    }

    #[DataProvider('foreignTransitions')]
    public function test_year_repair_does_not_grant_hidden_site_review_or_cancellation(string $command): void
    {
        $request = $this->submit('2027-01-01');
        $otherSite = Site::factory()->create();
        $foreignReviewer = $this->actor(['hr.leave.approve', 'hr.leave.manage'], $otherSite);
        $raw = $request->getRawOriginal();
        $balance = $this->balanceFor(2027)->getRawOriginal();
        $ledgerCount = HrLeaveBalanceLedger::query()->count();
        try {
            if ($command === 'cancelRequest') {
                app(LeaveService::class)->cancelRequest($request, $foreignReviewer);
            } else {
                app(LeaveService::class)->{$command}($request, $foreignReviewer, 'Outside approved Site');
            }
            $this->fail('A foreign Site reviewer must remain denied.');
        } catch (ModelNotFoundException) {
            $this->assertSame($raw, $request->fresh()->getRawOriginal());
            $this->assertSame($balance, $this->balanceFor(2027)->getRawOriginal());
            $this->assertSame($ledgerCount, HrLeaveBalanceLedger::query()->count());
            $this->assertDatabaseCount('staff_time_offs', 0);
        }
    }

    public static function foreignTransitions(): array
    {
        return ['approve' => ['approveRequest'], 'decline' => ['declineRequest'], 'cancel' => ['cancelRequest']];
    }

    private function submit(string $start, ?string $end = null): HrLeaveRequest
    {
        return app(LeaveService::class)->submitRequest($this->staff, ['leave_type' => 'annual',
            'starts_at' => $start, 'ends_at' => $end ?? $start, 'hours_requested' => 8,
            'reason' => 'Synthetic balance boundary', 'created_by' => $this->staff->id]);
    }

    private function balance(int $year, float $hours, float $used, float $pending): HrLeaveBalance
    {
        return HrLeaveBalance::query()->create(['user_id' => $this->staff->id, 'leave_type' => 'annual',
            'year' => $year, 'balance_hours' => $hours, 'accrued_hours' => $hours,
            'used_hours' => $used, 'pending_hours' => $pending, 'source' => 'system',
            'last_synced_at' => now(), 'updated_by' => $this->staff->id]);
    }

    private function balanceFor(int $year): HrLeaveBalance
    {
        return HrLeaveBalance::query()->where('user_id', $this->staff->id)->where('leave_type', 'annual')->where('year', $year)->sole();
    }

    private function actor(array $keys, Site $site): User
    {
        $actor = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $role = Role::query()->create(['name' => 'leave_year_'.$actor->id, 'label' => 'Custom leave role', 'type' => 'custom', 'level' => 50]);
        foreach ($keys as $key) {
            $permission = Permission::query()->firstOrCreate(['key' => $key], ['description' => 'Leave year fixture', 'group' => 'HR', 'module' => 'HR']);
            $role->permissions()->attach($permission);
        }
        $actor->roles()->attach($role);
        HrEmployeeProfile::factory()->create(['user_id' => $actor->id, 'primary_site_id' => $site->id,
            'secondary_site_ids' => [], 'start_date' => '2025-01-01', 'end_date' => null, 'is_active' => true,
            'created_by' => $actor->id, 'updated_by' => $actor->id]);

        return $actor;
    }
}
