<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Models\Staff;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
            'roles:id,name,label',
            'staffProfile:id,user_id,job_title,mobile_phone',
        ]);

        $roles = $user->roles
            ->map(fn ($role) => $role->label ?: str($role->name)->replace('_', ' ')->title()->toString())
            ->values()
            ->all();

        return Inertia::render('settings/profile', [
            'mustVerifyEmail' => $user instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
            'profile' => [
                'phone' => $user->cellphone ?? $user->staffProfile?->mobile_phone,
                'jobTitle' => $user->staffProfile?->job_title,
                'timezone' => $user->timezone ?? 'Pacific/Auckland',
                'dateFormat' => $user->date_format ?? 'DD/MM/YYYY',
                'timeFormat' => $user->time_format ?? '24',
                'emailVerifiedAt' => $this->serializeDateTime($user->email_verified_at),
                'createdAt' => $this->serializeDateTime($user->created_at),
                'updatedAt' => $this->serializeDateTime($user->updated_at),
                'lastLoginAt' => $this->serializeDateTime(data_get($user, 'last_login_at')),
                'passwordChangedAt' => $this->serializeDateTime(data_get($user, 'password_changed_at')),
                'roles' => $roles,
                'twoFactorEnabled' => $user->two_factor_confirmed_at !== null,
                'microsoftLinked' => false,
                'googleLinked' => false,
                'profilePhotoPath' => $user->profile_photo_path,
            ],
        ]);
    }

    /**
     * Update the user's profile settings.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $user = $request->user();

        $userAttributes = $this->filterExistingUserColumns([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'cellphone' => $validated['phone'],
            'timezone' => $validated['timezone'],
            'date_format' => $validated['date_format'],
            'time_format' => $validated['time_format'],
        ]);

        $user->fill($userAttributes);

        if (
            array_key_exists('email', $userAttributes)
            && $user->isDirty('email')
            && Schema::hasColumn('users', 'email_verified_at')
        ) {
            $user->email_verified_at = null;
        }

        $user->save();

        $staffAttributes = [
            'job_title' => $validated['job_title'],
            'mobile_phone' => $validated['phone'],
        ];

        if (
            $user->staffProfile()->exists()
            || filled($staffAttributes['job_title'])
            || filled($staffAttributes['mobile_phone'])
        ) {
            Staff::query()->updateOrCreate(
                ['user_id' => $user->id],
                $staffAttributes,
            );
        }

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

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
