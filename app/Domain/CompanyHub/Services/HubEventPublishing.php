<?php

namespace App\Domain\CompanyHub\Services;

use App\Domain\CompanyHub\Models\HubEventDraft;
use App\Domain\CompanyHub\Models\HubEventDraftFile;
use App\Domain\Hr\Models\HrCalendarEvent;
use App\Domain\Hr\Models\HrCalendarEventAttachment;
use App\Domain\Hr\Services\HrCalendarAccessService;
use App\Domain\Hr\Services\HrCalendarCancellation;
use App\Domain\Hr\Services\HrCalendarChanges;
use App\Domain\Hr\Services\HrCalendarHubContent;
use App\Domain\Hr\Services\HrCalendarRecurrence;
use App\Domain\Hr\Services\HrCurrentStaffService;
use App\Domain\Hr\Services\HrEventRegistrationService;
use App\Http\Controllers\Hr\CalendarController;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Private editorial records. Only approval writes the canonical calendar graph. */
class HubEventPublishing
{
    public function __construct(private readonly HubAccess $hub, private readonly HrCalendarAccessService $calendar) {}

    public function schedulingReady(): bool
    {
        return Schema::hasColumns('hub_event_drafts', ['publish_at', 'scheduled_at', 'schedule_blocked_at', 'schedule_error', 'scheduled_files']);
    }

    private function actor(Request $request): User
    {
        $user = $request->user()?->fresh();
        abort_unless($user && app(HrCurrentStaffService::class)->isCurrent($user), 404);
        $request->setUserResolver(fn () => $user);

        return $user;
    }

    private function assertActor(User $user, array $data, bool $required = false): void
    {
        abort_unless(! $required && ! isset($data['actor_id']) || (int) ($data['actor_id'] ?? 0) === (int) $user->id, 409, 'Your account changed. Reopen Event drafts before continuing.');
    }

    public function canCreate(User $user): bool
    {
        $user = $user->fresh();
        if (! $user) {
            return false;
        }

        return $this->hub->canEnter($user) && $user->canDo('company_hub.write') && $user->canDo('hr.calendar.view')
            && ($user->canDo('calendar.create') || $user->canDo('calendar.manage') || $user->canDo('hr.calendar.manage'));
    }

    public function canEdit(User $user, HubEventDraft $draft): bool
    {
        $user = $user->fresh();
        if (! $user) {
            return false;
        }
        if (! $this->canCreate($user) || ((int) $draft->owner_id !== (int) $user->id && (! $user->canDo('company_hub.edit_all') || (! $user->canDo('calendar.manage') && ! $user->canDo('hr.calendar.manage'))))) {
            return false;
        }
        if ($draft->event_id && (! $draft->event || ! $this->calendar->canManageEvent($user, $draft->event))) {
            return false;
        }
        try {
            $this->assertAudience($user, $draft->payload);
        } catch (HttpExceptionInterface) {
            return false;
        }

        return true;
    }

    public function assertEdit(User $user, HubEventDraft $draft): void
    {
        abort_unless($this->canEdit($user, $draft), 404);
    }

    public function workspace(User $user): array
    {
        $user = $user->fresh();
        abort_unless($user, 404);
        abort_unless($this->canCreate($user), 403);

        return ['actor_id' => $user->id, 'can_publish' => $user->canDo('company_hub.publish'), 'can_schedule' => $this->schedulingReady() && $user->canDo('company_hub.publish'), 'drafts' => HubEventDraft::with(['owner:id,name', 'event', 'files'])->latest('updated_at')->get()
            ->filter(fn ($draft) => $this->canEdit($user, $draft))->map(fn ($draft) => $this->serialize($draft))->values()->all()];
    }

    public function save(Request $request, ?HubEventDraft $bound = null): HubEventDraft
    {
        $user = $this->actor($request);
        abort_unless($this->canCreate($user), 403);
        if ($bound) {
            $this->assertEdit($user, $bound);
        }
        $data = $request->validate([
            ...app(HrCalendarHubContent::class)->rules(),
            ...app(HubEventMedia::class)->rules(true),
            'draft_key' => [$bound ? 'sometimes' : 'required', 'uuid'], 'version' => [$bound ? 'required' : 'nullable', 'integer', 'min:1'],
            'source_event_id' => ['nullable', 'integer', 'min:1'], 'edit_version' => ['nullable', 'integer', 'min:1'],
            'actor_id' => ['nullable', 'required_with:hub_library_images', 'integer', 'min:1', 'max:'.HubEventMedia::MAX_ID],
            'mode' => ['required', 'in:draft,review,publish,schedule'],
            'publish_at' => ['nullable', 'date', 'regex:/T.*(?:Z|[+-][0-9]{2}:[0-9]{2})$/'],
            'title' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:5000'],
            'event_type' => ['required', 'in:company,team,training,social,holiday'],
            'starts_at' => ['required', 'date'], 'ends_at' => ['required', 'date', 'after_or_equal:starts_at'], 'is_all_day' => ['required', 'boolean'],
            'rrule' => ['nullable', 'string', 'max:255'], 'recurrence_until' => ['nullable', 'date'], 'recurrence_timezone' => ['required', 'in:UTC,Pacific/Auckland'],
            'location' => ['nullable', 'string', 'max:255'], 'site_id' => ['nullable', 'integer'], 'department_id' => ['nullable', 'integer'],
            'audience_type' => ['required', 'in:org,site,department,team,people'], 'audience_team' => ['nullable', 'string', 'max:255'],
            'audience_user_ids' => ['sometimes', 'array'], 'audience_user_ids.*' => ['integer', 'distinct'],
            'reminders' => ['sometimes', 'array', 'max:20'], 'reminders.*.offset_minutes' => ['required', 'integer', 'min:0', 'max:43200'], 'reminders.*.channel' => ['required', 'in:notification,email'],
            'scope' => ['sometimes', 'in:all,this,following'], 'occurrence_date' => ['nullable', 'date_format:Y-m-d'],
            'registration_enabled' => ['sometimes', 'boolean'], 'registration_capacity' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'registration_closes_before_minutes' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:43200'],
            'registration_version' => ['nullable', 'integer', 'min:1'],
            'files' => ['sometimes', 'array', 'max:10'], 'files.*' => ['file', 'max:10240', CalendarController::attachmentRule()],
            'removed_attachment_ids' => ['sometimes', 'array', 'max:100'], 'removed_attachment_ids.*' => ['integer', 'distinct'],
        ]);
        app(HubEventMedia::class)->assertBatchLimit(count($request->file('files', [])), $data['hub_library_images'] ?? []);
        // Multipart values arrive as strings. Persist the editor's typed contract.
        $data['is_all_day'] = (bool) $data['is_all_day'];
        foreach (['site_id', 'department_id', 'registration_capacity', 'registration_closes_before_minutes', 'registration_version', 'source_event_id', 'edit_version'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = $data[$field] === null ? null : (int) $data[$field];
            }
        }
        if (array_key_exists('registration_enabled', $data)) {
            $data['registration_enabled'] = (bool) $data['registration_enabled'];
        }
        if (array_key_exists('registration_closes_before_minutes', $data)) {
            abort_unless(array_key_exists('registration_enabled', $data), 422, 'Save booking cutoff with the series registration settings.');
            if (! app(HrEventRegistrationService::class)->cutoffReady()) {
                throw ValidationException::withMessages(['registration_closes_before_minutes' => 'Booking cutoff settings are not available until the calendar update is installed.']);
            }
        }
        $data['audience_user_ids'] = array_map('intval', $data['audience_user_ids'] ?? []);
        $data['reminders'] = array_map(fn ($reminder) => [...$reminder, 'offset_minutes' => (int) $reminder['offset_minutes']], $data['reminders'] ?? []);
        if (isset($data['hub_content'])) {
            foreach (['host_id', 'story_id'] as $field) {
                $data['hub_content'][$field] = empty($data['hub_content'][$field]) ? null : (int) $data['hub_content'][$field];
            }
            foreach (['agenda', 'recap', 'cover_alt'] as $field) {
                $data['hub_content'][$field] = $data['hub_content'][$field] ?? '';
            }
            $data['hub_content']['gallery_refs'] ??= [];
        }
        $this->assertAudience($user, $data);
        app(HrCalendarRecurrence::class)->validateRule($data['rrule'] ?? null);
        $mode = $data['mode'];
        $this->assertActor($user, $data, $mode === 'schedule' || ! empty($data['publish_at']));
        abort_if(in_array($mode, ['publish', 'schedule'], true) && ! $user->canDo('company_hub.publish'), 403);
        $publishAt = ! empty($data['publish_at']) ? $this->publicationTime($data['publish_at']) : null;
        if ($mode === 'schedule' && ! $publishAt) {
            throw ValidationException::withMessages(['publish_at' => 'Choose a future publication time.']);
        }
        abort_if($mode === 'publish' && $publishAt, 422, 'Choose Schedule for later to use a publication time.');
        $written = [];
        try {
            return DB::transaction(function () use ($request, $user, $bound, $data, $mode, $publishAt, &$written) {
                $user = $this->actor($request);
                abort_unless($this->canCreate($user), 403);
                $this->assertActor($user, $data, $mode === 'schedule' || $publishAt !== null);
                $this->assertAudience($user, $data);
                $eventId = $bound?->event_id ?? ($data['source_event_id'] ?? null);
                [$event, $root] = $this->lockEvent($eventId);
                if ($event) {
                    $this->calendar->assertCanManageEvent($user, $event);
                    abort_if($event->archived_at || $root->archived_at, 404);
                    app(HrCalendarCancellation::class)->assertEditable($event, $data['scope'] ?? 'all', $data['occurrence_date'] ?? null);
                    abort_if(($event->recurrence_parent_id || ($data['scope'] ?? 'all') === 'this') && array_key_exists('registration_enabled', $data), 422, 'Registration settings apply to the whole series. Edit the whole series to change them.');
                }
                if ($bound) {
                    $draft = HubEventDraft::whereKey($bound->id)->lockForUpdate()->firstOrFail();
                    $this->assertEdit($user, $draft);
                    $this->assertVersion($draft, (int) $data['version']);
                    abort_if($draft->archived_at || ! in_array($draft->state, ['draft', 'changes_requested'], true), 409, 'Withdraw the review or cancel the schedule before editing, or start a new draft from the published event.');
                    abort_unless((int) $draft->event_id === (int) $eventId, 409);
                    if (array_key_exists('_source_root_id', $draft->payload)) {
                        abort_unless((int) $draft->payload['_source_root_id'] === (int) $root?->id, 409, 'The original event series changed. Start a fresh draft from the current event.');
                    }
                } else {
                    $draft = HubEventDraft::firstOrCreate(['owner_id' => $user->id, 'draft_key' => $data['draft_key']], ['event_id' => $eventId, 'base_edit_version' => $data['edit_version'] ?? null, 'payload' => []]);
                    abort_unless($draft->wasRecentlyCreated, 409, 'This draft was already saved. Open it from Event drafts.');
                }
                if ($event) {
                    abort_unless($draft->base_edit_version && (int) $root->edit_version === $draft->base_edit_version, 409, 'The published event changed. Your draft is retained; start a fresh draft from the current event before publishing.');
                }
                $removed = array_map('intval', $data['removed_attachment_ids'] ?? []);
                $ownedFiles = $draft->files()->where('retained', true)->get()->keyBy('id');
                $liveFiles = $this->liveFiles($event);
                foreach ($removed as $id) {
                    abort_unless($id < 0 ? $ownedFiles->has(-$id) : $liveFiles->has($id), 422, 'A selected file is no longer available.');
                    if ($id > 0) {
                        abort_unless((int) $liveFiles[$id]->event_id === (int) $event?->id && ($data['scope'] ?? 'all') !== 'this', 422, 'Series files cannot be removed from one date.');
                    } else {
                        $ownedFiles[-$id]->update(['retained' => false]);
                    }
                }
                $uploads = [];
                foreach ($request->file('files', []) as $file) {
                    $path = $file->store('company-hub/event-drafts/'.$draft->id, 'private');
                    $written[] = $path;
                    $uploads[] = $draft->files()->create(['path' => $path, 'original_name' => $file->getClientOriginalName(), 'mime' => CalendarController::attachmentMime($file), 'size' => $file->getSize()])->id;
                }
                $copies = app(HubEventMedia::class)->copyToDraft($user, $draft, $data['hub_library_images'] ?? [], $written);
                abort_if($draft->files()->where('retained', true)->count() > 10, 422, 'Keep up to 10 draft files.');
                $payload = $data;
                unset($payload['files'], $payload['hub_library_images'], $payload['draft_key'], $payload['version'], $payload['source_event_id'], $payload['edit_version'], $payload['mode'], $payload['actor_id'], $payload['publish_at']);
                $payload['_source_root_id'] = $root?->id;
                $payload['removed_attachment_ids'] = array_values(array_unique([...($draft->payload['removed_attachment_ids'] ?? []), ...array_filter($removed, fn ($id) => $id > 0)]));
                $payload['source_attachment_ids'] = $liveFiles->except($payload['removed_attachment_ids'])->pluck('id')->map(fn ($id) => (int) $id)->all();
                $retained = $draft->files()->where('retained', true)->get()->keyBy('id');
                $isRetained = function (?string $ref) use ($retained, $liveFiles, $payload): bool {
                    if (! HubEventMedia::validReference($ref, true) || ! str_starts_with($ref, 'file:')) {
                        return false;
                    }
                    $id = (int) substr($ref, 5);
                    $file = $id < 0 ? $retained->get(-$id) : $liveFiles->get($id);

                    return $file && ! in_array($id, $payload['removed_attachment_ids'], true) && in_array($file->mime, HubEventMedia::IMAGE_TYPES, true);
                };
                $previousHub = $draft->payload['hub_content'] ?? ($event ? app(HrCalendarHubContent::class)->read($user, $event, $event->ends_at ?? now(), $liveFiles)['editor'] : []) ?? [];
                if (! array_key_exists('hub_content', $payload) && $previousHub !== []) {
                    $payload['hub_content'] = $previousHub;
                    $payload['hub_content']['cover_ref'] = $isRetained($previousHub['cover_ref'] ?? null) ? $previousHub['cover_ref'] : null;
                    $payload['hub_content']['gallery_refs'] = array_values(array_filter($previousHub['gallery_refs'] ?? [], $isRetained));
                    $payload['hub_content']['photo_details'] = array_values(array_filter($previousHub['photo_details'] ?? [], fn ($row) => $isRetained($row['ref'])));
                }
                if ($copies !== [] || isset($payload['hub_content'])) {
                    $payload['hub_content'] = HubEventMedia::mergeDraftContent(HubEventMedia::materialiseContent($payload['hub_content'] ?? [], $copies), $previousHub, $isRetained);
                }
                $resolve = function (?string $ref) use ($uploads, $retained, $liveFiles, $payload): ?string {
                    if (! $ref) {
                        return null;
                    }
                    [$kind, $key] = explode(':', $ref);
                    abort_unless(in_array($kind, ['file', 'upload'], true), 422, 'Choose an owned event photo.');
                    $id = $kind === 'upload' ? -($uploads[(int) $key] ?? 0) : (int) $key;
                    $file = $id < 0 ? $retained->get(-$id) : $liveFiles->get($id);
                    abort_unless($file && ! in_array($id, $payload['removed_attachment_ids'], true) && in_array($file->mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true), 422, 'Choose a retained photo belonging to this event draft.');

                    return 'file:'.$id;
                };
                if (isset($payload['hub_content'])) {
                    $payload['hub_content'] = HubEventMedia::mapReferences($payload['hub_content'], $resolve);
                }
                $draft->update(['payload' => $payload, 'version' => $bound ? $draft->version + 1 : 1, 'state' => $mode === 'review' ? 'review' : 'draft',
                    'feedback' => null, 'submitted_by' => $mode === 'review' ? $user->id : null, 'submitted_at' => $mode === 'review' ? now() : null,
                    ...($this->schedulingReady() ? ['publish_at' => $publishAt, 'scheduled_at' => null, 'schedule_blocked_at' => null, 'schedule_error' => null, 'scheduled_files' => null] : [])]);
                $this->audit($user, $draft, $mode === 'review' ? 'submitted' : 'saved');
                if ($mode === 'publish') {
                    $this->publish($request, $draft);
                } elseif ($mode === 'schedule') {
                    $this->schedule($user, $draft, $publishAt);
                }

                return $draft->fresh();
            });
        } catch (\Throwable $error) {
            $this->cleanPromotedFiles($request);
            foreach ($written as $path) {
                if (! HubEventDraftFile::where('path', $path)->exists()) {
                    Storage::disk('private')->delete($path);
                }
            }
            throw $error;
        }
    }

    public function transition(Request $request, HubEventDraft $bound): HubEventDraft
    {
        $user = $this->actor($request);
        $this->assertEdit($user, $bound);
        $data = $request->validate(['actor_id' => 'nullable|integer|min:1', 'version' => 'required|integer|min:1', 'action' => 'required|in:approve,return,withdraw,archive,restore,schedule,cancel_schedule',
            'publish_at' => ['nullable', 'date', 'regex:/T.*(?:Z|[+-][0-9]{2}:[0-9]{2})$/'], 'feedback' => 'required_if:action,return|nullable|string|max:2000']);
        $this->assertActor($user, $data, in_array($data['action'], ['schedule', 'cancel_schedule'], true) || ! empty($data['publish_at']) || ($data['action'] === 'approve' && $bound->publish_at !== null));

        try {
            return DB::transaction(function () use ($request, $bound, $data) {
                $this->lockEvent($bound->event_id);
                $draft = HubEventDraft::whereKey($bound->id)->lockForUpdate()->firstOrFail();
                $actor = $this->actor($request);
                $this->assertEdit($actor, $draft);
                $this->assertVersion($draft, $data['version']);
                $action = $data['action'];
                $this->assertActor($actor, $data, in_array($action, ['schedule', 'cancel_schedule'], true) || ! empty($data['publish_at']) || ($action === 'approve' && $draft->publish_at !== null));
                abort_if(in_array($draft->state, ['scheduled', 'schedule_blocked'], true) && $action !== 'cancel_schedule', 409, 'Cancel the scheduled publication before changing its draft.');
                if (in_array($action, ['approve', 'return'])) {
                    abort_unless($request->user()->canDo('company_hub.publish'), 403);
                    abort_if($draft->archived_at || $draft->state !== 'review', 409, 'This version is no longer waiting for review.');
                }
                if ($action === 'approve') {
                    $publishAt = array_key_exists('publish_at', $data) ? $data['publish_at'] : $draft->publish_at?->toIso8601String();
                    if ($publishAt) {
                        $this->schedule($actor, $draft, $this->publicationTime($publishAt));
                    } else {
                        $this->publish($request, $draft);
                    }
                } elseif ($action === 'schedule') {
                    abort_if($draft->archived_at || ! in_array($draft->state, ['draft', 'changes_requested'], true), 409, 'Cancel the current schedule or withdraw the review before scheduling another version.');
                    $this->schedule($actor, $draft, $this->publicationTime($data['publish_at'] ?? null));
                } elseif ($action === 'cancel_schedule') {
                    abort_unless($actor->canDo('company_hub.publish'), 403);
                    $this->assertSchedulingReady();
                    abort_if($draft->archived_at || ! in_array($draft->state, ['scheduled', 'schedule_blocked'], true), 409, 'This schedule already changed or went live.');
                    $draft->update(['state' => 'draft', 'publish_at' => null, 'scheduled_at' => null, 'schedule_blocked_at' => null, 'schedule_error' => null, 'scheduled_files' => null,
                        'approved_by' => null, 'feedback' => null, 'submitted_at' => null, 'submitted_by' => null]);
                } elseif ($action === 'return') {
                    $draft->update(['state' => 'changes_requested', 'feedback' => trim($data['feedback'])]);
                } elseif ($action === 'withdraw') {
                    abort_if($draft->archived_at || $draft->state !== 'review', 409);
                    $draft->update(['state' => 'draft', 'submitted_at' => null, 'submitted_by' => null]);
                } elseif ($action === 'archive') {
                    abort_if(in_array($draft->state, ['scheduled', 'schedule_blocked'], true), 409, 'Cancel the scheduled publication before archiving its draft.');
                    abort_if($draft->state === 'published', 422, 'Archive the published event from its event details.');
                    $draft->update(['archived_at' => now()]);
                } else {
                    $draft->update(['archived_at' => null]);
                }
                $draft->increment('version');
                $this->audit($request->user(), $draft, $action);

                return $draft->fresh();
            });
        } catch (\Throwable $error) {
            $this->cleanPromotedFiles($request);
            throw $error;
        }
    }

    private function publish(Request $request, HubEventDraft $draft): void
    {
        $actor = $this->actor($request);
        abort_unless($actor->canDo('company_hub.publish'), 403);
        $this->assertEdit($actor, $draft);
        $owner = $draft->owner?->fresh();
        abort_unless($owner && $this->canEdit($owner, $draft), 409, 'The author no longer has access. Prepare a new draft with a current author before publishing.');
        [$event, $root] = $this->lockEvent($draft->event_id);
        if (array_key_exists('_source_root_id', $draft->payload)) {
            abort_unless((int) $draft->payload['_source_root_id'] === (int) $root?->id, 409, 'The original event series changed. Start a fresh draft from the current event.');
        }
        if ($root) {
            abort_unless(! $root->archived_at && ! $event->archived_at && (int) $root->edit_version === $draft->base_edit_version, 409, 'The published event changed. Start a fresh draft from the current event.');
        }
        $payload = $draft->payload;
        if ($event) {
            app(HrCalendarCancellation::class)->assertEditable($event, $payload['scope'] ?? 'all', $payload['occurrence_date'] ?? null);
        }
        unset($payload['source_attachment_ids'], $payload['_source_root_id']);
        if ($root && array_key_exists('registration_enabled', $payload)) {
            abort_unless(isset($payload['registration_version']) && (int) $root->registration_version === (int) $payload['registration_version'], 409, 'Registration settings changed. Review the current event before publishing.');
        }
        $files = $draft->files()->where('retained', true)->orderBy('id')->get();
        $indices = $files->pluck('id')->flip();
        $resolve = function (?string $ref) use ($indices): ?string {
            if (! $ref) {
                return null;
            }
            $id = (int) substr($ref, 5);
            if ($id >= 0) {
                return $ref;
            }
            abort_unless($indices->has(-$id), 422, 'A draft photo is no longer available.');

            return 'upload:'.$indices[-$id];
        };
        if (isset($payload['hub_content'])) {
            $payload['hub_content'] = HubEventMedia::mapReferences($payload['hub_content'], $resolve);
        }
        $canonical = Request::create('/hr/calendar/events'.($event ? '/'.$event->id : ''), $event ? 'PUT' : 'POST', [...$payload, 'edit_version' => $draft->base_edit_version, 'replace_reminders' => true]);
        $canonical->setUserResolver(fn () => $actor);
        $canonical->setLaravelSession($request->session());
        $canonical->attributes->set('hub.approved_publication', true);
        $canonical->files->set('files', $files->map(function ($file) {
            abort_unless(Storage::disk('private')->exists($file->path), 422, 'A draft file is missing. Replace it before publishing.');

            return new UploadedFile(Storage::disk('private')->path($file->path), $file->original_name, $file->mime, null, true);
        })->all());
        $before = $event ? HrCalendarEventAttachment::whereIn('event_id', [$root->id, ...HrCalendarEvent::where('recurrence_parent_id', $root->id)->pluck('id')->all()])->pluck('id')->all() : [];
        $newFiles = [];
        try {
            $controller = app(CalendarController::class);
            $event ? $controller->update($canonical, $event) : $controller->store($canonical);
            $saved = $canonical->attributes->get('hub.saved_event');
            abort_unless($saved instanceof HrCalendarEvent, 500);
            $newFiles = $saved->attachments()->whereNotIn('id', $before)->get();
            $request->attributes->set('hub.published_files', $newFiles->map(fn ($file) => ['disk' => $file->disk ?: 'private', 'path' => $file->path])->all());
            $savedRoot = HrCalendarEvent::findOrFail($saved->recurrence_parent_id ?? $saved->id);
            $savedRoot->forceFill(['hub_managed' => true])->save();
            if (! $event) {
                $savedRoot->forceFill(['created_by' => $draft->owner_id])->save();
            }
            if (array_key_exists('registration_enabled', $payload)) {
                app(HrEventRegistrationService::class)->configure($actor, $saved, ['enabled' => $payload['registration_enabled'], 'capacity' => $payload['registration_capacity'] ?? null, 'version' => $savedRoot->registration_version,
                    'actor_id' => $actor->id, 'edit_version' => $savedRoot->edit_version,
                    ...(array_key_exists('registration_closes_before_minutes', $payload) ? ['closes_before_minutes' => $payload['registration_closes_before_minutes']] : [])]);
            }
            if ($context = $canonical->attributes->get(HrCalendarChanges::CONTEXT)) {
                app(HrCalendarChanges::class)->deliver($actor, $context, $saved);
            }
            $draft->update(['event_id' => $saved->id, 'state' => 'published', 'approved_by' => $actor->id, 'published_at' => now()]);
            $this->audit($actor, $draft, 'published');
        } catch (\Throwable $error) {
            foreach ($newFiles as $file) {
                Storage::disk($file->disk ?: 'private')->delete($file->path);
            }
            throw $error;
        }
    }

    private function assertSchedulingReady(): void
    {
        if (! $this->schedulingReady()) {
            throw ValidationException::withMessages(['publish_at' => 'Scheduled event publication is not available yet. Refresh after the calendar update is installed.']);
        }
    }

    private function publicationTime(?string $value): Carbon
    {
        $this->assertSchedulingReady();
        if (! $value || ! Carbon::parse($value)->isFuture()) {
            throw ValidationException::withMessages(['publish_at' => 'Choose a future publication time.']);
        }

        return Carbon::parse($value)->utc();
    }

    /** Validate approval without creating or updating the canonical event. */
    private function publicationFiles(User $actor, HubEventDraft $draft): array
    {
        abort_unless($actor->canDo('company_hub.publish'), 403);
        $this->assertEdit($actor, $draft);
        $owner = $draft->owner?->fresh();
        abort_unless($owner && $this->canEdit($owner, $draft), 409, 'The author no longer has access.');
        [$event, $root] = $this->lockEvent($draft->event_id);
        if (array_key_exists('_source_root_id', $draft->payload)) {
            abort_unless((int) $draft->payload['_source_root_id'] === (int) $root?->id, 409, 'The original event series changed. Start a fresh draft from the current event.');
        }
        if ($event) {
            abort_unless(! $event->archived_at && ! $root->archived_at && (int) $root->edit_version === (int) $draft->base_edit_version, 409, 'The published event changed.');
            app(HrCalendarCancellation::class)->assertEditable($event, $draft->payload['scope'] ?? 'all', $draft->payload['occurrence_date'] ?? null);
            $seriesIds = [$root->id, ...HrCalendarEvent::where('recurrence_parent_id', $root->id)->pluck('id')->all()];
            abort_if(HubEventDraft::whereIn('event_id', $seriesIds)->where('state', 'scheduled')->whereKeyNot($draft->id)->whereNull('archived_at')->exists(), 409, 'Another edition of this event is scheduled. Cancel that schedule before approving another edition.');
            if (array_key_exists('registration_enabled', $draft->payload)) {
                abort_unless(isset($draft->payload['registration_version']) && (int) $root->registration_version === (int) $draft->payload['registration_version'], 409, 'Registration settings changed.');
            }
        }
        if ($root?->rrule || ! empty($draft->payload['rrule'])) {
            abort_unless($actor->canDo('calendar.manage_recurring'), 403);
        }
        $content = $draft->payload['hub_content'] ?? [];
        if (! empty($content['host_id'])) {
            abort_unless($this->calendar->visibleCurrentStaffQuery($actor)->whereKey($content['host_id'])->exists(), 422, 'Choose a current authorised host.');
        }
        if (! empty($content['story_id'])) {
            abort_unless(in_array((int) $content['story_id'], array_column(app(HrCalendarHubContent::class)->stories($actor), 'id'), true), 422, 'Choose a currently published authorised story.');
        }
        $draftFiles = $draft->files()->where('retained', true)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $sourceFiles = $event ? HrCalendarEventAttachment::whereIn('event_id', array_filter([$event->id, $event->recurrence_parent_id]))->orderBy('id')->lockForUpdate()->get()->keyBy('id') : collect();
        $sourceIds = $draft->payload['source_attachment_ids'] ?? ($event ? $sourceFiles->keys()->all() : []);
        abort_unless(array_diff($sourceIds, $sourceFiles->keys()->all()) === [], 422, 'A source file is no longer available.');
        $sourceFiles = $sourceFiles->only($sourceIds)->except($draft->payload['removed_attachment_ids'] ?? [])->keyBy('id');
        foreach (array_filter([$content['cover_ref'] ?? null, ...($content['gallery_refs'] ?? []), ...array_column($content['photo_details'] ?? [], 'ref')]) as $ref) {
            $id = (int) substr($ref, 5);
            $photo = $id < 0 ? $draftFiles->get(-$id) : $sourceFiles->get($id);
            abort_unless($photo && in_array($photo->mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true), 422, 'A selected photo is no longer available.');
        }
        $snapshot = [];
        foreach (['draft' => $draftFiles, 'source' => $sourceFiles] as $kind => $files) {
            foreach ($files as $file) {
                $disk = $kind === 'draft' ? 'private' : ($file->disk ?: 'private');
                abort_unless(Storage::disk($disk)->exists($file->path), 422, 'A publication file is missing. Replace it before scheduling.');
                $stream = Storage::disk($disk)->readStream($file->path);
                abort_unless(is_resource($stream), 422, 'A publication file cannot be read.');
                try {
                    $hash = hash_init('sha256');
                    hash_update_stream($hash, $stream);
                    $snapshot[] = ['kind' => $kind, 'id' => $file->id, 'disk' => $disk, 'path' => $file->path, 'name' => $file->original_name,
                        'mime' => $file->mime, 'size' => (int) $file->size, 'sha256' => hash_final($hash)];
                } finally {
                    fclose($stream);
                }
            }
        }

        return $snapshot;
    }

    private function schedule(User $actor, HubEventDraft $draft, Carbon $publishAt): void
    {
        $this->assertSchedulingReady();
        $files = $this->publicationFiles($actor, $draft);
        // Legacy unscheduled drafts gain the current identity under the same version/audience lock.
        [, $root] = $this->lockEvent($draft->event_id);
        $draft->update(['state' => 'scheduled', 'publish_at' => $publishAt, 'scheduled_at' => now(), 'approved_by' => $actor->id,
            'payload' => [...$draft->payload, '_source_root_id' => $root?->id],
            'schedule_blocked_at' => null, 'schedule_error' => null, 'scheduled_files' => $files, 'feedback' => null]);
        $this->audit($actor, $draft, 'scheduled');
    }

    private function samePublicationFiles(array $current, mixed $approved): bool
    {
        if (! is_array($approved)) {
            return false;
        }
        $normalise = function (array $rows): ?array {
            foreach ($rows as &$row) {
                if (! is_array($row)) {
                    return null;
                }
                // MySQL JSON object key order is not part of an approved file's identity.
                ksort($row);
            }

            return $rows;
        };

        return $normalise($current) === $normalise($approved);
    }

    /** Invoked only by the bounded local due publisher; the draft is the durable once guard. */
    public function releaseScheduled(int $id, int $version): bool
    {
        $this->assertSchedulingReady();
        $probe = HubEventDraft::findOrFail($id);
        $request = Request::create('/company-hub/event-drafts/'.$id.'/transition', 'POST');
        $request->setLaravelSession(new Store('hub-event-publication', new ArraySessionHandler(120)));
        try {
            return DB::transaction(function () use ($probe, $request, $version): bool {
                $this->lockEvent($probe->event_id);
                $draft = HubEventDraft::whereKey($probe->id)->lockForUpdate()->firstOrFail();
                if ($draft->archived_at || $draft->state !== 'scheduled' || $draft->version !== $version || ! $draft->publish_at || $draft->publish_at->isFuture()) {
                    return false;
                }
                abort_unless((int) $draft->event_id === (int) $probe->event_id, 409, 'The publication source changed.');
                $actor = User::find($draft->approved_by);
                abort_unless($actor && $this->canCreate($actor), 409, 'The approving editor no longer has access.');
                $request->setUserResolver(fn () => $actor);
                $actor = $this->actor($request);
                abort_unless($this->samePublicationFiles($this->publicationFiles($actor, $draft), $draft->scheduled_files), 409, 'The approved publication files changed.');
                $this->publish($request, $draft);
                $draft->increment('version');
                $this->audit($actor, $draft, 'scheduled_published');

                return true;
            });
        } catch (\Throwable $error) {
            $this->cleanPromotedFiles($request);
            throw $error;
        }
    }

    private function cleanPromotedFiles(Request $request): void
    {
        foreach ($request->attributes->get('hub.published_files', []) as $file) {
            if (! HrCalendarEventAttachment::where('path', $file['path'])->exists()) {
                Storage::disk($file['disk'])->delete($file['path']);
            }
        }
    }

    private function assertAudience(User $user, array $payload): void
    {
        $this->calendar->assertCanUseSite($user, $payload['site_id'] ?? null);
        $this->calendar->assertCanUseDepartment($user, $payload['department_id'] ?? null);
        $type = $payload['audience_type'] ?? 'org';
        abort_if($type === 'site' && empty($payload['site_id']), 422, 'Choose a Site for this audience.');
        abort_if($type === 'department' && empty($payload['department_id']), 422, 'Choose a department for this audience.');
        if ($type === 'people') {
            abort_if(empty($payload['audience_user_ids']), 422, 'Choose at least one colleague.');
            $this->calendar->assertCanInviteUsers($user, $payload['audience_user_ids']);
        }
        if ($type === 'team') {
            abort_unless($this->calendar->canonicalVisibleTeam($user, $payload['audience_team'] ?? null), 422, 'Choose a current visible team.');
        }
    }

    private function lockEvent(?int $id): array
    {
        if (! $id) {
            return [null, null];
        }
        $event = HrCalendarEvent::findOrFail($id);
        $root = HrCalendarEvent::whereKey($event->recurrence_parent_id ?? $event->id)->lockForUpdate()->firstOrFail();
        $event = $id === (int) $root->id ? $root : HrCalendarEvent::whereKey($id)->lockForUpdate()->firstOrFail();
        abort_unless((int) ($event->recurrence_parent_id ?? $event->id) === (int) $root->id, 409, 'This event moved to another series. Reopen it before editing.');

        return [$event, $root];
    }

    private function liveFiles(?HrCalendarEvent $event)
    {
        return $event ? HrCalendarEventAttachment::whereIn('event_id', array_filter([$event->id, $event->recurrence_parent_id]))->get()->keyBy('id') : collect();
    }

    private function assertVersion(HubEventDraft $draft, int $version): void
    {
        abort_unless($draft->version === $version, 409, 'This draft changed. Reopen it to review the latest version.');
    }

    private function audit(User $user, HubEventDraft $draft, string $action): void
    {
        AuditLog::create(['user_id' => $user->id, 'action' => 'hub.event_draft.'.$action, 'auditable_type' => HubEventDraft::class, 'auditable_id' => $draft->id, 'meta' => ['version' => $draft->version, 'event_id' => $draft->event_id]]);
    }

    public function serialize(HubEventDraft $draft): array
    {
        $event = $draft->event;
        $payload = $draft->payload;
        $liveFiles = $this->liveFiles($event)->except($payload['removed_attachment_ids'] ?? []);
        // New-event publication must not add the promoted copies to its historical draft.
        $ids = $payload['source_attachment_ids'] ?? ($draft->base_edit_version ? $liveFiles->pluck('id')->all() : []);
        $attachments = $liveFiles->only($ids)->map(fn ($file) => HubEventMedia::attachmentUrls(['id' => $file->id, 'eventId' => $file->event_id, 'inherited' => (int) $file->event_id !== (int) $event?->id, 'name' => $file->original_name, 'mime' => $file->mime, 'size' => $file->size], '/company-hub/event-drafts/'.$draft->id.'/event-files/'.$file->id))->values()->all();
        unset($payload['source_attachment_ids'], $payload['_source_root_id']);
        foreach ($draft->files()->where('retained', true)->get() as $file) {
            $attachments[] = HubEventMedia::attachmentUrls(['id' => -$file->id, 'name' => $file->original_name, 'mime' => $file->mime, 'size' => $file->size], '/company-hub/event-draft-files/'.$file->id);
        }

        return ['id' => $draft->id, 'version' => $draft->version, 'state' => $draft->state, 'archived_at' => $draft->archived_at, 'owner' => $draft->owner?->name,
            'publish_at' => $draft->publish_at?->toIso8601String(), 'scheduled_at' => $draft->scheduled_at?->toIso8601String(),
            'schedule_blocked_at' => $draft->schedule_blocked_at?->toIso8601String(), 'schedule_error' => $draft->schedule_error,
            'feedback' => $draft->feedback, 'updated_at' => $draft->updated_at, 'published_at' => $draft->published_at, 'new_event' => $draft->base_edit_version === null,
            'initial' => [...$payload, 'id' => $draft->event_id ?? 0, 'edit_version' => $draft->base_edit_version ?? 1, 'recurrence_parent_id' => $event?->recurrence_parent_id, 'attachments' => $attachments]];
    }
}
