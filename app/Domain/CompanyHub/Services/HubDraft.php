<?php

namespace App\Domain\CompanyHub\Services;

use App\Domain\CompanyHub\Models\HubPage;
use App\Domain\Hr\Services\HrCurrentStaffService;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Reversible saved-draft lifecycle; published releases remain immutable. */
class HubDraft
{
    public function __construct(private readonly HrCurrentStaffService $staff) {}

    private function currentAccess(): HubAccess
    {
        return new HubAccess($this->staff, new UserSiteAccessService);
    }

    private function actor(User $actor): User
    {
        $current = $actor->fresh();
        if (! $current) {
            throw new NotFoundHttpException;
        }

        return $current;
    }

    private function navigationAllowed(User $actor, array $from, array $to): bool
    {
        return $actor->canDo('company_hub.layout') || (
            ($from['show_in_navigation'] ?? false) === ($to['show_in_navigation'] ?? false)
            && ($from['navigation_order'] ?? 0) === ($to['navigation_order'] ?? 0)
        );
    }

    /** Canonical local starter choices; opening them never saves a draft. */
    public static function starterLayouts(string $kind): array
    {
        $starters = array_map(fn ($plan) => [...$plan, 'sections' => HubTemplates::sections($plan['key'])], HubTemplates::catalogue());
        if ($kind === 'home') {
            array_unshift($starters, ['key' => 'home', 'label' => 'Starting Home design', 'sections' => HubContent::defaults()['sections']]);
        }

        return $starters;
    }

    public function ready(): bool
    {
        return Schema::hasTable('hub_draft_discards') && Schema::hasColumn('hub_pages', 'draft_discarded_at');
    }

    public function isDiscarded(HubPage $page): bool
    {
        return ($page->getAttributes()['draft_discarded_at'] ?? null) !== null;
    }

    public function hasSavedDraft(HubPage $page): bool
    {
        if ($this->isDiscarded($page)) {
            return false;
        }
        $release = $page->relationLoaded('currentRelease') ? $page->currentRelease : $page->currentRelease()->first();

        return ! $release || (int) $page->draft_version !== (int) $release->draft_version;
    }

    /** Normal saving is the only implicit transition from baseline to draft. */
    public static function savedFields(): array
    {
        return Schema::hasColumn('hub_pages', 'draft_discarded_at') ? ['draft_discarded_at' => null] : [];
    }

    public function assertSaved(HubPage $page): void
    {
        abort_if($this->isDiscarded($page), 409, 'This saved draft was discarded. Save new changes or recover the discarded draft before submitting it.');
    }

    private function blockedReason(HubPage $page): ?string
    {
        if ($page->revisions()->where('state', 'submitted')->where('draft_version', $page->draft_version)->exists()) {
            return 'review';
        }
        if ($page->revisions()->where('state', 'approved')->where('publish_at', '>', now())->exists()) {
            return 'schedule';
        }

        return null;
    }

    private function recovery(HubPage $page): ?object
    {
        return $this->ready() && $this->isDiscarded($page)
            ? DB::table('hub_draft_discards')->where('hub_page_id', $page->id)->orderByDesc('id')->first() : null;
    }

    private function snapshot(object $record): array
    {
        return json_decode($record->content, true, 512, JSON_THROW_ON_ERROR);
    }

    public function read(User $actor, HubPage $page, ?array $snapshotContext = null): array
    {
        $actor = $this->actor($actor);
        $page = HubPage::with('currentRelease')->findOrFail($page->id);
        $access = $this->currentAccess();
        $access->assertEdit($actor, $page);
        if ($snapshotContext !== null) {
            abort_unless((int) ($snapshotContext['actor_id'] ?? 0) === (int) $actor->id, 409, 'Your signed-in account changed. Reload the draft before previewing it.');
            abort_unless((int) ($snapshotContext['version'] ?? 0) === (int) $page->draft_version, 409, 'Someone changed this draft. Reload it before previewing the saved version.');
        }
        $ready = $this->ready();
        $hasDraft = $this->hasSavedDraft($page);
        $blocked = $this->blockedReason($page);
        $release = $page->currentRelease;
        $publishedBaseline = ! $release || $access->canAuthorAudience($actor, $release->content);
        $recovery = $this->recovery($page);
        $snapshot = $recovery ? $this->snapshot($recovery) : null;
        $canRecover = $snapshot && ! $recovery->restored_at && $access->canAuthorAudience($actor, $snapshot);
        $canRestoreNavigation = ! $snapshot || $this->navigationAllowed($actor, $page->draft, $snapshot);
        $canDiscardNavigation = $this->navigationAllowed($actor, $page->draft, $release?->content ?? HubContent::defaults());

        return [
            'actor_id' => (int) $actor->id, 'page_id' => (int) $page->id, 'version' => (int) $page->draft_version,
            'has_saved_draft' => $hasDraft,
            'can_discard' => $ready && $hasDraft && $blocked === null && $publishedBaseline && $canDiscardNavigation,
            'blocked_reason' => ! $ready ? 'unavailable' : ($blocked ?? (! $hasDraft ? 'no_saved_draft' : (! $publishedBaseline ? 'published_audience' : (! $canDiscardNavigation ? 'layout' : null)))),
            'recovery' => $canRecover ? [
                'id' => (int) $recovery->id, 'title' => $snapshot['title'], 'discarded_at' => Carbon::parse($recovery->created_at)->toIso8601String(),
                'can_restore' => $blocked === null && $canRestoreNavigation,
            ] : null,
            ...($snapshotContext !== null ? ['current_draft' => $hasDraft ? [
                'source' => 'draft', 'actor_id' => (int) $actor->id, 'page_id' => (int) $page->id,
                'version' => (int) $page->draft_version, 'saved_at' => $page->updated_at?->toIso8601String(),
                'content' => $page->draft,
            ] : null] : []),
        ];
    }

    private function locked(User $actor, HubPage $page, array $data): HubPage
    {
        $page = HubPage::whereKey($page->id)->lockForUpdate()->firstOrFail();
        $this->currentAccess()->assertEdit($actor, $page);
        abort_unless((int) $data['actor_id'] === (int) $actor->id, 409, 'Your signed-in account changed. Reload the draft before trying again.');
        abort_unless((int) $data['version'] === (int) $page->draft_version, 409, 'Someone changed this draft. Reload it before trying again.');
        abort_unless($this->ready(), 503, 'Saved-draft recovery is temporarily unavailable. Please try again later.');
        $blocked = $this->blockedReason($page);
        abort_if($blocked === 'review', 409, 'Withdraw the current review before discarding or recovering this draft.');
        abort_if($blocked === 'schedule', 409, 'Cancel the scheduled publication before discarding or recovering this draft.');

        return $page;
    }

    private function baseline(User $actor, HubPage $page): array
    {
        // currentRelease deliberately includes expired and archived pages. A
        // discard must never revive an older release or unarchive a page.
        $release = $page->currentRelease()->first();
        if ($release) {
            abort_unless($this->currentAccess()->canAuthorAudience($actor, $release->content), 404);

            return $release->content;
        }
        $baseline = HubContent::defaults($page->draft['title']);
        $baseline['sections'] = $page->kind === 'home' ? $baseline['sections'] : [];
        $baseline['audience'] = $page->draft['audience'];
        $baseline['requires_approval'] = $page->draft['requires_approval'] ?? false;
        $allowed = array_column(app(HubDiscovery::class)->preferences($actor)['available_teams'], 'id');
        $baseline['team_ids'] = array_values(array_intersect($page->draft['team_ids'] ?? [], $allowed));

        return $baseline;
    }

    public function discard(User $actor, HubPage $page, array $data): array
    {
        $actor = $this->actor($actor);
        DB::transaction(function () use ($actor, $page, $data): void {
            $page = $this->locked($actor, $page, $data);
            abort_unless($this->hasSavedDraft($page), 409, 'There is no saved unpublished draft to discard.');
            $baseline = $this->baseline($actor, $page);
            abort_unless($this->navigationAllowed($actor, $page->draft, $baseline), 403, 'Changing hub navigation requires the layout permission.');
            $at = now();
            $id = DB::table('hub_draft_discards')->insertGetId([
                'hub_page_id' => $page->id, 'content' => json_encode($page->draft, JSON_THROW_ON_ERROR),
                'draft_version' => $page->draft_version, 'discarded_by' => $actor->id,
                'created_at' => $at, 'updated_at' => $at,
            ]);
            $page->update(['draft' => $baseline, 'draft_version' => $page->draft_version + 1, 'draft_discarded_at' => $at]);
            $this->audit($actor, $page, 'discarded', $id);
        });

        return $this->read($actor, $page);
    }

    public function restore(User $actor, HubPage $page, array $data): array
    {
        $actor = $this->actor($actor);
        DB::transaction(function () use ($actor, $page, $data): void {
            $page = $this->locked($actor, $page, $data);
            abort_unless($this->isDiscarded($page), 409, 'This page now has a new draft. Reload it before recovering an older draft.');
            $record = DB::table('hub_draft_discards')->where('hub_page_id', $page->id)->where('id', $data['discard_id'])->lockForUpdate()->first();
            abort_unless($record, 404);
            abort_unless(! $record->restored_at && (int) $record->id === (int) $this->recovery($page)?->id, 409, 'The discarded draft has changed. Reload its recovery options.');
            $snapshot = $this->snapshot($record);
            abort_unless($this->currentAccess()->canAuthorAudience($actor, $snapshot), 404);
            // Reuse canonical save validation and navigation authority as well
            // as clearing the marker. Recovery must not bypass layout rights.
            $page = app(HubPublishing::class)->save($actor, $page, $snapshot, $page->draft_version);
            DB::table('hub_draft_discards')->where('id', $record->id)->update(['restored_at' => now(), 'restored_by' => $actor->id, 'updated_at' => now()]);
            $this->audit($actor, $page, 'recovered', $record->id);
        });

        return ['draft' => $page->fresh()->draft, ...$this->read($actor, $page)];
    }

    private function audit(User $actor, HubPage $page, string $action, int $recordId): void
    {
        AuditLog::create([
            'user_id' => $actor->id, 'action' => 'company_hub.draft_'.$action,
            'auditable_type' => HubPage::class, 'auditable_id' => $page->id,
            'meta' => ['discard_id' => $recordId, 'draft_version' => $page->draft_version],
        ]);
    }
}
