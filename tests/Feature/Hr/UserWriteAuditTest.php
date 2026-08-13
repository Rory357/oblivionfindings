<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrOffboardingChecklist;
use App\Domain\Hr\Models\HrOffboardingTask;
use App\Domain\Hr\Services\EmployeeIntakeService;
use App\Domain\Hr\Services\OnboardingService;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\Notification;

/**
 * Close-out C3 (Decision D-3) — user-write auditability.
 *
 * The four places HR mutates USER accounts (mint/link + role + approval on
 * intake; approval + role restore on re-hire; login revocation on offboarding
 * completion; login restoration on lightweight reactivation) now write
 * explicit AuditLog entries via the existing AuditLogger
 * (the same mechanism AuditableChanges feeds and the canonical
 * /settings/audit-logs page reads).
 * User itself deliberately does NOT carry the AuditableChanges trait — that
 * would log every remember-token/login touch. The authenticated actor is the
 * audit row's user and is repeated in metadata for service/queue traceability.
 */
beforeEach(function () {
    Notification::fake();
    $this->seed(RbacSeeder::class);

    $this->actor = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $hrRole = Role::query()->where('name', 'hr')->firstOrFail();
    $this->actor->roles()->syncWithoutDetaching([$hrRole->id]);
    $this->site = Site::factory()->create(['name' => 'User Audit Allowed Site']);
    HrEmployeeProfile::factory()->create([
        'user_id' => $this->actor->id,
        'primary_site_id' => $this->site->id,
        'is_active' => true,
        'start_date' => now()->subYear()->toDateString(),
    ]);
});

test('C3: employee intake writes a user audit entry with the actor and role', function () {
    $profile = app(EmployeeIntakeService::class)->intake(
        name: 'Audit Trail',
        email: 'audit.trail@example.test',
        roleName: 'support_worker',
        profileAttributes: [
            'position_title' => 'Support Worker',
            'position_role' => 'support_worker',
            'employment_type' => 'full_time',
            'primary_site_id' => $this->site->id,
            'start_date' => now()->toDateString(),
        ],
        actorId: $this->actor->id,
        startOnboarding: false,
        sendInvite: false,
    );

    $entry = AuditLog::query()->where('action', 'user.employee_intake')->first();

    expect($entry)->not->toBeNull();
    expect($entry->action)->toBe('user.employee_intake');
    expect((int) $entry->user_id)->toBe($this->actor->id);
    expect($entry->auditable_type)->toBe($profile->user->getMorphClass());
    expect((int) $entry->auditable_id)->toBe($profile->user_id);
    expect($entry->meta['actor_id'])->toBe($this->actor->id);
    expect($entry->meta['role'])->toBe('support_worker');
    expect($entry->meta['linked_existing_user'])->toBeFalse();
});

test('C3: role-changing re-hire grants and audits the resolved role', function () {
    $former = User::factory()->create(['role' => 'support_worker', 'approved_at' => null]);
    $profile = HrEmployeeProfile::factory()->create([
        'user_id' => $former->id,
        'position_role' => 'support_worker',
        'is_active' => false,
    ]);

    app(EmployeeIntakeService::class)->rehire(
        $profile,
        [
            'start_date' => now()->toDateString(),
            'position_role' => 'team_lead',
        ],
        $this->actor->id,
        sendInvite: false,
        startOnboarding: false,
    );

    $entry = AuditLog::query()->where('action', 'user.rehire_login_restored')->first();

    expect($entry)->not->toBeNull();
    expect($entry->action)->toBe('user.rehire_login_restored');
    expect((int) $entry->user_id)->toBe($this->actor->id);
    expect($entry->auditable_type)->toBe($former->getMorphClass());
    expect((int) $entry->auditable_id)->toBe($former->id);
    expect($entry->meta['actor_id'])->toBe($this->actor->id);
    expect($entry->meta['employee_profile_id'])->toBe($profile->id);
    expect($former->hasRole('team_lead'))->toBeTrue();
    expect($entry->meta['role'])->toBe('team_lead');
});

test('C3: offboarding login revocation writes a user audit entry', function () {
    $leaver = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $profile = HrEmployeeProfile::factory()->create([
        'user_id' => $leaver->id,
        'is_active' => true,
    ]);

    $checklist = HrOffboardingChecklist::query()->create([
        'employee_profile_id' => $profile->id,
        'template_key' => 'c3-audit-test',
        'status' => 'pending',
        'started_at' => now(),
        'due_date' => now()->addWeek()->toDateString(),
        'created_by' => $this->actor->id,
    ]);
    $task = HrOffboardingTask::query()->create([
        'offboarding_checklist_id' => $checklist->id,
        'category' => 'it',
        'title' => 'Revoke system access',
        'is_required' => true,
        'sort_order' => 1,
        'status' => 'pending',
        'dependency_task_ids' => [],
        'sign_off_required' => false,
    ]);

    expect(auth()->id())->toBeNull();

    app(OnboardingService::class)->completeOffboardingTask($task, $this->actor->id);

    expect($leaver->fresh()->approved_at)->toBeNull();

    $entry = AuditLog::query()->where('action', 'user.login_revoked')->first();

    expect($entry)->not->toBeNull();
    expect($entry->action)->toBe('user.login_revoked');
    expect((int) $entry->user_id)->toBe($this->actor->id);
    expect($entry->auditable_type)->toBe($leaver->getMorphClass());
    expect((int) $entry->auditable_id)->toBe($leaver->id);
    expect($entry->meta['actor_id'])->toBe($this->actor->id);
    expect($entry->meta['reason'])->toBe('offboarding_completed');
    expect($entry->meta['employee_profile_id'])->toBe($profile->id);
});

test('C3: lightweight reactivation audits restored login access', function () {
    $returnTo = '/hr/people?status=inactive';
    $former = User::factory()->create(['role' => 'support_worker', 'approved_at' => null]);
    $profile = HrEmployeeProfile::factory()->create([
        'user_id' => $former->id,
        'is_active' => false,
        'primary_site_id' => $this->site->id,
    ]);

    $this->actingAs($this->actor)
        ->from($returnTo)
        ->patch("/hr/people/{$profile->id}/active", ['is_active' => true])
        ->assertRedirect($returnTo);

    expect($former->fresh()->approved_at)->not->toBeNull();

    $entry = AuditLog::query()->where('action', 'user.login_reactivated')->first();

    expect($entry)->not->toBeNull();
    expect((int) $entry->user_id)->toBe($this->actor->id);
    expect($entry->auditable_type)->toBe($former->getMorphClass());
    expect((int) $entry->auditable_id)->toBe($former->id);
    expect($entry->meta['actor_id'])->toBe($this->actor->id);
    expect($entry->meta['reason'])->toBe('employee_profile_reactivated');
    expect($entry->meta['employee_profile_id'])->toBe($profile->id);
});
