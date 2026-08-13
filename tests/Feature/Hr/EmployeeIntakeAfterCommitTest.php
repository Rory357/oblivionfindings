<?php

use App\Domain\Hr\Jobs\DeliverHrWebhookJob;
use App\Domain\Hr\Models\HrAutomationRule;
use App\Domain\Hr\Models\HrAutomationRun;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrOnboardingChecklist;
use App\Domain\Hr\Models\HrOnboardingTemplate;
use App\Domain\Hr\Models\HrWebhookDelivery;
use App\Domain\Hr\Models\HrWebhookEndpoint;
use App\Domain\Hr\Services\EmployeeIntakeService;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Notification::fake();
    Queue::fake();
    $this->seed(RbacSeeder::class);

    $this->actor = User::factory()->create([
        'name' => 'After Commit HR',
        'email' => 'after-commit-hr@example.test',
        'role' => 'hr',
        'approved_at' => now(),
    ]);
    $this->actor->roles()->sync([Role::query()->where('name', 'hr')->firstOrFail()->id]);
    $this->site = Site::factory()->create(['name' => 'After Commit Site']);
    HrEmployeeProfile::factory()->create([
        'user_id' => $this->actor->id,
        'employee_number' => 'AFTER-COMMIT-HR',
        'position_role' => 'hr',
        'primary_site_id' => $this->site->id,
        'secondary_site_ids' => [],
        'start_date' => today()->subYear(),
        'end_date' => null,
        'is_active' => true,
    ]);

    HrOnboardingTemplate::query()->create([
        'role' => 'support_worker',
        'site_type' => 'all',
        'tasks' => [[
            'category' => 'hr',
            'title' => 'Complete first-day checks',
            'is_required' => true,
            'sign_off_required' => false,
        ]],
        'is_active' => true,
        'created_by' => $this->actor->id,
        'updated_by' => $this->actor->id,
    ]);
    HrWebhookEndpoint::query()->create([
        'name' => 'Employee created endpoint',
        'target_url' => 'https://hooks.example.test/employee-created',
        'event_types' => ['employee.created'],
        'timeout_seconds' => 10,
        'retry_limit' => 3,
        'is_active' => true,
        'created_by' => $this->actor->id,
        'updated_by' => $this->actor->id,
    ]);
    HrAutomationRule::query()->create([
        'name' => 'Employee created audit',
        'event_type' => 'employee.created',
        'conditions' => [],
        'actions' => [],
        'is_active' => true,
        'stop_on_match' => false,
        'created_by' => $this->actor->id,
        'updated_by' => $this->actor->id,
    ]);
});

function intakeInsideOuterTransaction(object $test, string $email)
{
    return app(EmployeeIntakeService::class)->intake(
        name: 'After Commit Hire',
        email: $email,
        roleName: 'support_worker',
        profileAttributes: [
            'position_title' => 'Support Worker',
            'position_role' => 'support_worker',
            'employment_type' => 'full_time',
            'primary_site_id' => $test->site->id,
            'start_date' => now()->toDateString(),
        ],
        actorId: $test->actor->id,
        startOnboarding: true,
        sendInvite: true,
    );
}

test('intake produces no onboarding invite automation or webhook effects when an outer transaction rolls back', function () {
    DB::beginTransaction();
    $profile = intakeInsideOuterTransaction($this, 'rolled-back-hire@example.test');

    expect(HrOnboardingChecklist::query()->where('employee_profile_id', $profile->id)->exists())->toBeFalse()
        ->and(HrWebhookDelivery::query()->exists())->toBeFalse()
        ->and(HrAutomationRun::query()->exists())->toBeFalse()
        ->and(AuditLog::query()->where('action', 'user.employee_intake')->count())->toBe(1);
    Notification::assertNothingSent();
    Queue::assertNothingPushed();

    DB::rollBack();

    expect(User::query()->where('email', 'rolled-back-hire@example.test')->exists())->toBeFalse()
        ->and(HrOnboardingChecklist::query()->exists())->toBeFalse()
        ->and(HrWebhookDelivery::query()->exists())->toBeFalse()
        ->and(HrAutomationRun::query()->exists())->toBeFalse()
        ->and(AuditLog::query()->where('action', 'user.employee_intake')->exists())->toBeFalse();
    Notification::assertNothingSent();
    Queue::assertNothingPushed();
});

test('intake produces every side effect exactly once only after the outer transaction commits', function () {
    DB::beginTransaction();
    $profile = intakeInsideOuterTransaction($this, 'committed-hire@example.test');

    expect(HrOnboardingChecklist::query()->where('employee_profile_id', $profile->id)->exists())->toBeFalse()
        ->and(HrWebhookDelivery::query()->exists())->toBeFalse()
        ->and(HrAutomationRun::query()->exists())->toBeFalse()
        ->and(AuditLog::query()->where('action', 'user.employee_intake')->count())->toBe(1);
    Notification::assertNothingSent();
    Queue::assertNothingPushed();

    DB::commit();

    expect(HrOnboardingChecklist::query()->where('employee_profile_id', $profile->id)->count())->toBe(1)
        ->and(HrWebhookDelivery::query()->where('event_type', 'employee.created')->count())->toBe(1)
        ->and(HrAutomationRun::query()->where('event_type', 'employee.created')->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'user.employee_intake')->count())->toBe(1);
    Notification::assertSentToTimes($profile->user, ResetPassword::class, 1);
    Queue::assertPushed(DeliverHrWebhookJob::class, 1);
});
