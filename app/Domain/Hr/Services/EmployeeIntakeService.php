<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrOffboardingChecklist;
use App\Domain\Hr\Models\HrOnboardingChecklist;
use App\Http\Controllers\Hr\EmployeeProfileController;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * The single source of truth for creating an employee (`User` +
 * `HrEmployeeProfile`). Both the manual Add-Employee modal
 * ({@see EmployeeProfileController::store}) and the
 * recruitment offer→convert flow
 * ({@see RecruitmentService::convertToEmployee}) call
 * {@see self::intake()} so the two doors can never diverge, double-create, or
 * skip onboarding/invites.
 */
class EmployeeIntakeService
{
    /**
     * External portal personas — never assignable through employee intake
     * (they are not staff; their accounts are provisioned by the portal flows).
     */
    private const EXTERNAL_PERSONA_ROLES = ['client', 'next_of_kin'];

    /**
     * RBAC level at/above which a role is system-administrator grade
     * (RbacSeeder: admin = 100; execs/board sit below).
     */
    private const ADMIN_LEVEL_THRESHOLD = 100;

    public function __construct(
        private readonly OnboardingService $onboarding,
        private readonly HrWebhookService $webhooks,
        private readonly PeopleMutationLockService $mutationLocks,
    ) {}

    /**
     * D-2 privilege-escalation guard for both intake doors: an admin-grade role
     * can only be assigned by an actor who already holds admin, and external
     * portal personas can never be minted as employees. Throws the same
     * exception type the intake callers already surface as a flash.
     *
     * @throws \InvalidArgumentException
     */
    private function assertRoleAssignable(?string $roleName, int $actorId, ?User $actor = null): void
    {
        if ($roleName === null || $roleName === '') {
            return;
        }

        if (in_array($roleName, self::EXTERNAL_PERSONA_ROLES, true)) {
            throw new \InvalidArgumentException(
                "The '{$roleName}' role is an external portal persona and cannot be assigned through employee intake."
            );
        }

        $role = Role::query()->where('name', $roleName)->first();
        $isAdminGrade = $roleName === 'admin'
            || ($role && (int) ($role->level ?? 0) >= self::ADMIN_LEVEL_THRESHOLD);

        if (! $isAdminGrade) {
            return;
        }

        $actor ??= User::find($actorId);
        if (! $actor || ! ($actor->role === 'admin' || $actor->hasRole('admin'))) {
            throw new \InvalidArgumentException(
                'Only an administrator can assign an administrator-level role.'
            );
        }
    }

    /**
     * Existing logins may only be linked after the caller has proved their
     * canonical profile or recruitment provenance. Privileged and external
     * personas are never repurposed through employee intake.
     */
    private function assertExistingAccountCompatible(User $user, string $roleName): void
    {
        if ($user->permissionOverrides()->exists()) {
            throw new \InvalidArgumentException('This existing login cannot be linked through employee intake.');
        }

        $roleNames = collect([$user->role, ...$user->roles()->pluck('roles.name')->all()])
            ->filter()
            ->unique();

        if ($roleNames->intersect(self::EXTERNAL_PERSONA_ROLES)->isNotEmpty()) {
            throw new \InvalidArgumentException('This existing login cannot be linked through employee intake.');
        }

        $hasAdminGradeRole = Role::query()
            ->whereIn('name', $roleNames)
            ->where(function ($query): void {
                $query->where('name', 'admin')
                    ->orWhere('level', '>=', self::ADMIN_LEVEL_THRESHOLD);
            })
            ->exists();

        $unexpectedRoles = $roleNames->reject(fn (string $existingRole) => $existingRole === $roleName);

        if ($hasAdminGradeRole || $unexpectedRoles->isNotEmpty()) {
            throw new \InvalidArgumentException('This existing login cannot be linked through employee intake.');
        }
    }

    /**
     * Create (or link to) the user and upsert their single employee profile.
     *
     * The User + profile write is atomic (one transaction). Side-effects —
     * onboarding, the invite, the domain event — run AFTER the commit and are
     * each best-effort: a failing checklist template, mail outage, or webhook
     * must never roll back or block a hire.
     *
     * Resolves the user by email — so a person who already exists (e.g. a
     * candidate-created account) is linked and updated rather than duplicated or
     * rejected. `hr_employee_profiles.user_id` is UNIQUE, so the profile is a
     * true upsert keyed on the user.
     *
     * @param  array<string, mixed>  $profileAttributes  resolved hr_employee_profiles columns
     */
    public function intake(
        string $name,
        string $email,
        string $roleName,
        array $profileAttributes,
        int $actorId,
        bool $startOnboarding = true,
        bool $sendInvite = false,
        string $source = 'manual',
        ?int $authorizedExistingUserId = null,
    ): HrEmployeeProfile {
        $resolvedExistingUserId = User::query()->where('email', $email)->value('id');

        /** @var array{user: User, profile: HrEmployeeProfile, linkedExisting: bool} $written */
        $written = DB::transaction(function () use (
            $name,
            $email,
            $roleName,
            $profileAttributes,
            $actorId,
            $authorizedExistingUserId,
            $resolvedExistingUserId,
        ) {
            $locks = $this->mutationLocks->lock(
                [$actorId, $authorizedExistingUserId, $resolvedExistingUserId],
            );
            $actor = $locks['users']->get($actorId);
            abort_unless($actor, 403);
            $this->assertRoleAssignable($roleName, $actorId, $actor);

            // 1. Resolve the user by email — link an existing account instead of
            //    erroring/duplicating; create one otherwise.
            $user = $resolvedExistingUserId
                ? $locks['users']->get((int) $resolvedExistingUserId)
                : null;
            if ($user && $user->email !== $email) {
                throw new \InvalidArgumentException('This existing email cannot be linked through employee intake.');
            }
            $linkedExisting = $user !== null;

            if ($user) {
                if ((int) $user->id !== (int) $authorizedExistingUserId) {
                    throw new \InvalidArgumentException('This existing email cannot be linked through employee intake.');
                }
                $this->assertExistingAccountCompatible($user, $roleName);
            } else {
                $user = User::query()->create([
                    'name' => $name,
                    'email' => $email,
                    'role' => $roleName,
                    'password' => bcrypt(Str::random(40)),
                    'approved_at' => now(),
                    'approved_by' => $actorId,
                ]);
            }

            // Back-fill role/approval on a pre-existing account.
            $updates = [];
            if (! $user->role) {
                $updates['role'] = $roleName;
            }
            if (! $user->approved_at) {
                $updates['approved_at'] = now();
                $updates['approved_by'] = $actorId;
            }
            if ($updates !== []) {
                $user->forceFill($updates)->save();
            }

            $role = Role::query()->where('name', $roleName)->first();
            if ($role) {
                $user->roles()->sync([$role->id]);
            }

            // 2. Upsert the single profile per user (user_id is UNIQUE). Only
            //    stamp employee_number / created_by on first creation.
            $existing = $locks['profiles']->first(
                fn (HrEmployeeProfile $lockedProfile) => (int) $lockedProfile->user_id === (int) $user->id,
            );

            $values = array_merge($profileAttributes, [
                'work_email' => $profileAttributes['work_email'] ?? $email,
                'is_active' => true,
                'updated_by' => $actorId,
            ]);
            if (! $existing) {
                $values['employee_number'] = $this->generateEmployeeNumber();
                $values['created_by'] = $actorId;
            }

            if ($existing) {
                abort_if($existing->trashed(), 404);
                $existing->fill($values)->save();
                $profile = $existing;
            } else {
                $profile = HrEmployeeProfile::query()->create([
                    'user_id' => $user->id,
                    ...$values,
                ]);
            }

            return ['user' => $user, 'profile' => $profile, 'linkedExisting' => $linkedExisting];
        }, attempts: 3);

        $user = $written['user'];
        $profile = $written['profile'];

        DB::afterCommit(function () use ($actorId, $profile, $roleName, $sendInvite, $source, $startOnboarding, $user, $written): void {
            // --- Best-effort side-effects (post-commit; never block the hire) ---

            // D-3: the USER write (account minted/linked, role set, login approved) is
            // audited explicitly — User deliberately doesn't carry AuditableChanges
            // (that would log every login-token touch). AuditLogger never throws,
            // and uses actor_id when no HTTP request user exists.
            AuditLogger::log('user.employee_intake', $user, [
                'actor_id' => $actorId,
                'source' => $source,
                'linked_existing_user' => $written['linkedExisting'],
                'role' => $roleName,
                'approved' => (bool) $user->approved_at,
            ]);

            // 3. Onboarding parity (toggle; idempotent).
            if ($startOnboarding) {
                $this->maybeGenerateOnboarding($profile, $actorId);
            }

            // 3b. Seed the compliance DISPLAY matrix for the new hire (audit fix
            // round 2). Rostering hard-stops are already live-checked at assign
            // time (LiveComplianceValidator) — this only materialises the
            // hr_staff_compliance_status rows so the person isn't invisible on
            // /hr/compliance until the nightly EvaluateComplianceMatrixJob runs.
            try {
                app(ComplianceMatrixService::class)->evaluateStaff($user);
            } catch (\Throwable $e) {
                Log::warning('Compliance matrix seed failed for new hire.', [
                    'employee_profile_id' => $profile->id,
                    'error' => $e->getMessage(),
                ]);
            }

            // 4. One invite path — the password-reset link doubles as "set your
            //    password & first login". Shared by both doors.
            if ($sendInvite) {
                try {
                    Password::broker()->sendResetLink(['email' => $user->email]);
                } catch (\Throwable $e) {
                    Log::warning('Employee intake invite failed.', [
                        'email' => $user->email,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // 5. Consistent domain signal regardless of source.
            try {
                $this->webhooks->publishApplicationEvent('employee.created', [
                    'employee_profile_id' => $profile->id,
                    'user_id' => $user->id,
                    'source' => $source,
                    'linked_existing_user' => $written['linkedExisting'],
                ]);
            } catch (\Throwable $e) {
                Log::warning('employee.created webhook publish failed.', [
                    'employee_profile_id' => $profile->id,
                    'error' => $e->getMessage(),
                ]);
            }
        });

        // Return the committed model (no extra query — side-effects don't touch
        // the profile's own columns).
        return $profile;
    }

    /**
     * Re-hire a former employee onto their existing profile — the ONE door for
     * bringing a leaver back (the row-menu "Reactivate" stays the light undo;
     * this is the full welcome-back workflow).
     *
     * Archives the outgoing stint into `employment_history`, reactivates the
     * profile onto the new engagement, restores the login (approved_at + RBAC
     * role pivot), and re-runs the intake side-effects: an optional invite
     * (same password-reset link as intake) and a FRESH onboarding checklist
     * for the new stint.
     *
     * @param  array<string, mixed>  $attributes  new-engagement fields; `start_date` is required
     */
    public function rehire(
        HrEmployeeProfile $profile,
        array $attributes,
        int $actorId,
        bool $sendInvite = true,
        bool $startOnboarding = true,
    ): HrEmployeeProfile {
        if ($profile->is_active) {
            throw new \InvalidArgumentException('Only an inactive (former) employee profile can be re-hired.');
        }
        if (empty($attributes['start_date'])) {
            throw new \InvalidArgumentException('A new start date is required to re-hire.');
        }

        // Resolve once so validation, the RBAC write, and the audit record all
        // describe the same role even when the legacy users.role value differs.
        $roleName = $attributes['position_role'] ?? $profile->position_role ?? $profile->user?->role;

        // Same D-2 guard as intake, on the role this re-hire would restore/attach
        // (step 3 below re-syncs the RBAC pivot for the resolved role).
        $this->assertRoleAssignable($roleName, $actorId);

        $newStart = Carbon::parse($attributes['start_date'])->startOfDay();

        $profileId = (int) $profile->id;
        $profileUserId = (int) $profile->user_id;
        $profile = DB::transaction(function () use ($profileId, $profileUserId, $attributes, $actorId, $newStart, &$roleName) {
            $locks = $this->mutationLocks->lock([$actorId, $profileUserId], [$profileId]);
            $actor = $locks['users']->get($actorId);
            abort_unless($actor, 403);
            $profileUser = $locks['users']->get($profileUserId);
            $profile = $locks['profiles']->get($profileId);
            abort_unless($profile, 404);
            abort_unless($profileUser && (int) $profile->user_id === $profileUserId, 404);
            if ($profile->is_active) {
                throw new \InvalidArgumentException('Only an inactive (former) employee profile can be re-hired.');
            }
            if ($profile->trashed()) {
                $profile->restore();
            }
            $profile->setRelation('user', $profileUser);
            $roleName = $attributes['position_role'] ?? $profile->position_role ?? $profileUser?->role;
            $this->assertRoleAssignable($roleName, $actorId, $actor);

            // 0. Close out any leaver workflow still open from the previous
            //    stint — rehiring supersedes it, and leaving it open would
            //    strand a live checklist whose completion revokes the login
            //    this rehire is about to restore.
            HrOffboardingChecklist::query()
                ->where('employee_profile_id', $profile->id)
                ->whereIn('status', ['pending', 'in_progress'])
                ->get()
                ->each(fn ($checklist) => $checklist->update([
                    'status' => 'cancelled',
                    'completed_at' => null,
                ]));

            // 1. Archive the outgoing stint (append-only history).
            $history = $profile->employment_history ?? [];
            $history[] = [
                'start_date' => $profile->start_date?->toDateString(),
                'end_date' => $profile->end_date?->toDateString(),
                'position_title' => $profile->position_title,
                'position_role' => $profile->position_role,
                'employment_type' => $profile->employment_type,
                'archived_at' => now()->toIso8601String(),
            ];

            // 2. Reactivate onto the new engagement.
            $profile->fill(Arr::only($attributes, [
                'position_title',
                'position_role',
                'position_id',
                'employment_type',
                'contract_type',
                'primary_site_id',
                'hours_per_week',
                'department',
                'department_id',
                'manager_user_id',
                'probation_end_date',
            ]));
            $profile->forceFill([
                'employment_history' => $history,
                'is_active' => true,
                'start_date' => $newStart->toDateString(),
                'end_date' => null,
                'termination_reason' => null,
                'updated_by' => $actorId,
            ])->save();

            // 3. Restore login + RBAC role pivot (offboarding revokes approval).
            $user = $profile->user;
            if ($user) {
                if (! $user->approved_at) {
                    $user->forceFill([
                        'approved_at' => now(),
                        'approved_by' => $actorId,
                    ])->save();
                }

                if ($roleName) {
                    if ($user->role !== $roleName) {
                        $user->forceFill(['role' => $roleName])->save();
                    }
                    $role = Role::query()->where('name', $roleName)->first();
                    if ($role) {
                        $user->roles()->sync([$role->id]);
                    }
                }
            }

            return $profile;
        }, attempts: 3);

        DB::afterCommit(function () use ($actorId, $newStart, $profile, $roleName, $sendInvite, $startOnboarding): void {
            // --- Best-effort side-effects (post-commit; never block the re-hire) ---

            // D-3: the login restore (approved_at + role pivot back on) is a user
            // write — audit it like intake does.
            if ($profile->user) {
                AuditLogger::log('user.rehire_login_restored', $profile->user, [
                    'actor_id' => $actorId,
                    'employee_profile_id' => $profile->id,
                    'role' => $roleName,
                    'start_date' => $newStart->toDateString(),
                ]);
            }

            if ($startOnboarding) {
                $this->maybeGenerateOnboarding($profile, $actorId, $newStart);
            }

            if ($sendInvite && $profile->user) {
                try {
                    Password::broker()->sendResetLink(['email' => $profile->user->email]);
                } catch (\Throwable $e) {
                    Log::warning('Re-hire invite failed.', [
                        'email' => $profile->user->email,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            try {
                $this->webhooks->publishApplicationEvent('employee.rehired', [
                    'employee_profile_id' => $profile->id,
                    'user_id' => $profile->user_id,
                    'start_date' => $newStart->toDateString(),
                ]);
            } catch (\Throwable $e) {
                Log::warning('employee.rehired webhook publish failed.', [
                    'employee_profile_id' => $profile->id,
                    'error' => $e->getMessage(),
                ]);
            }
        });

        return $profile;
    }

    /**
     * Generate the onboarding checklist once. Idempotent and fully best-effort:
     * a missing template, mail outage, or any other failure is logged and
     * swallowed so onboarding config can never block a hire.
     *
     * First hire ($stintStart null): skips if ANY checklist ever existed.
     * Re-hire ($stintStart given): a fresh checklist is generated for the new
     * stint — skipped only when one already belongs to this stint (started on
     * or after the new start date) or an open one (pending / in progress) is
     * still live.
     */
    public function maybeGenerateOnboarding(HrEmployeeProfile $profile, int $actorId, ?Carbon $stintStart = null): void
    {
        try {
            $blocking = HrOnboardingChecklist::query()
                ->where('employee_profile_id', $profile->id)
                ->when($stintStart !== null, fn ($query) => $query->where(function ($inner) use ($stintStart) {
                    $inner->where('started_at', '>=', $stintStart->copy()->startOfDay())
                        ->orWhereIn('status', ['pending', 'in_progress']);
                }))
                ->exists();
            if ($blocking) {
                return;
            }

            $this->onboarding->generateChecklist($profile, $actorId);
        } catch (\Throwable $e) {
            Log::warning('Onboarding checklist not generated for new hire.', [
                'employee_profile_id' => $profile->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** Next sequential employee number, e.g. EMP-00042. */
    public function generateEmployeeNumber(): string
    {
        $next = (int) (HrEmployeeProfile::withTrashed()->max('id') ?? 0) + 1;

        return 'EMP-'.str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    }
}
