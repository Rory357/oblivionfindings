<?php

namespace App\Domain\CompanyHub\Services;

use App\Domain\CompanyHub\Models\HubMedia;
use App\Domain\CompanyHub\Models\HubPage;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class HubMediaLibrary
{
    public function __construct(private readonly HubAccess $access) {}

    public function assertEditor(User $user): void
    {
        $this->access->assertEnter($user);
        abort_unless($user->canDo('company_hub.write') || $user->canDo('company_hub.edit_all') || $user->canDo('company_hub.layout'), 403);
    }

    public function listing(User $user, ?HubPage $page, array $filters): array
    {
        if ($page) {
            $this->access->assertEdit($user, $page);
        }
        $this->assertEditor($user);
        $pages = HubPage::all()->filter(fn ($source) => $this->access->canEdit($user, $source))->keyBy('id');
        $all = HubMedia::whereIn('hub_page_id', $pages->keys());
        $albumIds = (clone $all)->whereNotNull('album_id')->distinct()->pluck('album_id');
        $albums = DB::table('hub_media_albums')->where(fn ($q) => $q->where('owner_id', $user->id)->orWhereIn('id', $albumIds))->orderBy('name')->get(['id', 'name']);
        $query = clone $all;
        if ($page && ($filters['library'] ?? 'page') === 'page') {
            $query->where('hub_page_id', $page->id);
        }
        if (! empty($filters['album_id'])) {
            $query->where('album_id', $filters['album_id']);
        }
        if (($filters['type'] ?? '') === 'image') {
            $query->where('mime', 'like', 'image/%');
        }
        if (! empty($filters['q'])) {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filters['q']).'%';
            $query->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('alt', 'like', $like));
        }
        $items = $query->latest('id')->paginate(60, ['*'], 'cursor_page', $filters['cursor_page'] ?? 1);

        return [
            'media' => $items->map(fn ($item) => $this->describe($item, $pages[$item->hub_page_id]))->values()->all(),
            'albums' => $albums, 'pages' => $pages->map(fn ($p) => ['id' => $p->id, 'title' => $p->draft['title']])->values()->all(),
            'total' => $items->total(), 'next_page' => $items->hasMorePages() ? $items->currentPage() + 1 : null,
        ];
    }

    public function describe(HubMedia $media, HubPage $page): array
    {
        return [...$media->only(['id', 'hub_page_id', 'name', 'mime', 'size', 'alt', 'album_id', 'version', 'crop']),
            'page_title' => $page->draft['title'], 'url' => '/company-hub/media/'.$media->id];
    }

    public function album(User $user, string $name): int
    {
        $this->assertEditor($user);

        return DB::transaction(function () use ($user, $name): int {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $existing = DB::table('hub_media_albums')->where('owner_id', $user->id)->where('name', $name)->first();
            if ($existing) {
                return $existing->id;
            }
            abort_if(DB::table('hub_media_albums')->where('owner_id', $user->id)->count() >= 100, 422, 'You can keep up to 100 albums.');
            $id = DB::table('hub_media_albums')->insertGetId(['owner_id' => $user->id, 'name' => $name, 'created_at' => now(), 'updated_at' => now()]);
            AuditLog::create(['user_id' => $user->id, 'action' => 'company_hub.media_album_create', 'auditable_type' => User::class, 'auditable_id' => $user->id, 'meta' => ['album_id' => $id]]);

            return $id;
        });
    }

    private function assertAlbum(User $user, ?int $id): void
    {
        if (! $id) {
            return;
        }
        $album = DB::table('hub_media_albums')->find($id);
        abort_unless($album, 422, 'Choose an available album.');
        if ((int) $album->owner_id === (int) $user->id) {
            return;
        }
        $pages = HubPage::whereIn('id', HubMedia::where('album_id', $id)->pluck('hub_page_id'))->get();
        abort_unless($pages->contains(fn ($p) => $this->access->canEdit($user, $p)), 422, 'Choose an available album.');
    }

    public function update(User $user, HubPage $page, HubMedia $media, array $data): HubMedia
    {
        $this->access->assertEdit($user, $page);
        abort_unless((int) $media->hub_page_id === (int) $page->id, 404);
        $this->assertAlbum($user, $data['album_id'] ?? null);

        return DB::transaction(function () use ($user, $media, $data): HubMedia {
            $current = HubMedia::whereKey($media->id)->lockForUpdate()->firstOrFail();
            abort_unless($current->version === (int) $data['version'], 409, 'Another editor changed this image. Reload the library before saving.');
            $current->update(['name' => $data['name'], 'alt' => array_key_exists('alt', $data) ? ($data['alt'] ?? '') : $current->alt,
                'album_id' => array_key_exists('album_id', $data) ? $data['album_id'] : $current->album_id, 'version' => $current->version + 1]);
            $this->audit($user, $current->id, 'details', ['version' => $current->version]);

            return $current;
        });
    }

    public function crop(User $user, HubPage $page, HubMedia $media, array $data): HubMedia
    {
        $this->access->assertEdit($user, $page);
        abort_unless((int) $media->hub_page_id === (int) $page->id, 404);

        return DB::transaction(function () use ($user, $page, $media, $data): HubMedia {
            $source = HubMedia::whereKey($media->id)->lockForUpdate()->firstOrFail();
            abort_unless($source->version === (int) $data['version'], 409, 'Another editor changed this image. Reload the library before cropping.');
            abort_unless(in_array($source->mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true), 422, 'Choose an image to crop.');
            $disk = Storage::disk('private');
            abort_unless($disk->exists($source->path), 404);
            abort_if($disk->size($source->path) > 10485760, 422, 'Choose an image up to 10 MB for cropping.');
            abort_unless(function_exists('imagecreatefromstring'), 503, 'Image cropping is temporarily unavailable.');
            $bytes = $disk->get($source->path);
            $size = @getimagesizefromstring($bytes);
            abort_unless($size && $size[0] > 0 && $size[1] > 0 && $size[0] * $size[1] <= 16000000, 422, 'Choose an image up to 16 megapixels for cropping.');
            $image = @imagecreatefromstring($bytes);
            abort_unless($image, 422, 'This image could not be opened. Upload another copy.');
            $canvas = null;
            try {
                // Browsers apply EXIF orientation before their object-fit preview.
                if ($source->mime === 'image/jpeg' && function_exists('exif_read_data')) {
                    $orientation = (int) ((@exif_read_data($disk->path($source->path), 'IFD0'))['Orientation'] ?? 1);
                    if (in_array($orientation, [2, 5, 7], true)) {
                        imageflip($image, IMG_FLIP_HORIZONTAL);
                    }
                    if ($orientation === 4) {
                        imageflip($image, IMG_FLIP_VERTICAL);
                    }
                    $angle = match ($orientation) {
                        3 => 180, 5, 8 => 90, 6, 7 => -90, default => 0
                    };
                    if ($angle) {
                        $rotated = imagerotate($image, $angle, 0);
                        abort_unless($rotated, 422, 'This image could not be rotated.');
                        imagedestroy($image);
                        $image = $rotated;
                    }
                }
                [$rw, $rh] = array_map('intval', explode(':', $data['ratio']));
                $ratio = $rw / $rh;
                $iw = imagesx($image);
                $ih = imagesy($image);
                $cw = min($iw, $ih * $ratio);
                $ch = $cw / $ratio;
                $width = max(1, (int) round(min(1600, $cw)));
                $height = max(1, (int) round($width / $ratio));
                $canvas = imagecreatetruecolor($width, $height);
                imagealphablending($canvas, false);
                imagesavealpha($canvas, true);
                imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
                imagecopyresampled($canvas, $image, 0, 0,
                    (int) round(($iw - $cw) * $data['x'] / 100), (int) round(($ih - $ch) * $data['y'] / 100),
                    $width, $height, max(1, (int) round($cw)), max(1, (int) round($ch)));
                ob_start();
                try {
                    $written = imagepng($canvas, null, 6);
                    $result = ob_get_contents();
                } finally {
                    ob_end_clean();
                }
                abort_unless($written && is_string($result), 500, 'The crop could not be saved.');
                $path = 'company-hub/'.$page->id.'/'.Str::uuid().'.png';
                abort_unless($disk->put($path, $result), 500, 'The crop could not be saved.');
                try {
                    $copy = HubMedia::create(['hub_page_id' => $page->id, 'uploaded_by' => $user->id, 'path' => $path,
                        'name' => Str::limit(pathinfo($source->name, PATHINFO_FILENAME), 130, '').' — cropped.png',
                        'mime' => 'image/png', 'size' => strlen($result), 'alt' => $source->alt, 'album_id' => $source->album_id,
                        'source_media_id' => $source->id, 'crop' => ['ratio' => $data['ratio'], 'x' => $data['x'], 'y' => $data['y']], 'version' => 1]);
                    $this->audit($user, $copy->id, 'crop_copy', ['source_id' => $source->id]);
                } catch (\Throwable $exception) {
                    $disk->delete($path);
                    throw $exception;
                }

                return $copy;
            } finally {
                imagedestroy($image);
                if ($canvas) {
                    imagedestroy($canvas);
                }
            }
        });
    }

    public function audit(User $user, int $id, string $action, array $meta): void
    {
        AuditLog::create(['user_id' => $user->id, 'action' => 'company_hub.media_'.$action, 'auditable_type' => HubMedia::class, 'auditable_id' => $id, 'meta' => $meta]);
    }
}
