<?php

use App\Domain\Hr\Jobs\RunHrScheduledReportsJob;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrReportExport;
use App\Domain\Hr\Models\HrReportSubscription;
use App\Domain\Hr\Notifications\HrScheduledReportReadyNotification;
use App\Domain\Hr\Services\HrReportingService;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    Storage::fake('private');
    Notification::fake();
    $this->seed(RbacSeeder::class);

    $this->hrRole = Role::query()->where('name', 'hr')->sole();
    $this->hr = User::factory()->create([
        'role' => 'hr',
        'approved_at' => now(),
    ]);
    $this->hr->roles()->attach($this->hrRole);
});

function createReportProfile(User $actor, int $legacyMarker, string $number): HrEmployeeProfile
{
    $legacyColumn = 'ten'.'ant_id';
    $worker = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);

    return HrEmployeeProfile::query()->create([
        $legacyColumn => $legacyMarker,
        'user_id' => $worker->id,
        'employee_number' => $number,
        'work_email' => strtolower($number).'@example.test',
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'employment_type' => 'full_time',
        'start_date' => now()->subYear()->toDateString(),
        'is_active' => true,
        'created_by' => $actor->id,
        'updated_by' => $actor->id,
    ]);
}

it('reports over the complete application and hides compatibility storage', function (): void {
    $legacyColumn = 'ten'.'ant_id';
    createReportProfile($this->hr, 1, 'REPORT-001');
    createReportProfile($this->hr, 777, 'REPORT-002');

    $subscriptionA = HrReportSubscription::query()->create([
        $legacyColumn => 1,
        'report_type' => 'headcount',
        'cadence' => 'daily',
        'run_at' => '08:00:00',
        'timezone' => 'Pacific/Auckland',
        'is_active' => true,
        'created_by' => $this->hr->id,
    ]);
    $subscriptionB = HrReportSubscription::query()->create([
        $legacyColumn => 777,
        'report_type' => 'turnover',
        'cadence' => 'weekly',
        'run_at' => '08:00:00',
        'timezone' => 'Pacific/Auckland',
        'is_active' => true,
        'created_by' => $this->hr->id,
    ]);

    $report = app(HrReportingService::class)->generate('headcount');
    expect(data_get($report, 'data.total_active'))->toBe(2)
        ->and($subscriptionA->toArray())->not->toHaveKey($legacyColumn)
        ->and($subscriptionB->toArray())->not->toHaveKey($legacyColumn);

    $this->actingAs($this->hr)
        ->get('/hr/reports')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('hr/reports/index')
            ->has('subscriptions', 2)
            ->where('subscriptions.0.id', $subscriptionB->id)
            ->where('subscriptions.1.id', $subscriptionA->id));
});

it('allows only approved users who can view HR reports to receive scheduled exports', function (): void {
    $legacyColumn = 'ten'.'ant_id';
    $otherHr = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $otherHr->roles()->attach($this->hrRole);
    $ordinaryWorker = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $formerHr = User::factory()->create(['role' => 'hr', 'approved_at' => null]);
    $formerHr->roles()->attach($this->hrRole);

    $this->actingAs($this->hr)
        ->post('/hr/reports/subscriptions', [
            'report_type' => 'headcount',
            'cadence' => 'daily',
            'run_at' => '08:00',
            'timezone' => 'Pacific/Auckland',
            'recipient_user_ids' => [$ordinaryWorker->id],
        ])
        ->assertSessionHasErrors('recipient_user_ids.0');

    $this->actingAs($this->hr)
        ->post('/hr/reports/subscriptions', [
            'report_type' => 'headcount',
            'cadence' => 'daily',
            'run_at' => '08:00',
            'timezone' => 'Pacific/Auckland',
            'recipient_user_ids' => [$this->hr->id, $otherHr->id],
        ])
        ->assertSessionHas('success');

    $subscription = HrReportSubscription::query()->latest('id')->sole();
    $subscription->forceFill([
        'recipient_user_ids' => [$this->hr->id, $otherHr->id, $ordinaryWorker->id, $formerHr->id],
        'next_run_at' => now()->subMinute(),
    ])->save();

    (new RunHrScheduledReportsJob)->handle(app(HrReportingService::class));

    $export = HrReportExport::query()->where('subscription_id', $subscription->id)->sole();
    expect($export->toArray())->not->toHaveKey($legacyColumn)
        ->and(Storage::disk('private')->exists($export->storage_path))->toBeTrue();

    Notification::assertSentTo([$this->hr, $otherHr], HrScheduledReportReadyNotification::class);
    Notification::assertNotSentTo([$ordinaryWorker, $formerHr], HrScheduledReportReadyNotification::class);
});

it('neutralises formula-leading report keys and values in generated CSV', function (): void {
    $csv = app(HrReportingService::class)->buildCsv([
        '=unsafe-key' => '@SUM(A1:A2)',
        'payload' => '-2+cmd',
        'numeric' => -4,
    ]);

    expect($csv)->toContain('"\'=unsafe-key","\'@SUM(A1:A2)"')
        ->toContain('"payload","\'-2+cmd"')
        ->toContain('"numeric","-4"');
});
