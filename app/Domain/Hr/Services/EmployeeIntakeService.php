<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrOnboardingChecklist;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * The single source of truth for creating an employee (`User` +
 * `HrEmployeeProfile`). Both the manual Add-Employee modal
 * ({@see \App\Http\Controllers\Hr\EmployeeProfileController::store}) and the
 * recruitment offer→convert flow
 * ({@see \App\Domain\Hr\Services\RecruitmentService::convertToEmployee}) call
 * {@see self::intake()} so the two doors can never diverge, double-create, or
 * skip onboarding/invites.
 */
class EmployeeIntakeService
{
    public function __construct(
        private readonly OnboardingService $onboarding,
        private readonly HrWebhookService $webhooks,
    ) {}

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
        int $tenantId,
        bool $startOnboarding = true,
        bool $sendInvite = false,
        string $source = 'manual',
    ): HrEmployeeProfile {
        /** @var array{user: User, profile: HrEmployeeProfile, linkedExisting: bool} $written */
        $written = DB::transaction(function () use (
            $name,
            $email,
            $roleName,
            $profileAttributes,
            $actorId,
            $tenantId,
        ) {
            // 1. Resolve the user by email — link an existing account instead of
            //    erroring/duplicating; create one otherwise.
            $user = User::query()->firstOrCreate(
                ['email' => $email],
                [
                    'name' => $name,
                    'role' => $roleName,
                    'password' => bcrypt(Str::random(40)),
                    'approved_at' => now(),
                    'approved_by' => $actorId,
                ],
            );
            $linkedExisting = ! $user->wasRecentlyCreated;

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
                $user->roles()->syncWithoutDetaching([$role->id]);
            }

            // 2. Upsert the single profile per user (user_id is UNIQUE). Only
            //    stamp employee_number / created_by on first creation.
            $existing = HrEmployeeProfile::query()
                ->where('user_id', $user->id)
                ->first();

            $values = array_merge($profileAttributes, [
                'tenant_id' => $tenantId,
                'work_email' => $profileAttributes['work_email'] ?? $email,
                'is_active' => true,
                'updated_by' => $actorId,
            ]);
            if (! $existing) {
                $values['employee_number'] = $this->generateEmployeeNumber();
                $values['created_by'] = $actorId;
            }

            $profile = HrEmployeeProfile::query()->updateOrCreate(
                ['user_id' => $user->id],
                $values,
            );

            return ['user' => $user, 'profile' => $profile, 'linkedExisting' => $linkedExisting];
        });

        $user = $written['user'];
        $profile = $written['profile'];

        // --- Best-effort side-effects (post-commit; never block the hire) ---

        // 3. Onboarding parity (toggle; idempotent).
        if ($startOnboarding) {
            $this->maybeGenerateOnboarding($profile, $actorId);
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
            $this->webhooks->publish($tenantId, 'employee.created', [
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

        // Return the committed model (no extra query — side-effects don't touch
        // the profile's own columns).
        return $profile;
    }

    /**
     * Generate the onboarding checklist once. Idempotent (skips if one exists)
     * and fully best-effort: a missing template, mail outage, or any other
     * failure is logged and swallowed so onboarding config can never block a
     * hire.
     */
    public function maybeGenerateOnboarding(HrEmployeeProfile $profile, int $actorId): void
    {
        try {
            $alreadyHasChecklist = HrOnboardingChecklist::query()
                ->where('employee_profile_id', $profile->id)
                ->exists();
            if ($alreadyHasChecklist) {
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

        return 'EMP-' . str_pad((string) $next, 5, '0', STR_PAD_LEFT);
    }
}
