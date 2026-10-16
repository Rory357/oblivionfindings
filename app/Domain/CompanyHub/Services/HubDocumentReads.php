<?php

namespace App\Domain\CompanyHub\Services;

use App\Domain\CompanyHub\Models\HubPage;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Private, bounded reading history; neither an acknowledgement nor an update-seen signal. */
class HubDocumentReads
{
    private const KIND = 'document_read';

    private const LIMIT = 50;

    public function __construct(private readonly HubAccess $access, private readonly HubResourceDocuments $documents) {}

    public function record(User $actor, int $documentId, array $expected): array
    {
        return DB::transaction(function () use ($actor, $documentId, $expected): array {
            // Serialise this actor's upserts and pruning without introducing a second history store.
            $actor = User::whereKey($actor->id)->lockForUpdate()->first();
            abort_unless($actor, 403);
            $this->access->assertEnter($actor);
            abort_unless((int) $expected['actor_id'] === (int) $actor->id, 409, 'Your signed-in account changed. Reopen this document before continuing.');
            $media = $this->documents->published($actor, $documentId, true);
            // published() holds the canonical page lock until this transaction completes.
            $page = HubPage::with('currentRelease')->findOrFail($media->hub_page_id);
            $revision = $this->access->assertRead($actor, $page);
            abort_unless((int) $expected['media_id'] === (int) $media->id && (int) $expected['revision_id'] === (int) $revision->id,
                409, 'This published document changed. Reopen its current edition before continuing.');

            $at = now();
            $match = ['user_id' => $actor->id, 'kind' => self::KIND, 'body' => (string) $documentId];
            $existing = DB::table('hub_interactions')->where($match)->orderBy('id')->first();
            $id = $existing?->id ?? DB::table('hub_interactions')->insertGetId([
                ...$match, 'hub_page_id' => $page->id, 'created_at' => $at, 'updated_at' => $at,
            ]);
            if ($existing) {
                DB::table('hub_interactions')->where('id', $id)->update(['updated_at' => $at]);
            }
            DB::table('hub_interactions')->where($match)->where('id', '!=', $id)->delete();
            $keep = DB::table('hub_interactions')->where('user_id', $actor->id)->where('kind', self::KIND)
                ->orderByDesc('updated_at')->orderByDesc('id')->limit(self::LIMIT)->pluck('id');
            DB::table('hub_interactions')->where('user_id', $actor->id)->where('kind', self::KIND)->whereNotIn('id', $keep)->delete();

            return ['saved' => true, 'actor_id' => (int) $actor->id, 'document_id' => $documentId,
                'media_id' => (int) $media->id, 'revision_id' => (int) $revision->id, 'read_at' => $at->toIso8601String()];
        }, 3);
    }

    /** Call only with the current reader-authorised, search-filtered resource projections. */
    public function recent(User $actor, array $pages, array $resources): array
    {
        $actor = $actor->fresh();
        abort_unless($actor, 403);
        $this->access->assertEnter($actor);
        $pageIds = collect($pages)->whereIn('kind', ['page', 'knowledge'])->pluck('id')->all();
        $documents = collect($resources)->filter(fn ($row) => isset($row['document_id']))
            ->mapWithKeys(fn ($row) => [(int) $row['document_id'] => (int) $row['id']]);
        $documentReads = DB::table('hub_interactions')->where('user_id', $actor->id)->where('kind', self::KIND)
            ->whereIn('body', $documents->keys()->map(fn ($id) => (string) $id))->whereNotNull('updated_at')
            ->orderByDesc('updated_at')->orderByDesc('id')->limit(self::LIMIT)->get(['hub_page_id', 'body', 'updated_at'])
            ->filter(fn ($row) => (string) (int) $row->body === $row->body && $documents->get((int) $row->body) === (int) $row->hub_page_id);
        $pageReads = DB::table('hub_reads')->where('user_id', $actor->id)->whereIn('hub_page_id', $pageIds)
            ->orderByDesc('last_read_at')->limit(self::LIMIT)->get(['hub_page_id', 'last_read_at']);
        $entries = $pageReads->map(fn ($row) => ['key' => 'page:'.$row->hub_page_id, 'at' => $row->last_read_at])
            ->concat($documentReads->map(fn ($row) => ['key' => 'media:'.$row->body, 'at' => $row->updated_at]));

        return [
            'recentResources' => $entries->sort(fn ($left, $right) => strcmp($right['at'], $left['at']) ?: strcmp($left['key'], $right['key']))
                ->unique('key')->take(self::LIMIT)->pluck('key')->values()->all(),
            'recentDocuments' => $documentReads->pluck('body')->map(fn ($id) => (int) $id)->unique()->values()->all(),
        ];
    }
}
