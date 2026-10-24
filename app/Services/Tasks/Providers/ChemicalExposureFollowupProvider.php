<?php

namespace App\Services\Tasks\Providers;

use App\Models\User;
use App\Services\HealthSafety\ChemicalExposureFollowupDestinationService;
use App\Services\HealthSafety\HealthSafetyReadContext;
use App\Services\Tasks\Contracts\ContextualHealthSafetyTaskProvider;
use App\Services\Tasks\Contracts\SiteScopedTaskProvider;
use App\Services\Tasks\TaskItem;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Registered in TaskAggregator::defaultProviders(). Source commands alone complete work; no generic model/audit/assignment adapter. */
final class ChemicalExposureFollowupProvider implements ContextualHealthSafetyTaskProvider, SiteScopedTaskProvider
{
    public function sourceKey(): string { return 'chemical_exposure_followup'; }
    public function label(): string { return 'Private follow-ups'; }
    public function canView(User $user): bool { return $user->canDo('hazards.view') && $user->canDo('hr.wellbeing.view'); }
    public function authorizedTasks(User $user, array $filters = []): array { return $this->rows($user, $filters); }
    public function authorizedTasksInContext(User $viewer, array $filters, HealthSafetyReadContext $context): array
    { return $this->rows($viewer, $filters, $context); }
    public function authorizedNavigationTasksInContext(User $viewer, HealthSafetyReadContext $context): array
    { return $this->rows($viewer, [], $context, true); }

    private function rows(User $viewer, array $filters, ?HealthSafetyReadContext $context = null, bool $navigation = false): array
    {
        try {
            $rows = app(ChemicalExposureFollowupDestinationService::class)->taskRows($viewer, $filters, $context, $navigation);
            return array_map(fn ($row) => new TaskItem(...$row, restricted: true), $rows);
        } catch (HttpExceptionInterface $error) {
            if (! in_array($error->getStatusCode(), [403, 404], true)) { throw $error; }
            return [];
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) { return []; }
    }
}
