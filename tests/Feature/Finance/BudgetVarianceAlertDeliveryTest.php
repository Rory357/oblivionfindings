<?php

use App\Domain\Finance\Jobs\SyncBudgetActualsJob;
use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinCostAllocation;
use App\Domain\Finance\Models\FinJournal;
use App\Domain\Finance\Models\FinJournalLine;
use App\Domain\Finance\Models\SiteBudgetLine;
use App\Domain\Finance\Notifications\BudgetVarianceAlertNotification;
use App\Domain\Finance\Services\BudgetActualsService;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Mockery\MockInterface;

test('budget actual sync delivers variance alerts once per debounce window', function () {
    $this->travelTo(Carbon::parse('2026-05-15 10:00:00'));
    Notification::fake();

    config([
        'finance.insight_thresholds.budget_approaching_pct' => 85,
        'finance.insight_thresholds.budget_over_pct' => 100,
        'finance.budget_variance_alerts.debounce_hours' => 24,
    ]);

    $financeRole = Role::create([
        'name' => 'finance',
        'label' => 'Finance',
        'level' => 50,
        'type' => 'system',
    ]);

    $recipient = User::factory()->create([
        'organization_id' => 1,
        'role' => 'finance',
    ]);
    $recipient->roles()->attach($financeRole);

    $site = Site::factory()->create([
        'tenant_id' => 1,
        'type' => 'house',
        'is_active' => true,
    ]);

    $budgetLine = SiteBudgetLine::create([
        'tenant_id' => 1,
        'site_id' => $site->id,
        'period' => '2026-05',
        'category' => 'utilities',
        'planned_amount' => 1000,
    ]);

    $account = FinAccount::factory()->create([
        'organization_id' => 1,
        'type' => 'expense',
    ]);
    $journal = FinJournal::factory()->create([
        'organization_id' => 1,
        'journal_date' => '2026-05-10',
        'status' => 'posted',
    ]);
    $journalLine = FinJournalLine::create([
        'journal_id' => $journal->id,
        'account_id' => $account->id,
        'description' => 'Utilities allocation',
        'debit' => 900,
        'credit' => 0,
    ]);

    FinCostAllocation::create([
        'journal_id' => $journal->id,
        'journal_line_id' => $journalLine->id,
        'site_id' => $site->id,
        'amount' => 900,
        'event_type' => 'site_utilities_expense',
        'event_date' => '2026-05-10',
    ]);

    $this->mock(BudgetActualsService::class, function (MockInterface $mock) {
        $mock->shouldReceive('syncActuals')
            ->twice()
            ->with(1)
            ->andReturn([
                'updated' => 0,
                'total_budget' => 0,
                'total_actual' => 0,
                'variance' => 0,
            ]);
    });

    app()->call([new SyncBudgetActualsJob, 'handle']);
    app()->call([new SyncBudgetActualsJob, 'handle']);

    Notification::assertSentTo(
        $recipient,
        BudgetVarianceAlertNotification::class,
        fn (BudgetVarianceAlertNotification $notification) => $notification->alertLevel === 'approaching_budget'
            && round((float) $notification->utilizationPct, 1) === 90.0,
    );

    expect(Notification::sent($recipient, BudgetVarianceAlertNotification::class))->toHaveCount(1);
    expect($budgetLine->fresh()->last_alerted_at)->not->toBeNull();
});
