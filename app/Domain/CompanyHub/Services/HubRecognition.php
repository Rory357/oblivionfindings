<?php

namespace App\Domain\CompanyHub\Services;

use App\Domain\Hr\Models\HrKudos;
use App\Domain\Hr\Services\FeedService;
use App\Domain\Hr\Services\HrAudienceAccessService;
use App\Domain\Hr\Services\HrCalendarAccessService;
use App\Domain\Hr\Services\HrCurrentStaffService;
use App\Domain\Hr\Services\HrProfilePhotoStorageService;
use App\Domain\Hr\Services\HrRecognitionMedia;
use App\Domain\Hr\Services\HrRecognitionMentions;
use App\Domain\Hr\Services\HrRecognitionReplies;
use App\Domain\Hr\Services\HrTeamRecognition;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/** Shared editorial recognition always projects the canonical HR records. */
class HubRecognition
{
    public function __construct(
        private readonly HubAccess $access,
        private readonly FeedService $feed,
        private readonly HrAudienceAccessService $audiences,
        private readonly HrCurrentStaffService $staff,
    ) {}

    public function canFeature(User $viewer): bool
    {
        $viewer = $viewer->fresh() ?? $viewer;

        return $this->access->canEnter($viewer) && $viewer->canDo('hr.recognition.view') && $viewer->canDo('company_hub.layout');
    }

    private function settings(): ?object
    {
        // Source can be deployed before its additive migration. Reads stay
        // usable and never create or repair shared settings as a side effect.
        return Schema::hasTable('hub_recognition_spotlight') ? DB::table('hub_recognition_spotlight')->where('id', 1)->first() : null;
    }

    public function read(User $viewer): array
    {
        $viewer = $viewer->fresh() ?? $viewer;
        $this->access->assertEnter($viewer);
        $row = $this->settings();
        $canFeature = $row !== null && $this->canFeature($viewer);
        $spotlight = null;
        if ($viewer->canDo('hr.recognition.view') && $row?->kudos_id) {
            $kudos = HrKudos::find($row->kudos_id);
            $spotlight = $kudos ? $this->describe($viewer, $kudos) : null;
        }

        return [
            'actor_id' => (int) $viewer->id, 'version' => (int) ($row?->version ?? 1),
            'can_feature' => $canFeature, 'spotlight' => $spotlight,
            'selection_unavailable' => $canFeature && $row->kudos_id !== null && $spotlight === null,
        ];
    }

    public function select(User $actor, array $data): array
    {
        $actor = $actor->fresh() ?? $actor;
        $this->access->assertEnter($actor);
        abort_unless($this->canFeature($actor), 403);
        abort_unless((int) $data['actor_id'] === (int) $actor->id, 409, 'Your signed-in account changed. Reload recognition before changing the spotlight.');
        abort_unless(Schema::hasTable('hub_recognition_spotlight'), 503, 'Spotlight editing is temporarily unavailable. Please try again later.');

        DB::transaction(function () use ($actor, $data): void {
            $row = DB::table('hub_recognition_spotlight')->where('id', 1)->lockForUpdate()->first();
            abort_unless($row, 503, 'Spotlight editing is temporarily unavailable. Please try again later.');
            abort_unless((int) $row->version === (int) $data['version'], 409, 'Another editor changed the spotlight. Reload recognition before trying again.');
            if ($data['kudos_id'] !== null) {
                $kudos = HrKudos::whereKey($data['kudos_id'])->lockForUpdate()->first();
                if (! $kudos || ! $this->describe($actor, $kudos)) {
                    throw ValidationException::withMessages(['kudos_id' => 'Choose public recognition that is currently available to you, or clear the spotlight.']);
                }
            }
            $next = (int) $row->version + 1;
            DB::table('hub_recognition_spotlight')->where('id', 1)->update([
                'kudos_id' => $data['kudos_id'], 'version' => $next, 'updated_by' => $actor->id, 'updated_at' => now(),
            ]);
            AuditLog::create([
                'user_id' => $actor->id, 'action' => 'company_hub.recognition_spotlight',
                'auditable_type' => self::class, 'auditable_id' => 1,
                'meta' => ['kudos_id' => $data['kudos_id'], 'version' => $next, 'previous_version' => (int) $row->version],
            ]);
        });

        return $this->read($actor);
    }

    public function listing(User $viewer, string $search = ''): array
    {
        return $this->browse($viewer, ['search' => $search])['data'];
    }

    /** Filter the complete canonical source before applying the reader's page. */
    public function browse(User $viewer, array $input): array
    {
        $viewer = $viewer->fresh() ?? $viewer;
        $this->access->assertEnter($viewer);
        $search = mb_substr(trim((string) ($input['search'] ?? '')), 0, 200);
        $teamId = max(0, (int) ($input['team_id'] ?? 0));
        $category = in_array($input['category'] ?? '', array_keys(FeedService::KUDOS_CATEGORIES), true) ? $input['category'] : '';
        $recipientType = in_array($input['recipient_type'] ?? '', ['person', 'team'], true) ? $input['recipient_type'] : null;
        $sort = in_array($input['sort'] ?? '', ['newest', 'oldest', 'most_appreciated'], true) ? $input['sort'] : 'newest';
        $page = max(1, (int) ($input['page'] ?? 1));
        $perPage = min(50, max(1, (int) ($input['per_page'] ?? 20)));
        $filters = ['search' => $search, 'team_id' => $teamId ?: null, 'category' => $category, 'recipient_type' => $recipientType, 'sort' => $sort];
        $pagination = ['page' => $page, 'per_page' => $perPage, 'has_more' => false, 'next_page' => null];
        if (! $viewer->canDo('hr.recognition.view') || ($recipientType === 'team' && ! app(HrTeamRecognition::class)->ready())) {
            return ['data' => [], 'pagination' => $pagination, 'filters' => $filters];
        }
        $query = $this->feed->visibleKudosQuery($viewer)->where('is_public', true)->with(['feedPost', 'reactions'])->withCount('reactions')
            ->when($recipientType !== null && app(HrTeamRecognition::class)->ready(), fn ($query) => $query->where('recipient_type', $recipientType))
            ->when($category !== '', fn ($query) => $query->where('category', $category))
            ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search): void {
                $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
                $query->where('message', 'like', $term)->orWhereHas('fromUser', fn ($users) => $users->where('name', 'like', $term))
                    ->orWhereHas('toUser', fn ($users) => $users->where('name', 'like', $term));
                if (app(HrTeamRecognition::class)->ready()) {
                    $query->orWhereHas('toDepartment', fn ($teams) => $teams->where('name', 'like', $term));
                }
            }));
        if ($teamId) {
            $query->where(function ($query) use ($teamId): void {
                $query->whereHas('toUser.hrEmployeeProfile', fn ($profiles) => $profiles->where('department_id', $teamId));
                if (app(HrTeamRecognition::class)->ready()) {
                    $query->orWhere('to_department_id', $teamId);
                }
            });
        }
        if ($sort === 'most_appreciated') {
            $query->orderByDesc('reactions_count');
        }
        $query->orderBy('created_at', $sort === 'oldest' ? 'asc' : 'desc')->orderBy('id', $sort === 'oldest' ? 'asc' : 'desc');
        // Audience and identity visibility are canonical predicates. Apply them
        // before the page offset, so hidden rows never consume a reader's page.
        $skip = ($page - 1) * $perPage;
        $rows = [];
        foreach ($query->lazy(100) as $kudos) {
            $row = $this->describe($viewer, $kudos);
            if ($row === null) {
                continue;
            }
            if ($skip > 0) {
                $skip--;

                continue;
            }
            $rows[] = $row;
            if (count($rows) > $perPage) {
                break;
            }
        }
        $pagination['has_more'] = count($rows) > $perPage;
        $pagination['next_page'] = $pagination['has_more'] ? $page + 1 : null;

        return ['data' => array_slice($rows, 0, $perPage), 'pagination' => $pagination, 'filters' => $filters];
    }

    public static function readerUrl(int $id): string
    {
        return '/company-hub?view=shoutouts&recognition='.$id.'#kudos-'.$id;
    }

    public function selected(User $viewer, int $id): ?array
    {
        $viewer = $viewer->fresh();
        abort_unless($viewer, 403);
        $this->access->assertEnter($viewer);
        if (! $viewer->canDo('hr.recognition.view')) {
            return null;
        }
        $kudos = HrKudos::find($id);

        return $kudos ? $this->describe($viewer, $kudos) : null;
    }

    public function teams(User $viewer): array
    {
        $viewer = $viewer->fresh() ?? $viewer;
        $this->access->assertEnter($viewer);

        return $viewer->canDo('hr.recognition.give') ? app(HrTeamRecognition::class)->options($viewer) : [];
    }

    /** Eligible user IDs with only currently authorised public work labels. */
    public function recipients(User $viewer): array
    {
        $viewer = $viewer->fresh() ?? $viewer;
        $this->access->assertEnter($viewer);
        if (! $viewer->canDo('hr.recognition.give')) {
            return [];
        }
        $sites = new UserSiteAccessService;
        $calendar = new HrCalendarAccessService($sites, $this->staff);
        $siteNames = $calendar->visibleSitesQuery($viewer)->pluck('name', 'id');
        $departments = $calendar->visibleDepartmentsQuery($viewer)->get(['id', 'name'])->keyBy('id');
        $people = $sites->applyStaffScope($this->staff->currentUsersQuery(), $viewer)
            ->with('hrEmployeeProfile:id,user_id,position_title,primary_site_id,department_id')
            ->orderBy('name')->get(['users.id', 'users.name']);

        return $people->map(function (User $person) use ($siteNames, $departments): array {
            $profile = $person->hrEmployeeProfile;
            $department = $departments[$profile?->department_id] ?? null;

            return ['id' => (int) $person->id, 'name' => $person->name,
                'position' => $profile?->position_title ?: null, 'site' => $siteNames[$profile?->primary_site_id] ?? null,
                'department' => $department?->name, 'department_id' => $department ? (int) $department->id : null];
        })->all();
    }

    public function describe(User $viewer, HrKudos $kudos): ?array
    {
        $viewer = $viewer->fresh() ?? $viewer;
        if (! $this->access->canEnter($viewer) || ! $viewer->canDo('hr.recognition.view') || ! $kudos->is_public
            || ! in_array($kudos->getAttributes()['recipient_type'] ?? 'person', ['person', 'team'], true)) {
            return null;
        }
        $kudos->loadMissing(['feedPost', 'reactions', 'replies.user:id,name']);
        $post = $kudos->feedPost;
        // A shared feature respects the feed audience even for a giver or
        // recipient who can directly read their own private conversation.
        if (! $post || $post->post_type !== 'kudos' || (int) $post->user_id !== (int) $kudos->from_user_id
            || ! $this->feed->canViewKudos($kudos, $viewer)
            || ! $this->audiences->resolveUsers([['type' => $post->target_audience ?: 'all', 'value' => $post->target_value]])->contains('id', $viewer->id)) {
            return null;
        }
        $team = $kudos->isTeamRecognition();
        $departmentTarget = $kudos->getAttributes()['to_department_id'] ?? null;
        if (($team && ($kudos->to_user_id !== null || $departmentTarget === null))
            || (! $team && ($kudos->to_user_id === null || $departmentTarget !== null))) {
            return null;
        }
        // Reused services must not retain a viewer's old Site grants after
        // archival, reassignment or permission changes.
        $calendar = new HrCalendarAccessService(new UserSiteAccessService, $this->staff);
        $replies = app(HrRecognitionReplies::class)->project($viewer, $kudos);
        $profiles = $calendar->visibleCurrentProfilesQuery($viewer)
            ->whereIn('user_id', array_filter([$kudos->from_user_id, $team ? null : $kudos->to_user_id, ...$replies->pluck('user_id')->all()]))
            ->with(['user:id,name', 'departmentRelation:id,name'])->get()->keyBy('user_id');
        $author = $profiles->get($kudos->from_user_id);
        $recipient = $team ? null : $profiles->get($kudos->to_user_id);
        if (! $author || (! $team && ! $recipient)) {
            return null;
        }
        $departmentId = $team ? $departmentTarget : $recipient->department_id;
        $department = $departmentId ? $calendar->visibleDepartmentsQuery($viewer)->whereKey($departmentId)->first(['id', 'name']) : null;
        if ($team && ! $department) {
            return null;
        }

        return [
            'id' => $kudos->id, 'message' => $kudos->message, 'from' => $author->user?->name,
            'author_id' => (int) $kudos->from_user_id, 'author_url' => '/company-hub/people/'.$author->id,
            'avatar_url' => $this->avatar($author), 'recipient_avatar_url' => $recipient ? $this->avatar($recipient) : null,
            'to' => $team ? $department->name : $recipient->user?->name,
            'to_id' => $team ? null : (int) $kudos->to_user_id,
            'recipient_type' => $team ? 'team' : 'person', 'to_department_id' => $team ? (int) $department->id : null,
            'team_name' => $department?->name, 'team_ids' => $department ? [(int) $department->id] : [],
            'recipient_url' => $team ? null : '/company-hub/people/'.$recipient->id,
            'category' => $kudos->categoryLabel(), 'category_key' => $kudos->category,
            'impact' => $kudos->impactKey(), 'impact_label' => $kudos->impactLabel(),
            'created_at' => $kudos->created_at->toIso8601String(),
            'reactions' => collect(FeedService::REACTION_EMOJIS)->mapWithKeys(fn ($emoji) => [$emoji => [
                'count' => $kudos->reactions->where('emoji', $emoji)->count(),
                'mine' => $kudos->reactions->where('emoji', $emoji)->contains('user_id', $viewer->id),
            ]])->all(),
            'replies' => $replies->filter(fn ($reply) => $profiles->has($reply['user_id']))
                ->map(fn ($reply) => [...$reply, 'name' => $profiles->get($reply['user_id'])->user?->name,
                    'author_url' => '/company-hub/people/'.$profiles->get($reply['user_id'])->id,
                    'avatar_url' => $this->avatar($profiles->get($reply['user_id']))])->values()->all(),
            'mentions' => app(HrRecognitionMentions::class)->project($viewer, $kudos),
            'images' => app(HrRecognitionMedia::class)->project($kudos),
            'can_reply' => $this->feed->canReplyKudos($kudos, $viewer),
            'social_available' => app(HrRecognitionReplies::class)->socialReady(),
        ];
    }

    private function avatar($profile): ?string
    {
        return app(HrProfilePhotoStorageService::class)->readableDisk($profile->profile_photo_path, (int) $profile->id) !== null
            ? route('hr.directory.photo', ['profile' => $profile->id]) : null;
    }
}
