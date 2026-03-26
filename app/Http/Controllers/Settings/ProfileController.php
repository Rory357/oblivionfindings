<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
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
        return Inertia::render('settings/profile', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Update the user's profile settings.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return to_route('profile.edit');
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
