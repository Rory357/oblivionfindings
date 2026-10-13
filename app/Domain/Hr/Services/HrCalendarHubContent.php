<?php

namespace App\Domain\Hr\Services;

use App\Domain\CompanyHub\Models\HubPage;
use App\Domain\CompanyHub\Services\HubAccess;
use App\Domain\Hr\Models\HrCalendarEvent;
use App\Domain\Hr\Models\HrCalendarEventAttachment;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/** Editorial event details remain bound to canonical event access and protected files. */
class HrCalendarHubContent
{
    private const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    public function rules(): array
    {
        return [
            'hub_content' => ['sometimes', 'array:host_id,agenda,recap,story_id,cover_ref,cover_alt,gallery_refs'],
            'hub_content.host_id' => ['nullable', 'integer'],
            'hub_content.agenda' => ['nullable', 'string', 'max:6000'],
            'hub_content.recap' => ['nullable', 'string', 'max:8000'],
            'hub_content.story_id' => ['nullable', 'integer'],
            'hub_content.cover_ref' => ['nullable', 'string', 'regex:/^(file|upload):[0-9]+$/'],
            'hub_content.cover_alt' => ['nullable', 'string', 'max:240'],
            'hub_content.gallery_refs' => ['sometimes', 'array', 'max:12'],
            'hub_content.gallery_refs.*' => ['string', 'distinct', 'regex:/^(file|upload):[0-9]+$/'],
        ];
    }

    public function stories(User $viewer): array
    {
        $access = app(HubAccess::class);
        if (! $access->canEnter($viewer)) {
            return [];
        }

        return HubPage::where('kind', 'news')->with('currentRelease')->get()->map(function ($page) use ($access, $viewer) {
            $revision = $access->visibleRevision($viewer, $page);

            return $revision ? ['id' => $page->id, 'title' => $revision->content['title']] : null;
        })->filter()->sortBy('title')->values()->all();
    }

    /** Apply after uploads and series copying, inside the canonical event transaction. */
    public function save(User $actor, HrCalendarEvent $event, array $input, array $uploads, array $copied = []): void
    {
        $previous = $event->hub_content ?? $event->recurrenceParent?->hub_content ?? [];
        $host = empty($input['host_id']) ? null : (int) $input['host_id'];
        if ($host && ! app(HrCalendarAccessService::class)->visibleCurrentStaffQuery($actor)->whereKey($host)->exists()) {
            throw ValidationException::withMessages(['hub_content.host_id' => 'Choose a current colleague you can access.']);
        }
        $story = empty($input['story_id']) ? null : (int) $input['story_id'];
        if ($story && ! in_array($story, array_column($this->stories($actor), 'id'), true)) {
            throw ValidationException::withMessages(['hub_content.story_id' => 'Choose a published story you can currently read.']);
        }
        // Redacted relationships are not a request to erase historical metadata.
        if (! $host && ! empty($previous['host_id']) && ! app(HrCalendarAccessService::class)->visibleCurrentStaffQuery($actor)->whereKey($previous['host_id'])->exists()) {
            $host = $previous['host_id'];
        }
        if (! $story && ! empty($previous['story_id']) && ! in_array($previous['story_id'], array_column($this->stories($actor), 'id'), true)) {
            $story = $previous['story_id'];
        }
        $allowed = HrCalendarEventAttachment::whereIn('event_id', array_filter([$event->id, $event->recurrence_parent_id]))->get()->keyBy('id');
        $resolve = function (?string $ref, string $field) use ($allowed, $uploads, $copied): ?int {
            if (! $ref) {
                return null;
            }
            [$kind, $key] = explode(':', $ref);
            $id = $kind === 'upload' ? ($uploads[(int) $key] ?? null) : ($copied[(int) $key] ?? (int) $key);
            $file = $id ? $allowed->get($id) : null;
            if (! $file || ! in_array($file->mime, self::IMAGE_TYPES, true)) {
                throw ValidationException::withMessages([$field => 'Choose a photo attached to this event. Removed, missing or unrelated files cannot be used.']);
            }

            return (int) $id;
        };
        $event->update(['hub_content' => [
            'host_id' => $host, 'agenda' => trim($input['agenda'] ?? ''), 'recap' => trim($input['recap'] ?? ''), 'story_id' => $story,
            'cover_id' => $resolve($input['cover_ref'] ?? null, 'hub_content.cover_ref'), 'cover_alt' => trim($input['cover_alt'] ?? ''),
            'gallery_ids' => array_values(array_unique(array_map(fn ($ref) => $resolve($ref, 'hub_content.gallery_refs'), $input['gallery_refs'] ?? []))),
        ]]);
    }

    public function copy(array $content, array $map, array $retained = []): array
    {
        $resolve = fn ($id) => $map[$id] ?? (in_array($id, $retained, true) ? $id : null);

        return [...$content, 'cover_id' => $resolve($content['cover_id'] ?? 0),
            'gallery_ids' => array_values(array_filter(array_map($resolve, $content['gallery_ids'] ?? [])))];
    }

    public function read(User $viewer, HrCalendarEvent $event, Carbon $end, Collection $attachments): array
    {
        $root = $event->recurrence_parent_id ? $event->recurrenceParent : $event;
        $details = $event->hub_content ?? $root?->hub_content ?? [];
        $photos = $attachments->filter(fn ($file) => in_array($file->mime, self::IMAGE_TYPES, true))->keyBy('id');
        $coverId = $details['cover_id'] ?? null;
        // Preserve the original automatic cover for events not yet configured by an editor.
        if (! $details) {
            $coverId = $photos->keys()->first();
        }
        $cover = $photos->get($coverId);
        $host = empty($details['host_id']) ? null : app(HrCalendarAccessService::class)->visibleCurrentStaffQuery($viewer)->whereKey($details['host_id'])->first(['id', 'name']);
        $story = empty($details['story_id']) ? null : HubPage::where('kind', 'news')->find($details['story_id']);
        $revision = $story ? app(HubAccess::class)->visibleRevision($viewer, $story) : null;
        $past = $this->finishedAt($end, (bool) $event->is_all_day)->isPast();
        $canEdit = app(HrCalendarAccessService::class)->canManageEvent($viewer, $event);
        $fileBase = app(HubAccess::class)->canEnter($viewer) ? '/company-hub/event-files/' : '/hr/calendar/attachments/';
        $image = fn ($file) => ['id' => $file->id, 'name' => $file->original_name, 'url' => $fileBase.$file->id];
        $gallery = $photos->only($details['gallery_ids'] ?? [])->values();

        return [
            'cover' => $cover ? [...$image($cover), 'alt' => $details['cover_alt'] ?? ''] : null,
            'host' => $host ? ['id' => $host->id, 'name' => $host->name] : null,
            'agenda' => $details['agenda'] ?? '', 'recap' => $past ? ($details['recap'] ?? '') : '',
            'gallery' => $past ? $gallery->map($image)->all() : [],
            'story' => $past && $revision ? ['title' => $revision->content['title'], 'url' => '/company-hub/read/'.$story->slug] : null,
            'past' => $past,
            'editor' => $canEdit ? ['host_id' => $host?->id, 'agenda' => $details['agenda'] ?? '', 'recap' => $details['recap'] ?? '',
                'story_id' => $revision ? $story->id : null, 'cover_ref' => $cover ? 'file:'.$cover->id : '', 'cover_alt' => $details['cover_alt'] ?? '',
                'gallery_refs' => $gallery->map(fn ($file) => 'file:'.$file->id)->all()] : null,
        ];
    }

    public function finishedAt(Carbon $end, bool $allDay): Carbon
    {
        // Canonical all-day timestamps carry calendar dates, not UTC clock times.
        return $allDay ? Carbon::parse($end->toDateString(), 'Pacific/Auckland')->endOfDay()->utc() : $end;
    }
}
