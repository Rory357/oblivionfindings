<?php

namespace App\Services\Tasks\Providers;

use App\Models\MedicationError;
use App\Models\MedicationErrorAction;
use App\Models\User;
use App\Services\Medication\MedicationErrorReadScope;
use App\Services\Medication\MedicationGovernanceScopeService;
use App\Services\Tasks\Contracts\HasModelClass;
use App\Services\Tasks\Contracts\SiteScopedTaskProvider;
use App\Services\Tasks\Contracts\TaskProvider;
use App\Services\Tasks\TaskItem;
use App\Services\Tasks\TaskProviderAuthorization;

/** An investigation action has its own identity; its description stays in the error record. */
class MedicationErrorActionProvider implements HasModelClass, SiteScopedTaskProvider, TaskProvider
{
    public function sourceKey(): string
    {
        return 'med_error_action';
    }

    public function label(): string
    {
        return 'Medication error actions';
    }

    public function modelClass(): string
    {
        return MedicationErrorAction::class;
    }

    public function canView(User $user): bool
    {
        return $user->canDo('medications.view');
    }

    public function authorizedTasks(User $user, array $filters = []): array
    {
        if (! $this->canView($user)) {
            return [];
        }
        $sites = app(MedicationGovernanceScopeService::class)->readerSiteIds($user, 'medications.view');
        $parents = app(MedicationErrorReadScope::class)->apply(MedicationError::query(), $user, $sites)->select('medication_errors.id');
        $query = MedicationErrorAction::query()->with(['error.client', 'error.owner', 'owner'])
            ->whereIn('medication_error_id', $parents)->when(isset($filters['id']), fn ($q) => $q->whereKey((int) $filters['id']))->orderBy('due_at')->limit(300);
        if (empty($filters['include_done'])) {
            $query->whereNull('completed_at');
        }

        return app(TaskProviderAuthorization::class)->siteScoped($user, $this->canView($user), $query, fn ($q) => $q, function ($action) {
            $error = $action->error;

            return new TaskItem(
                id: 'med_error_action-'.$action->id, source: $this->sourceKey(), sourceLabel: $this->label(), ref: $error->reference_number,
                title: 'Medication error action '.$action->id, status: $action->completed_at ? 'completed' : 'open',
                bucket: $action->completed_at ? TaskItem::BUCKET_DONE : TaskItem::BUCKET_OPEN, severity: TaskItem::normaliseSeverity($error->severity),
                assignee: $action->owner?->only(['id', 'name']), client: ['id' => $error->client_id, 'name' => trim($error->client->first_name.' '.$error->client->last_name)],
                dueAt: $action->due_at->toIso8601String(), createdAt: $action->created_at->toIso8601String(),
                link: '/emar/errors?error='.$error->id, type: 'Medication error action', description: 'An action on medication error '.$error->reference_number.'. Open the permitted error record for details.',
            );
        });
    }
}
