<?php

namespace App\Domain\CompanyHub\Services;

use App\Domain\CompanyHub\Models\HubPage;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class HubCommunity
{
    public const CATEGORIES = ['Better ways of working', 'Our people', 'Community', 'Sustainability', 'Something else'];

    public const STATUSES = ['open', 'reviewing', 'planned', 'delivered', 'closed'];

    public function __construct(private readonly HubAccess $access) {}

    public function ideas(User $user, string $search = ''): array
    {
        $this->access->assertEnter($user);
        $ideas = DB::table('hub_ideas')->whereNull('hidden_at')
            ->when($search !== '', function ($query) use ($search) {
                $pattern = '%'.addcslashes($search, '%_\\').'%';
                $query->where(fn ($terms) => $terms->where('title', 'like', $pattern)->orWhere('body', 'like', $pattern)->orWhere('category', 'like', $pattern));
            })->orderByDesc('updated_at')->limit(200)->get();
        $supports = DB::table('hub_idea_supports')->whereIn('hub_idea_id', $ideas->pluck('id'))->get();
        $comments = DB::table('hub_idea_comments')->whereIn('hub_idea_id', $ideas->pluck('id'))->whereNull('hidden_at')->orderBy('id')->get();
        $names = User::whereIn('id', $ideas->pluck('user_id')->merge($comments->pluck('user_id')))->pluck('name', 'id');

        return $ideas->filter(fn ($idea) => $search === '' || str_contains(mb_strtolower($idea->title.' '.$idea->body.' '.$idea->category), mb_strtolower($search)))->map(fn ($idea) => [
            'id' => $idea->id, 'title' => $idea->title, 'body' => $idea->body, 'category' => $idea->category,
            'status' => $idea->status, 'status_note' => $idea->status_note, 'version' => $idea->version,
            'author' => $names[$idea->user_id] ?? 'Former colleague', 'created_at' => $idea->created_at,
            'can_remove' => (int) $idea->user_id === (int) $user->id || $user->canDo('company_hub.moderate'),
            'support_count' => $supports->where('hub_idea_id', $idea->id)->count(),
            'supported' => $supports->where('hub_idea_id', $idea->id)->contains('user_id', $user->id),
            'comments' => $comments->where('hub_idea_id', $idea->id)->map(fn ($comment) => [
                'id' => $comment->id, 'body' => $comment->body, 'author' => $names[$comment->user_id] ?? 'Former colleague',
                'created_at' => $comment->created_at,
                'can_remove' => (int) $comment->user_id === (int) $user->id || $user->canDo('company_hub.moderate'),
            ])->values()->all(),
        ])->values()->all();
    }

    public function createIdea(User $user, array $data): int
    {
        $this->access->assertEnter($user);

        return DB::transaction(function () use ($user, $data): int {
            $id = DB::table('hub_ideas')->insertGetId([
                'user_id' => $user->id, 'title' => trim($data['title']), 'body' => trim($data['body']),
                'category' => $data['category'], 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit($user, 'idea_created', $id);

            return $id;
        });
    }

    public function updateIdea(User $user, int $id, array $data): void
    {
        $this->access->assertEnter($user);
        DB::transaction(function () use ($user, $id, $data): void {
            $idea = DB::table('hub_ideas')->where('id', $id)->whereNull('hidden_at')->lockForUpdate()->first();
            abort_unless($idea, 404);
            if ($data['action'] === 'support') {
                $query = DB::table('hub_idea_supports')->where('hub_idea_id', $id)->where('user_id', $user->id);
                if (! $data['active']) {
                    $query->delete();
                } elseif (! $query->exists()) {
                    DB::table('hub_idea_supports')->insert(['hub_idea_id' => $id, 'user_id' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
                }
            } elseif ($data['action'] === 'comment') {
                DB::table('hub_idea_comments')->insert(['hub_idea_id' => $id, 'user_id' => $user->id, 'body' => trim($data['body']), 'created_at' => now(), 'updated_at' => now()]);
            } elseif ($data['action'] === 'remove_comment') {
                $comment = DB::table('hub_idea_comments')->where('hub_idea_id', $id)->where('id', $data['comment_id'])->first();
                abort_unless($comment, 404);
                abort_unless((int) $comment->user_id === (int) $user->id || $user->canDo('company_hub.moderate'), 403);
                DB::table('hub_idea_comments')->where('id', $comment->id)->update(['hidden_at' => now()]);
                $this->audit($user, 'idea_comment_removed', $id, ['comment_id' => $comment->id]);
            } else {
                abort_unless($user->canDo('company_hub.moderate') || ($data['action'] === 'remove' && (int) $idea->user_id === (int) $user->id), 403);
                abort_unless((int) $data['version'] === (int) $idea->version, 409, 'This idea changed. Reload before updating it.');
                $change = $data['action'] === 'remove'
                    ? ['hidden_at' => now()]
                    : ['status' => $data['status'], 'status_note' => trim($data['status_note'])];
                DB::table('hub_ideas')->where('id', $id)->update([...$change, 'version' => $idea->version + 1, 'updated_at' => now()]);
                $this->audit($user, 'idea_'.$data['action'], $id, ['status' => $change['status'] ?? null]);
            }
        });
    }

    public function follow(User $user, HubPage $page, bool $following): void
    {
        $this->access->assertRead($user, $page);
        DB::transaction(function () use ($user, $page, $following): void {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $record = DB::table('hub_preferences')->where('user_id', $user->id)->first();
            $ids = array_values(array_diff(json_decode($record?->followed_pages ?? '[]', true), [$page->id]));
            if ($following) {
                abort_if(count($ids) >= 200, 422, 'You can follow up to 200 pages.');
                $ids[] = $page->id;
            }
            DB::table('hub_preferences')->updateOrInsert(['user_id' => $user->id], ['followed_pages' => json_encode($ids), 'updated_at' => now()]);
        });
    }

    public function trackRead(User $user, HubPage $page, ?int $revisionId = null): void
    {
        $revision = $this->access->assertRead($user, $page);
        DB::table('hub_reads')->upsert([
            ['hub_page_id' => $page->id, 'user_id' => $user->id, 'first_read_at' => now(), 'last_read_at' => now()],
        ], ['hub_page_id', 'user_id'], ['last_read_at']);
        // A delayed request from an older open article must not mark its replacement seen.
        if ($revisionId === (int) $revision->id) {
            DB::table('hub_update_seen')->insertOrIgnore([
                'user_id' => $user->id, 'update_key' => 'page:'.$page->id.':'.$revisionId,
                'batch' => (string) Str::uuid(), 'seen_at' => now(),
            ]);
        }
    }

    private function audit(User $user, string $action, int $idea, array $meta = []): void
    {
        AuditLog::create(['user_id' => $user->id, 'action' => 'company_hub.'.$action, 'auditable_type' => 'hub_idea', 'auditable_id' => $idea, 'meta' => $meta]);
    }
}
