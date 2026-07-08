<?php

namespace App\Http\Controllers\It\Concerns;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Asset;
use App\Models\ItKbArticle;
use Illuminate\Support\Facades\Schema;

/**
 * Shared option lists for the IT hub and the ticket workspace.
 */
trait BuildsItOptions
{
    /** Active tenant staff usable as request/ticket assignees (IT owners). */
    protected function tenantUserOptions(int $tenantId): array
    {
        return HrEmployeeProfile::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->with('user:id,name')
            ->get()
            ->map(fn (HrEmployeeProfile $p) => [
                'id' => $p->user_id,
                'name' => $p->user?->name,
            ])
            ->filter(fn ($u) => $u['id'] && $u['name'])
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
    protected function assetOptions(): array
    {
        return Asset::query()
            ->where('status', 'active')
            ->orderBy('name')
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
     * tenant-scoped; Schema-guarded so a pre-migration render stays empty.
     *
     * @return array<int, array{id: int, title: string, category: string}>
     */
    protected function kbSuggestions(int $tenantId): array
    {
        if (! Schema::hasTable('it_kb_articles')) {
            return [];
        }

        return ItKbArticle::query()
            ->forTenant($tenantId)
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
