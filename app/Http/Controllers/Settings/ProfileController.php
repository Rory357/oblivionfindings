<?php

namespace App\Http\Controllers\Settings;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        $user = $request->user();
        $user->loadMissing([
            'roles:id,name,label,landing_route',
        ]);
        $employeeProfile = $this->currentEmployeeProfile($user);

        $roleLabels = $user->roles
            ->map(fn ($role) => $role->label ?: str($role->name)->replace('_', ' ')->title()->toString())
            ->values()
            ->all();

        // Build the landing-page dropdown from the roles assigned to this user.
        // Each option lists one role's landing_route. "System default" is
        // always available — falls through to LoginResponse's hierarchy.
        $landingRoutesConfig = (array) config('landing_routes', []);
        $landingOptions = $user->roles
            ->filter(fn ($role) => filled($role->landing_route) && isset($landingRoutesConfig[$role->landing_route]))
            ->map(fn ($role) => [
                'key' => (string) $role->landing_route,
                'label' => (string) ($landingRoutesConfig[$role->landing_route]['label'] ?? $role->landing_route),
                'role_label' => $role->label ?: $role->name,
            ])
            ->unique('key')
            ->values()
            ->all();

        return Inertia::render('settings/profile', [
            'mustVerifyEmail' => $user instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
            'profile' => [
                'phone' => $user->cellphone ?? $employeeProfile?->work_phone,
                'jobTitle' => $employeeProfile?->position_title,
                'timezone' => $user->timezone ?? 'Pacific/Auckland',
                'locale' => $user->locale ?? 'en',
                'dateFormat' => $user->date_format ?? 'DD/MM/YYYY',
                'timeFormat' => $user->time_format ?? '24',
                'landingRoutePreference' => $user->landing_route_preference,
                'landingOptions' => $landingOptions,
                'emailVerifiedAt' => $this->serializeDateTime($user->email_verified_at),
                'createdAt' => $this->serializeDateTime($user->created_at),
                'updatedAt' => $this->serializeDateTime($user->updated_at),
                'lastLoginAt' => $this->serializeDateTime(data_get($user, 'last_login_at')),
                'passwordChangedAt' => $this->serializeDateTime(data_get($user, 'password_changed_at')),
                'roles' => $roleLabels,
                'twoFactorEnabled' => $user->two_factor_confirmed_at !== null,
                'microsoftLinked' => false,
                'googleLinked' => false,
                'profilePhotoPath' => $user->profile_photo_path,
            ],
        ]);
    }

    /**
     * Update the user's preferred landing page. Accepts a key from the keys
     * their roles expose (or null to clear / "system default"). Validated
     * against config('landing_routes') so a user can't opt into a route that
     * no longer exists.
     */
    public function updateLanding(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user, 403);

        $allowedKeys = array_keys((array) config('landing_routes', []));

        $data = $request->validate([
            'landing_route_preference' => [
                'nullable',
                'string',
                function (string $attribute, mixed $value, \Closure $fail) use ($user, $allowedKeys) {
                    if (! in_array($value, $allowedKeys, true)) {
                        $fail('That landing page is no longer available.');
                        return;
                    }
                    // Confirm the user has a role that offers this landing.
                    $userRoleKeys = $user->roles()->pluck('landing_route')->filter()->unique();
                    if (! $userRoleKeys->contains($value)) {
                        $fail('None of your roles are configured for that landing page.');
                    }
                },
            ],
        ]);

        $user->forceFill([
            'landing_route_preference' => $data['landing_route_preference'] ?? null,
        ])->save();

        return back()->with('success', 'Landing page preference updated.');
    }

    /**
     * Update the user's profile settings.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $user = $request->user();
        $employeeProfile = $this->currentEmployeeProfile($user);

        if ($request->jobTitleWasSubmitted() && filled($validated['job_title']) && ! $employeeProfile) {
            throw ValidationException::withMessages([
                'job_title' => 'Employment details must be created or restored in HR People before a job title can be changed.',
            ]);
        }

        $userAttributes = $this->filterExistingUserColumns([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'cellphone' => $validated['phone'],
            'timezone' => $validated['timezone'],
            'locale' => $validated['locale'],
            'date_format' => $validated['date_format'],
            'time_format' => $validated['time_format'],
        ]);

        DB::transaction(function () use ($employeeProfile, $request, $user, $userAttributes, $validated): void {
            $user->fill($userAttributes);

            if (
                array_key_exists('email', $userAttributes)
                && $user->isDirty('email')
                && Schema::hasColumn('users', 'email_verified_at')
            ) {
                $user->email_verified_at = null;
            }

            $user->save();

            if (! $employeeProfile) {
                return;
            }

            $profileAttributes = [
                'work_email' => $user->email,
                'updated_by' => $user->id,
            ];

            if ($request->phoneWasSubmitted()) {
                $profileAttributes['work_phone'] = $validated['phone'];
            }

            if ($request->jobTitleWasSubmitted()) {
                $profileAttributes['position_title'] = $validated['job_title'];
            }

            $employeeProfile->forceFill($profileAttributes)->save();
        });

        return to_route('profile.edit');
    }

    private function serializeDateTime(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }

        return filled($value) ? (string) $value : null;
    }

    /**
     * Filter profile attributes down to columns the current users table can persist.
     *
     * This keeps the profile page from 500ing when local environments are behind
     * on optional profile-preferences migrations.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function filterExistingUserColumns(array $attributes): array
    {
        return collect($attributes)
            ->filter(fn (mixed $value, string $column): bool => Schema::hasColumn('users', $column))
            ->all();
    }

    private function currentEmployeeProfile(?User $user): ?HrEmployeeProfile
    {
        if (! $user) {
            return null;
        }

        $query = HrEmployeeProfile::query()->where('user_id', $user->id);
        app(UserSiteAccessService::class)->applyCurrentStaffProfileScope(
            $query,
            $user,
            ['sites.viewAll'],
        );

        return $query->first();
    }



    /**
     * Update the user's profile photo.
     */
    public function updatePhoto(Request $request): RedirectResponse
    {
        $request->validate([
            'photo' => ['required', 'image', 'max:5120'], // 5MB
        ]);

        $user = $request->user();
        abort_unless($user, 403);

        $path = $this->storeAvatar($request->file('photo'), 'profile-photos/users');

        if ($user->profile_photo_path) {
            Storage::disk('public')->delete($user->profile_photo_path);
        }

        $user->forceFill(['profile_photo_path' => $path])->save();

        return back()->with('success', 'Profile photo updated.');
    }

    /**
     * Remove the user's profile photo.
     */
    public function destroyPhoto(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user, 403);

        if ($user->profile_photo_path) {
            Storage::disk('public')->delete($user->profile_photo_path);
        }

        $user->forceFill(['profile_photo_path' => null])->save();

        return back()->with('success', 'Profile photo removed.');
    }

    /**
     * Store a square-cropped avatar (center crop) and resize to 512x512.
     */
    private function storeAvatar(UploadedFile $file, string $dir): string
    {
        try {
            $data = file_get_contents($file->getRealPath());
            $src = @imagecreatefromstring($data);
            if (!$src) {
                throw new \RuntimeException('Unable to read image');
            }

            $w = imagesx($src);
            $h = imagesy($src);
            $size = min($w, $h);
            $x = (int) floor(($w - $size) / 2);
            $y = (int) floor(($h - $size) / 2);

            $crop = imagecrop($src, ['x' => $x, 'y' => $y, 'width' => $size, 'height' => $size]);
            if (!$crop) {
                $crop = $src;
            }

            $dst = imagecreatetruecolor(512, 512);
            // white background for transparent PNGs
            $white = imagecolorallocate($dst, 255, 255, 255);
            imagefill($dst, 0, 0, $white);

            imagecopyresampled($dst, $crop, 0, 0, 0, 0, 512, 512, imagesx($crop), imagesy($crop));

            ob_start();
            imagejpeg($dst, null, 85);
            $jpg = ob_get_clean();

            imagedestroy($dst);
            if ($crop !== $src) {
                imagedestroy($crop);
            }
            imagedestroy($src);

            $filename = trim($dir, '/') . '/' . Str::uuid()->toString() . '.jpg';
            Storage::disk('public')->put($filename, $jpg);

            return $filename;
        } catch (\Throwable $e) {
            // Fallback: store original if GD isn't available
            return $file->storePublicly($dir, 'public');
        }
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        if (HrEmployeeProfile::withTrashed()->where('user_id', $user->id)->exists()) {
            throw ValidationException::withMessages([
                'password' => 'Staff accounts must be offboarded in HR People so employment history and Site provenance are retained.',
            ]);
        }

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
