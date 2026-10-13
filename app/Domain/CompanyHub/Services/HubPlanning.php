<?php

namespace App\Domain\CompanyHub\Services;

use App\Domain\CompanyHub\Models\HubEditorialPlan;
use App\Domain\CompanyHub\Models\HubPage;
use App\Domain\Hr\Models\HrAnnouncement;
use App\Domain\Hr\Services\AnnouncementAudienceResolver;
use App\Domain\Hr\Services\HrCalendarAccessService;
use App\Domain\Hr\Services\HrCalendarAggregator;
use App\Models\AuditLog;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class HubPlanning
{
    public function __construct(private readonly HubAccess $access, private readonly HubPublishing $publishing, private readonly HrCalendarAccessService $calendarAccess) {}

    public function assertWorkspace(User $user): void
    {
        $this->access->assertEnter($user);
        abort_unless($user->canDo('company_hub.write') || $user->canDo('company_hub.edit_all') || $user->canDo('company_hub.layout'), 403);
    }

    private function candidate(array $audience, int $ownerId): HubPage
    {
        return new HubPage(['kind' => 'news', 'owner_id' => $ownerId, 'draft' => ['audience' => $audience]]);
    }

    private function canEdit(User $user, HubEditorialPlan $plan): bool
    {
        return $this->access->canEdit($user, $this->candidate($plan->audience, $plan->owner_id))
            && (! $plan->hub_page_id || ($plan->page && $this->access->canEdit($user, $plan->page)));
    }

    public function workspace(User $user, string $month): array
    {
        $this->assertWorkspace($user);
        $start = CarbonImmutable::parse($month.'-01', config('app.worker_timezone', 'Pacific/Auckland'))->startOfMonth();
        $end = $start->endOfMonth();
        $plans = HubEditorialPlan::with(['owner:id,name', 'page'])->whereBetween('target_date', [$start->toDateString(), $end->toDateString()])->get()
            ->filter(fn ($plan) => $this->canEdit($user, $plan))->values();
        $pages = collect(app(HubEditorial::class)->workspace($user))->keyBy('id');
        $statuses = ['draft' => 'Draft linked', 'review' => 'Awaiting review', 'changes_requested' => 'Changes requested', 'scheduled' => 'Scheduled', 'published' => 'Published', 'changes' => 'Unpublished changes', 'expired' => 'Expired', 'archived' => 'Archived'];
        $items = $plans->map(fn ($plan) => [
            'key' => 'plan:'.$plan->id, 'type' => 'plan', 'date' => $plan->target_date->toDateString(),
            'title' => $plan->title, 'owner' => $plan->owner?->name, 'status' => $plan->hub_page_id ? ($statuses[$pages->get($plan->hub_page_id)['status'] ?? 'draft']) : 'Planned', 'plan_id' => $plan->id,
        ])->all();
        foreach ($pages as $page) {
            foreach (['planned_for' => 'Planned publication', 'publish_at' => 'Publication', 'review_on' => 'Review due', 'expires_at' => 'Expires'] as $field => $label) {
                if (! $page[$field]) {
                    continue;
                }
                $date = CarbonImmutable::parse($page[$field], $start->timezone)->setTimezone($start->timezone)->toDateString();
                if (str_starts_with($date, $month) && $page['status'] !== 'archived') {
                    // A linked plan already represents this target, but actual publication/review dates remain visible.
                    if ($field === 'planned_for' && $plans->contains('hub_page_id', $page['id'])) {
                        continue;
                    }
                    $items[] = ['key' => 'page:'.$page['id'].':'.$field, 'type' => 'page', 'date' => $date, 'title' => $page['title'], 'owner' => $page['owner'], 'status' => $label, 'url' => '/company-hub/manage/'.$page['id'].'/edit'];
                }
            }
        }
        foreach (app(HrCalendarAggregator::class)->feed($start->toDateString(), $end->toDateString(), ['event'], [], $user) as $event) {
            $props = $event['extendedProps'];
            $event['extendedProps']['attachments'] = array_map(fn ($file) => [...$file, 'url' => '/company-hub/event-files/'.$file['id']], $props['attachments'] ?? []);
            $items[] = ['key' => $event['id'], 'type' => 'event', 'date' => CarbonImmutable::parse($event['start'])->setTimezone($start->timezone)->toDateString(), 'title' => $event['title'], 'owner' => $props['createdBy'] ?? '', 'status' => 'Company event', 'event' => $event];
        }
        $eventPublishing = app(HubEventPublishing::class);
        if ($eventPublishing->canCreate($user)) {
            foreach ($eventPublishing->workspace($user)['drafts'] as $draft) {
                if ($draft['archived_at'] || $draft['state'] === 'published') {
                    continue;
                }
                $date = CarbonImmutable::parse($draft['initial']['starts_at'])->setTimezone($start->timezone)->toDateString();
                if (str_starts_with($date, $month)) {
                    $items[] = ['key' => 'event-draft:'.$draft['id'], 'type' => 'event', 'date' => $date, 'title' => $draft['initial']['title'], 'owner' => $draft['owner'], 'status' => match ($draft['state']) {
                        'review' => 'Event awaiting review', 'changes_requested' => 'Event changes requested', default => 'Private event draft'
                    }, 'url' => '/company-hub?view=events&event_draft='.$draft['id']];
                }
            }
        }
        $items = [...$items, ...$this->announcements($user, $start, $end)];
        $owners = $this->calendarAccess->visibleCurrentStaffQuery($user)->orderBy('name')->get()->push($user)->unique('id')
            ->filter(fn ($owner) => ($user->canDo('company_hub.edit_all') || $owner->id === $user->id) && $this->access->canEdit($owner, $this->candidate(['type' => 'all', 'ids' => []], $owner->id)))
            ->map(fn ($owner) => $owner->only(['id', 'name']))->values()->all();

        return [
            'month' => $month, 'items' => $items, 'plans' => $plans->map(fn ($plan) => $this->serialize($plan))->all(),
            'owners' => $owners, 'viewer_id' => $user->id, 'can_create' => $user->canDo('company_hub.write'), 'can_assign' => $user->canDo('company_hub.edit_all'),
            'sites' => $this->calendarAccess->visibleSitesQuery($user)->orderBy('name')->get(['id', 'name'])->toArray(),
            'departments' => $this->calendarAccess->visibleDepartmentsQuery($user)->orderBy('name')->get(['id', 'name'])->toArray(),
        ];
    }

    private function announcements(User $user, CarbonImmutable $start, CarbonImmutable $end): array
    {
        // Hub editing alone never grants announcement management. Current staff is
        // checked by assertWorkspace; use the canonical announcement manager permission.
        $manager = $user->canDo('hr.announcements.manage');
        $query = HrAnnouncement::with(['creator:id,name', 'targets']);
        if (! $manager) {
            $query->active()->whereBetween('published_at', [$start->utc(), $end->utc()]);
        } else {
            $query->where(function ($dates) use ($start, $end) {
                $range = [$start->utc(), $end->utc()];
                $dates->whereBetween('published_at', $range)->orWhereBetween('expires_at', $range)
                    ->orWhereBetween('ack_deadline', $range)
                    ->orWhere(fn ($drafts) => $drafts->whereNull('published_at')->whereBetween('created_at', $range));
            });
        }
        $items = [];
        foreach ($query->get() as $notice) {
            if (! $manager && ! app(AnnouncementAudienceResolver::class)->includesCurrentUser($notice, $user)) {
                continue;
            }
            $status = match (true) {
                $notice->status === 'archived' => 'Archived announcement',
                $notice->status === 'draft' => 'Draft announcement',
                $notice->status === 'scheduled' || $notice->published_at?->isFuture() => 'Scheduled announcement',
                $notice->expires_at?->lte(now()) => 'Expired announcement',
                default => 'Active announcement',
            };
            $dates = ['publication' => [$notice->published_at ?? $notice->created_at, ! $notice->published_at && $notice->status === 'draft' ? 'Draft created' : $status]];
            if ($manager) {
                $dates['expiry'] = [$notice->expires_at, 'Announcement expires'];
                $dates['acknowledgement'] = [$notice->ack_deadline, 'Read confirmation due'];
            }
            foreach ($dates as $key => [$at, $label]) {
                if (! $at || ! $at->betweenIncluded($start->utc(), $end->utc())) {
                    continue;
                }
                $items[] = [
                    'key' => 'notice:'.$notice->id.':'.$key, 'type' => 'notice',
                    'date' => $at->copy()->timezone($start->timezone)->toDateString(), 'title' => $notice->title,
                    'owner' => $notice->creator?->name, 'status' => $label,
                    'url' => $manager ? '/hr/announcements/'.$notice->id : '/company-hub?view=updates&announcement='.$notice->id,
                ];
            }
        }

        return $items;
    }

    public function save(User $user, array $data, ?HubEditorialPlan $plan = null): array
    {
        $this->assertWorkspace($user);

        return DB::transaction(function () use ($user, $data, $plan) {
            if ($plan) {
                $plan = HubEditorialPlan::whereKey($plan->id)->lockForUpdate()->firstOrFail();
                abort_unless($this->canEdit($user, $plan), 404);
                abort_unless($plan->version === $data['version'], 409, 'This plan has changed. Reload the calendar before editing it again.');
                if ($plan->hub_page_id && ($plan->owner_id !== $data['owner_id'] || $plan->template !== $data['template'] || $plan->audience !== $data['audience'])) {
                    throw ValidationException::withMessages(['plan' => 'Change ownership or audience in the linked page editor. The original plan settings are retained.']);
                }
            } else {
                abort_unless($user->canDo('company_hub.write'), 403);
            }
            $candidate = $this->candidate($data['audience'], $data['owner_id']);
            abort_unless($this->access->canAuthorAudience($user, $candidate->draft), 422, 'Choose an audience within your approved access.');
            if (! $plan?->hub_page_id) {
                abort_unless($user->canDo('company_hub.edit_all') || $data['owner_id'] === (int) $user->id, 403);
                $owner = $this->calendarAccess->visibleCurrentStaffQuery($user)->whereKey($data['owner_id'])->first();
                if ($data['owner_id'] === (int) $user->id) {
                    $owner = $user;
                }
                abort_unless($owner && $this->access->canEdit($owner, $candidate), 422, 'Choose a current author who can edit this audience.');
            }
            $fields = collect($data)->only(['title', 'target_date', 'owner_id', 'template', 'brief', 'audience'])->all();
            if ($plan) {
                $plan->update([...$fields, 'version' => $plan->version + 1]);
            } else {
                $plan = HubEditorialPlan::create([...$fields, 'created_by' => $user->id]);
            }
            $this->audit($user, $plan, 'saved');

            return $this->serialize($plan->fresh(['owner', 'page']));
        });
    }

    public function start(User $user, HubEditorialPlan $plan, int $version): HubPage
    {
        $this->assertWorkspace($user);

        return DB::transaction(function () use ($user, $plan, $version) {
            $plan = HubEditorialPlan::whereKey($plan->id)->lockForUpdate()->firstOrFail();
            abort_unless($this->canEdit($user, $plan), 404);
            // Retrying an already linked plan opens the same draft, never creates another page.
            if ($plan->hub_page_id) {
                return $plan->page;
            }
            abort_unless($plan->version === $version, 409, 'This plan has changed. Reload the calendar before starting its draft.');
            $owner = User::find($plan->owner_id);
            abort_unless($owner && $this->access->canEdit($owner, $this->candidate($plan->audience, $plan->owner_id)), 422, 'Reassign the plan to a current author who can edit its audience.');
            $kind = match ($plan->template) {
                'guide' => 'knowledge', 'team' => 'department', 'campaign' => 'campaign', 'information', 'blank', 'welcome' => 'page', default => 'news'
            };
            $page = $this->publishing->create($user, $kind, $plan->title, $plan->template);
            $page->refresh();
            $page = $this->publishing->save($user, $page, [...$page->draft, 'audience' => $plan->audience, 'planned_for' => $plan->target_date->toDateString()], $page->draft_version);
            // Assignment does not grant any permissions; both actors were checked against the audience.
            $page->update(['owner_id' => $plan->owner_id]);
            $plan->update(['hub_page_id' => $page->id, 'version' => $plan->version + 1]);
            $this->audit($user, $plan, 'draft_started');

            return $page;
        });
    }

    private function serialize(HubEditorialPlan $plan): array
    {
        return [...$plan->only(['id', 'title', 'owner_id', 'template', 'brief', 'audience', 'version', 'hub_page_id']), 'target_date' => $plan->target_date->toDateString(), 'owner' => $plan->owner?->name, 'page_title' => $plan->page?->draft['title'] ?? null];
    }

    private function audit(User $user, HubEditorialPlan $plan, string $action): void
    {
        AuditLog::create(['user_id' => $user->id, 'action' => 'company_hub.plan.'.$action, 'auditable_type' => HubEditorialPlan::class, 'auditable_id' => $plan->id, 'meta' => ['version' => $plan->version, 'owner_id' => $plan->owner_id, 'hub_page_id' => $plan->hub_page_id]]);
    }
}
