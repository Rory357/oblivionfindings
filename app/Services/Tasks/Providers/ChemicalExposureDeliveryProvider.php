<?php

namespace App\Services\Tasks\Providers;

use App\Models\User;
use App\Services\HealthSafety\ChemicalExposureDeliveryProblems;
use App\Services\HealthSafety\HealthSafetyReadContext;
use App\Services\Tasks\Contracts\ContextualHealthSafetyTaskProvider;
use App\Services\Tasks\Contracts\SiteScopedTaskProvider;
use App\Services\Tasks\TaskItem;

/**
 * Exposures that haven't reached the H&S register (job 3, Stephan 26 Oct), for exposure managers. The row names the
 * Site and what happened, never the worker, substance or anything clinical; the exposure page has the details.
 */
final class ChemicalExposureDeliveryProvider implements ContextualHealthSafetyTaskProvider, SiteScopedTaskProvider
{
    private const STATES = [
        'needs_manager' => ['Needs a manager', 'The automatic delivery stopped because the record needs checking. Retry it from the exposure\'s Connections tab.'],
        'failed' => ['Retrying', 'The automatic delivery failed and is trying again. If it keeps failing, retry it from the exposure\'s Connections tab.'],
        'overdue' => ['Waiting', 'It has been waiting more than 10 minutes to reach the H&S register.'],
    ];

    public function sourceKey(): string { return 'chemical_exposure_delivery'; }

    public function label(): string { return 'Exposure deliveries'; }

    public function canView(User $user): bool
    {
        return $user->canDo('hazards.manage') && $user->canDo('hazards.view') && $user->canDo('hr.wellbeing.view');
    }

    public function authorizedTasks(User $user, array $filters = []): array { return $this->rows($user, $filters); }

    public function authorizedTasksInContext(User $viewer, array $filters, HealthSafetyReadContext $context): array
    {
        return $this->rows($viewer, $filters, $context);
    }

    public function authorizedNavigationTasksInContext(User $viewer, HealthSafetyReadContext $context): array
    {
        return $this->rows($viewer, [], $context);
    }

    private function rows(User $viewer, array $filters, ?HealthSafetyReadContext $context = null): array
    {
        $only = isset($filters['id']) ? (int) $filters['id'] : null;
        $items = [];
        foreach (app(ChemicalExposureDeliveryProblems::class)->rows($viewer, $context, $only) as $row) {
            [$state, $help] = self::STATES[$row['state']] ?? self::STATES['overdue'];
            $items[] = new TaskItem(
                id: $this->sourceKey().'-'.$row['exposure_id'],
                source: $this->sourceKey(),
                sourceLabel: $this->label(),
                ref: null,
                title: 'Exposure not on the H&S register yet',
                status: $row['state'],
                bucket: TaskItem::BUCKET_OPEN,
                severity: 'high',
                site: $row['site_name'] === null ? null : ['id' => $row['site_id'], 'name' => $row['site_name']],
                createdAt: $row['since'],
                link: '/health-safety/chemical-exposures/'.$row['exposure_id'].'?section=connections',
                type: 'Exposure delivery',
                description: $help,
                restricted: true,
                actionLabel: 'Check the delivery',
                displayState: $state,
            );
        }
        return $items;
    }
}
