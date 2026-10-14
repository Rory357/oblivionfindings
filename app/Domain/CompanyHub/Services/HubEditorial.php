<?php

namespace App\Domain\CompanyHub\Services;

use App\Domain\CompanyHub\Models\HubPage;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class HubEditorial
{
    public function __construct(private readonly HubAccess $access) {}

    public function workspace(User $user): array
    {
        $this->access->assertEnter($user);
        abort_unless($user->canDo('company_hub.write') || $user->canDo('company_hub.edit_all') || $user->canDo('company_hub.layout'), 403);
        $pages = HubPage::with(['owner:id,name', 'currentRelease', 'revisions' => fn ($q) => $q->orderByDesc('id')])->get()
            ->filter(fn ($page) => $this->access->canEdit($user, $page));
        $reads = DB::table('hub_reads')->whereIn('hub_page_id', $pages->pluck('id'))->selectRaw('hub_page_id, count(*) as total')->groupBy('hub_page_id')->pluck('total', 'hub_page_id');
        $interactions = DB::table('hub_interactions')->whereIn('hub_page_id', $pages->pluck('id'))->whereNull('hidden_at')->get(['hub_page_id', 'kind']);
        $reports = DB::table('hub_interactions')->whereIn('hub_page_id', $pages->pluck('id'))->where('kind', 'resource_report')->latest()->get(['id', 'hub_page_id', 'body', 'created_at']);

        return $pages->map(function ($page) use ($reads, $interactions, $reports) {
            $latest = $page->revisions->first();
            $live = $page->liveRevision();
            $scheduled = $page->revisions->first(fn ($r) => $r->state === 'approved' && $r->publish_at?->isFuture());
            $submitted = $page->revisions->first(fn ($r) => $r->state === 'submitted' && $r->draft_version === $page->draft_version);
            $status = match (true) {
                (bool) $page->archived_at => 'archived',
                (bool) $submitted => 'review',
                $latest?->state === 'changes_requested' && $latest->draft_version === $page->draft_version => 'changes_requested',
                (bool) $scheduled => 'scheduled',
                $live && $live->draft_version === $page->draft_version => 'published',
                (bool) $live => 'changes',
                $latest?->state === 'approved' && ($latest->expires_at?->isPast() ?? false) => 'expired',
                default => 'draft',
            };

            return [
                'id' => $page->id, 'title' => $page->draft['title'], 'kind' => $page->kind,
                'reports' => $reports->where('hub_page_id', $page->id)->map(fn ($r) => ['id' => $r->id, 'created_at' => $r->created_at, ...json_decode($r->body, true)])->values()->all(),
                'owner' => $page->owner?->name, 'version' => $page->draft_version, 'status' => $status,
                'show_in_navigation' => $page->draft['show_in_navigation'], 'navigation_order' => $page->draft['navigation_order'],
                'review_on' => $page->draft['review_on'] ?? null, 'planned_for' => $page->draft['planned_for'] ?? null,
                'publish_at' => ($scheduled?->publish_at ?? $live?->publish_at)?->toIso8601String(),
                'expires_at' => ($scheduled?->expires_at ?? $live?->expires_at)?->toIso8601String(),
                'readers' => (int) ($reads[$page->id] ?? 0),
                'comments' => $interactions->where('hub_page_id', $page->id)->where('kind', 'comment')->count(),
                'reactions' => $interactions->where('hub_page_id', $page->id)->where('kind', 'reaction')->count(),
                'helpful' => $interactions->where('hub_page_id', $page->id)->where('kind', 'helpful')->count(),
            ];
        })->values()->all();
    }

    public function navigation(User $user, array $items): void
    {
        $this->access->assertEnter($user);
        abort_unless($user->canDo('company_hub.layout'), 403);
        DB::transaction(function () use ($user, $items): void {
            // Stable lock order avoids competing reorders deadlocking.
            $pages = HubPage::whereIn('id', array_column($items, 'id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($items as $index => $item) {
                $page = $pages->get($item['id']);
                abort_unless($page && $page->kind !== 'home', 404);
                app(HubPublishing::class)->save($user, $page, [
                    ...$page->draft, 'show_in_navigation' => $item['visible'], 'navigation_order' => $index,
                ], $item['version']);
            }
        });
    }
}
