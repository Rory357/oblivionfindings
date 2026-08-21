<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Services\EmployeeIntakeService;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);
});

function staffCreationSite(string $name, array $overrides = []): Site
{
    return Site::query()->create([
        'name' => $name,
        'is_active' => true,
        'archived' => false,
        'archived_at' => null,
        ...$overrides,
    ]);
}

function staffCreationActor(string $roleName, Site $site, bool $siteBound = false): User
{
    $role = Role::query()->where('name', $roleName)->firstOrFail();
    $actor = User::query()->create([
        'name' => 'Deterministic '.str($roleName)->headline().' Actor',
        'email' => 'staff-creation-'.$roleName.'@example.test',
        'password' => Hash::make('deterministic-test-password'),
        'role' => $roleName,
        'approved_at' => now(),
    ]);
    $actor->roles()->sync([$role->id]);
    HrEmployeeProfile::query()->create([
        'user_id' => $actor->id,
        'employee_number' => 'ACTOR-'.strtoupper($roleName),
        'work_email' => $actor->email,
        'position_title' => (string) str($roleName)->headline(),
        'position_role' => $roleName,
        'employment_type' => 'full_time',
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'start_date' => '2025-01-06',
        'is_active' => true,
        'created_by' => $actor->id,
        'updated_by' => $actor->id,
    ]);

    if ($siteBound) {
        $globalPeople = Permission::query()
            ->where('key', 'hr.employees.viewAllSites')
            ->firstOrFail();
        $actor->permissionOverrides()->syncWithoutDetaching([
            $globalPeople->id => ['allowed' => false],
        ]);
    }

    return $actor->refresh();
}

test('authorised active Sites and grantable roles share the rendered and request scope', function (): void {
    $home = staffCreationSite('Aroha House');
    $second = staffCreationSite('Kowhai House');
    staffCreationSite('Dormant House', ['is_active' => false]);
    staffCreationSite('Archived House', ['archived' => true, 'archived_at' => now()]);
    $admin = staffCreationActor('admin', $home);

    $global = $this->actingAs($admin)->get('/hr/people?create=staff');
    $global->assertOk();
    expect(collect($global->inertiaProps('sites'))->pluck('name'))
        ->toContain('Aroha House', 'Kowhai House')
        ->not->toContain('Dormant House', 'Archived House')
        ->and(collect($global->inertiaProps('formData.roles'))->pluck('value'))
        ->toContain('clinical_lead')
        ->and($global->inertiaProps('creationIntent'))->toBe('staff');

    $globalPeople = Permission::query()
        ->where('key', 'hr.employees.viewAllSites')
        ->firstOrFail();
    $admin->permissionOverrides()->syncWithoutDetaching([
        $globalPeople->id => ['allowed' => false],
    ]);
    // A real follow-up request resolves a new authenticated User object. Use
    // fresh() rather than mutating the original instance so the request-scoped
    // Site access cache cannot reuse the pre-deny object-identity entry.
    $boundedActor = $admin->fresh();
    $bounded = $this->actingAs($boundedActor)->get('/hr/people?create=staff');
    $bounded->assertOk();
    expect(collect($bounded->inertiaProps('sites'))->pluck('id')->all())
        ->toBe([$home->id])
        ->and(collect($bounded->inertiaProps('sites'))->pluck('id'))
        ->not->toContain($second->id)
        ->and(collect($bounded->inertiaProps('formData.roles'))->pluck('value'))
        ->toContain('clinical_lead');
});

test('a deterministic administrator creates exactly one Clinical Lead identity with Site and audit provenance', function (): void {
    $primary = staffCreationSite('Clinical Primary Site');
    $additional = staffCreationSite('Clinical Additional Site');
    $admin = staffCreationActor('admin', $primary);

    $this->actingAs($admin)->post('/hr/people', [
        'name' => 'Casey Clinical',
        'email' => 'CASEY.CLINICAL@EXAMPLE.TEST ',
        'role' => 'clinical_lead',
        'position_title' => 'Clinical Lead',
        'employment_type' => 'full_time',
        'primary_site_id' => $primary->id,
        'secondary_site_ids' => [$additional->id],
        'start_date' => '2026-09-01',
        'start_onboarding' => false,
        'send_invite' => false,
    ])->assertRedirect();

    $account = User::query()->where('email', 'casey.clinical@example.test')->sole();
    $profile = HrEmployeeProfile::query()->where('user_id', $account->id)->sole();
    $audit = AuditLog::query()
        ->where('action', 'user.employee_intake')
        ->where('auditable_type', $account->getMorphClass())
        ->where('auditable_id', $account->id)
        ->sole();

    expect(User::query()->where('email', $account->email)->count())->toBe(1)
        ->and(HrEmployeeProfile::query()->where('user_id', $account->id)->count())->toBe(1)
        ->and($account->role)->toBe('clinical_lead')
        ->and($account->roles()->pluck('name')->all())->toBe(['clinical_lead'])
        ->and($account->approved_at)->not->toBeNull()
        ->and($account->approved_by)->toBe($admin->id)
        ->and($account->hasVerifiedEmail())->toBeFalse()
        ->and($profile->position_role)->toBe('clinical_lead')
        ->and($profile->primary_site_id)->toBe($primary->id)
        ->and($profile->secondary_site_ids)->toBe([$additional->id])
        ->and($profile->created_by)->toBe($admin->id)
        ->and($audit->user_id)->toBe($admin->id)
        ->and($audit->meta['employee_profile_id'])->toBe($profile->id)
        ->and($audit->meta['primary_site_id'])->toBe($primary->id)
        ->and($audit->meta['secondary_site_ids'])->toBe([$additional->id])
        ->and($audit->meta['role'])->toBe('clinical_lead')
        ->and($audit->meta['source'])->toBe('manual')
        ->and($audit->meta['linked_existing_user'])->toBeFalse()
        ->and($audit->meta['invite_requested'])->toBeFalse();
});

test('System Users preserves staff creation intent and converges on the HR People wizard', function (): void {
    $site = staffCreationSite('System Users Convergence Site');
    $admin = staffCreationActor('admin', $site);
    $baselineUserCount = User::query()->count();

    $this->actingAs($admin)
        ->get('/system/users/create?type=staff')
        ->assertRedirect('/hr/people?create=staff');

    $page = $this->actingAs($admin)->get('/system/users/create');
    $page->assertOk();
    expect($page->inertiaProps('can.manageEmployees'))->toBeTrue()
        ->and($page->inertiaProps('staffLifecycleHref'))->toBe('/hr/people?create=staff')
        ->and($page->inertiaProps())->not->toHaveKey('roles');

    $this->actingAs($admin)->post('/system/users', [
        'name' => 'Parallel Staff Attempt',
        'email' => 'parallel.staff.attempt@example.test',
        'password' => 'deterministic-test-password',
        'password_confirmation' => 'deterministic-test-password',
        'user_type' => 'staff',
    ])->assertSessionHasErrors('user_type');

    expect(User::query()->count())->toBe($baselineUserCount)
        ->and(User::query()->where('email', 'parallel.staff.attempt@example.test')->exists())
        ->toBeFalse();
});

test('System Users does not disclose the staff creation intent to an actor without HR authority', function (): void {
    $site = staffCreationSite('System Users Denied Staff Site');
    $settingsActor = staffCreationActor('support_worker', $site);
    $settingsAccess = Permission::query()
        ->where('key', 'settings.access.manage')
        ->firstOrFail();
    $settingsActor->permissionOverrides()->syncWithoutDetaching([
        $settingsAccess->id => ['allowed' => true],
    ]);

    $this->actingAs($settingsActor->refresh())
        ->get('/system/users/create?type=staff')
        ->assertForbidden();

    $page = $this->actingAs($settingsActor->refresh())
        ->get('/system/users/create')
        ->assertOk();
    expect($page->inertiaProps('can.manageEmployees'))->toBeFalse()
        ->and($page->inertiaProps('staffLifecycleHref'))->toBeNull();

    $this->actingAs($settingsActor->refresh())
        ->get('/hr/people?create=staff')
        ->assertForbidden();
});

test('hidden role Site and replay attempts leave no partial employee identity', function (): void {
    $allowed = staffCreationSite('Boundary Allowed Site');
    $hidden = staffCreationSite('Boundary Hidden Site');
    $hr = staffCreationActor('hr', $allowed, siteBound: true);
    $baselineAuditCount = AuditLog::query()->where('action', 'user.employee_intake')->count();
    $page = $this->actingAs($hr)->get('/hr/people')->assertOk();
    expect(collect($page->inertiaProps('formData.roles'))->pluck('value'))
        ->not->toContain('clinical_lead');

    foreach ([
        ['email' => 'blocked-role@example.test', 'role' => 'clinical_lead', 'primary_site_id' => $allowed->id],
        ['email' => 'blocked-primary@example.test', 'role' => 'support_worker', 'primary_site_id' => $hidden->id],
        [
            'email' => 'blocked-secondary@example.test',
            'role' => 'support_worker',
            'primary_site_id' => $allowed->id,
            'secondary_site_ids' => [$hidden->id],
        ],
    ] as $attempt) {
        $response = $this->actingAs($hr)->post('/hr/people', [
            'name' => 'Blocked Employee',
            ...$attempt,
            'start_onboarding' => false,
            'send_invite' => false,
        ]);
        $response->assertSessionHasErrors();
        expect(User::query()->where('email', $attempt['email'])->exists())->toBeFalse();
    }

    expect(AuditLog::query()->where('action', 'user.employee_intake')->count())
        ->toBe($baselineAuditCount);
});

test('a Clinical Lead grant alone cannot bypass the underlying employee management authority', function (): void {
    $site = staffCreationSite('Lesser Permission Site');
    $lesserActor = staffCreationActor('support_worker', $site);
    $clinicalLeadGrant = Permission::query()
        ->where('key', 'hr.employees.assignClinicalLead')
        ->firstOrFail();
    $lesserActor->permissionOverrides()->syncWithoutDetaching([
        $clinicalLeadGrant->id => ['allowed' => true],
    ]);
    $baselineAuditCount = AuditLog::query()->where('action', 'user.employee_intake')->count();

    expect(fn () => app(EmployeeIntakeService::class)->intake(
        name: 'Lesser Permission Clinical Lead',
        email: 'lesser.permission.clinical@example.test',
        roleName: 'clinical_lead',
        profileAttributes: [
            'position_title' => 'Clinical Lead',
            'position_role' => 'clinical_lead',
            'employment_type' => 'full_time',
            'primary_site_id' => $site->id,
            'start_date' => '2026-09-01',
        ],
        actorId: $lesserActor->id,
        startOnboarding: false,
        sendInvite: false,
    ))->toThrow(InvalidArgumentException::class, 'not allowed');

    expect(User::query()->where('email', 'lesser.permission.clinical@example.test')->exists())->toBeFalse()
        ->and(AuditLog::query()->where('action', 'user.employee_intake')->count())
        ->toBe($baselineAuditCount);
});

test('stale Site and role options fail closed without an identity side effect', function (): void {
    $home = staffCreationSite('Stale Option Home Site');
    $staleSite = staffCreationSite('Stale Option Target Site');
    $admin = staffCreationActor('admin', $home);
    $baselineAuditCount = AuditLog::query()->where('action', 'user.employee_intake')->count();

    $page = $this->actingAs($admin)->get('/hr/people')->assertOk();
    expect(collect($page->inertiaProps('sites'))->pluck('id'))->toContain($staleSite->id)
        ->and(collect($page->inertiaProps('formData.roles'))->pluck('value'))
        ->toContain('clinical_lead');

    $staleSite->update(['is_active' => false]);
    $this->actingAs($admin)->post('/hr/people', [
        'name' => 'Stale Site Submission',
        'email' => 'stale.site.submission@example.test',
        'role' => 'clinical_lead',
        'primary_site_id' => $staleSite->id,
    ])->assertSessionHasErrors('primary_site_id');

    $clinicalLeadGrant = Permission::query()
        ->where('key', 'hr.employees.assignClinicalLead')
        ->firstOrFail();
    $admin->permissionOverrides()->syncWithoutDetaching([
        $clinicalLeadGrant->id => ['allowed' => false],
    ]);
    $this->actingAs($admin->refresh())->post('/hr/people', [
        'name' => 'Stale Role Submission',
        'email' => 'stale.role.submission@example.test',
        'role' => 'clinical_lead',
        'primary_site_id' => $home->id,
    ])->assertSessionHasErrors('role');

    expect(User::query()->whereIn('email', [
        'stale.site.submission@example.test',
        'stale.role.submission@example.test',
    ])->exists())->toBeFalse()
        ->and(AuditLog::query()->where('action', 'user.employee_intake')->count())
        ->toBe($baselineAuditCount);
});

test('the canonical intake service rejects a missing Primary Site before creating identity or audit rows', function (): void {
    $site = staffCreationSite('Required Primary Site');
    $admin = staffCreationActor('admin', $site);
    $baselineAuditCount = AuditLog::query()->where('action', 'user.employee_intake')->count();

    expect(fn () => app(EmployeeIntakeService::class)->intake(
        name: 'Missing Site Clinical Lead',
        email: 'missing.site.clinical@example.test',
        roleName: 'clinical_lead',
        profileAttributes: [
            'position_title' => 'Clinical Lead',
            'position_role' => 'clinical_lead',
            'employment_type' => 'full_time',
            'start_date' => '2026-09-01',
        ],
        actorId: $admin->id,
        startOnboarding: false,
        sendInvite: false,
    ))->toThrow(InvalidArgumentException::class, 'Primary Site');

    expect(User::query()->where('email', 'missing.site.clinical@example.test')->exists())->toBeFalse()
        ->and(AuditLog::query()->where('action', 'user.employee_intake')->count())
        ->toBe($baselineAuditCount);
});

test('the canonical intake service revalidates injected foreign Site IDs inside its transaction', function (): void {
    $allowed = staffCreationSite('Direct Service Allowed Site');
    $hidden = staffCreationSite('Direct Service Hidden Site');
    $hr = staffCreationActor('hr', $allowed, siteBound: true);
    $baselineAuditCount = AuditLog::query()->where('action', 'user.employee_intake')->count();

    foreach ([
        ['email' => 'direct.hidden.primary@example.test', 'primary_site_id' => $hidden->id],
        [
            'email' => 'direct.hidden.secondary@example.test',
            'primary_site_id' => $allowed->id,
            'secondary_site_ids' => [$hidden->id],
        ],
    ] as $attempt) {
        expect(fn () => app(EmployeeIntakeService::class)->intake(
            name: 'Direct Hidden Site Attempt',
            email: $attempt['email'],
            roleName: 'support_worker',
            profileAttributes: [
                'position_title' => 'Support Worker',
                'position_role' => 'support_worker',
                'employment_type' => 'full_time',
                'primary_site_id' => $attempt['primary_site_id'],
                'secondary_site_ids' => $attempt['secondary_site_ids'] ?? [],
                'start_date' => '2026-09-01',
            ],
            actorId: $hr->id,
            startOnboarding: false,
            sendInvite: false,
        ))->toThrow(InvalidArgumentException::class, 'unavailable');

        expect(User::query()->where('email', $attempt['email'])->exists())->toBeFalse();
    }

    expect(AuditLog::query()->where('action', 'user.employee_intake')->count())
        ->toBe($baselineAuditCount);
});

test('duplicate and replay handling never duplicates users profiles roles employee numbers or audits', function (): void {
    $site = staffCreationSite('Replay Site');
    $admin = staffCreationActor('admin', $site);
    $legacy = User::query()->create([
        'name' => 'Legacy Number Holder',
        'email' => 'legacy.number.holder@example.test',
        'password' => Hash::make('deterministic-test-password'),
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    $legacyProfile = HrEmployeeProfile::query()->create([
        'user_id' => $legacy->id,
        'employee_number' => 'TEMP-LEGACY-NUMBER',
        'work_email' => $legacy->email,
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'employment_type' => 'full_time',
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'start_date' => '2025-01-06',
        'is_active' => true,
        'created_by' => $admin->id,
        'updated_by' => $admin->id,
    ]);
    $occupiedSequenceNumber = 'EMP-'.str_pad(
        (string) ($legacyProfile->id + 1),
        5,
        '0',
        STR_PAD_LEFT,
    );
    $legacyProfile->update(['employee_number' => $occupiedSequenceNumber]);
    $payload = [
        'name' => 'Replay Clinical Lead',
        'email' => 'replay.clinical@example.test',
        'employee_number' => 'INJECTED-EMPLOYEE-NUMBER',
        'role' => 'clinical_lead',
        'primary_site_id' => $site->id,
        'start_onboarding' => false,
        'send_invite' => false,
    ];

    $this->actingAs($admin)->post('/hr/people', $payload)->assertRedirect();
    $this->actingAs($admin)->post('/hr/people', $payload)->assertSessionHasErrors('email');

    $account = User::query()->where('email', $payload['email'])->sole();
    $profile = HrEmployeeProfile::query()->where('user_id', $account->id)->sole();
    expect(User::query()->where('email', $payload['email'])->count())->toBe(1)
        ->and(HrEmployeeProfile::query()->where('user_id', $account->id)->count())->toBe(1)
        ->and($account->roles()->count())->toBe(1)
        ->and($profile->employee_number)->not->toBe('INJECTED-EMPLOYEE-NUMBER')
        ->and($profile->employee_number)->not->toBe($occupiedSequenceNumber)
        ->and(AuditLog::query()
            ->where('action', 'user.employee_intake')
            ->where('auditable_id', $account->id)
            ->count())->toBe(1);
});

test('the hashed intake mutex serializes concurrent real MySQL transactions', function (): void {
    expect(DB::connection()->getDriverName())->toBe('mysql');
    $base = config('database.connections.mysql');
    config([
        'database.connections.hr_intake_first' => $base,
        'database.connections.hr_intake_second' => $base,
    ]);
    DB::purge('hr_intake_first');
    DB::purge('hr_intake_second');
    $first = DB::connection('hr_intake_first');
    $second = DB::connection('hr_intake_second');
    $keyHash = hash('sha256', 'email:concurrent.clinical@example.test');

    try {
        $first->beginTransaction();
        $first->table('hr_employee_intake_locks')->insertOrIgnore([
            'key_hash' => $keyHash,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $first->table('hr_employee_intake_locks')
            ->where('key_hash', $keyHash)
            ->lockForUpdate()
            ->first();

        $second->statement('SET SESSION innodb_lock_wait_timeout = 1');
        $second->beginTransaction();
        $blocked = null;
        try {
            $second->table('hr_employee_intake_locks')->insertOrIgnore([
                'key_hash' => $keyHash,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException $exception) {
            $blocked = $exception;
        }

        expect($blocked)->toBeInstanceOf(QueryException::class)
            ->and((int) ($blocked?->errorInfo[1] ?? 0))->toBe(1205);
    } finally {
        if ($second->transactionLevel() > 0) {
            $second->rollBack();
        }
        if ($first->transactionLevel() > 0) {
            $first->commit();
        }
        $first->table('hr_employee_intake_locks')->where('key_hash', $keyHash)->delete();
        DB::disconnect('hr_intake_first');
        DB::disconnect('hr_intake_second');
    }
});
