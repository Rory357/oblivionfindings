<?php

namespace App\Services\Tasks\Providers;

use App\Models\FirstAidFollowup;
use App\Models\User;
use App\Services\CurrentAuthorizationReads;
use App\Services\HealthSafety\FirstAidAccessService;
use App\Services\HealthSafety\HealthSafetyReadContext;
use App\Services\Tasks\Contracts\ContextualHealthSafetyTaskProvider;
use App\Services\Tasks\Contracts\HasModelClass;
use App\Services\Tasks\Contracts\SiteScopedTaskProvider;
use App\Services\Tasks\Contracts\TaskProvider;
use App\Services\Tasks\TaskItem;
use App\Services\Tasks\TaskProviderAuthorization;
use App\Support\HealthSafety\FirstAidReturn;
use Symfony\Component\HttpKernel\Exception\HttpException;

class FirstAidFollowupProvider implements ContextualHealthSafetyTaskProvider, HasModelClass, SiteScopedTaskProvider, TaskProvider
{
    public function sourceKey(): string
    {
        return 'first_aid_followup';
    }

    public function label(): string
    {
        return 'First Aid Follow-ups';
    }

    public function modelClass(): string
    {
        return FirstAidFollowup::class;
    }

    public function canView(User $user): bool
    {
        // Mirrors routes/health-safety.php: the first-aid register is gated by hazards.view.
        return $user->canDo('hazards.view');
    }

    public function authorizedTasks(User $user, array $filters = []): array
    {
        return $this->readTasks($user, $filters);
    }

    public function authorizedTasksInContext(User $viewer, array $filters, HealthSafetyReadContext $context): array
    {
        $context->assertViewer($viewer);

        return $this->readTasks($viewer, $filters, $context);
    }

    public function authorizedNavigationTasksInContext(User $viewer, HealthSafetyReadContext $context): array
    {
        $context->assertViewer($viewer);

        return $this->readTasks($viewer, [], $context, true);
    }

    private function readTasks(User $user, array $filters, ?HealthSafetyReadContext $context = null, bool $navigation = false): array
    {
        $access = app(FirstAidAccessService::class);
        try {
            $operation = function (User $current, CurrentAuthorizationReads $reads, array $sites) use ($access, $filters, $context, $navigation) {
                $parents = $access->readableQuery($current, $reads, $sites, false, $context)->select('id');
                $query = $reads->query(FirstAidFollowup::query())
                    ->with($navigation ? [] : ['record.site:id,name', 'assignedTo:id,name'])->when(isset($filters['id']), fn ($q) => $q->whereKey((int) $filters['id']))->orderByDesc('created_at')->orderByDesc('id');
                if (empty($filters['include_done'])) {
                    $query->whereNull('completed_at');
                }
                $return = FirstAidReturn::leaf($filters['return_to'] ?? null);
                if (! in_array(parse_url($return ?? '', PHP_URL_PATH), ['/tasks', '/my-day'], true)) {
                    $return = request()->is('my-day') ? '/my-day' : '/tasks';
                }

                return app(TaskProviderAuthorization::class)->siteScoped(
                    $current, $this->canView($current), $query,
                    fn ($scoped, User $actor) => $scoped->whereIn('first_aid_record_id', $parents),
                    function (FirstAidFollowup $followup) use ($access, $current, $reads, $sites, $return, $navigation) {
                        if ($navigation) {
                            // readableQuery proves the exact live parent/Site/subject/Client tuple before get().
                            return new TaskItem(id: 'first_aid_followup-'.$followup->id, source: $this->sourceKey(), sourceLabel: $this->label(),
                                ref: null, title: '', status: $followup->completed_at ? 'completed' : 'open',
                                bucket: $followup->completed_at ? TaskItem::BUCKET_DONE : TaskItem::BUCKET_OPEN, severity: 'info',
                                assignee: (int) $followup->assigned_to_user_id === (int) $current->id ? ['id' => $current->id] : null,
                                dueAt: $followup->due_at?->toIso8601String());
                        }
                        $record = $followup->record;
                        $access->assertSource($record, $current, $reads, $sites);
                        $client = $record->client;

                        return new TaskItem(
                            id: 'first_aid_followup-'.$followup->id, source: $this->sourceKey(), sourceLabel: $this->label(), ref: $record->reference_number,
                            title: 'First aid follow-up', status: $followup->completed_at ? 'completed' : 'open',
                            bucket: $followup->completed_at ? TaskItem::BUCKET_DONE : TaskItem::BUCKET_OPEN, severity: 'medium',
                            assignee: $followup->assignedTo ? ['id' => (int) $followup->assignedTo->id, 'name' => $followup->assignedTo->name] : null,
                            client: $client ? ['id' => (int) $client->id, 'name' => trim($client->first_name.' '.$client->last_name)] : null,
                            site: $record->site ? ['id' => (int) $record->site->id, 'name' => $record->site->name] : null,
                            dueAt: $followup->due_at?->toIso8601String(), createdAt: $followup->created_at?->toIso8601String(),
                            link: FirstAidReturn::href($record->id, $return, 'followups', $followup->id), type: 'First aid follow-up',
                            description: $followup->notes ? str($followup->notes)->limit(140)->toString() : null,
                        );
                    },
                );
            };

            return $context === null
                ? $access->withCurrentViewer($user, $operation)
                : $access->withReadContext($context, $operation);
        } catch (HttpException $error) {
            if (! in_array($error->getStatusCode(), [403, 404], true)) {
                throw $error;
            }

            return [];
        }
    }
}
