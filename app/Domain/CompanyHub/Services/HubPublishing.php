<?php

namespace App\Domain\CompanyHub\Services;

use App\Domain\CompanyHub\Models\HubPage;
use App\Domain\CompanyHub\Models\HubRevision;
use App\Models\AuditLog;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class HubPublishing
{
    public function __construct(private readonly HubAccess $access, private readonly HubContent $content) {}

    public function create(User $actor, string $kind, string $title, string $template = 'story', ?array $source = null): HubPage
    {
        $this->access->assertEnter($actor);
        abort_unless($actor->canDo('company_hub.write'), 403);
        abort_unless(in_array($kind, ['page', 'news', 'home', 'department', 'knowledge', 'campaign'], true), 422);
        abort_if($kind === 'home' && ! $actor->canDo('company_hub.layout'), 403);

        return DB::transaction(function () use ($actor, $kind, $title, $template, $source): HubPage {
            $draft = HubContent::defaults($title);
            if ($kind !== 'home') {
                $draft['summary'] = '';
                $draft['sections'] = HubTemplates::sections($template);
            }
            if ($kind === 'knowledge') {
                $draft['resource_type'] = 'Guide';
            }
            if ($source) {
                $space = HubPage::whereKey($source['page_id'])->lockForUpdate()->firstOrFail();
                $revision = $this->access->assertRead($actor, $space);
                abort_unless($space->kind === 'department' && $kind === 'news', 422, 'Team updates start as news stories from a published department space.');
                abort_unless((int) $revision->id === $source['revision_id'], 409, 'This space changed. Reload it before creating an update.');
                abort_unless($this->access->canAuthorAudience($actor, $revision->content), 403);
                $allowed = array_column(app(HubDiscovery::class)->preferences($actor)['available_teams'], 'id');
                $draft['team_ids'] = array_values(array_intersect($revision->content['team_ids'] ?? [], $allowed));
                abort_unless($draft['team_ids'] !== [], 409, 'Connect this space to an available team before creating its update.');
                $draft['audience'] = $revision->content['audience'];
            }
            $page = HubPage::create([
                'kind' => $kind, 'slug' => $kind === 'home' ? 'home' : Str::slug($title).'-'.Str::lower(Str::random(8)),
                'owner_id' => $actor->id, 'draft' => $draft,
            ]);
            $this->audit($actor, $page, 'created');

            return $page;
        });
    }

    public function save(User $actor, HubPage $page, array $input, int $version): HubPage
    {
        return DB::transaction(function () use ($actor, $page, $input, $version): HubPage {
            $page = $this->locked($page, $actor, $version);
            $content = $this->content->validate($input, $actor, $page);
            if (! $actor->canDo('company_hub.layout') && (
                ($content['show_in_navigation'] !== ($page->draft['show_in_navigation'] ?? false))
                || ($content['navigation_order'] !== ($page->draft['navigation_order'] ?? 0))
            )) {
                abort(403, 'Changing hub navigation requires the layout permission.');
            }
            $page->update(['draft' => $content, 'draft_version' => $page->draft_version + 1]);
            // A review belongs to its exact snapshot. Editing it requires a fresh submission.
            $page->revisions()->where('state', 'submitted')->update(['state' => 'superseded']);

            return $page->fresh();
        });
    }

    public function submit(User $actor, HubPage $page, int $version): HubRevision
    {
        return DB::transaction(function () use ($actor, $page, $version): HubRevision {
            $page = $this->locked($page, $actor, $version);
            $content = $this->content->validate($page->draft, $actor, $page);
            $revision = $page->revisions()->firstOrCreate(
                ['draft_version' => $version, 'state' => 'submitted'],
                ['content' => $content, 'created_by' => $actor->id],
            );
            $this->audit($actor, $page, 'submitted', ['revision_id' => $revision->id]);

            return $revision;
        });
    }

    public function publish(User $actor, HubPage $page, int $version, ?string $publishAt, ?string $expiresAt): HubRevision
    {
        return DB::transaction(function () use ($actor, $page, $version, $publishAt, $expiresAt): HubRevision {
            $page = $this->locked($page, $actor, $version);
            abort_unless($actor->canDo('company_hub.publish'), 403);
            if (($page->draft['requires_approval'] ?? false)) {
                throw ValidationException::withMessages(['publish' => 'Submit this draft for another publisher to approve.']);
            }
            $revision = $this->submit($actor, $page, $version);

            return $this->approve($actor, $page, $revision, $publishAt, $expiresAt);
        });
    }

    public function approve(User $actor, HubPage $page, HubRevision $revision, ?string $publishAt, ?string $expiresAt): HubRevision
    {
        return DB::transaction(function () use ($actor, $page, $revision, $publishAt, $expiresAt): HubRevision {
            $page = HubPage::whereKey($page->id)->lockForUpdate()->firstOrFail();
            $this->access->assertEdit($actor, $page);
            abort_unless($actor->canDo('company_hub.publish'), 403);
            $revision = $page->revisions()->whereKey($revision->id)->lockForUpdate()->firstOrFail();
            abort_unless($revision->state === 'submitted', 409, 'This revision has already been reviewed.');
            abort_unless($revision->draft_version === $page->draft_version, 409, 'The draft has changed. Review its latest submitted version.');
            abort_if(($revision->content['requires_approval'] ?? false) && (int) $revision->created_by === (int) $actor->id, 403, 'Another publisher must approve this revision.');
            // Revalidate the submitted snapshot against current access, not only today's draft.
            $this->content->validate($revision->content, $actor, $page);
            $starts = $publishAt ? Carbon::parse($publishAt) : now();
            $ends = $expiresAt ? Carbon::parse($expiresAt) : null;
            if ($ends && $ends->lte($starts)) {
                throw ValidationException::withMessages(['expires_at' => 'Expiry must be after publication.']);
            }
            // A deliberate later publication replaces previously scheduled releases.
            $page->revisions()->where('state', 'approved')->where('publish_at', '>', now())
                ->whereKeyNot($revision->id)->update(['state' => 'superseded']);
            $revision->update([
                'state' => 'approved', 'approved_by' => $actor->id, 'approved_at' => now(),
                'reviewed_by' => $actor->id, 'reviewed_at' => now(),
                'publish_at' => $starts, 'expires_at' => $ends,
            ]);
            $page->update(['archived_at' => null]);
            $this->audit($actor, $page, $starts->isFuture() ? 'scheduled' : 'published', ['revision_id' => $revision->id]);

            return $revision->fresh();
        });
    }

    public function restore(User $actor, HubPage $page, HubRevision $revision, int $version): HubPage
    {
        return DB::transaction(function () use ($actor, $page, $revision, $version): HubPage {
            $page = $this->locked($page, $actor, $version);
            $revision = $page->revisions()->whereKey($revision->id)->firstOrFail();
            app(HubRevisionHistory::class)->assertView($actor, $page, $revision);
            $content = $this->content->validate($revision->content, $actor, $page);
            // Restore into the draft; readers continue to see the published snapshot.
            $page = $this->save($actor, $page, $content, $version);
            $this->audit($actor, $page, 'restored_to_draft', ['revision_id' => $revision->id]);

            return $page;
        });
    }

    public function archive(User $actor, HubPage $page, int $version): void
    {
        DB::transaction(function () use ($actor, $page, $version): void {
            $page = $this->locked($page, $actor, $version);
            abort_unless($actor->canDo('company_hub.publish'), 403);
            abort_if($page->kind === 'home', 422, 'Publish an updated homepage instead of archiving it.');
            $page->update(['archived_at' => now(), 'draft_version' => $page->draft_version + 1]);
            $page->revisions()->where('state', 'approved')->where('publish_at', '>', now())->update(['state' => 'superseded']);
            $this->audit($actor, $page, 'archived');
        });
    }

    public function reviewDecision(User $actor, HubPage $page, HubRevision $revision, string $action, int $version, ?string $note): void
    {
        DB::transaction(function () use ($actor, $page, $revision, $action, $version, $note): void {
            $page = $this->locked($page, $actor, $version);
            $revision = $page->revisions()->whereKey($revision->id)->lockForUpdate()->firstOrFail();
            app(HubRevisionHistory::class)->assertView($actor, $page, $revision);
            if ($action === 'cancel_schedule') {
                abort_unless($actor->canDo('company_hub.publish'), 403);
                abort_unless($revision->state === 'approved' && $revision->publish_at?->isFuture(), 409, 'This schedule has already changed or gone live.');
                $state = 'cancelled';
                $note = 'Scheduled publication cancelled. The current published page is unchanged.';
            } else {
                abort_unless($revision->state === 'submitted' && $revision->draft_version === $page->draft_version, 409, 'This submission has changed. Reload the current review.');
                if ($action === 'request_changes') {
                    abort_unless($actor->canDo('company_hub.publish'), 403);
                    if (trim($note ?? '') === '') {
                        throw ValidationException::withMessages(['note' => 'Add feedback so the author knows what to change.']);
                    }
                    $state = 'changes_requested';
                    $note = trim($note);
                } else {
                    abort_unless($action === 'withdraw', 422);
                    abort_unless($actor->canDo('company_hub.edit_all') || (int) $page->owner_id === (int) $actor->id || (int) $revision->created_by === (int) $actor->id, 403);
                    $state = 'withdrawn';
                    $note = 'Review withdrawn. Update the draft and submit it again when ready.';
                }
            }
            $revision->update(['state' => $state, 'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'review_note' => $note]);
            $this->audit($actor, $page, $action, ['revision_id' => $revision->id]);
        });
    }

    public function changeOwner(User $actor, HubPage $page, User $owner, int $version): void
    {
        DB::transaction(function () use ($actor, $page, $owner, $version): void {
            $page = $this->locked($page, $actor, $version);
            abort_unless($actor->canDo('company_hub.edit_all'), 403);
            $candidate = clone $page;
            $candidate->owner_id = $owner->id;
            abort_unless($this->access->canEdit($owner, $candidate), 422, 'Choose current staff who can edit this page and its audience.');
            $page->update(['owner_id' => $owner->id, 'draft_version' => $page->draft_version + 1]);
            $this->audit($actor, $page, 'owner_changed', ['owner_id' => $owner->id]);
        });
    }

    private function locked(HubPage $page, User $actor, int $version): HubPage
    {
        $page = HubPage::whereKey($page->id)->lockForUpdate()->firstOrFail();
        $this->access->assertEdit($actor, $page);
        abort_unless($page->draft_version === $version, 409, 'Someone has updated this draft. Reload before saving so their changes are preserved.');

        return $page;
    }

    private function audit(User $actor, HubPage $page, string $action, array $meta = []): void
    {
        AuditLog::create([
            'user_id' => $actor->id, 'action' => 'company_hub.'.$action,
            'auditable_type' => HubPage::class, 'auditable_id' => $page->id,
            'meta' => ['draft_version' => $page->draft_version, ...$meta],
        ]);
    }
}
