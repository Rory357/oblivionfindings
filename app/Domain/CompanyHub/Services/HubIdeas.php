<?php

namespace App\Domain\CompanyHub\Services;

use App\Domain\Hr\Services\HrCalendarAccessService;
use App\Domain\Hr\Services\HrCurrentStaffService;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/** Public staff ideas; routing and ownership never broaden access to work identities. */
class HubIdeas
{
    public function __construct(private readonly HubAccess $hub, private readonly HrCurrentStaffService $staff) {}

    public function ready(): bool
    {
        return Schema::hasColumn('hub_ideas', 'department_id') && Schema::hasColumn('hub_ideas', 'owner_id') && Schema::hasTable('hub_idea_updates');
    }

    private function currentAccess(): HrCalendarAccessService
    {
        return new HrCalendarAccessService(new UserSiteAccessService, $this->staff);
    }

    private function actor(User $user, array $data = []): User
    {
        $current = User::find($user->id);
        abort_unless($current, 403);
        $this->hub->assertEnter($current);
        abort_if(isset($data['actor_id']) && (int) $data['actor_id'] !== (int) $current->id, 409, 'Your signed-in account changed. Reopen the idea before continuing.');

        return $current;
    }

    /** Only public work identity fields from current visible staff. */
    private function contacts(User $viewer): Collection
    {
        return $this->currentAccess()->visibleCurrentStaffQuery($viewer)->with('hrEmployeeProfile:id,user_id,preferred_name,position_title')->get(['users.id', 'users.name'])->mapWithKeys(function (User $person): array {
            $profile = $person->hrEmployeeProfile;
            if (! $profile) {
                return [];
            }

            return [$person->id => ['id' => (int) $person->id, 'profile_id' => (int) $profile->id,
                'name' => $profile->preferred_name ?: $person->name, 'position' => $profile->position_title,
                'url' => '/company-hub/people/'.$profile->id]];
        });
    }

    public function options(User $user): array
    {
        $viewer = $this->actor($user);
        if (! $this->ready()) {
            return ['ready' => false, 'actor_id' => (int) $viewer->id, 'teams' => [], 'owners' => []];
        }
        $teams = $this->currentAccess()->visibleDepartmentsQuery($viewer)->orderBy('name')->get(['id', 'name'])->map(fn ($team) => ['id' => (int) $team->id, 'name' => $team->name])->all();
        $owners = $viewer->canDo('company_hub.moderate') ? $this->contacts($viewer)->filter(function ($contact): bool {
            $person = User::find($contact['id']);

            return $person && $this->hub->canEnter($person);
        })->sortBy('name')->map(fn ($contact) => collect($contact)->except('url')->all())->values()->all() : [];

        return ['ready' => true, 'actor_id' => (int) $viewer->id, 'teams' => $teams, 'owners' => $owners];
    }

    public function selected(User $user, int $id): ?array
    {
        return $this->read($user, selectedId: $id)[0] ?? null;
    }

    public function read(User $user, string $search = '', ?int $selectedId = null): array
    {
        return $this->browse($user, ['search' => $search], $selectedId)['data'];
    }

    /** Filters and ordering run before the bounded page, including public progress history. */
    public function browse(User $user, array $filters = [], ?int $selectedId = null): array
    {
        $viewer = $this->actor($user);
        $ready = $this->ready();
        $contacts = $ready ? $this->contacts($viewer) : collect();
        $teams = $ready ? $this->currentAccess()->visibleDepartmentsQuery($viewer)->get(['id', 'name'])->keyBy('id') : collect();
        $search = trim((string) ($filters['search'] ?? ''));
        $teamId = empty($filters['team_id']) ? null : (int) $filters['team_id'];
        $category = $filters['category'] ?? null;
        $status = $filters['status'] ?? null;
        $sort = in_array($filters['sort'] ?? '', ['updated', 'newest', 'oldest', 'most_supported'], true) ? $filters['sort'] : 'updated';
        $perPage = $selectedId === null ? max(1, min(50, (int) ($filters['per_page'] ?? 20))) : 1;
        $page = $selectedId === null ? max(1, (int) ($filters['page'] ?? 1)) : 1;
        $needle = mb_strtolower($search);
        $matchingOwners = $contacts->filter(fn ($person) => str_contains(mb_strtolower($person['name']), $needle))->keys();
        $matchingTeams = $teams->filter(fn ($team) => str_contains(mb_strtolower($team->name), $needle))->keys();
        $query = DB::table('hub_ideas')->whereNull('hidden_at')
            ->when($selectedId !== null, fn ($query) => $query->where('id', $selectedId))
            ->when($teamId !== null, fn ($query) => $ready && $teams->has($teamId) ? $query->where('department_id', $teamId) : $query->whereRaw('1 = 0'))
            ->when($category !== null && $category !== '', fn ($query) => $query->where('category', $category))
            ->when($status !== null && $status !== '', fn ($query) => $query->where('status', $status))
            ->when($search !== '', function ($query) use ($search, $ready, $matchingOwners, $matchingTeams): void {
                $pattern = '%'.addcslashes($search, '%_\\').'%';
                $query->where(function ($terms) use ($pattern, $ready, $matchingOwners, $matchingTeams): void {
                    $terms->where('title', 'like', $pattern)->orWhere('body', 'like', $pattern)->orWhere('category', 'like', $pattern);
                    if ($ready) {
                        $terms->orWhere('status_note', 'like', $pattern)->orWhereIn('owner_id', $matchingOwners)->orWhereIn('department_id', $matchingTeams)
                            ->orWhereExists(fn ($updates) => $updates->selectRaw('1')->from('hub_idea_updates')->whereColumn('hub_idea_id', 'hub_ideas.id')->where('note', 'like', $pattern));
                    }
                });
            });
        if ($sort === 'most_supported') {
            $query->select('hub_ideas.*')->selectSub(DB::table('hub_idea_supports')->selectRaw('count(*)')->whereColumn('hub_idea_id', 'hub_ideas.id'), 'browse_support_count')
                ->orderByDesc('browse_support_count')->orderByDesc('created_at');
        } else {
            $query->orderBy($sort === 'updated' ? 'updated_at' : 'created_at', $sort === 'oldest' ? 'asc' : 'desc');
        }
        $result = $query->orderBy('id', $sort === 'oldest' ? 'asc' : 'desc')->simplePaginate($perPage, ['*'], 'page', $page);
        $ideas = $result->getCollection();
        $ids = $ideas->pluck('id');
        $supports = DB::table('hub_idea_supports')->whereIn('hub_idea_id', $ids)->get();
        $comments = DB::table('hub_idea_comments')->whereIn('hub_idea_id', $ids)->whereNull('hidden_at')->orderBy('id')->get();
        $updates = $ready ? DB::table('hub_idea_updates')->whereIn('hub_idea_id', $ids)->orderBy('id')->get()->groupBy('hub_idea_id') : collect();
        // Existing publicly posted authorship is retained, separately from owner work-profile access.
        $names = User::whereIn('id', $ideas->pluck('user_id')->merge($comments->pluck('user_id')))->pluck('name', 'id');

        $data = $ideas->map(function ($idea) use ($viewer, $contacts, $teams, $supports, $comments, $updates, $names, $ready): array {
            $history = ($updates[$idea->id] ?? collect())->map(fn ($update) => $this->updateProjection($update, $contacts, $teams))->all();
            if ($history === [] && trim((string) $idea->status_note) !== '') {
                $history[] = $this->legacyProjection($idea);
            }
            $team = $teams[$idea->department_id ?? null] ?? null;

            return ['id' => (int) $idea->id, 'title' => $idea->title, 'body' => $idea->body, 'category' => $idea->category,
                'status' => $idea->status, 'status_note' => $idea->status_note, 'version' => (int) $idea->version,
                'author' => $names[$idea->user_id] ?? 'Former colleague', 'created_at' => $this->date($idea->created_at),
                'team' => $team ? ['id' => (int) $team->id, 'name' => $team->name] : null,
                'owner' => $contacts[$idea->owner_id ?? null] ?? null, 'updates' => $history,
                'can_update' => $ready && $viewer->canDo('company_hub.moderate'),
                'can_remove' => (int) $idea->user_id === (int) $viewer->id || $viewer->canDo('company_hub.moderate'),
                'support_count' => $supports->where('hub_idea_id', $idea->id)->count(),
                'supported' => $supports->where('hub_idea_id', $idea->id)->contains('user_id', $viewer->id),
                'comments' => $comments->where('hub_idea_id', $idea->id)->map(fn ($comment) => [
                    'id' => (int) $comment->id, 'body' => $comment->body, 'author' => $names[$comment->user_id] ?? 'Former colleague',
                    'created_at' => $this->date($comment->created_at),
                    'can_remove' => (int) $comment->user_id === (int) $viewer->id || $viewer->canDo('company_hub.moderate'),
                ])->values()->all()];
        })->values()->all();

        return ['data' => $data,
            'pagination' => ['page' => $page, 'per_page' => $perPage, 'has_more' => $result->hasMorePages(), 'next_page' => $result->hasMorePages() ? $page + 1 : null],
            'filters' => ['search' => $search, 'team_id' => $teamId, 'category' => $category, 'status' => $status, 'sort' => $sort]];
    }

    private function date(?string $date): ?string
    {
        return $date ? Carbon::parse($date)->toISOString() : null;
    }

    private function legacyProjection(object $idea): array
    {
        return ['id' => 0, 'status' => $idea->status, 'note' => $idea->status_note, 'created_at' => $this->date($idea->updated_at),
            'author' => null, 'owner' => null, 'team' => null, 'legacy' => true];
    }

    private function updateProjection(object $update, Collection $contacts, Collection $teams): array
    {
        $team = $teams[$update->department_id] ?? null;
        $author = $contacts[$update->posted_by_user_id] ?? null;

        return ['id' => (int) $update->id, 'status' => $update->status, 'note' => $update->note, 'created_at' => $this->date($update->created_at),
            'author' => $author ? collect($author)->only(['id', 'name'])->all() : null,
            'owner' => $contacts[$update->owner_id] ?? null, 'team' => $team ? ['id' => (int) $team->id, 'name' => $team->name] : null,
            'legacy' => (bool) $update->legacy];
    }

    private function department(User $actor, ?int $id): ?int
    {
        if ($id === null) {
            return null;
        }
        if (! $this->ready() || ! $this->currentAccess()->visibleDepartmentsQuery($actor)->whereKey($id)->lockForUpdate()->first(['id'])) {
            throw ValidationException::withMessages(['department_id' => 'Choose a current team available within your approved sites.']);
        }

        return $id;
    }

    private function owner(User $actor, ?int $id, ?int $department): ?int
    {
        if ($id === null) {
            return null;
        }
        $person = $this->currentAccess()->visibleCurrentStaffQuery($actor)->whereKey($id)->lockForUpdate()->first();
        if (! $person || ! $this->hub->canEnter($person) || ($department !== null && ! $this->currentAccess()->visibleDepartmentsQuery($person)->whereKey($department)->exists())) {
            throw ValidationException::withMessages(['owner_id' => 'Choose current visible staff who can access this team and Company Hub.']);
        }

        return (int) $person->id;
    }

    public function create(User $user, array $data): int
    {
        $actor = $this->actor($user, $data);

        return DB::transaction(function () use ($actor, $data): int {
            $department = $this->department($actor, isset($data['department_id']) ? (int) $data['department_id'] : null);
            $fields = ['user_id' => $actor->id, 'title' => trim($data['title']), 'body' => trim($data['body']),
                'category' => $data['category'], 'created_at' => now(), 'updated_at' => now()];
            if ($this->ready()) {
                $fields['department_id'] = $department;
            }
            $id = DB::table('hub_ideas')->insertGetId($fields);
            $this->audit($actor, 'idea_created', $id, ['department_id' => $department]);

            return $id;
        });
    }

    public function mutate(User $user, int $id, array $data): void
    {
        $actor = $this->actor($user, $data);
        DB::transaction(function () use ($actor, $id, $data): void {
            $idea = DB::table('hub_ideas')->where('id', $id)->whereNull('hidden_at')->lockForUpdate()->first();
            abort_unless($idea, 404);
            $action = $data['action'];
            if (! in_array($action, ['support', 'comment', 'remove_comment', 'status', 'remove'], true)) {
                throw ValidationException::withMessages(['action' => 'Choose a supported idea action.']);
            }
            if (in_array($action, ['status', 'remove'], true)) {
                abort_unless($actor->canDo('company_hub.moderate') || ($action === 'remove' && (int) $idea->user_id === (int) $actor->id), 403);
            }
            if (isset($data['version'])) {
                abort_unless((int) $data['version'] === (int) $idea->version, 409, 'This idea changed. Reload before updating it.');
            } elseif (in_array($action, ['status', 'remove'], true)) {
                abort(409, 'Reload this idea before updating it.');
            }
            if ($action === 'support') {
                $support = DB::table('hub_idea_supports')->where('hub_idea_id', $id)->where('user_id', $actor->id);
                if (! $data['active']) {
                    $support->delete();
                } elseif (! $support->exists()) {
                    DB::table('hub_idea_supports')->insert(['hub_idea_id' => $id, 'user_id' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
                }
            } elseif ($action === 'comment') {
                $commentId = DB::table('hub_idea_comments')->insertGetId(['hub_idea_id' => $id, 'user_id' => $actor->id, 'body' => trim($data['body']), 'created_at' => now(), 'updated_at' => now()]);
                DB::afterCommit(fn () => app(HubConversations::class)->dispatch('idea', $commentId));
            } elseif ($action === 'remove_comment') {
                $comment = DB::table('hub_idea_comments')->where('hub_idea_id', $id)->where('id', $data['comment_id'])->first();
                abort_unless($comment, 404);
                abort_unless((int) $comment->user_id === (int) $actor->id || $actor->canDo('company_hub.moderate'), 403);
                DB::table('hub_idea_comments')->where('id', $comment->id)->update(['hidden_at' => now()]);
                $this->audit($actor, 'idea_comment_removed', $id, ['comment_id' => (int) $comment->id]);
            } elseif ($action === 'status') {
                $this->progress($actor, $idea, $data);
            } else {
                DB::table('hub_ideas')->where('id', $id)->update(['hidden_at' => now(), 'version' => $idea->version + 1, 'updated_at' => now()]);
                $this->audit($actor, 'idea_remove', $id, ['version' => (int) $idea->version + 1]);
            }
        });
    }

    private function progress(User $actor, object $idea, array $data): void
    {
        $note = trim($data['status_note']);
        if (! in_array($data['status'], HubCommunity::STATUSES, true)) {
            throw ValidationException::withMessages(['status' => 'Choose a supported progress stage.']);
        }
        if ($note === '') {
            throw ValidationException::withMessages(['status_note' => 'Add an update for staff.']);
        }
        $fields = ['status' => $data['status'], 'status_note' => $note, 'version' => (int) $idea->version + 1, 'updated_at' => now()];
        $updateId = null;
        if ($this->ready()) {
            $department = $this->department($actor, array_key_exists('department_id', $data) ? ($data['department_id'] === null ? null : (int) $data['department_id']) : $idea->department_id);
            $owner = $this->owner($actor, isset($data['owner_id']) ? (int) $data['owner_id'] : $idea->owner_id, $department);
            $fields += ['department_id' => $department, 'owner_id' => $owner];
            if (trim((string) $idea->status_note) !== '' && ! DB::table('hub_idea_updates')->where('hub_idea_id', $idea->id)->exists()) {
                DB::table('hub_idea_updates')->insert(['hub_idea_id' => $idea->id, 'status' => $idea->status, 'note' => $idea->status_note,
                    'source_version' => (int) $idea->version, 'legacy' => true, 'created_at' => $idea->updated_at ?? $idea->created_at]);
            }
            $updateId = DB::table('hub_idea_updates')->insertGetId(['hub_idea_id' => $idea->id, 'status' => $data['status'], 'note' => $note,
                'posted_by_user_id' => $actor->id, 'owner_id' => $owner, 'department_id' => $department,
                'source_version' => $fields['version'], 'legacy' => false, 'created_at' => now()]);
        } elseif (isset($data['owner_id']) || isset($data['department_id']) || $data['status'] === 'in_progress') {
            throw ValidationException::withMessages(['status_note' => 'Idea progress is not available yet. Reopen the idea after it becomes available.']);
        }
        DB::table('hub_ideas')->where('id', $idea->id)->update($fields);
        $this->audit($actor, 'idea_status', (int) $idea->id, ['status' => $fields['status'], 'version' => $fields['version'],
            'department_id' => $fields['department_id'] ?? null, 'owner_id' => $fields['owner_id'] ?? null, 'update_id' => $updateId]);
    }

    private function audit(User $actor, string $action, int $idea, array $meta): void
    {
        AuditLog::create(['user_id' => $actor->id, 'action' => 'company_hub.'.$action, 'auditable_type' => 'hub_idea', 'auditable_id' => $idea, 'meta' => $meta]);
    }
}
