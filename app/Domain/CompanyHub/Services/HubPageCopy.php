<?php

namespace App\Domain\CompanyHub\Services;

use App\Domain\CompanyHub\Models\HubMedia;
use App\Domain\CompanyHub\Models\HubPage;
use App\Domain\Hr\Services\HrCurrentStaffService;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** A complete authorised snapshot becomes an independent unpublished page. */
class HubPageCopy
{
    public function __construct(private readonly HrCurrentStaffService $staff, private readonly HubContent $content) {}

    private function actor(User $actor): User
    {
        $current = $actor->fresh();
        if (! $current) {
            throw new NotFoundHttpException;
        }

        return $current;
    }

    private function access(): HubAccess
    {
        return new HubAccess($this->staff, new UserSiteAccessService);
    }

    private function assertSource(User $actor, HubPage $page): void
    {
        $access = $this->access();
        $access->assertEnter($actor);
        abort_unless($actor->canDo('company_hub.write'), 403);
        $access->assertEdit($actor, $page);
    }

    /** Only relative or this application's canonical media URLs are assets. */
    private function urlMediaId(mixed $url): ?int
    {
        if (! is_string($url) || ! HubContent::safeUrl($url)) {
            return null;
        }
        $parts = parse_url($url);
        if (! $parts) {
            return null;
        }
        if (isset($parts['host'])) {
            $origin = parse_url((string) config('app.url'));
            if (strtolower($parts['host']) !== strtolower($origin['host'] ?? '') || ($parts['port'] ?? null) !== ($origin['port'] ?? null)) {
                return null;
            }
        }

        return preg_match('~^/company-hub/media/([1-9][0-9]*)/?$~', rawurldecode($parts['path'] ?? ''), $match) ? (int) $match[1] : null;
    }

    private function mapUrl(mixed $url, array $mediaMap): mixed
    {
        $id = $this->urlMediaId($url);

        if ($id === null || ! isset($mediaMap[$id])) {
            return $url;
        }
        $path = parse_url($url, PHP_URL_PATH);

        return substr_replace($url, '/company-hub/media/'.$mediaMap[$id], strpos($url, $path), strlen($path));
    }

    private function mediaIds(array $snapshot): array
    {
        $ids = empty($snapshot['cover_id']) ? [] : [(int) $snapshot['cover_id']];
        $urls = [$snapshot['hero']['action_url'] ?? null];
        foreach ($snapshot['sections'] ?? [] as $section) {
            foreach ($section['blocks'] ?? [] as $block) {
                if (! empty($block['media_id'])) {
                    $ids[] = (int) $block['media_id'];
                }
                $urls[] = $block['url'] ?? null;
                foreach ($block['links'] ?? [] as $link) {
                    $urls[] = $link['url'] ?? null;
                }
            }
        }
        foreach ($snapshot['campaign']['steps'] ?? [] as $step) {
            $urls[] = $step['url'] ?? null;
        }
        foreach ($urls as $url) {
            if ($id = $this->urlMediaId($url)) {
                $ids[] = $id;
            }
        }
        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }

    private function media(HubPage $page, array $snapshot, bool $lock): Collection
    {
        $ids = $this->mediaIds($snapshot);
        $query = HubMedia::where('hub_page_id', $page->id)->whereIn('id', $ids)->orderBy('id');
        $rows = ($lock ? $query->lockForUpdate() : $query)->get();
        if ($rows->count() !== count($ids) || $rows->contains(fn ($media) => ! in_array($media->mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf'], true)
            || ! Storage::disk('private')->exists($media->path))) {
            throw ValidationException::withMessages(['media_versions' => 'Some referenced files are no longer available on this page. Update its saved content before duplicating it.']);
        }

        return $rows;
    }

    private function versions(Collection $media): array
    {
        return $media->mapWithKeys(fn ($file) => [(int) $file->id => (int) $file->version])->all();
    }

    private function snapshot(User $actor, HubPage $page, string $mode): ?array
    {
        if ($mode === 'draft') {
            return app(HubDraft::class)->hasSavedDraft($page) ? $page->draft : null;
        }
        $release = $page->currentRelease()->first();
        if (! $release) {
            return null;
        }
        abort_unless($this->access()->canAuthorAudience($actor, $release->content), 404);

        return $release->content;
    }

    public function read(User $actor, HubPage $source, ?string $mode = null): array
    {
        $actor = $this->actor($actor);
        abort_unless($mode === null || in_array($mode, ['draft', 'published'], true), 422);
        $page = HubPage::with('currentRelease')->findOrFail($source->id);
        $this->assertSource($actor, $page);
        $hasDraft = app(HubDraft::class)->hasSavedDraft($page);
        $mode ??= $hasDraft ? 'draft' : ($page->currentRelease ? 'published' : null);
        $blocked = $page->kind === 'home' ? 'home' : null;
        $snapshot = $blocked === null && $mode ? $this->snapshot($actor, $page, $mode) : null;
        $media = collect();
        if ($blocked === null && ! $snapshot) {
            $blocked = $mode === 'draft' && app(HubDraft::class)->isDiscarded($page) ? 'discarded' : 'no_snapshot';
        }
        if ($snapshot) {
            try {
                $snapshot = $this->content->validate($snapshot, $actor, $page);
                $media = $this->media($page, $snapshot, false);
            } catch (ValidationException) {
                $blocked = 'content_unavailable';
            }
        }

        return [
            'actor_id' => (int) $actor->id, 'page_id' => (int) $page->id, 'version' => (int) $page->draft_version,
            'can_duplicate' => $blocked === null, 'source' => $mode,
            'revision_id' => $mode === 'published' && $snapshot ? (int) $page->currentRelease?->id : null,
            'media_versions' => (object) $this->versions($media), 'blocked_reason' => $blocked,
        ];
    }

    private function remap(array $snapshot, array $mediaMap): array
    {
        if (! empty($snapshot['cover_id'])) {
            $snapshot['cover_id'] = $mediaMap[$snapshot['cover_id']];
        }
        if (isset($snapshot['hero']['action_url'])) {
            $snapshot['hero']['action_url'] = $this->mapUrl($snapshot['hero']['action_url'], $mediaMap);
        }
        foreach ($snapshot['sections'] as &$section) {
            foreach ($section['blocks'] as &$block) {
                if (! empty($block['media_id'])) {
                    $block['media_id'] = $mediaMap[$block['media_id']];
                }
                if (isset($block['url'])) {
                    $block['url'] = $this->mapUrl($block['url'], $mediaMap);
                }
                foreach ($block['links'] ?? [] as $index => $link) {
                    $block['links'][$index]['url'] = $this->mapUrl($link['url'], $mediaMap);
                }
            }
            unset($block);
        }
        unset($section);
        foreach ($snapshot['campaign']['steps'] ?? [] as $index => $step) {
            if (isset($step['url'])) {
                $snapshot['campaign']['steps'][$index]['url'] = $this->mapUrl($step['url'], $mediaMap);
            }
        }

        return $snapshot;
    }

    public function duplicate(User $actor, HubPage $source, array $data): HubPage
    {
        $actor = $this->actor($actor);
        abort_unless(in_array($data['source'], ['draft', 'published'], true), 422);
        $paths = [];
        try {
            return DB::transaction(function () use ($actor, $source, $data, &$paths): HubPage {
                $page = HubPage::whereKey($source->id)->lockForUpdate()->firstOrFail();
                $this->assertSource($actor, $page);
                abort_if($page->kind === 'home', 422, 'The company homepage cannot be duplicated. Use a page starter for a new page.');
                abort_unless((int) $data['actor_id'] === (int) $actor->id, 409, 'Your signed-in account changed. Reload this page before duplicating it.');
                abort_unless((int) $data['version'] === (int) $page->draft_version, 409, 'The source page changed. Reload it before duplicating it.');
                $snapshot = $this->snapshot($actor, $page, $data['source']);
                abort_unless($snapshot, 409, 'There is no saved snapshot of this kind to duplicate. Recover the discarded draft or choose the published page.');
                $revision = $data['source'] === 'published' ? $page->currentRelease()->lockForUpdate()->first() : null;
                abort_unless($revision ? (int) $revision->id === (int) $data['revision_id'] : $data['revision_id'] === null, 409, 'The published snapshot changed. Reload this page before duplicating it.');
                if ($revision) {
                    $snapshot = $revision->content;
                }
                $snapshot = $this->content->validate($snapshot, $actor, $page);
                $media = $this->media($page, $snapshot, true);
                $expected = $this->versions($media);
                $requested = [];
                foreach ($data['media_versions'] as $id => $version) {
                    abort_unless(preg_match('/^[1-9][0-9]*$/', (string) $id), 409, 'The page files changed. Refresh duplication before trying again.');
                    $requested[(int) $id] = (int) $version;
                }
                ksort($requested);
                abort_unless($expected === $requested, 409, 'The page files changed. Refresh duplication before trying again.');
                $title = rtrim(mb_substr($snapshot['title'], 0, 153)).' — copy';
                $neutral = HubContent::defaults($title);
                $neutral['audience'] = $snapshot['audience'];
                $neutral['sections'] = [];
                $copy = HubPage::create([
                    'kind' => $page->kind, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(8)),
                    'owner_id' => $actor->id, 'draft' => $neutral, 'draft_version' => 1,
                ]);
                $map = [];
                foreach ($media as $file) {
                    $copied = app(HubMediaLibrary::class)->reuse($actor, $copy, $file, (int) $file->version, fromSnapshot: true);
                    $paths[] = $copied->path;
                    $map[(int) $file->id] = (int) $copied->id;
                }
                $snapshot = $this->remap($snapshot, $map);
                $snapshot['title'] = $title;
                $snapshot['show_in_navigation'] = false;
                $snapshot['navigation_order'] = 0;
                $snapshot['featured'] = false;
                $snapshot['planned_for'] = null;
                $current = $this->actor($actor);
                $this->assertSource($current, $page);
                $content = $this->content->validate($snapshot, $current, $copy);
                $copy->update(['draft' => $content]);
                AuditLog::create([
                    'user_id' => $current->id, 'action' => 'company_hub.duplicated', 'auditable_type' => HubPage::class, 'auditable_id' => $copy->id,
                    'meta' => ['source_page_id' => $page->id, 'source_version' => $page->draft_version, 'source' => $data['source'], 'revision_id' => $revision?->id],
                ]);

                return $copy->fresh();
            });
        } catch (\Throwable $exception) {
            // Files are outside the encompassing DB transaction. Compensate
            // every successful private copy after a later failure as well.
            Storage::disk('private')->delete($paths);
            throw $exception;
        }
    }
}
