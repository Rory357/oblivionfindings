<?php

namespace App\Domain\CompanyHub\Services;

use App\Domain\CompanyHub\Models\HubPage;
use App\Models\AuditLog;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class HubParticipation
{
    public const REACTIONS = ['heart', 'applause', 'celebrate'];

    public function __construct(private readonly HubAccess $access) {}

    public static function pollKey(array $block): string
    {
        // Editing the question or answers starts a new poll, never reassigns old votes.
        return hash('sha256', json_encode([$block['id'], $block['question'], $block['options']]));
    }

    public static function stepKey(array $step): string
    {
        return hash('sha256', json_encode([$step['id'], $step['title'], $step['url'] ?? '']));
    }

    public function read(User $user, HubPage $page): array
    {
        $revision = $this->access->assertRead($user, $page);
        $content = $revision->content;
        $items = DB::table('hub_interactions')->where('hub_page_id', $page->id)->whereNull('hidden_at')->get();
        $polls = [];
        foreach (collect($content['sections'])->flatMap(fn ($s) => $s['blocks'])->where('type', 'poll') as $block) {
            $key = self::pollKey($block);
            $votes = $items->where('kind', 'vote')->map(fn ($item) => ['user_id' => $item->user_id, ...json_decode($item->body, true)])
                ->where('key', $key);
            $polls[$block['id']] = [
                'key' => $key, 'total' => $votes->count(),
                'mine' => $votes->firstWhere('user_id', $user->id)['choice'] ?? null,
                'counts' => collect($block['options'])->map(fn ($option, $index) => $votes->where('choice', $index)->count())->all(),
                'closed' => $this->pollClosed($block),
            ];
        }
        $comments = $content['comments_enabled'] ? $items->where('kind', 'comment')->sortBy('id') : collect();
        $steps = collect($content['campaign']['steps'] ?? [])->map(function ($step) use ($items, $user) {
            $key = self::stepKey($step);
            $completed = $items->where('kind', 'campaign')->where('body', $key);

            return [...$step, 'key' => $key, 'done' => $completed->contains('user_id', $user->id), 'count' => $completed->count()];
        })->all();
        $names = User::whereIn('id', $comments->pluck('user_id'))->pluck('name', 'id');

        return [
            'page_id' => $page->id, 'polls' => $polls, 'campaign' => $steps,
            'helpful' => ['count' => $items->where('kind', 'helpful')->count(), 'mine' => $items->where('kind', 'helpful')->contains('user_id', $user->id)],
            'reactions' => $content['reactions_enabled'] ? collect(self::REACTIONS)->mapWithKeys(fn ($reaction) => [$reaction => [
                'count' => $items->where('kind', 'reaction')->where('body', $reaction)->count(),
                'mine' => $items->where('kind', 'reaction')->where('body', $reaction)->contains('user_id', $user->id),
            ]])->all() : [],
            'comments' => $comments->map(fn ($item) => [
                'id' => $item->id, 'name' => $names[$item->user_id] ?? 'Former colleague', 'body' => $item->body,
                'parent_id' => $item->parent_id,
                'created_at' => Carbon::parse($item->created_at)->toIso8601String(),
                'can_remove' => (int) $item->user_id === (int) $user->id || $user->canDo('company_hub.moderate'),
            ])->values()->all(),
        ];
    }

    public function write(User $user, HubPage $page, array $data): void
    {
        DB::transaction(function () use ($user, $page, $data): void {
            // Serialize per-page writes, including duplicate votes and reactions.
            $page = HubPage::whereKey($page->id)->lockForUpdate()->firstOrFail();
            $content = $this->access->assertRead($user, $page)->content;
            $kind = $data['kind'];
            $query = DB::table('hub_interactions')->where('hub_page_id', $page->id)->where('user_id', $user->id)->where('kind', $kind);
            if ($kind === 'comment') {
                abort_unless($content['comments_enabled'], 403);
                $body = trim($data['body'] ?? '');
                abort_if($body === '', 422, 'Write a comment first.');
                if (! empty($data['parent_id'])) {
                    $parent = DB::table('hub_interactions')->where('hub_page_id', $page->id)->where('id', $data['parent_id'])->where('kind', 'comment')->whereNull('hidden_at')->first();
                    abort_unless($parent && ! $parent->parent_id, 422, 'Reply to a current comment on this page.');
                }
            } elseif ($kind === 'helpful') {
                abort_unless(in_array($page->kind, ['page', 'knowledge'], true), 422);
                $query->delete();
                if (! $data['active']) {
                    return;
                }
                $body = 'yes';
            } elseif ($kind === 'campaign') {
                $step = collect($content['campaign']['steps'] ?? [])->firstWhere('id', $data['step_id']);
                abort_unless($step, 404);
                $body = self::stepKey($step);
                abort_unless(hash_equals($body, $data['step_key']), 409, 'This activity has changed. Reload to see the latest version.');
                abort_if(! empty($content['campaign']['ends_on']) && Carbon::parse($content['campaign']['ends_on'], config('app.worker_timezone', 'Pacific/Auckland'))->endOfDay()->isPast(), 422, 'This campaign has ended.');
                $query->where('body', $body)->delete();
                if (! $data['active']) {
                    return;
                }
            } elseif ($kind === 'reaction') {
                abort_unless($content['reactions_enabled'], 403);
                $query->delete();
                if (! $data['active']) {
                    return;
                }
                $body = $data['reaction'];
            } else {
                $block = collect($content['sections'])->flatMap(fn ($s) => $s['blocks'])->firstWhere('id', $data['block_id']);
                abort_unless($block && $block['type'] === 'poll', 404);
                $key = self::pollKey($block);
                abort_unless(hash_equals($key, $data['poll_key']), 409, 'This poll has changed. Reload to see the current question.');
                abort_if($this->pollClosed($block), 422, 'This poll is closed.');
                abort_unless(array_key_exists($data['choice'], $block['options']), 422);
                foreach ($query->get() as $vote) {
                    if ((json_decode($vote->body, true)['key'] ?? '') === $key) {
                        DB::table('hub_interactions')->where('id', $vote->id)->delete();
                    }
                }
                $body = json_encode(['key' => $key, 'choice' => $data['choice']]);
            }
            DB::table('hub_interactions')->insert([
                'hub_page_id' => $page->id, 'user_id' => $user->id, 'kind' => $kind, 'body' => $body,
                'parent_id' => $kind === 'comment' ? ($data['parent_id'] ?? null) : null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });
    }

    public function remove(User $user, HubPage $page, int $id): void
    {
        DB::transaction(function () use ($user, $page, $id): void {
            $page = HubPage::whereKey($page->id)->lockForUpdate()->firstOrFail();
            $this->access->assertRead($user, $page);
            $item = DB::table('hub_interactions')->where('hub_page_id', $page->id)->where('id', $id)->where('kind', 'comment')->first();
            abort_unless($item, 404);
            abort_unless((int) $item->user_id === (int) $user->id || $user->canDo('company_hub.moderate'), 403);
            DB::table('hub_interactions')->where('id', $id)->update(['hidden_at' => now(), 'updated_at' => now()]);
            DB::table('hub_interactions')->where('hub_page_id', $page->id)->where('parent_id', $id)->update(['hidden_at' => now(), 'updated_at' => now()]);
            AuditLog::create(['user_id' => $user->id, 'action' => 'company_hub.comment_removed',
                'auditable_type' => HubPage::class, 'auditable_id' => $page->id, 'meta' => ['comment_id' => $id]]);
        });
    }

    private function pollClosed(array $block): bool
    {
        return ! empty($block['closes_at']) && Carbon::parse($block['closes_at'], config('app.worker_timezone', 'Pacific/Auckland'))->endOfDay()->lte(now());
    }
}
