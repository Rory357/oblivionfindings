<?php

namespace App\Services\Tasks\Providers;

use App\Models\MedicationError;
use App\Models\User;
use App\Services\Medication\MedicationErrorReadScope;
use App\Services\Medication\MedicationErrorSummary;
use App\Services\Medication\MedicationGovernanceScopeService;
use App\Services\Tasks\Contracts\HasModelClass;
use App\Services\Tasks\Contracts\SiteScopedTaskProvider;
use App\Services\Tasks\Contracts\TaskProvider;
use App\Services\Tasks\TaskItem;
use App\Services\Tasks\TaskProviderAuthorization;

class MedicationErrorProvider implements HasModelClass, SiteScopedTaskProvider, TaskProvider
{
    public function sourceKey(): string
    {
        return 'med_error';
    }

    public function label(): string
    {
        return 'Medication Errors';
    }

    public function modelClass(): string
    {
        return MedicationError::class;
    }

    public function canView(User $user): bool
    {
        return $user->canDo('medications.view');
    }

    public function authorizedTasks(User $user, array $filters = []): array
    {
        $query = MedicationError::query()
            ->with(['client:id,first_name,last_name', 'owner:id,name'])
            ->when(isset($filters['id']), fn ($q) => $q->whereKey((int) $filters['id']))
            ->orderByDesc('reported_at')
            ->limit(300);

        if (empty($filters['include_done'])) {
            $query->whereIn('status', ['reported', 'investigating', 'resolved']);
        }

        // Mirror the medication error register exactly: the reader's Sites,
        // canonical client/medication ownership, and — without the controlled
        // medicines reader capability — no controlled-drug rows at all. Tasks
        // list, stats, reports, detail, lookup (global search), CSV and watch
        // all read through this one query.
        return app(TaskProviderAuthorization::class)->siteScoped(
            $user,
            $this->canView($user),
            $query,
            function ($scoped, User $actor) {
                $governance = app(MedicationGovernanceScopeService::class);
                app(MedicationErrorReadScope::class)->apply(
                    $scoped, $actor, $governance->readerSiteIds($actor, MedicationGovernanceScopeService::MODULE_VIEW_CAPABILITY),
                );

                return $scoped;
            },
            function (MedicationError $error) {
                $client = $error->client;

                $title = ucfirst(str_replace('_', ' ', (string) $error->error_type));

                if ($client) {
                    $title .= ' — '.trim($client->first_name.' '.$client->last_name);
                }

                return new TaskItem(
                    id: 'med_error-'.$error->id,
                    source: $this->sourceKey(),
                    sourceLabel: $this->label(),
                    ref: $error->reference_number,
                    title: $title,
                    status: (string) $error->status,
                    bucket: match ($error->status) {
                        'closed' => TaskItem::BUCKET_DONE,
                        'investigating', 'resolved' => TaskItem::BUCKET_IN_PROGRESS,
                        default => TaskItem::BUCKET_OPEN,
                    },
                    severity: TaskItem::normaliseSeverity($error->severity),
                    client: $client
                        ? ['id' => $client->id, 'name' => trim($client->first_name.' '.$client->last_name)]
                        : null,
                    dueAt: ($error->stage() === 'triage' ? $error->triage_due_at : $error->investigation_due_at)?->toIso8601String(),
                    assignee: $error->owner?->only(['id', 'name']),
                    createdAt: optional($error->created_at)->toIso8601String(),
                    link: '/emar/errors?error='.$error->id,
                    type: 'Medication error',
                    description: MedicationErrorSummary::for($error),
                );
            },
        );
    }
}
