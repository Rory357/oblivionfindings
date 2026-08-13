<?php

namespace App\Services\Tasks\Providers;

use App\Models\DataSubjectRequest;
use App\Models\User;
use App\Services\Tasks\Contracts\AssignableTaskProvider;
use App\Services\Tasks\Contracts\ExplicitlyGlobalTaskProvider;
use App\Services\Tasks\Contracts\HasModelClass;
use App\Services\Tasks\Contracts\TaskProvider;
use App\Services\Tasks\TaskItem;
use App\Services\Tasks\TaskProviderAuthorization;
use Illuminate\Validation\ValidationException;

class DataSubjectRequestProvider implements AssignableTaskProvider, ExplicitlyGlobalTaskProvider, HasModelClass, TaskProvider
{
    public function sourceKey(): string
    {
        return 'dsr';
    }

    public function label(): string
    {
        return 'Privacy Requests';
    }

    public function modelClass(): string
    {
        return DataSubjectRequest::class;
    }

    public function canAssign(User $user): bool
    {
        // Mirrors routes/privacy.php: PUT /privacy/requests/{dsRequest}
        // (the module's assignment surface) → permission:privacy.processRequests.
        return $user->canDo('privacy.processRequests');
    }

    public function assign(User $actor, int $id, ?int $assigneeId): void
    {
        $dsRequest = DataSubjectRequest::query()->find($id);

        if (! $dsRequest) {
            throw ValidationException::withMessages([
                'assignee_id' => 'Privacy request not found.',
            ]);
        }

        $data = [
            'assigned_to_user_id' => $assigneeId,
            'updated_by' => $actor->id,
        ];

        // DataSubjectRequestController::update() stamps assigned_at only on
        // first assignment — keep the original allocation time on reassigns.
        if ($assigneeId !== null && ! $dsRequest->assigned_at) {
            $data['assigned_at'] = now();
        }

        $dsRequest->update($data);
    }

    public function canView(User $user): bool
    {
        // Only privacy.viewRequests can reach the module's list/show routes —
        // processRequests alone has no read surface, so it gets no feed either.
        return $user->canDo('privacy.viewRequests');
    }

    public function globalViewPermissions(): array
    {
        return ['privacy.viewRequests'];
    }

    public function authorizedTasks(User $user, array $filters = []): array
    {
        $query = DataSubjectRequest::query()
            ->with(['client:id,first_name,last_name', 'assignedTo:id,name'])
            ->when(isset($filters['id']), fn ($q) => $q->whereKey((int) $filters['id']))
            ->orderByDesc('received_at')
            ->limit(300);

        if (empty($filters['include_done'])) {
            $query->whereNotIn('status', ['completed', 'rejected', 'withdrawn']);
        }

        return app(TaskProviderAuthorization::class)->explicitlyGlobal(
            $user,
            $this->globalViewPermissions(),
            $query,
            function (DataSubjectRequest $dsRequest) {
                $client = $dsRequest->client;

                $title = ucfirst(str_replace('_', ' ', (string) $dsRequest->request_type)).' request';

                if ($dsRequest->subject_name) {
                    $title .= ' — '.$dsRequest->subject_name;
                }

                return new TaskItem(
                    id: 'dsr-'.$dsRequest->id,
                    source: $this->sourceKey(),
                    sourceLabel: $this->label(),
                    ref: $dsRequest->reference_number,
                    title: $title,
                    status: (string) $dsRequest->status,
                    bucket: match ($dsRequest->status) {
                        'completed', 'rejected', 'withdrawn' => TaskItem::BUCKET_DONE,
                        'received', 'identity_verification' => TaskItem::BUCKET_OPEN,
                        default => TaskItem::BUCKET_IN_PROGRESS,
                    },
                    severity: TaskItem::normaliseSeverity(null),
                    assignee: $dsRequest->assignedTo
                        ? ['id' => $dsRequest->assignedTo->id, 'name' => (string) $dsRequest->assignedTo->name]
                        : null,
                    client: $client
                        ? ['id' => $client->id, 'name' => trim($client->first_name.' '.$client->last_name)]
                        : null,
                    dueAt: optional($dsRequest->extended_due_date ?: $dsRequest->due_date)->toIso8601String(),
                    createdAt: optional($dsRequest->created_at)->toIso8601String(),
                    link: '/privacy/requests',
                    type: 'Subject request',
                );
            },
        );
    }
}
