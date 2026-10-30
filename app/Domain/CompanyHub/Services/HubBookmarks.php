<?php

namespace App\Domain\CompanyHub\Services;

use App\Domain\CompanyHub\Models\HubPage;
use App\Domain\Hr\Models\HrCalendarEvent;
use App\Domain\Hr\Services\HrCalendarAccessService;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Desired-state bookmark writes share the canonical store and current reader access. */
class HubBookmarks
{
    public function __construct(private readonly HubAccess $access, private readonly HrCalendarAccessService $calendar) {}

    public static function version(int $actorId, string $type, int $subjectId, int|string $rowId): string
    {
        return hash('sha256', json_encode([$actorId, $type, $subjectId, (string) $rowId]));
    }

    private function actor(User $user, ?int $expected): User
    {
        $actor = User::query()->whereKey($user->id)->lockForUpdate()->first();
        abort_unless($actor, 403);
        $this->access->assertEnter($actor);
        abort_if($expected !== null && $expected !== (int) $actor->id, 409, 'Your signed-in account changed. Reload this saved item before continuing.');

        return $actor;
    }

    private function subject(User $actor, string $type, int $id): void
    {
        if ($type === 'page') {
            $this->access->assertRead($actor, HubPage::whereKey($id)->lockForUpdate()->firstOrFail());
        } elseif ($type === 'media') {
            app(HubResourceDocuments::class)->published($actor, $id, lock: true);
        } else {
            abort_unless($type === 'event', 404);
            $event = HrCalendarEvent::active()->whereKey($id)->lockForUpdate()->firstOrFail();
            $this->calendar->assertCanViewEvent($actor, $event);
        }
    }

    private function state(User $actor, array $key, ?object $row): array
    {
        return [
            'actor_id' => (int) $actor->id, 'subject_type' => $key['subject_type'], 'subject_id' => $key['subject_id'],
            'bookmarked' => $row !== null,
            // Deletion removes the row; absence has no durable history generation.
            'version' => $row ? self::version((int) $actor->id, $key['subject_type'], $key['subject_id'], $row->id) : null,
        ];
    }

    public function read(User $user, array $data): array
    {
        return DB::transaction(function () use ($user, $data): array {
            $actor = $this->actor($user, (int) $data['actor_id']);
            $this->subject($actor, $data['subject_type'], (int) $data['subject_id']);
            $key = ['user_id' => (int) $actor->id, 'subject_type' => $data['subject_type'], 'subject_id' => (int) $data['subject_id']];

            return $this->state($actor, $key, DB::table('hub_bookmarks')->where($key)->lockForUpdate()->first());
        });
    }

    public function set(User $user, array $data, bool $guarded = false): array
    {
        return DB::transaction(function () use ($user, $data, $guarded): array {
            $actor = $this->actor($user, $guarded ? (int) $data['actor_id'] : null);
            $this->subject($actor, $data['subject_type'], (int) $data['subject_id']);
            $key = ['user_id' => (int) $actor->id, 'subject_type' => $data['subject_type'], 'subject_id' => (int) $data['subject_id']];
            $before = DB::table('hub_bookmarks')->where($key)->lockForUpdate()->first();
            $current = $this->state($actor, $key, $before);
            if ($guarded) {
                abort_unless($current['bookmarked'] === (bool) $data['expected_saved'] && $current['version'] === $data['expected_version'], 409, 'This saved item changed. Reload its current state before continuing.');
            }
            $desired = (bool) $data['saved'];
            if ($desired && ! $before) {
                DB::table('hub_bookmarks')->insert([...$key, 'created_at' => now(), 'updated_at' => now()]);
            } elseif (! $desired && $before) {
                DB::table('hub_bookmarks')->where($key)->delete();
            }
            $after = DB::table('hub_bookmarks')->where($key)->first();

            return ['saved' => true, ...$this->state($actor, $key, $after),
                'previous_saved' => $current['bookmarked'], 'changed' => $current['bookmarked'] !== $desired];
        });
    }
}
