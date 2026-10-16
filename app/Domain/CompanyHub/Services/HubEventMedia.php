<?php

namespace App\Domain\CompanyHub\Services;

use App\Domain\CompanyHub\Models\HubEventDraft;
use App\Domain\CompanyHub\Models\HubMedia;
use App\Domain\CompanyHub\Models\HubPage;
use App\Domain\Hr\Models\HrCalendarEvent;
use App\Domain\Hr\Models\HrCalendarEventAttachment;
use App\Domain\Hr\Services\HrCalendarAccessService;
use App\Domain\Hr\Services\HrCurrentStaffService;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Event-owned file copies and photo metadata; a library reference is never a reader grant. */
class HubEventMedia
{
    public const MAX_ID = 2147483647;

    public const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    private const DETAIL_LIMITS = ['title' => 160, 'alt' => 300, 'caption' => 2000, 'album' => 80];

    public function rules(bool $draft = false): array
    {
        return [
            'actor_id' => ['nullable', 'required_with:hub_library_images', 'integer', 'min:1', 'max:'.self::MAX_ID],
            'hub_library_images' => ['sometimes', 'array', 'max:10', $this->listRule()],
            'hub_library_images.*' => ['array:media_id,version'],
            'hub_library_images.*.media_id' => ['required', 'integer', 'distinct', 'min:1', 'max:'.self::MAX_ID],
            'hub_library_images.*.version' => ['required', 'integer', 'min:1', 'max:'.self::MAX_ID],
            'hub_content.cover_ref' => ['nullable', ...$this->referenceRules($draft)],
            'hub_content.gallery_refs' => ['sometimes', 'array', 'max:12', $this->listRule()],
            'hub_content.gallery_refs.*' => ['distinct', ...$this->referenceRules($draft)],
            'hub_content.recap_title' => ['sometimes', 'nullable', 'string', 'max:160'],
            'hub_content.photo_details' => ['sometimes', 'array', 'max:100', $this->listRule()],
            'hub_content.photo_details.*' => ['array:ref,title,alt,caption,album'],
            'hub_content.photo_details.*.ref' => ['required', 'distinct', ...$this->referenceRules($draft)],
            ...collect(self::DETAIL_LIMITS)->mapWithKeys(fn ($limit, $field) => [
                'hub_content.photo_details.*.'.$field => ['sometimes', 'nullable', 'string', 'max:'.$limit],
            ])->all(),
        ];
    }

    private function listRule(): \Closure
    {
        return function (string $field, mixed $value, \Closure $fail): void {
            if (is_array($value) && ! array_is_list($value)) {
                $fail('Use an ordered list of files.');
            }
        };
    }

    public function referenceRules(bool $draft = false): array
    {
        return ['string', function (string $field, mixed $ref, \Closure $fail) use ($draft): void {
            if (! self::validReference($ref, $draft)) {
                $fail('Choose a bounded photo reference belonging to this event.');
            }
        }];
    }

    public function assertBatchLimit(int $uploads, array $selections): void
    {
        if ($uploads + count($selections) > 10) {
            throw ValidationException::withMessages(['hub_library_images' => 'Choose up to 10 new files in this save, including library files.']);
        }
    }

    public static function validReference(mixed $ref, bool $draft = false): bool
    {
        if (! is_string($ref) || ! preg_match('/^(file|upload|library):(-?[0-9]{1,10})$/D', $ref, $parts)) {
            return false;
        }
        $value = (int) $parts[2];

        return match ($parts[1]) {
            'upload' => $value >= 0 && $value < 10 && $parts[2] === (string) $value,
            'library' => $value > 0 && $value <= self::MAX_ID && $parts[2] === (string) $value,
            'file' => $value !== 0 && abs($value) <= self::MAX_ID && ($draft || $value > 0) && $parts[2] === (string) $value,
        };
    }

    public static function details(array $details): array
    {
        return collect(self::DETAIL_LIMITS)->mapWithKeys(fn ($limit, $field) => [
            $field => is_string($details[$field] ?? null) ? mb_substr(trim($details[$field]), 0, $limit) : '',
        ])->all();
    }

    /** Transform every authored photo reference, including metadata, using the same ownership map. */
    public static function mapReferences(array $content, callable $resolve): array
    {
        foreach (['cover_ref'] as $field) {
            if (array_key_exists($field, $content)) {
                $content[$field] = $resolve($content[$field]);
            }
        }
        if (array_key_exists('gallery_refs', $content)) {
            $content['gallery_refs'] = array_map($resolve, $content['gallery_refs']);
        }
        if (array_key_exists('photo_details', $content)) {
            $content['photo_details'] = array_map(fn ($row) => [...$row, 'ref' => $resolve($row['ref'])], $content['photo_details']);
        }

        return $content;
    }

    /** Copy defaults are snapshots. Explicit authored details take precedence, including empty strings. */
    public static function materialiseContent(array $content, array $copies): array
    {
        $rows = [];
        foreach ($copies as $sourceId => $copy) {
            if (isset($copy['details'])) {
                $rows['library:'.$sourceId] = ['ref' => 'library:'.$sourceId, ...$copy['details']];
            }
        }
        foreach ($content['photo_details'] ?? [] as $row) {
            $rows[$row['ref']] = [...($rows[$row['ref']] ?? []), ...$row];
        }
        if ($rows !== [] || array_key_exists('photo_details', $content)) {
            $content['photo_details'] = array_values($rows);
        }

        return self::mapReferences($content, function (?string $ref) use ($copies): ?string {
            if (! str_starts_with($ref ?? '', 'library:')) {
                return $ref;
            }
            $copy = $copies[(int) substr($ref, 8)] ?? null;
            if (! $copy) {
                throw ValidationException::withMessages(['hub_library_images' => 'Choose a versioned library image before using it in this event.']);
            }

            return $copy['ref'];
        });
    }

    public static function copyDetails(array $details, array $map, array $retained = []): array
    {
        $result = [];
        foreach ($details as $id => $row) {
            $target = $map[$id] ?? (in_array((int) $id, $retained, true) ? (int) $id : null);
            if ($target && is_array($row)) {
                $result[$target] = self::details($row);
            }
        }

        return $result;
    }

    public static function editorDetails(array $details, Collection $photos): array
    {
        return collect($details)->filter(fn ($row, $id) => $photos->has($id) && is_array($row))
            ->map(fn ($row, $id) => ['ref' => 'file:'.$id, ...self::details($row)])->values()->all();
    }

    /** Keep omitted additive fields in saved drafts; removed old references do not block file removal. */
    public static function mergeDraftContent(array $content, array $previous, callable $retained): array
    {
        if (! array_key_exists('recap_title', $content) && array_key_exists('recap_title', $previous)) {
            $content['recap_title'] = $previous['recap_title'];
        }
        if (array_key_exists('recap_title', $content)) {
            $content['recap_title'] = trim($content['recap_title'] ?? '');
        }
        $rows = [];
        foreach ($previous['photo_details'] ?? [] as $row) {
            if ($retained($row['ref'])) {
                $rows[$row['ref']] = $row;
            }
        }
        foreach ($content['photo_details'] ?? [] as $row) {
            $rows[$row['ref']] = [...($rows[$row['ref']] ?? []), ...$row];
        }
        if ($rows !== [] || array_key_exists('photo_details', $content) || array_key_exists('photo_details', $previous)) {
            self::assertDetailCount($rows);
            $content['photo_details'] = array_values(array_map(fn ($row) => ['ref' => $row['ref'], ...self::details($row)], $rows));
        }

        return $content;
    }

    /** Metadata edits patch retained photos, so older clients cannot erase newer descriptions. */
    public static function storedDetails(array $input, array $previous, array $allowedIds, callable $resolve, array $copied = []): array
    {
        $details = self::copyDetails($previous['photo_details'] ?? [], $copied, $allowedIds);
        foreach ($input['photo_details'] ?? [] as $index => $row) {
            $id = $resolve($row['ref'], 'hub_content.photo_details.'.$index.'.ref');
            $changes = array_intersect_key($row, self::DETAIL_LIMITS);
            $details[$id] = self::details([...($details[$id] ?? []), ...$changes]);
        }
        self::assertDetailCount($details);

        return $details;
    }

    private static function assertDetailCount(array $details): void
    {
        if (count($details) > 100) {
            throw ValidationException::withMessages(['hub_content.photo_details' => 'Keep details for up to 100 attached photos.']);
        }
    }

    public static function orderedPhotos(array $ids, Collection $photos): Collection
    {
        return collect($ids)->unique()->map(fn ($id) => $photos->get($id))->filter()->values();
    }

    private function actor(User $user): User
    {
        $actor = $user->fresh();
        abort_unless($actor && app(HrCurrentStaffService::class)->isCurrent($actor), 404);
        (new HubAccess(app(HrCurrentStaffService::class), new UserSiteAccessService))->assertEnter($actor);

        return $actor;
    }

    private function copySources(User $actor, array $selections, string $folder, callable $attach, string $targetType, int $targetId, array &$written): array
    {
        $copies = [];
        foreach ($selections as $selection) {
            $sourcePageId = HubMedia::findOrFail($selection['media_id'])->hub_page_id;
            $sourcePage = HubPage::whereKey($sourcePageId)->lockForUpdate()->firstOrFail();
            $source = HubMedia::whereKey($selection['media_id'])->lockForUpdate()->firstOrFail();
            abort_unless((int) $source->hub_page_id === (int) $sourcePage->id, 409, 'This library file moved. Refresh the library before choosing it.');
            $access = new HubAccess(app(HrCurrentStaffService::class), new UserSiteAccessService);
            $access->assertEdit($actor, $sourcePage);
            abort_unless((int) $source->version === (int) $selection['version'], 409, 'This file changed. Refresh the library before choosing it.');
            $attributes = $source->getAttributes();
            abort_if(($attributes['removed_at'] ?? null) !== null, 409, 'Restore this file in the library before using it again.');
            $extension = HubMediaLibrary::MIME_EXTENSIONS[$source->mime] ?? null;
            abort_unless($extension, 422, 'Choose a PDF, DOCX, XLSX, PPTX or image from the Hub library.');
            $disk = Storage::disk('private');
            abort_unless($disk->exists($source->path), 404);
            abort_if($disk->size($source->path) > 10485760, 422, 'Choose a file up to 10 MB.');
            // Display names may be edited. Verify the actual bytes using the canonical
            // MIME's package extension, never trust a renamed filename or stored MIME alone.
            $candidate = new UploadedFile($disk->path($source->path), 'library-copy.'.$extension, null, null, true);
            try {
                $mime = app(HubMediaLibrary::class)->uploadMime($candidate);
            } catch (ValidationException $error) {
                throw ValidationException::withMessages(['hub_library_images' => $error->validator->errors()->first()]);
            }
            abort_unless($mime === $source->mime, 422, 'This library file no longer matches its recorded type. Upload a valid replacement before reusing it.');
            $path = $folder.'/'.Str::uuid().'.'.$extension;
            $written[] = $targetType === HubEventDraft::class ? $path : ['private', $path];
            abort_unless($disk->copy($source->path, $path), 500, 'The file could not be copied. Your event changes were not saved.');
            $file = $attach(['path' => $path, 'original_name' => mb_substr($source->name, 0, 160), 'mime' => $source->mime, 'size' => $disk->size($path)]);
            $copy = ['ref' => 'file:'.($targetType === HubEventDraft::class ? -$file->id : $file->id)];
            if (in_array($source->mime, self::IMAGE_TYPES, true)) {
                $album = $source->album_id ? DB::table('hub_media_albums')->where('id', $source->album_id)->value('name') : '';
                $copy['details'] = self::details(['title' => $source->name, 'alt' => $attributes['alt'] ?? '', 'caption' => $attributes['caption'] ?? '', 'album' => $album]);
            }
            $copies[(int) $source->id] = $copy;
            AuditLog::create(['user_id' => $actor->id, 'action' => 'hub.event_media.reused', 'auditable_type' => $targetType, 'auditable_id' => $targetId,
                'meta' => ['source_media_id' => $source->id, 'source_version' => $source->version, 'attachment_id' => $file->id]]);
        }

        return $copies;
    }

    /** Caller holds the destination transaction and compensates every path if a later operation fails. */
    public function copyToDraft(User $user, HubEventDraft $draft, array $selections, array &$written): array
    {
        if ($selections === []) {
            return [];
        }
        $actor = $this->actor($user);
        Validator::make(['actor_id' => $actor->id, 'hub_library_images' => $selections], $this->rules(true))->validate();
        $draft = HubEventDraft::findOrFail($draft->id);
        app(HubEventPublishing::class)->assertEdit($actor, $draft);
        abort_if($draft->archived_at || ! in_array($draft->state, ['draft', 'changes_requested'], true), 409, 'Withdraw review or cancel scheduling before editing files.');
        abort_if($draft->files()->where('retained', true)->count() + count($selections) > 10, 422, 'Keep up to 10 draft files.');

        return $this->copySources($actor, $selections, 'company-hub/event-drafts/'.$draft->id,
            fn ($data) => $draft->files()->create($data), HubEventDraft::class, (int) $draft->id, $written);
    }

    public function copyToEvent(User $user, HrCalendarEvent $event, array $selections, array &$written): array
    {
        if ($selections === []) {
            return [];
        }
        $actor = $this->actor($user);
        Validator::make(['actor_id' => $actor->id, 'hub_library_images' => $selections], $this->rules())->validate();
        $event = HrCalendarEvent::findOrFail($event->id);
        app(HrCalendarAccessService::class)->assertCanManageEvent($actor, $event);
        abort_if($event->archived_at || $event->recurrenceParent?->archived_at, 404);
        $root = $event->recurrence_parent_id ? $event->recurrenceParent : $event;
        if ($root->getAttributes()['hub_managed'] ?? false) {
            throw ValidationException::withMessages(['edit_version' => 'Open Event drafts to prepare and publish file changes for this Hub event.']);
        }

        return $this->copySources($actor, $selections, 'hr/calendar/events/'.$event->id,
            fn ($data) => $event->attachments()->create([...$data, 'disk' => 'private', 'uploaded_by' => $actor->id]), HrCalendarEvent::class, (int) $event->id, $written);
    }

    /** Add safe byte endpoints without exposing library/source identities to event readers. */
    public static function attachmentUrls(array $file, string $url): array
    {
        return [...$file, 'url' => $url, 'download_url' => $url,
            'preview_url' => HubMediaLibrary::previewable((string) ($file['mime'] ?? '')) ? $url.'?preview=1' : null];
    }

    public function readerPhoto(User $viewer, HrCalendarEventAttachment $file, array $details, string $fileBase, string $fileSuffix = ''): array
    {
        $metadata = self::details($details);
        if ($metadata['title'] === '') {
            $metadata['title'] = mb_substr($file->original_name, 0, 160);
        }
        $sharedBy = $file->uploaded_by ? app(HrCalendarAccessService::class)->visibleCurrentStaffQuery($viewer)->whereKey($file->uploaded_by)->first(['id', 'name']) : null;

        return ['id' => $file->id, 'name' => $file->original_name, 'url' => $fileBase.$file->id.$fileSuffix, ...$metadata,
            'shared_by' => $sharedBy ? ['name' => $sharedBy->name, 'profile_url' => app(HubDirectory::class)->profileUrlForUser($viewer, (int) $sharedBy->id)] : null,
            'shared_at' => $file->created_at?->toIso8601String()];
    }
}
