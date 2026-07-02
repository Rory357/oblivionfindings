<?php

namespace App\Services\Tasks\Providers;

use App\Models\DataSubjectRequest;
use App\Models\User;
use App\Services\Tasks\Contracts\TaskProvider;
use App\Services\Tasks\TaskItem;

class DataSubjectRequestProvider implements TaskProvider
{
    public function sourceKey(): string
    {
        return 'dsr';
    }

    public function label(): string
    {
        return 'Privacy Requests';
    }

    public function canView(User $user): bool
    {
        // Only privacy.viewRequests can reach the module's list/show routes —
        // processRequests alone has no read surface, so it gets no feed either.
        return $user->canDo('privacy.viewRequests');
    }

    public function tasks(User $user, array $filters = []): array
    {
        $query = DataSubjectRequest::query()
            ->with(['client:id,first_name,last_name', 'assignedTo:id,name'])
            ->orderByDesc('received_at')
            ->limit(300);

        if (empty($filters['include_done'])) {
            $query->whereNotIn('status', ['completed', 'rejected', 'withdrawn']);
        }

        return $query->get()->map(function (DataSubjectRequest $dsRequest) {
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
        })->all();
    }
}
