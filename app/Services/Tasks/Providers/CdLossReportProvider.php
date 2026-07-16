<?php

namespace App\Services\Tasks\Providers;

use App\Models\ControlledDrugLossReport;
use App\Models\User;
use App\Services\Tasks\Contracts\HasModelClass;
use App\Services\Tasks\Contracts\TaskProvider;
use App\Services\Tasks\TaskItem;

class CdLossReportProvider implements HasModelClass, TaskProvider
{
    public function sourceKey(): string
    {
        return 'cd_loss';
    }

    public function label(): string
    {
        return 'CD Loss Reports';
    }

    public function modelClass(): string
    {
        return ControlledDrugLossReport::class;
    }

    public function canView(User $user): bool
    {
        return $user->canDo('medications.controlled.view') || $user->canDo('clients.update');
    }

    public function tasks(User $user, array $filters = []): array
    {
        $query = ControlledDrugLossReport::query()
            ->with('client:id,first_name,last_name')
            ->when(isset($filters['id']), fn ($q) => $q->whereKey((int) $filters['id']))
            ->orderByDesc('discovered_at')
            ->limit(300);

        if (empty($filters['include_done'])) {
            $query->whereIn('investigation_status', ['reported', 'investigating']);
        }

        return $query->get()->map(function (ControlledDrugLossReport $report) {
            $client = $report->client;

            return new TaskItem(
                id: 'cd_loss-'.$report->id,
                source: $this->sourceKey(),
                sourceLabel: $this->label(),
                ref: $report->reference_number,
                title: 'CD loss — '.$report->medication_name,
                status: (string) $report->investigation_status,
                bucket: match ($report->investigation_status) {
                    'investigating' => TaskItem::BUCKET_IN_PROGRESS,
                    'reported' => TaskItem::BUCKET_OPEN,
                    default => TaskItem::BUCKET_DONE,
                },
                severity: 'high',
                client: $client
                    ? ['id' => $client->id, 'name' => trim($client->first_name.' '.$client->last_name)]
                    : null,
                dueAt: null,
                createdAt: optional($report->created_at)->toIso8601String(),
                link: '/emar/controlled/loss-reports',
                type: 'CD loss',
                description: $report->circumstances ? str($report->circumstances)->limit(140)->toString() : null,
            );
        })->all();
    }
}
