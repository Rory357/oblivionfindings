<?php

namespace App\Http\Controllers\It\Concerns;

use App\Domain\It\ItStaffDirectory;
use App\Domain\It\Services\ItWorkAccessService;
use App\Models\Asset;
use App\Models\ItKbArticle;
use App\Models\ItTicket;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

/**
 * Shared option lists for the IT hub and the ticket workspace.
 */
trait BuildsItOptions
{
    /** Current IT agents who share an approved Site or can work the ticket. */
    protected function staffUserOptions(User $viewer, ?ItTicket $ticket = null): array
    {
        $agents = $ticket
            ? ItStaffDirectory::agentsForTicket($ticket)
            : ItStaffDirectory::agentsForSharedSites($viewer);

        return $agents
            ->map(fn (User $agent) => [
                'id' => $agent->id,
                'name' => $agent->name,
            ])
            ->unique('id')
            ->sortBy('name')
            ->values()
            ->all();
    }

    /**
     * Active entries from the canonical (fleet-)assets register — the picker
     * source for linking a ticket to an asset. Never a parallel IT register.
     *
     * @return array<int, array{id: int, name: string, tag: string|null}>
     */
    protected function assetOptions(User $viewer, ?ItTicket $ticket = null): array
    {
        $query = Asset::query()
            ->where('status', 'active')
            ->when(
                $ticket?->site_id !== null,
                fn ($assets) => $assets->where('site_id', $ticket->site_id),
                function ($assets) use ($viewer): void {
                    if ($viewer->canDo('it.organisationWide')) {
                        return;
                    }

                    $siteIds = app(ItWorkAccessService::class)->approvedSiteIds($viewer);
                    $siteIds === []
                        ? $assets->whereRaw('1 = 0')
                        : $assets->whereIn('site_id', $siteIds);
                },
            )
            ->orderBy('name');

        return $query
            ->limit(200)
            ->get(['id', 'name', 'asset_tag'])
            ->map(fn (Asset $a) => ['id' => $a->id, 'name' => $a->name, 'tag' => $a->asset_tag])
            ->values()
            ->all();
    }

    /**
     * §I published knowledge-base titles for the ticket-workspace composer's
     * "Suggest from Knowledge" — lean (no body, never client detail) so an
     * agent replying can reference the guide that fixes it. Published only,
     * globally catalogued; Schema-guarded so a pre-migration render stays empty.
     *
     * @return array<int, array{id: int, title: string, category: string}>
     */
    protected function kbSuggestions(): array
    {
        if (! Schema::hasTable('it_kb_articles')) {
            return [];
        }

        return ItKbArticle::query()
            ->published()
            ->orderByDesc('updated_at')
            ->limit(100)
            ->get(['id', 'title', 'category'])
            ->map(fn (ItKbArticle $a) => [
                'id' => $a->id,
                'title' => $a->title,
                'category' => $a->category,
            ])
            ->values()
            ->all();
    }
}
