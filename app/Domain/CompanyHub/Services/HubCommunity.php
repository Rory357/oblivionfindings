<?php

namespace App\Domain\CompanyHub\Services;

use App\Domain\CompanyHub\Models\HubPage;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class HubCommunity
{
    public const CATEGORIES = ['Better ways of working', 'Our people', 'Community', 'Sustainability', 'Something else'];

    public const STATUSES = ['open', 'reviewing', 'planned', 'in_progress', 'delivered', 'closed'];

    public function __construct(private readonly HubAccess $access, private readonly HubIdeas $ideas) {}

    public function ideas(User $user, string $search = ''): array
    {
        return $this->ideas->read($user, $search);
    }

    public function ideaOptions(User $user): array
    {
        return $this->ideas->options($user);
    }

    public function createIdea(User $user, array $data): int
    {
        return $this->ideas->create($user, $data);
    }

    public function updateIdea(User $user, int $id, array $data): void
    {
        $this->ideas->mutate($user, $id, $data);
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
}
