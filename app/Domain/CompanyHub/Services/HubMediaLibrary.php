<?php

namespace App\Domain\CompanyHub\Services;

use App\Domain\CompanyHub\Models\HubMedia;
use App\Domain\CompanyHub\Models\HubPage;
use App\Domain\Hr\Services\HrCurrentStaffService;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use ZipArchive;

class HubMediaLibrary
{
    public const MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp', 'application/pdf' => 'pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
    ];

    public function __construct(private readonly HubAccess $access) {}

    public static function previewable(string $mime): bool
    {
        return $mime === 'application/pdf' || in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true);
    }

    /** Office packages are download-only, macro-free OOXML, never arbitrary ZIPs. */
    public function uploadMime(UploadedFile $file): string
    {
        Validator::make(['file' => $file], ['file' => 'required|file|max:10240'])->validate();
        $extension = strtolower($file->getClientOriginalExtension());
        $office = [
            'docx' => ['word/document.xml', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml'],
            'xlsx' => ['xl/workbook.xml', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml'],
            'pptx' => ['ppt/presentation.xml', 'application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml'],
        ];
        if (! isset($office[$extension])) {
            $mime = (string) $file->getMimeType();
            if (in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf'], true)) {
                return $mime;
            }
            throw ValidationException::withMessages(['file' => 'Choose a PDF, DOCX, XLSX, PPTX, JPG, PNG, WebP or GIF file up to 10 MB.']);
        }
        if (! class_exists(ZipArchive::class)) {
            throw ValidationException::withMessages(['file' => 'Office uploads are unavailable until ZIP support is installed. PDF and image uploads remain available.']);
        }
        $zip = new ZipArchive;
        $valid = $zip->open($file->getRealPath()) === true;
        if ($valid) {
            try {
                $valid = $zip->numFiles <= 2000 && $zip->locateName($office[$extension][0]) !== false && $zip->locateName('_rels/.rels') !== false;
                $total = 0;
                for ($index = 0; $valid && $index < $zip->numFiles; $index++) {
                    $entry = $zip->statIndex($index);
                    $name = str_replace('\\', '/', strtolower((string) ($entry['name'] ?? '')));
                    $total += (int) ($entry['size'] ?? 0);
                    $valid = $entry !== false && $total <= 100 * 1024 * 1024
                        && ! preg_match('~(^/|(^|/)\.\.(/|$)|vbaproject|/activex/|/embeddings/)~', $name)
                        && empty($entry['encryption_method']);
                }
                $types = $zip->getFromName('[Content_Types].xml', 512 * 1024);
                $valid = $valid && is_string($types) && ! preg_match('/<!DOCTYPE|<!ENTITY/i', $types)
                    && ! preg_match('/macroEnabled|vbaProject|activeX|oleObject/i', $types);
                if ($valid) {
                    $previousErrors = libxml_use_internal_errors(true);
                    try {
                        $xml = new \DOMDocument;
                        $valid = $xml->loadXML($types, LIBXML_NONET);
                        if ($valid) {
                            $xpath = new \DOMXPath($xml);
                            $xpath->registerNamespace('ct', 'http://schemas.openxmlformats.org/package/2006/content-types');
                            $part = '/'.$office[$extension][0];
                            $valid = $xpath->query('/ct:Types/ct:Override[@PartName="'.$part.'" and @ContentType="'.$office[$extension][1].'"]')->length === 1;
                        }
                    } finally {
                        libxml_clear_errors();
                        libxml_use_internal_errors($previousErrors);
                    }
                }
            } finally {
                $zip->close();
            }
        }
        if (! $valid) {
            throw ValidationException::withMessages(['file' => 'Choose a valid, unencrypted DOCX, XLSX or PPTX file without macros or embedded programs, up to 10 MB.']);
        }

        return array_search($extension, self::MIME_EXTENSIONS, true);
    }

    public function upload(User $user, HubPage $page, UploadedFile $file): HubMedia
    {
        $user = $this->editorFor($user, $page);
        $mime = $this->uploadMime($file);
        $path = $file->storeAs('company-hub/'.$page->id, Str::uuid().'.'.self::MIME_EXTENSIONS[$mime], 'private');
        abort_unless($path, 500, 'The file could not be stored. Try again.');
        try {
            return HubMedia::create(['hub_page_id' => $page->id, 'uploaded_by' => $user->id, 'path' => $path,
                'name' => Str::limit(preg_replace('/[\x00-\x1f\x7f\/\\\\]/u', ' ', $file->getClientOriginalName()), 160, ''),
                'mime' => $mime, 'size' => $file->getSize(), 'alt' => '', 'version' => 1])->fresh();
        } catch (\Throwable $exception) {
            Storage::disk('private')->delete($path);
            throw $exception;
        }
    }

    public function ready(): bool
    {
        return Schema::hasColumns('hub_media', ['caption', 'focal_x', 'focal_y', 'removed_at', 'removed_by']);
    }

    private function actor(User $user): User
    {
        $current = $user->fresh();
        abort_unless($current, 404);

        return $current;
    }

    private function currentAccess(): HubAccess
    {
        return new HubAccess(app(HrCurrentStaffService::class), new UserSiteAccessService);
    }

    /** Library state is not an access grant. Always check the current page. */
    public function editorFor(User $user, HubPage $page, ?HubMedia $media = null): User
    {
        $user = $this->actor($user);
        $page = HubPage::findOrFail($page->id);
        $this->currentAccess()->assertEdit($user, $page);
        if ($media) {
            abort_unless((int) HubMedia::findOrFail($media->id)->hub_page_id === (int) $page->id, 404);
        }

        return $user;
    }

    private function removed(HubMedia $media): bool
    {
        return $media->getAttribute('removed_at') !== null;
    }

    private function copyMetadata(HubMedia $media, bool $cropped = false): array
    {
        return $this->ready() ? ['caption' => $media->caption ?? '', 'focal_x' => $cropped ? 50 : ($media->focal_x ?? 50), 'focal_y' => $cropped ? 50 : ($media->focal_y ?? 50)] : [];
    }

    public function assertEditor(User $user): void
    {
        $user = $this->actor($user);
        $this->currentAccess()->assertEnter($user);
        abort_unless($user->canDo('company_hub.write') || $user->canDo('company_hub.edit_all') || $user->canDo('company_hub.layout'), 403);
    }

    public function listing(User $user, ?HubPage $page, array $filters): array
    {
        $user = $this->actor($user);
        $access = $this->currentAccess();
        if ($page) {
            $access->assertEdit($user, HubPage::findOrFail($page->id));
        }
        $this->assertEditor($user);
        $ready = $this->ready();
        $state = $filters['state'] ?? 'active';
        $pages = HubPage::all()->filter(fn ($source) => $access->canEdit($user, $source))->keyBy('id');
        $all = HubMedia::whereIn('hub_page_id', $pages->keys());
        if ($ready) {
            $state === 'removed' ? $all->whereNotNull('removed_at') : $all->whereNull('removed_at');
        } elseif ($state === 'removed') {
            $all->whereRaw('1 = 0');
        }
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
        } elseif (($filters['type'] ?? '') === 'file') {
            $query->whereIn('mime', array_values(array_diff(array_keys(self::MIME_EXTENSIONS), ['image/jpeg', 'image/png', 'image/webp', 'image/gif'])));
        }
        if (! empty($filters['q'])) {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filters['q']).'%';
            $query->where(function ($q) use ($like, $ready): void {
                $q->where('name', 'like', $like)->orWhere('alt', 'like', $like);
                if ($ready) {
                    $q->orWhere('caption', 'like', $like);
                }
            });
        }
        $items = $query->latest('id')->paginate(60, ['*'], 'cursor_page', $filters['cursor_page'] ?? 1);

        return [
            'media' => $items->map(fn ($item) => $this->describe($item, $pages[$item->hub_page_id], $ready))->values()->all(),
            'albums' => $albums, 'pages' => $pages->map(fn ($p) => ['id' => $p->id, 'title' => $p->draft['title']])->values()->all(),
            'total' => $items->total(), 'next_page' => $items->hasMorePages() ? $items->currentPage() + 1 : null,
            'actor_id' => (int) $user->id, 'state' => $state, 'metadata_enabled' => $ready, 'recovery_enabled' => $ready,
        ];
    }

    public function describe(HubMedia $media, HubPage $page, ?bool $ready = null): array
    {
        $ready ??= $this->ready();
        $removed = $ready && $this->removed($media);

        return [...$media->only(['id', 'hub_page_id', 'name', 'mime', 'size', 'alt', 'album_id', 'version', 'crop']),
            'caption' => $ready ? ($media->caption ?? '') : '', 'focal_x' => $ready ? ($media->focal_x ?? 50) : 50, 'focal_y' => $ready ? ($media->focal_y ?? 50) : 50,
            'removed_at' => $removed ? $media->removed_at->toIso8601String() : null,
            'can_remove' => $ready && ! $removed, 'can_restore' => $removed && Storage::disk('private')->exists($media->path),
            'page_title' => $page->draft['title'], 'url' => '/company-hub/media/'.$media->id,
            'document_id' => $media->documentId(), 'document_file_version' => $media->documentSequence(),
            'reader_url' => '/company-hub/documents/'.$media->documentId(),
            'download_url' => '/company-hub/media/'.$media->id.'?download=1',
            'preview_url' => self::previewable($media->mime) ? '/company-hub/media/'.$media->id.'/preview' : null];
    }

    /** Copy a canonical private asset only between pages this actor can edit. */
    public function reuse(User $user, HubPage $page, HubMedia $media, ?int $version = null, bool $copy = false, bool $fromSnapshot = false): HubMedia
    {
        $user = $this->actor($user);
        $path = null;
        try {
            return DB::transaction(function () use ($user, $page, $media, $version, $copy, $fromSnapshot, &$path): HubMedia {
                $user = $this->editorFor($user, $page);
                $source = HubMedia::whereKey($media->id)->lockForUpdate()->firstOrFail();
                $this->currentAccess()->assertEdit($user, HubPage::findOrFail($source->hub_page_id));
                abort_if($version !== null && $source->version !== $version, 409, 'This file changed. Refresh the library before selecting it.');
                // A full authorised authored/release snapshot may retain an old
                // use; a direct library selection must restore the item first.
                abort_if($this->removed($source) && ! $fromSnapshot, 409, 'This file was removed from the library. Restore it before using it again.');
                if ((int) $source->hub_page_id === (int) $page->id && ! $copy) {
                    return $source;
                }
                $extension = self::MIME_EXTENSIONS[$source->mime] ?? null;
                $disk = Storage::disk('private');
                abort_unless($extension && $disk->exists($source->path), 404);
                $path = 'company-hub/'.$page->id.'/'.Str::uuid().'.'.$extension;
                abort_unless($disk->copy($source->path, $path), 500, 'The file could not be copied. Try again.');

                return HubMedia::create(['hub_page_id' => $page->id, 'uploaded_by' => $user->id, 'path' => $path,
                    'name' => $copy ? Str::limit(pathinfo($source->name, PATHINFO_FILENAME), 140, '').' — copy.'.$extension : $source->name,
                    'mime' => $source->mime, 'size' => $source->size, 'alt' => $source->alt, 'album_id' => $source->album_id,
                    'source_media_id' => $source->id, 'crop' => $source->crop, 'version' => 1, ...$this->copyMetadata($source)]);
            });
        } catch (\Throwable $exception) {
            if ($path) {
                Storage::disk('private')->delete($path);
            }
            throw $exception;
        }
    }

    public function album(User $user, string $name): int
    {
        $user = $this->actor($user);
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
        abort_unless($pages->contains(fn ($p) => $this->currentAccess()->canEdit($user, $p)), 422, 'Choose an available album.');
    }

    public function update(User $user, HubPage $page, HubMedia $media, array $data): HubMedia
    {
        $user = $this->editorFor($user, $page, $media);
        $data = Validator::make($data, [
            'name' => ['required', 'string', 'max:160', 'not_regex:/[\x00-\x1f\/\\\\]/'],
            'version' => 'required|integer|min:1', 'actor_id' => 'sometimes|integer|min:1',
            'alt' => 'sometimes|nullable|string|max:300', 'album_id' => 'sometimes|nullable|integer|min:1',
            'caption' => 'sometimes|nullable|string|max:2000',
            'focal_x' => 'sometimes|required|integer|min:0|max:100', 'focal_y' => 'sometimes|required|integer|min:0|max:100',
        ])->validate();

        return DB::transaction(function () use ($user, $page, $media, $data): HubMedia {
            $current = HubMedia::whereKey($media->id)->lockForUpdate()->firstOrFail();
            $user = $this->editorFor($user, $page, $current);
            abort_if(isset($data['actor_id']) && (int) $data['actor_id'] !== (int) $user->id, 409, 'Your signed-in account changed. Reload the library before saving.');
            $this->assertAlbum($user, $data['album_id'] ?? null);
            abort_unless($current->version === (int) $data['version'], 409, 'Another editor changed this image. Reload the library before saving.');
            $extra = array_intersect_key($data, array_flip(['caption', 'focal_x', 'focal_y']));
            if ($extra !== []) {
                if (! $this->ready()) {
                    throw ValidationException::withMessages(['caption' => 'Image details are not available until the media update is installed.']);
                }
                if (! str_starts_with($current->mime, 'image/')) {
                    throw ValidationException::withMessages(['caption' => 'Captions and focal position belong to an image.']);
                }
                if (array_key_exists('caption', $extra)) {
                    $extra['caption'] = $extra['caption'] ?? '';
                }
            }
            $current->update([...$extra, 'name' => $data['name'], 'alt' => array_key_exists('alt', $data) ? ($data['alt'] ?? '') : $current->alt,
                'album_id' => array_key_exists('album_id', $data) ? $data['album_id'] : $current->album_id, 'version' => $current->version + 1]);
            $this->audit($user, $current->id, 'details', ['version' => $current->version]);

            return $current;
        });
    }

    public function crop(User $user, HubPage $page, HubMedia $media, array $data): HubMedia
    {
        $user = $this->editorFor($user, $page, $media);

        return DB::transaction(function () use ($user, $page, $media, $data): HubMedia {
            $source = HubMedia::whereKey($media->id)->lockForUpdate()->firstOrFail();
            $user = $this->editorFor($user, $page, $source);
            abort_unless($source->version === (int) $data['version'], 409, 'Another editor changed this image. Reload the library before cropping.');
            abort_if($this->removed($source), 409, 'This image was removed from the library. Restore it before cropping.');
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
                        'source_media_id' => $source->id, 'crop' => ['ratio' => $data['ratio'], 'x' => $data['x'], 'y' => $data['y']], 'version' => 1, ...$this->copyMetadata($source, cropped: true)]);
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

    public function availability(User $user, HubPage $page, HubMedia $media, array $data): HubMedia
    {
        $user = $this->editorFor($user, $page, $media);
        $data = Validator::make($data, ['actor_id' => 'required|integer|min:1', 'version' => 'required|integer|min:1', 'action' => 'required|in:remove,restore'])->validate();
        if (! $this->ready()) {
            throw ValidationException::withMessages(['action' => 'Media recovery is not available until the media update is installed.']);
        }

        return DB::transaction(function () use ($user, $page, $media, $data): HubMedia {
            $page = HubPage::whereKey($page->id)->lockForUpdate()->firstOrFail();
            $current = HubMedia::whereKey($media->id)->lockForUpdate()->firstOrFail();
            $user = $this->editorFor($user, $page, $current);
            abort_unless((int) $data['actor_id'] === (int) $user->id, 409, 'Your signed-in account changed. Reload the library before continuing.');
            abort_unless((int) $data['version'] === (int) $current->version, 409, 'This file changed. Refresh the library before continuing.');
            $restore = $data['action'] === 'restore';
            abort_unless($restore === $this->removed($current), 409, 'The library availability changed. Refresh it before continuing.');
            abort_if($restore && ! Storage::disk('private')->exists($current->path), 422, 'This file is missing. It cannot be restored until its private file is recovered.');
            $current->update(['removed_at' => $restore ? null : now(), 'removed_by' => $restore ? null : $user->id, 'version' => $current->version + 1]);
            $this->audit($user, $current->id, $data['action'], ['version' => $current->version]);

            return $current;
        });
    }

    /** Removal changes library discovery, never the authorised file reader. */
    public function fileFor(User $user, HubMedia $media): HubMedia
    {
        $user = $this->actor($user);
        $media = HubMedia::findOrFail($media->id);
        $page = HubPage::findOrFail($media->hub_page_id);
        $access = $this->currentAccess();
        $access->assertEnter($user);
        if (! $access->canEdit($user, $page)) {
            $content = $access->assertRead($user, $page)->content;
            $content = HubFeatured::readerContent(HubContent::readerContent($content), app(HubFeatured::class)->stories($user), $page->kind === 'department' ? ($content['team_ids'] ?? []) : null);
            $referenced = HubContent::visibleBlocks($content)->pluck('media_id')->push($content['cover_id'] ?? null)->filter()->map(fn ($id) => (int) $id);
            abort_unless($referenced->contains((int) $media->id), 404);
        }

        return $media;
    }

    public function audit(User $user, int $id, string $action, array $meta): void
    {
        AuditLog::create(['user_id' => $user->id, 'action' => 'company_hub.media_'.$action, 'auditable_type' => HubMedia::class, 'auditable_id' => $id, 'meta' => $meta]);
    }
}
