<?php

namespace App\Domain\CompanyHub\Services;

use App\Domain\CompanyHub\Models\HubMedia;
use App\Domain\CompanyHub\Models\HubPage;
use App\Domain\Hr\Services\HrCalendarAccessService;
use App\Domain\Hr\Services\HrCurrentStaffService;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Stable document identity, immutable file editions, canonical page publication. */
class HubDocuments
{
    private const DETAILS = ['category', 'resource_type', 'collection_id', 'review_on', 'audience', 'team_ids', 'requires_approval'];

    public function ready(): bool
    {
        return Schema::hasColumns('hub_media', ['document_root_id', 'document_sequence', 'document_version'])
            && Schema::hasTable('hub_document_versions') && Schema::hasTable('hub_document_upload_receipts');
    }

    private function installed(): void
    {
        if (! $this->ready()) {
            throw ValidationException::withMessages(['document' => 'Document creation and version changes are unavailable until the document update is installed. Existing files remain readable.']);
        }
    }

    private function access(): HubAccess
    {
        return new HubAccess(app(HrCurrentStaffService::class), new UserSiteAccessService);
    }

    private function actor(User $actor, ?int $expected = null, bool $lock = false): User
    {
        $actor = $lock ? User::whereKey($actor->id)->lockForUpdate()->first() : User::find($actor->id);
        abort_unless($actor, 404);
        $this->access()->assertEnter($actor);
        abort_if($expected !== null && $expected !== (int) $actor->id, 409, 'Your signed-in account changed. Reopen this document before continuing.');

        return $actor;
    }

    public function options(User $actor): array
    {
        $actor = $this->actor($actor);
        $canCreate = $actor->canDo('company_hub.write');
        $canAuthor = $canCreate || $actor->canDo('company_hub.edit_all') || $actor->canDo('company_hub.publish');
        $calendar = app(HrCalendarAccessService::class);

        return ['ready' => $this->ready(), 'actor_id' => (int) $actor->id, 'can_create' => $canCreate,
            'extensions' => array_values(HubMediaLibrary::MIME_EXTENSIONS), 'max_bytes' => 10 * 1024 * 1024,
            'resource_types' => HubContent::RESOURCE_TYPES, 'categories' => HubDiscovery::TOPICS,
            'collections' => $canAuthor ? app(HubCollections::class)->context($actor)['options'] : [],
            'sites' => $canAuthor ? $calendar->visibleSitesQuery($actor)->orderBy('name')->get(['id', 'name'])->toArray() : [],
            'departments' => $canAuthor ? $calendar->visibleDepartmentsQuery($actor)->orderBy('name')->get(['id', 'name'])->toArray() : [],
            'capabilities' => $this->access()->capabilities($actor)];
    }

    private function root(HubMedia $media, bool $lock = false): HubMedia
    {
        $media = HubMedia::findOrFail($media->id);
        $query = HubMedia::whereKey($media->documentId());
        $root = ($lock ? $query->lockForUpdate() : $query)->firstOrFail();
        abort_unless((int) $root->hub_page_id === (int) $media->hub_page_id && $root->documentId() === (int) $root->id, 404);

        return $root;
    }

    /** Only File blocks establish a reader document, never guessed library IDs. */
    private function references(array $content, HubMedia $root, bool $visible = true): array
    {
        $blocks = $visible ? HubContent::visibleBlocks($content) : collect($content['sections'] ?? [])->flatMap(fn ($section) => $section['blocks'] ?? []);
        $ids = $blocks->where('type', 'file')->pluck('media_id')->filter()->unique();

        return HubMedia::where('hub_page_id', $root->hub_page_id)->whereIn('id', $ids)->get()
            ->filter(fn ($item) => $item->documentId() === (int) $root->id)->values()->all();
    }

    private function edition(HubMedia $root, array $content, string $edition, ?string $at = null): ?array
    {
        $files = $this->references($content, $root, $edition === 'published');
        if ($files === []) {
            return null;
        }
        // A deliberately retained old File block must never become a guessed newer file.
        usort($files, fn ($left, $right) => $right->documentSequence() <=> $left->documentSequence());
        $media = $files[0];
        if (! Storage::disk('private')->exists($media->path)) {
            return null;
        }
        $blocks = $edition === 'published' ? HubContent::visibleBlocks($content) : collect($content['sections'] ?? [])->flatMap(fn ($section) => $section['blocks'] ?? []);
        $block = $blocks->where('type', 'file')->firstWhere('media_id', $media->id);

        return ['id' => (int) $root->id, 'media_id' => (int) $media->id, 'title' => $block['title'],
            'name' => $media->name, 'mime' => $media->mime, 'size' => (int) $media->size, 'version' => $media->documentSequence(),
            'description' => $block['body'] ?? '', 'updated_at' => $at ?? $media->created_at->toIso8601String(), 'review_on' => $content['review_on'] ?? null, 'edition' => $edition,
            'download_url' => '/company-hub/media/'.$media->id.'?download=1',
            'preview_url' => HubMediaLibrary::previewable($media->mime) ? '/company-hub/media/'.$media->id.'/preview' : null];
    }

    public function read(User $actor, HubMedia $media, string $edition = 'published', ?array $draftContext = null): array
    {
        $actor = $this->actor($actor, $draftContext['actor_id'] ?? null);
        $root = $this->root($media);
        $page = HubPage::with('currentRelease')->findOrFail($root->hub_page_id);
        $access = $this->access();
        $canEdit = $access->canEdit($actor, $page);
        if ($edition === 'draft') {
            $access->assertEdit($actor, $page);
            abort_unless($draftContext !== null && (int) $draftContext['page_version'] === (int) $page->draft_version, 409, 'This draft changed. Reopen its current document preview.');
            app(HubDraft::class)->assertSaved($page);
            $content = $page->draft;
            $data = $this->edition($root, $content, 'draft');
        } else {
            $release = $access->assertRead($actor, $page);
            $content = $release->content;
            $data = $this->edition($root, $content, 'published', $release->publish_at->toIso8601String());
        }
        abort_unless($data, 404);
        $names = app(HubReaderMetadata::class)->names($actor, [$page->owner_id]);
        $owner = $names[$page->owner_id] ?? 'Company team';

        return [...$data, 'actor_id' => (int) $actor->id, 'revision_id' => $edition === 'published' ? (int) $release->id : null,
            'document_version' => $canEdit && $this->ready() ? (int) $root->document_version : null,
            'owner' => $owner, 'owner_url' => isset($names[$page->owner_id]) ? '/company-hub/people/'.$page->owner_id : null,
            'governance_warnings' => HubReadiness::governanceWarnings($content, isset($names[$page->owner_id])),
            'page' => ['id' => (int) $page->id, 'title' => $content['title'], 'url' => '/company-hub/read/'.$page->slug],
            'can_edit' => $canEdit, 'history_url' => $canEdit ? '/company-hub/manage/'.$page->id.'/documents/'.$root->id : null,
            'replacement_url' => $canEdit ? '/company-hub/manage/'.$page->id.'/documents/'.$root->id.'/replace' : null];
    }

    public function context(User $actor, HubPage $page, HubMedia $media, ?int $expectedActor = null): array
    {
        $actor = $this->actor($actor, $expectedActor);
        $page = HubPage::with('currentRelease')->findOrFail($page->id);
        $this->access()->assertEdit($actor, $page);
        $root = $this->root($media);
        abort_unless((int) $root->hub_page_id === (int) $page->id, 404);
        $draft = app(HubDraft::class)->isDiscarded($page) ? null : $this->edition($root, $page->draft, 'draft');
        $release = $this->access()->visibleRevision($actor, $page);
        $published = $release ? $this->edition($root, $release->content, 'published', $release->publish_at->toIso8601String()) : null;
        abort_unless($draft || $published, 404);
        $ready = $this->ready();
        $blocked = $page->archived_at ? 'archived' : (app(HubDraft::class)->isDiscarded($page) ? 'discarded' : (HubReadiness::blockingRevision($page) ? 'publication' : null));
        $canReplace = $ready && $draft !== null && $blocked === null;
        $readiness = app(HubReadiness::class)->forPage($actor, $page);
        $owners = $actor->canDo('company_hub.edit_all') ? app(UserSiteAccessService::class)
            ->applyStaffScope(app(HrCurrentStaffService::class)->currentUsersQuery(), $actor)->orderBy('name')->get()->filter(function ($candidate) use ($page) {
                $copy = clone $page;
                $copy->owner_id = $candidate->id;

                return $this->access()->canEdit($candidate, $copy);
            })->map(fn ($person) => ['id' => (int) $person->id, 'name' => $person->name])->values()->all() : [];
        $history = $ready ? DB::table('hub_document_versions')->where('root_media_id', $root->id)->orderByDesc('sequence')->get()->map(function ($row) use ($actor, $draft, $published, $canReplace) {
            $snapshot = json_decode($row->snapshot, true, 512, JSON_THROW_ON_ERROR);
            if (! $this->access()->canAuthorAudience($actor, $snapshot)) {
                return null;
            }
            $source = HubMedia::find($row->hub_media_id);
            $available = $source && Storage::disk('private')->exists($source->path);

            return ['id' => (int) $row->id, 'version' => (int) $row->sequence, 'media_id' => (int) $row->hub_media_id,
                'name' => $snapshot['name'], 'mime' => $snapshot['mime'], 'size' => $snapshot['size'], 'action' => $row->action,
                'created_at' => $row->created_at, 'is_draft' => $draft && $draft['media_id'] === (int) $row->hub_media_id,
                'is_published' => $published && $published['media_id'] === (int) $row->hub_media_id,
                'can_restore' => $canReplace && $available && (! $draft || $draft['media_id'] !== (int) $row->hub_media_id),
                'download_url' => $available ? '/company-hub/media/'.$row->hub_media_id.'?download=1' : null,
                'preview_url' => $available && HubMediaLibrary::previewable($source->mime) ? '/company-hub/media/'.$row->hub_media_id.'/preview' : null];
        })->filter()->values()->all() : [];

        return ['ready' => $ready, 'actor_id' => (int) $actor->id, 'page_id' => (int) $page->id, 'page_version' => (int) $page->draft_version,
            'document_id' => (int) $root->id, 'document_version' => $ready ? (int) $root->document_version : null,
            'draft' => $draft, 'published' => $published, 'history' => $history, 'blocked_reason' => $blocked,
            'can_replace' => $canReplace, 'can_edit_details' => $canReplace, 'can_change_owner' => $actor->canDo('company_hub.edit_all'),
            'can_submit' => $canReplace && $readiness['content_ready'],
            'can_publish' => $canReplace && $readiness['content_ready'] && $actor->canDo('company_hub.publish') && empty($page->draft['requires_approval']),
            'metadata' => ['title' => $draft['title'] ?? $published['title'], 'description' => $draft['description'] ?? $published['description'],
                'category' => $page->draft['category'] ?? '', 'resource_type' => $page->draft['resource_type'] ?? null,
                'collection_id' => $page->draft['collection_id'] ?? null, 'collection' => $page->draft['collection'] ?? null,
                'review_on' => $page->draft['review_on'] ?? null, 'owner_id' => (int) $page->owner_id,
                'audience' => $page->draft['audience'], 'team_ids' => $page->draft['team_ids'] ?? [],
                'requires_approval' => (bool) ($page->draft['requires_approval'] ?? false)],
            'owners' => $owners, 'readiness' => $readiness, 'governance_warnings' => $readiness['governance_warnings'],
            'details_url' => '/company-hub/manage/'.$page->id.'/documents/'.$root->id.'/edit',
            'update_url' => '/company-hub/manage/'.$page->id.'/documents/'.$root->id.'/details',
            'publication_url' => '/company-hub/manage/'.$page->id.'/documents/'.$root->id.'/publication',
            'reader_url' => '/company-hub/documents/'.$root->id,
            'edit_url' => '/company-hub/manage/'.$page->id.'/edit'];
    }

    private function record(User $actor, HubPage $page, HubMedia $root, HubMedia $media, string $action, ?int $restoredFrom = null): void
    {
        DB::table('hub_document_versions')->insert(['root_media_id' => $root->id, 'hub_media_id' => $media->id,
            'sequence' => $media->documentSequence(), 'action' => $action, 'restored_from_id' => $restoredFrom, 'created_by' => $actor->id,
            'snapshot' => json_encode(['name' => $media->name, 'mime' => $media->mime, 'size' => (int) $media->size, 'audience' => $page->draft['audience']], JSON_THROW_ON_ERROR), 'created_at' => now()]);
    }

    private function receipt(User $actor, object $receipt, bool $replayed): array
    {
        $page = HubPage::findOrFail($receipt->hub_page_id);
        $this->access()->assertEdit($actor, $page);
        $media = HubMedia::findOrFail($receipt->hub_media_id);

        return ['saved' => true, 'replayed' => $replayed, 'actor_id' => (int) $actor->id, 'request_id' => $receipt->request_id,
            'document_id' => (int) $media->id, 'media_id' => (int) $media->id, 'page_id' => (int) $page->id,
            'page_version' => (int) $page->draft_version, 'document_version' => (int) $media->document_version,
            'edit_url' => '/company-hub/manage/'.$page->id.'/edit',
            'details_url' => '/company-hub/manage/'.$page->id.'/documents/'.$media->id.'/edit',
            'context_url' => '/company-hub/manage/'.$page->id.'/documents/'.$media->id,
            'reader_url' => '/company-hub/documents/'.$media->id];
    }

    public function uploadReceipt(User $actor, string $requestId, int $expectedActor): array
    {
        $actor = $this->actor($actor, $expectedActor);
        $this->installed();
        $receipt = DB::table('hub_document_upload_receipts')->where('actor_id', $actor->id)->where('request_id', $requestId)->first();
        abort_unless($receipt, 404);

        return $this->receipt($actor, $receipt, true);
    }

    public function create(User $actor, array $data, UploadedFile $file): array
    {
        $this->installed();
        $library = app(HubMediaLibrary::class);
        $mime = $library->uploadMime($file);
        $payload = [$data['title'], $data['description'] ?? '', $file->getClientOriginalName(), $mime, hash_file('sha256', $file->getRealPath())];
        $metadata = array_intersect_key($data, array_flip([...self::DETAILS, 'owner_id']));
        if ($metadata !== []) {
            if (isset($metadata['audience'])) {
                ksort($metadata['audience']);
            }
            ksort($metadata);
            $payload[] = $metadata;
        }
        $payloadHash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        $written = null;
        try {
            return DB::transaction(function () use ($actor, $data, $file, $library, $payloadHash, &$written): array {
                $actor = $this->actor($actor, (int) $data['actor_id'], true);
                abort_unless($actor->canDo('company_hub.write'), 403);
                $existing = DB::table('hub_document_upload_receipts')->where('actor_id', $actor->id)->where('request_id', $data['request_id'])->first();
                if ($existing) {
                    abort_unless(hash_equals($existing->payload_hash, $payloadHash), 409, 'This upload request was used for a different file or description. Check the existing draft before starting another upload.');

                    return $this->receipt($actor, $existing, true);
                }
                $publisher = app(HubPublishing::class);
                $page = $publisher->create($actor, 'knowledge', $data['title'], 'blank')->fresh();
                $media = $library->upload($actor, $page, $file);
                $written = $media->path;
                $draft = [...$page->draft, 'resource_type' => 'Guide', ...array_intersect_key($data, array_flip(self::DETAILS)), 'summary' => Str::limit($data['description'] ?? '', 240, ''),
                    'sections' => [['id' => 'document', 'columns' => 1, 'blocks' => [['id' => 'document', 'type' => 'file',
                        'title' => $data['title'], 'body' => $data['description'] ?? '', 'media_id' => $media->id]]]]];
                $page = $publisher->save($actor, $page, $draft, $page->draft_version);
                if (isset($data['owner_id']) && (int) $data['owner_id'] !== (int) $page->owner_id) {
                    $publisher->changeOwner($actor, $page, User::findOrFail($data['owner_id']), $page->draft_version);
                    $page = $page->fresh();
                }
                $this->record($actor, $page, $media, $media, 'uploaded');
                $id = DB::table('hub_document_upload_receipts')->insertGetId(['actor_id' => $actor->id, 'request_id' => $data['request_id'],
                    'payload_hash' => $payloadHash, 'hub_page_id' => $page->id, 'hub_media_id' => $media->id, 'created_at' => now()]);

                return $this->receipt($actor, DB::table('hub_document_upload_receipts')->find($id), false);
            });
        } catch (\Throwable $exception) {
            if ($written) {
                Storage::disk('private')->delete($written);
            }
            throw $exception;
        }
    }

    /** Metadata edits keep the authored page structure and live release intact. */
    public function update(User $actor, HubPage $page, HubMedia $media, array $data): array
    {
        $this->installed();

        return DB::transaction(function () use ($actor, $page, $media, $data): array {
            $actor = $this->actor($actor, (int) $data['actor_id'], true);
            [$page, $root] = $this->lockedDocument($actor, $page, $media, $data);
            HubReadiness::assertEditable($page);
            $references = $this->references($page->draft, $root, false);
            abort_unless($references !== [], 409, 'This document is absent from the saved draft. Use the page editor to choose its file block.');
            $ids = array_map(fn ($item) => (int) $item->id, $references);
            $draft = [...$page->draft, ...array_intersect_key($data, array_flip(self::DETAILS))];
            // Collection labels are legacy context, never a second editable registry.
            if (array_key_exists('collection_id', $data)) {
                $draft['collection'] = null;
            }
            foreach ($draft['sections'] as &$section) {
                foreach ($section['blocks'] as &$block) {
                    if ($block['type'] === 'file' && in_array((int) ($block['media_id'] ?? 0), $ids, true)) {
                        if (array_key_exists('title', $data)) {
                            $block['title'] = $data['title'];
                        }
                        if (array_key_exists('description', $data)) {
                            $block['body'] = $data['description'] ?? '';
                        }
                    }
                }
                unset($block);
            }
            unset($section);
            // An uploaded standalone resource shares its title/summary with its
            // file. Editing an attachment must not rename a larger authored page.
            if (collect($draft['sections'])->flatMap(fn ($section) => $section['blocks'])->count() === 1) {
                if (array_key_exists('title', $data)) {
                    $draft['title'] = $data['title'];
                }
                if (array_key_exists('description', $data)) {
                    $draft['summary'] = Str::limit($data['description'] ?? '', 240, '');
                }
            }
            $publisher = app(HubPublishing::class);
            $page = $publisher->save($actor, $page, $draft, (int) $page->draft_version);
            if (isset($data['owner_id']) && (int) $data['owner_id'] !== (int) $page->owner_id) {
                $publisher->changeOwner($actor, $page, User::findOrFail($data['owner_id']), (int) $page->draft_version);
                $page = $page->fresh();
            }
            AuditLog::create(['user_id' => $actor->id, 'action' => 'company_hub.document_details_updated',
                'auditable_type' => HubMedia::class, 'auditable_id' => $root->id,
                'meta' => ['page_version' => $page->draft_version, 'document_version' => $root->document_version]]);

            return ['saved' => true, ...$this->context($actor, $page, $root, (int) $actor->id)];
        });
    }

    /** The compact document workspace uses the same immutable release gates. */
    public function transition(User $actor, HubPage $page, HubMedia $media, array $data): array
    {
        $this->installed();

        return DB::transaction(function () use ($actor, $page, $media, $data): array {
            $actor = $this->actor($actor, (int) $data['actor_id'], true);
            [$page, $root] = $this->lockedDocument($actor, $page, $media, $data);
            abort_unless($this->references($page->draft, $root, false) !== [], 409, 'This document is absent from the saved draft.');
            $publisher = app(HubPublishing::class);
            abort_unless(in_array($data['action'], ['submit', 'publish'], true), 422);
            $revision = $data['action'] === 'publish'
                ? $publisher->publish($actor, $page, (int) $page->draft_version, $data['publish_at'] ?? null, $data['expires_at'] ?? null)
                : $publisher->submit($actor, $page, (int) $page->draft_version);

            return ['saved' => true, 'revision_id' => (int) $revision->id,
                'publication_state' => $revision->state === 'submitted' ? 'review' : ($revision->publish_at->isFuture() ? 'scheduled' : 'published'),
                ...$this->context($actor, $page->fresh(), $root, (int) $actor->id)];
        });
    }

    private function lockedDocument(User $actor, HubPage $page, HubMedia $media, array $data): array
    {
        $page = HubPage::whereKey($page->id)->lockForUpdate()->firstOrFail();
        $this->access()->assertEdit($actor, $page);
        abort_if($page->archived_at, 409, 'Restore this page before changing its document.');
        app(HubDraft::class)->assertSaved($page);
        $root = $this->root($media, true);
        abort_unless((int) $root->hub_page_id === (int) $page->id, 404);
        abort_unless((int) $page->draft_version === (int) $data['page_version'] && (int) $root->document_version === (int) $data['document_version'],
            409, 'This page or document changed. Reload its current versions before continuing.');

        return [$page, $root];
    }

    public function replace(User $actor, HubPage $page, HubMedia $media, array $data, ?UploadedFile $file = null): array
    {
        $this->installed();
        $written = null;
        try {
            return DB::transaction(function () use ($actor, $page, $media, $data, $file, &$written): array {
                $actor = $this->actor($actor, (int) $data['actor_id'], true);
                $page = HubPage::whereKey($page->id)->lockForUpdate()->firstOrFail();
                $this->access()->assertEdit($actor, $page);
                abort_if($page->archived_at, 409, 'Restore this page before changing its document.');
                app(HubDraft::class)->assertSaved($page);
                HubReadiness::assertEditable($page);
                $root = $this->root($media, true);
                abort_unless((int) $root->hub_page_id === (int) $page->id, 404);
                abort_unless((int) $page->draft_version === (int) $data['page_version'] && (int) $root->document_version === (int) $data['document_version'], 409, 'This page or document changed. Reload its current versions before continuing.');
                $current = $this->references($page->draft, $root, false);
                abort_unless($current !== [], 409, 'This document is absent from the saved draft. Use the page editor to choose its file block.');
                if (! DB::table('hub_document_versions')->where('root_media_id', $root->id)->exists()) {
                    $this->record($actor, $page, $root, $root, 'uploaded');
                }
                $library = app(HubMediaLibrary::class);
                $from = null;
                if ($file) {
                    $next = $library->upload($actor, $page, $file);
                } else {
                    $from = DB::table('hub_document_versions')->where('root_media_id', $root->id)->where('id', $data['history_id'])->first();
                    abort_unless($from, 404);
                    $snapshot = json_decode($from->snapshot, true, 512, JSON_THROW_ON_ERROR);
                    abort_unless($this->access()->canAuthorAudience($actor, $snapshot), 404);
                    $source = HubMedia::where('hub_page_id', $page->id)->whereKey($from->hub_media_id)->lockForUpdate()->firstOrFail();
                    abort_if(count($current) === 1 && (int) $current[0]->id === (int) $source->id, 409, 'That file edition is already in the saved draft.');
                    $next = $library->reuse($actor, $page, $source, $source->version, copy: true, fromSnapshot: true);
                    $written = $next->path;
                    $next->update(['name' => $snapshot['name']]);
                }
                $written = $next->path;
                $sequence = (int) $root->document_version + 1;
                $next->update(['document_root_id' => $root->id, 'document_sequence' => $sequence]);
                $root->update(['document_version' => $sequence]);
                $ids = array_map(fn ($item) => (int) $item->id, $current);
                $draft = $page->draft;
                foreach ($draft['sections'] as &$section) {
                    foreach ($section['blocks'] as &$block) {
                        if ($block['type'] === 'file' && in_array((int) ($block['media_id'] ?? 0), $ids, true)) {
                            $block['media_id'] = (int) $next->id;
                        }
                    }
                    unset($block);
                }
                unset($section);
                $page = app(HubPublishing::class)->save($actor, $page, $draft, (int) $page->draft_version);
                $this->record($actor, $page, $root, $next, $file ? 'replaced' : 'restored', $from?->id);
                AuditLog::create(['user_id' => $actor->id, 'action' => 'company_hub.document_'.($file ? 'replaced' : 'restored'),
                    'auditable_type' => HubMedia::class, 'auditable_id' => $root->id,
                    'meta' => ['media_id' => $next->id, 'page_version' => $page->draft_version, 'document_version' => $sequence]]);

                return ['saved' => true, ...$this->context($actor, $page, $root, (int) $actor->id)];
            });
        } catch (\Throwable $exception) {
            if ($written) {
                Storage::disk('private')->delete($written);
            }
            throw $exception;
        }
    }
}
