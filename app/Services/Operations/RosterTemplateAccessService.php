<?php

namespace App\Services\Operations;

use App\Models\Client;
use App\Models\RosterTemplate;
use App\Models\ServiceContext;
use App\Models\Site;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;

/** Complete template aggregates, scoped before any person or pattern labels are hydrated. */
final class RosterTemplateAccessService
{
    public const SITE_BYPASS_PERMISSIONS = ['shifts.manageAny'];

    public const ACTION_PERMISSIONS = [
        'create' => ['roster_templates.create', 'rostering.create'],
        'update' => ['roster_templates.update', 'rostering.edit'],
        'duplicate' => ['roster_templates.create', 'rostering.create'],
        'delete' => ['roster_templates.delete', 'rostering.delete'],
    ];

    public function __construct(private readonly UserSiteAccessService $sites) {}

    public function can(User $actor, string $action): bool
    {
        return $actor->isApproved() && collect(self::ACTION_PERMISSIONS[$action])->contains(fn ($key) => $actor->canDo($key));
    }

    public function capabilities(User $actor): array
    {
        $approved = $actor->isApproved();

        // The existing route middleware requires canonical catalogue grants;
        // controller compatibility ORs do not make a blocked URL usable.
        return ['can_view' => $approved && ($actor->canDo('rostering.viewAny') || $actor->canDo('roster_templates.viewAny')),
            'can_create' => $approved && $actor->canDo('roster_templates.create'),
            'can_edit' => $approved && $actor->canDo('roster_templates.update'),
            'can_duplicate' => $approved && $actor->canDo('roster_templates.create'),
            'can_delete' => $approved && $actor->canDo('roster_templates.delete'),
            'can_apply' => $approved && $actor->canDo('roster_templates.update')];
    }

    public function options(User $actor): array
    {
        abort_unless($this->capabilities($actor)['can_view'], 403);
        $siteIds = $this->sites->accessibleSiteIds($actor, self::SITE_BYPASS_PERMISSIONS);

        $contexts = ServiceContext::query()->where('is_active', true)->availableToSites($siteIds)
            ->orderBy('name')->orderBy('id')->get(['id', 'name', 'type', 'site_id']);
        $defaultId = ServiceContext::defaultId();

        return ['clients' => $this->sites->applyClientScope(Client::query(), $actor, self::SITE_BYPASS_PERMISSIONS)
            ->orderBy('first_name')->orderBy('id')->get(['id', 'first_name', 'last_name', 'site_id', 'service_context_id']),
            'staff' => $this->sites->applyStaffScope(User::query(), $actor, self::SITE_BYPASS_PERMISSIONS)
                ->orderBy('name')->orderBy('id')->get(['id', 'name']),
            'sites' => Site::query()->whereKey($siteIds)->orderBy('name')->orderBy('id')->get(['id', 'name']),
            'serviceContexts' => $contexts,
            'defaultServiceContextId' => $contexts->contains('id', $defaultId) ? $defaultId : null];
    }

    public function scope(Builder $query, User $actor): Builder
    {
        $siteIds = $this->sites->accessibleSiteIds($actor, self::SITE_BYPASS_PERMISSIONS);
        $clients = Client::query()->whereIn('site_id', $siteIds)->select('clients.id');
        $staff = $this->sites->applyStaffScope(User::query(), $actor, self::SITE_BYPASS_PERMISSIONS)->select('users.id');

        return $query->whereDoesntHave('templateShifts', function (Builder $rows) use ($clients, $staff): void {
            $clientColumn = $rows->qualifyColumn('client_id');
            $rows->where(function (Builder $invalid) use ($clients, $staff, $clientColumn): void {
                $invalid->whereNull('client_id')->orWhereNotIn('client_id', $clients)
                    ->orWhere(fn (Builder $worker) => $worker->whereNotNull('user_id')->whereNotIn('user_id', $staff))
                    ->orWhere(function (Builder $context) use ($clientColumn): void {
                        $context->whereNotNull('service_context_id')->whereDoesntHave('serviceContext', fn (Builder $contexts) => $contexts
                            ->where('is_active', true)->where(fn (Builder $sites) => $sites->whereNull('service_contexts.site_id')
                            ->orWhere('service_contexts.site_id', Client::query()->select('site_id')->whereColumn('clients.id', $clientColumn))));
                    });
            });
        })->where(function (Builder $visible) use ($actor): void {
            $visible->whereHas('templateShifts')->orWhere('created_by', $actor->id);
            if ($this->sites->canBypass($actor, self::SITE_BYPASS_PERMISSIONS)) {
                $visible->orWhereDoesntHave('templateShifts');
            }
        });
    }

    public function templates(User $actor): array
    {
        $caps = $this->capabilities($actor);
        abort_unless($caps['can_view'], 403);
        $templates = $this->scope(RosterTemplate::query(), $actor)->with('templateShifts')->orderByDesc('is_active')->orderBy('name')->orderBy('id')->get();
        $options = $this->options($actor);
        $clients = $options['clients']->keyBy('id');
        $staff = $options['staff']->keyBy('id');
        $contexts = $options['serviceContexts']->keyBy('id');
        // Repeat the complete source check against the hydrated scalar rows;
        // labels are attached only after the whole aggregate survives it.
        $templates = $templates->filter(function ($template) use ($clients, $staff, $contexts): bool {
            return $template->templateShifts->every(function ($row) use ($clients, $staff, $contexts): bool {
                $client = $clients->get($row->client_id);
                $context = $row->service_context_id === null ? null : $contexts->get($row->service_context_id);

                return $client && ($row->user_id === null || $staff->has($row->user_id))
                    && ($row->service_context_id === null || ($context && ($context->site_id === null || (int) $context->site_id === (int) $client->site_id)));
            });
        });
        $creators = User::query()->whereKey($templates->pluck('created_by')->filter()->unique())->get(['id', 'name'])->keyBy('id');

        return $templates->map(function ($template) use ($caps, $clients, $staff, $contexts, $creators): array {
            $rows = $template->templateShifts;
            $creator = $creators->get($template->created_by);
            $dto = ['id' => (int) $template->id, 'name' => $template->name, 'description' => $template->description,
                'template_type' => $template->template_type, 'is_active' => (bool) $template->is_active,
                'template_shifts_count' => $rows->count(), 'creator' => $creator ? ['id' => (int) $creator->id, 'name' => $creator->name] : null,
                'updated_at' => $template->updated_at?->toIso8601String(), 'source_revision' => RosterTemplateSource::revision($template, $rows),
                'capabilities' => $caps, 'urls' => [
                    'edit' => $caps['can_edit'] ? route('operations.rostering.templates.update', $template) : null,
                    'update' => $caps['can_edit'] ? route('operations.rostering.templates.update', $template) : null,
                    'duplicate' => $caps['can_duplicate'] ? route('operations.rostering.templates.duplicate', $template) : null,
                    'delete' => $caps['can_delete'] ? route('operations.rostering.templates.destroy', $template) : null,
                    'apply' => $caps['can_apply'] ? route('operations.rostering.templates.apply', $template) : null]];
            $dto['template_shifts'] = $rows->sortBy([['day_of_week', 'asc'], ['start_time', 'asc'], ['id', 'asc']])->map(function ($row) use ($clients, $staff, $contexts): array {
                $client = $clients->get($row->client_id);
                $worker = $staff->get($row->user_id);
                $context = $contexts->get($row->service_context_id);

                return ['id' => (int) $row->id, ...RosterTemplateSource::row($row),
                    'shift_type' => $row->shift_type ?? 'standard', 'is_sleepover' => (bool) $row->is_sleepover,
                    'is_on_call' => (bool) $row->is_on_call, 'is_lone_worker' => (bool) $row->is_lone_worker,
                    'required_skills' => $row->required_skills ?? [],
                    'client' => $client ? ['id' => (int) $client->id, 'first_name' => $client->first_name, 'last_name' => $client->last_name] : null,
                    'user' => $worker ? ['id' => (int) $worker->id, 'name' => $worker->name] : null,
                    'service_context' => $context ? ['id' => (int) $context->id, 'name' => $context->name] : null];
            })->values()->all();

            return $dto;
        })->values()->all();
    }
}
