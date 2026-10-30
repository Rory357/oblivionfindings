<?php

namespace App\Domain\CompanyHub\Services;

use App\Domain\CompanyHub\Models\HubPage;
use App\Domain\CompanyHub\Models\HubRevision;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** A reader sees their own correction and the response, never the editor's report queue. */
class HubResourceReports
{
    public const ISSUES = ['Out of date', 'Incorrect information', 'Broken link', 'Other'];

    public function __construct(private readonly HubAccess $access) {}

    public function read(User $viewer, HubPage $page, ?int $before = null): array
    {
        $revision = $this->assertResource($viewer, $page);
        $query = DB::table('hub_interactions')->where('hub_page_id', $page->id)->where('user_id', $viewer->id)->where('kind', 'resource_report');
        if ($before !== null) {
            $query->where('id', '<', $before);
        }
        $items = $query->orderByDesc('id')->limit(51)->get(['id', 'body', 'created_at', 'updated_at']);
        $visible = $items->take(50);
        $releases = $this->releasedIds($page, $visible->all());

        return [
            ...$this->context($viewer, $page, $revision),
            'reports' => $visible->map(fn ($item) => $this->project($item, $revision, $releases))->filter()->values()->all(),
            'next_cursor' => $items->count() > 50 ? (int) $visible->last()->id : null,
        ];
    }

    public function detail(User $viewer, HubPage $page, int $id): array
    {
        $revision = $this->assertResource($viewer, $page);
        $item = DB::table('hub_interactions')->where('hub_page_id', $page->id)->where('user_id', $viewer->id)
            ->where('kind', 'resource_report')->where('id', $id)->first(['id', 'body', 'created_at', 'updated_at']);
        $report = $item ? $this->project($item, $revision, $this->releasedIds($page, [$item])) : null;
        abort_unless($report, 404);

        return [...$this->context($viewer, $page, $revision), 'report' => $report];
    }

    private function assertResource(User $viewer, HubPage $page): HubRevision
    {
        // Check today's published audience and staff access before looking up any report ID.
        $revision = $this->access->assertRead($viewer, $page);
        abort_unless(in_array($page->kind, ['page', 'knowledge'], true), 404);

        return $revision;
    }

    private function context(User $viewer, HubPage $page, HubRevision $revision): array
    {
        return ['actor_id' => (int) $viewer->id, 'page_id' => (int) $page->id, 'revision_id' => (int) $revision->id];
    }

    private function releasedIds(HubPage $page, array $items): array
    {
        // Old own reports remain useful after a new release, without exposing old article content.
        $ids = collect($items)->map(fn ($item) => json_decode($item->body, true)['revision_id'] ?? null)
            ->filter(fn ($id) => is_int($id) && $id > 0 && $id <= 2147483647)->unique()->values()->all();

        return HubRevision::where('hub_page_id', $page->id)->whereIn('id', $ids)->where('state', 'approved')->whereNotNull('approved_at')
            ->where('publish_at', '<=', now())->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    private function project(object $item, HubRevision $current, array $releases): ?array
    {
        $body = json_decode($item->body, true);
        if (! is_array($body) || ! is_int($body['revision_id'] ?? null) || ! in_array($body['revision_id'], $releases, true)
            || ! in_array($body['issue'] ?? null, self::ISSUES, true) || ! is_string($body['note'] ?? null)
            || ! in_array($body['resolved'] ?? null, [true, false, 0, 1, '0', '1'], true) || ! is_string($body['response'] ?? null)
            || ! is_int($body['version'] ?? null) || $body['version'] < 1) {
            return null;
        }

        return [
            'id' => (int) $item->id, 'revision_id' => $body['revision_id'], 'current_revision' => $body['revision_id'] === (int) $current->id,
            'issue' => $body['issue'], 'note' => $body['note'], 'resolved' => (bool) $body['resolved'], 'response' => $body['response'], 'version' => $body['version'],
            'created_at' => Carbon::parse($item->created_at)->toIso8601String(), 'updated_at' => Carbon::parse($item->updated_at)->toIso8601String(),
        ];
    }
}
