<?php

namespace App\Domain\Obli\Fixes;

use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Obli's screen pictures (D17, pop-up 94). The jpeg the tray sent is the one stored: encrypted, on
 * the private disk, deleted after 7 days by the daily prune (file and row). Opening them needs
 * "See Obli's screen pictures", the computer's Site and a reason; every view is logged (who, when,
 * why) and the view lasts 30 minutes.
 */
final class ObliScreenPictures
{
    public const PERMISSION = 'securityDevices.obli.pictures.view';

    public const DISK = 'private';

    public const MAX_BYTES = 524288;

    /** Anthropic's limits for the computer-use toolset's models: 2,576 px long edge, 4,784 visual tokens. */
    public const MAX_EDGE = 2576;

    public const MAX_TOKENS = 4784;

    /**
     * Check the picture the tray sent: a real jpeg of the size it says, within the limits.
     *
     * @param  array<string, mixed>  $picture
     * @return array{bytes: string, base64: string, width: int, height: int, taken_at: ?CarbonImmutable}
     */
    public static function decode(array $picture): array
    {
        $base64 = $picture['jpeg_base64'] ?? null;
        $width = $picture['width'] ?? null;
        $height = $picture['height'] ?? null;
        if (! is_string($base64) || ! is_int($width) || ! is_int($height) || $width < 100 || $height < 100 || strlen($base64) > (int) ceil(self::MAX_BYTES / 3) * 4 + 4) {
            throw ValidationException::withMessages(['picture' => 'Send the screen picture as a jpeg of up to 512 KB.']);
        }
        $bytes = base64_decode($base64, true);
        if ($bytes === false || strlen($bytes) > self::MAX_BYTES || ! str_starts_with($bytes, "\xFF\xD8\xFF")) {
            throw ValidationException::withMessages(['picture' => 'Send the screen picture as a jpeg of up to 512 KB.']);
        }
        $size = @getimagesizefromstring($bytes);
        if (! is_array($size) || ($size[2] ?? null) !== IMAGETYPE_JPEG || $size[0] !== $width || $size[1] !== $height) {
            throw ValidationException::withMessages(['picture' => 'The picture isn’t the size it says.']);
        }
        if (max($width, $height) > self::MAX_EDGE || ceil($width / 28) * ceil($height / 28) > self::MAX_TOKENS) {
            throw ValidationException::withMessages(['picture' => 'Scale the picture down (at most 2,576 px on the long edge and about 3.7 megapixels).']);
        }
        $taken = null;
        if (is_string($picture['taken_at'] ?? null)) {
            try {
                $taken = CarbonImmutable::parse($picture['taken_at'])->utc();
                if ($taken->isAfter(now()->addMinute()) || $taken->isBefore(now()->subHour())) {
                    $taken = null;
                }
            } catch (\Throwable) {
                $taken = null;
            }
        }

        return ['bytes' => $bytes, 'base64' => base64_encode($bytes), 'width' => $width, 'height' => $height, 'taken_at' => $taken];
    }

    /** Store one picture, encrypted, deleted after 7 days. Returns its id. */
    public function store(object $session, int $turn, array $decoded): int
    {
        $path = 'obli-pictures/'.$session->uuid.'/'.Str::random(40).'.bin';
        if (! Storage::disk(self::DISK)->put($path, Crypt::encryptString($decoded['bytes']))) {
            throw new \RuntimeException('The picture could not be stored.');
        }

        return (int) DB::table('obli_screen_pictures')->insertGetId([
            'session_id' => $session->id, 'turn' => $turn, 'path' => $path, 'width' => $decoded['width'], 'height' => $decoded['height'],
            'bytes' => strlen($decoded['bytes']), 'sha256' => hash('sha256', $decoded['bytes']), 'taken_at' => $decoded['taken_at'],
            'delete_after' => now()->addDays(ObliFixSettings::PICTURE_DAYS), 'created_at' => now(),
        ]);
    }

    public static function canOpen(User $viewer): bool
    {
        return $viewer->canDo(self::PERMISSION);
    }

    /**
     * Pop-up 94: open a session's pictures with a reason. Logs the view and returns the pictures.
     *
     * @return array{view_id: int, expires_at: string, pictures: list<array<string, mixed>>}
     */
    public function open(User $viewer, string $sessionUuid, string $reason): array
    {
        abort_unless(self::canOpen($viewer), 403);
        $reason = trim(preg_replace('/\s+/u', ' ', $reason) ?? '');
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 500 || preg_match('/[\p{Cc}\p{Cf}]/u', $reason)) {
            throw ValidationException::withMessages(['reason' => 'Say why you need to see them (5 to 500 characters).']);
        }

        return DB::transaction(function () use ($viewer, $sessionUuid, $reason): array {
            $session = DB::table('obli_fix_sessions')->where('uuid', $sessionUuid)->lockForUpdate()->first();
            abort_unless($session && $session->device_id, 404);
            $device = Device::query()->find($session->device_id);
            abort_unless($device instanceof Device, 404);
            app(SecurityDevicesAccessService::class)->assertCanViewDevice($viewer, $device);
            $pictures = DB::table('obli_screen_pictures')->where('session_id', $session->id)->where('delete_after', '>', now())->orderBy('turn')->get();
            abort_if($pictures->isEmpty(), 410, 'These pictures have been deleted.');
            $expires = now()->addMinutes(30);
            $viewId = (int) DB::table('obli_picture_views')->insertGetId([
                'session_id' => $session->id, 'user_id' => $viewer->id, 'reason' => $reason, 'pictures' => $pictures->count(),
                'viewed_at' => now(), 'expires_at' => $expires,
            ]);
            AuditLogger::logOrFail('obli.fix.pictures_viewed', null, [
                'actor_id' => (int) $viewer->id, 'session' => $session->uuid, 'device_id' => (int) $session->device_id,
                'site_id' => (int) $session->site_id, 'pictures' => $pictures->count(), 'reason' => $reason,
            ]);

            return [
                'view_id' => $viewId,
                'expires_at' => $expires->toIso8601String(),
                'pictures' => $pictures->map(fn (object $picture): array => [
                    'id' => (int) $picture->id, 'turn' => (int) $picture->turn,
                    'width' => (int) $picture->width, 'height' => (int) $picture->height,
                    'taken_at' => $picture->taken_at ? CarbonImmutable::parse($picture->taken_at)->toIso8601String() : CarbonImmutable::parse($picture->created_at)->toIso8601String(),
                    'url' => '/security-devices/obli/pictures/'.$picture->id.'?view='.$viewId,
                ])->values()->all(),
            ];
        });
    }

    /** One picture, for a view this person opened in the last 30 minutes. Never cached. */
    public function serve(User $viewer, int $pictureId, int $viewId): Response
    {
        abort_unless(self::canOpen($viewer), 403);
        $view = DB::table('obli_picture_views')->where('id', $viewId)->first();
        $picture = DB::table('obli_screen_pictures')->where('id', $pictureId)->first();
        abort_unless($view && $picture && (int) $view->user_id === (int) $viewer->id && (int) $view->session_id === (int) $picture->session_id
            && CarbonImmutable::parse($view->expires_at)->isFuture() && CarbonImmutable::parse($picture->delete_after)->isFuture(), 404);
        $session = DB::table('obli_fix_sessions')->where('id', $picture->session_id)->first();
        $device = $session?->device_id ? Device::query()->find($session->device_id) : null;
        abort_unless($device instanceof Device, 404);
        app(SecurityDevicesAccessService::class)->assertCanViewDevice($viewer, $device);
        $stored = Storage::disk(self::DISK)->get($picture->path);
        abort_unless(is_string($stored), 410);
        $bytes = Crypt::decryptString($stored);

        return response($bytes, 200, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'no-store, private, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline; filename="obli-picture-'.$picture->id.'.jpg"',
        ]);
    }

    /**
     * The daily prune: delete pictures past their 7 days, file and row. The row goes only once the
     * file is gone, so a failed delete is tried again tomorrow rather than forgotten. Then a sweep
     * removes any picture file with no row (a store whose transaction rolled back) older than 8 days.
     * Returns how many went.
     */
    public function prune(int $limit = 500): int
    {
        $disk = Storage::disk(self::DISK);
        $count = 0;
        DB::table('obli_screen_pictures')->where('delete_after', '<=', now())->orderBy('id')->limit($limit)->get(['id', 'path'])
            ->each(function (object $picture) use (&$count, $disk): void {
                if ($disk->exists($picture->path) && (! $disk->delete($picture->path) || $disk->exists($picture->path))) {
                    report(new \RuntimeException('An Obli screen picture past its 7 days could not be deleted; it will be tried again.'));

                    return;
                }
                DB::table('obli_fix_steps')->where('picture_id', $picture->id)->update(['picture_id' => null]);
                DB::table('obli_screen_pictures')->where('id', $picture->id)->delete();
                $count++;
            });

        $cutoff = now()->subDays(ObliFixSettings::PICTURE_DAYS + 1)->getTimestamp();
        $known = null;
        foreach ($disk->allFiles('obli-pictures') as $path) {
            try {
                if ($disk->lastModified($path) > $cutoff) {
                    continue;
                }
            } catch (\Throwable) {
                continue;
            }
            $known ??= DB::table('obli_screen_pictures')->pluck('path')->flip();
            if (! $known->has($path) && $disk->delete($path)) {
                $count++;
            }
        }

        return $count;
    }

    /** Delete every picture of a session now (used when a session row itself is removed). */
    public function deleteForSession(int $sessionId): void
    {
        DB::table('obli_screen_pictures')->where('session_id', $sessionId)->get(['id', 'path'])
            ->each(function (object $picture): void {
                Storage::disk(self::DISK)->delete($picture->path);
                DB::table('obli_screen_pictures')->where('id', $picture->id)->delete();
            });
    }
}
