<?php

namespace App\Services\Operations;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\RosterTemplate;
use App\Models\RosterTemplateShift;
use App\Models\ServiceContext;
use App\Models\User;
use App\Services\AuthorizationEvidenceLockService;
use App\Services\CurrentAuthorizationReads;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class RosterTemplateCommand
{
    public function __construct(private readonly RosterTemplateAccessService $access, private readonly AuthorizationEvidenceLockService $authorization, private readonly UserSiteAccessService $sites) {}

    public function execute(User $actor, string $action, array $values = [], ?int $templateId = null, ?array $expectedSource = null, ?string $requestId = null): RosterTemplateCommandResult
    {
        if (! array_key_exists($action, RosterTemplateAccessService::ACTION_PERMISSIONS) || ($action === 'create') !== ($templateId === null)) {
            throw new \LogicException('Unsupported roster template command.');
        }
        try {
            return DB::transaction(function () use ($actor, $action, $values, $templateId, $expectedSource, $requestId): RosterTemplateCommandResult {
                app(WorkforceMutationGuard::class)->lock();
                $template = $templateId === null ? null : RosterTemplate::query()->whereKey($templateId)->lockForUpdate()->firstOrFail();
                $rows = $template ? $this->rows($templateId) : collect();
                $source = $template ? RosterTemplateSource::expected($template, $rows) : null;
                $intent = in_array($action, ['create', 'update'], true) ? RosterTemplateIntent::normalize($values, $template) : null;
                $current = $this->authority($actor, $action, $rows->concat($intent['template_shifts'] ?? []));
                if ($template && $rows->isEmpty()) {
                    abort_unless((int) $template->created_by === (int) $current->id || $current->canDo('shifts.manageAny'), 403);
                }
                abort_unless($expectedSource === null || $expectedSource === $source, 409, 'This roster template changed. Reload before acting.');
                $hash = RosterTemplateIntent::hash($action, $templateId, $source, $intent);
                $copyId = null;
                $resultRevision = null;
                $changed = true;
                $count = $rows->count();
                if ($action === 'delete') {
                    $before = $template->getRawOriginal();
                    $beforeRows = $rows->map(fn ($row) => $row->getRawOriginal())->all();
                    abort_unless($template->delete() === true && (int) $template->id === $templateId, 409, 'The roster template deletion was not saved.');
                    $stored = RosterTemplate::withTrashed()->whereKey($templateId)->lockForUpdate()->firstOrFail();
                    $after = $stored->getRawOriginal();
                    abort_unless($stored->deleted_at !== null && $this->without($before, ['deleted_at', 'updated_at']) === $this->without($after, ['deleted_at', 'updated_at'])
                        && $beforeRows === $this->rows($templateId)->map(fn ($row) => $row->getRawOriginal())->all(), 409, 'The roster template deletion was not saved as intended.');
                    $outcome = 'deleted';
                } elseif ($action === 'duplicate') {
                    $copyValues = RosterTemplateSource::values($template, $rows, false);
                    $copyValues['name'] = $this->duplicateName($template->name);
                    [$copy, $copyRows] = $this->createAggregate($current, $copyValues);
                    $copyId = (int) $copy->id;
                    $count = $copyRows->count();
                    $resultRevision = RosterTemplateSource::revision($copy, $copyRows);
                    $outcome = 'copied';
                } elseif ($action === 'create') {
                    [$template, $storedRows] = $this->createAggregate($current, $intent);
                    $templateId = (int) $template->id;
                    $count = $storedRows->count();
                    $resultRevision = RosterTemplateSource::revision($template, $storedRows);
                    $outcome = 'saved';
                } elseif ($this->matches($template, $rows, $intent, ignoreRowOrder: true)) {
                    $changed = false;
                    $resultRevision = $source['source_revision'];
                    $outcome = 'unchanged';
                } else {
                    $before = $template->getRawOriginal();
                    $template->fill($this->without($intent, ['template_shifts']));
                    abort_unless($template->save() === true && (int) $template->id === $templateId, 409, 'The roster template update was not saved.');
                    foreach ($rows as $row) {
                        $id = (int) $row->id;
                        abort_unless($row->delete() === true && (int) $row->id === $id, 409, 'The roster template rows could not be replaced.');
                    }
                    abort_unless(RosterTemplateShift::query()->whereKey($rows->pluck('id'))->lockForUpdate()->get()->isEmpty(), 409, 'The original roster template rows were retained.');
                    $this->createRows($template, $intent['template_shifts']);
                    $template = RosterTemplate::query()->whereKey($templateId)->lockForUpdate()->firstOrFail();
                    $storedRows = $this->rows($templateId);
                    abort_unless($this->without($before, ['name', 'description', 'template_type', 'is_active', 'updated_at']) === $this->without($template->getRawOriginal(), ['name', 'description', 'template_type', 'is_active', 'updated_at'])
                        && $this->matches($template, $storedRows, $intent), 409, 'The roster template update was not saved as intended.');
                    $count = $storedRows->count();
                    $resultRevision = RosterTemplateSource::revision($template, $storedRows);
                    $outcome = 'saved';
                }

                return new RosterTemplateCommandResult($action, (int) $current->id, $templateId, $copyId, $requestId, $source,
                    $outcome, $changed, $hash, $resultRevision, $count, $current->canDo('rostering.viewAny'));
            });
        } catch (QueryException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) !== 3572) {
                throw $exception;
            }
            throw ValidationException::withMessages(['template' => 'This roster template is being updated. Reload before trying again.']);
        }
    }

    private function authority(User $actor, string $action, Collection $rows): User
    {
        $contextIds = $rows->pluck('service_context_id')->filter()->map(fn ($id) => (int) $id)->unique()->sort()->values();
        $contexts = ServiceContext::query()->whereKey($contextIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $clientIds = $rows->pluck('client_id')->filter()->map(fn ($id) => (int) $id)->unique()->sort()->values();
        $clients = Client::query()->whereKey($clientIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $userIds = collect([$actor->id, ...$rows->pluck('user_id')->filter()->all()])->map(fn ($id) => (int) $id)->unique()->sort()->values();
        $keys = collect(RosterTemplateAccessService::ACTION_PERMISSIONS)->flatten()->merge(['shifts.manageAny', 'rostering.viewAny', 'roster_templates.viewAny'])->unique()->all();
        $users = $userIds->mapWithKeys(fn ($id) => [$id => $this->authorization->lockForUserWithoutWaiting($id, $keys)]);
        $current = $users->get((int) $actor->id);
        abort_unless($current && $this->access->can($current, $action), 403);
        CurrentAuthorizationReads::within(function ($reads) use ($current, $rows, $clients, $contexts, $userIds, $users): void {
            $profiles = $reads->query(HrEmployeeProfile::query()->whereIn('user_id', $userIds)->orderBy('user_id'))->get()->keyBy('user_id');
            foreach ($users as $user) {
                $user->setRelation('hrEmployeeProfile', $profiles->get($user->id));
            }
            $siteIds = $this->sites->accessibleSiteIds($current, RosterTemplateAccessService::SITE_BYPASS_PERMISSIONS, $reads);
            $bypass = $current->canDo('shifts.manageAny');
            $workerIds = $rows->pluck('user_id')->filter()->map(fn ($id) => (int) $id)->unique()->values();
            $workers = $reads->query($this->staffQuery($siteIds, $bypass)->whereKey($workerIds))->get(['users.id'])->pluck('id')->map(fn ($id) => (int) $id);
            foreach ($rows as $row) {
                $clientId = data_get($row, 'client_id');
                $client = $clients->get((int) $clientId);
                abort_unless($client && in_array((int) $client->site_id, $siteIds, true), 403, UserSiteAccessService::DEFAULT_MESSAGE);
                $contextId = data_get($row, 'service_context_id');
                if ($contextId) {
                    $context = $contexts->get((int) $contextId);
                    if (! $context || ! $context->is_active || ($context->site_id !== null && (int) $context->site_id !== (int) $client->site_id)) {
                        throw ValidationException::withMessages(['template_shifts' => 'Each service context must be active and available to the template shift client\'s Site.']);
                    }
                }
                if ($workerId = data_get($row, 'user_id')) {
                    abort_unless($workers->containsStrict((int) $workerId), 403, UserSiteAccessService::DEFAULT_MESSAGE);
                }
            }
        });

        return $current;
    }

    /** Exact existing applyStaffScope predicate, using current actor Site IDs. */
    private function staffQuery(array $siteIds, bool $bypass): Builder
    {
        $today = now((string) (config('app.worker_timezone') ?: config('app.timezone', 'UTC')))->toDateString();
        $query = User::staff()->whereNotNull('users.approved_at')->whereHas('hrEmployeeProfile', fn (Builder $profiles) => $profiles
            ->where('is_active', true)->where(fn (Builder $dates) => $dates->whereNull('start_date')->orWhereDate('start_date', '<=', $today))
            ->where(fn (Builder $dates) => $dates->whereNull('end_date')->orWhereDate('end_date', '>=', $today)));
        if (! $bypass) {
            $query->whereHas('hrEmployeeProfile', function (Builder $profiles) use ($siteIds): void {
                $profiles->where(function (Builder $sites) use ($siteIds): void {
                    $sites->whereIn('primary_site_id', $siteIds);
                    foreach ($siteIds as $siteId) {
                        $sites->orWhereJsonContains('secondary_site_ids', $siteId);
                    }
                });
            });
        }

        return $query;
    }

    private function rows(int $templateId): Collection
    {
        return RosterTemplateShift::query()->where('roster_template_id', $templateId)->orderBy('id')->lockForUpdate()->get();
    }

    private function createAggregate(User $actor, array $values): array
    {
        $previous = (int) RosterTemplate::withTrashed()->orderByDesc('id')->lockForUpdate()->value('id');
        $template = new RosterTemplate([...$this->without($values, ['template_shifts']), 'created_by' => $actor->id]);
        abort_unless($template->save() === true && $template->exists && (int) $template->id > $previous, 409, 'The roster template was not created.');
        $id = (int) $template->id;
        $this->createRows($template, $values['template_shifts']);
        $stored = RosterTemplate::query()->whereKey($id)->lockForUpdate()->firstOrFail();
        $rows = $this->rows($id);
        abort_unless((int) $stored->created_by === (int) $actor->id && $this->matches($stored, $rows, $values), 409, 'The roster template was not created as intended.');

        return [$stored, $rows];
    }

    private function createRows(RosterTemplate $template, array $values): void
    {
        $ids = [];
        foreach ($values as $value) {
            $row = new RosterTemplateShift(['roster_template_id' => $template->id, ...$value]);
            abort_unless($row->save() === true && $row->exists && (int) $row->id > 0, 409, 'The roster template row was not saved.');
            $ids[] = (int) $row->id;
        }
        $rows = $this->rows((int) $template->id);
        abort_unless($rows->pluck('id')->map(fn ($id) => (int) $id)->all() === $ids, 409, 'The saved roster template rows changed identity.');
    }

    private function matches(RosterTemplate $template, Collection $rows, array $intent, bool $ignoreRowOrder = false): bool
    {
        foreach ($intent['template_shifts'] as &$row) {
            foreach (['start_time', 'end_time'] as $key) {
                if (strlen($row[$key]) === 5) {
                    $row[$key] .= ':00';
                }
            }
        }
        unset($row);

        $actual = RosterTemplateSource::values($template, $rows, false);
        if ($ignoreRowOrder) {
            // There is no stored row position. The library presents day/time order,
            // which may differ from insertion IDs without changing the pattern.
            $compare = static fn (array $left, array $right): int => strcmp(
                json_encode($left, JSON_THROW_ON_ERROR), json_encode($right, JSON_THROW_ON_ERROR),
            );
            usort($actual['template_shifts'], $compare);
            usort($intent['template_shifts'], $compare);
        }

        return $actual === $intent;
    }

    private function duplicateName(string $name): string
    {
        $base = trim(preg_replace('/\s*\(copy(?:\s+\d+)?\)$/i', '', $name)) ?: $name;
        $counter = 1;
        do {
            $suffix = $counter === 1 ? ' (copy)' : ' (copy '.$counter.')';
            $candidate = Str::substr($base, 0, 255 - Str::length($suffix)).$suffix;
            $counter++;
        } while (RosterTemplate::query()->where('name', $candidate)->lockForUpdate()->first(['id']));

        return $candidate;
    }

    private function without(array $values, array $keys): array
    {
        return array_diff_key($values, array_flip($keys));
    }
}
