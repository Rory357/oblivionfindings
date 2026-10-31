<?php

namespace App\Services\Tasks\Providers;

use App\Models\ProcedureReviewRequest;
use App\Models\Site;
use App\Models\User;
use App\Services\CurrentAuthorizationReads;
use App\Services\HealthSafety\HealthSafetyReadContext;
use App\Services\HealthSafety\SafeWorkProcedureAccessService;
use App\Services\Tasks\Contracts\ContextualHealthSafetyTaskProvider;
use App\Services\Tasks\Contracts\SiteScopedTaskProvider;
use App\Services\Tasks\Contracts\TaskProvider;
use App\Services\Tasks\TaskItem;
use App\Support\HealthSafety\SafeWorkProcedureReturn;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Read-only queue projection; procedure commands retain their own version guards. */
class ProcedureReviewRequestProvider implements ContextualHealthSafetyTaskProvider, SiteScopedTaskProvider, TaskProvider
{
    public function sourceKey(): string
    {
        return 'procedure_review_request';
    }

    public function label(): string
    {
        return 'Procedure reviews';
    }

    public function canView(User $user): bool
    {
        return $user->canDo('procedures.view') && $user->canDo('procedures.manage') && $user->canDo('hazards.view');
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
        $access = app(SafeWorkProcedureAccessService::class);
        try {
            $operation = function (User $current, CurrentAuthorizationReads $reads, array $sites) use ($access, $filters, $context, $navigation) {
                if (! $this->canView($current)) {
                    return [];
                }
                $rows = $reads->query(ProcedureReviewRequest::query())
                    ->where('assigned_to_user_id', $current->id)
                    ->whereIn('status', empty($filters['include_done']) ? ['requested', 'in_review'] : ['requested', 'in_review', 'resolved', 'declined', 'cancelled'])
                    ->when(isset($filters['id']), fn ($q) => $q->whereKey((int) $filters['id']))
                    ->with(['procedure.publishedVersion', 'requestedVersion'])
                    ->orderBy('requested_at')->orderBy('id')->get();
                $items = [];
                foreach ($rows as $row) {
                    try {
                        $event = $access->assertSource($row, $current, $reads, $sites, false, $context);
                        $record = $row->procedure;
                        $version = $row->requestedVersion;
                        if (! $record || ! $version || (int) $version->safe_work_procedure_id !== (int) $record->id
                            || ! $access->isApprovedVersion($version) || ! $access->audienceAllows($version->content_snapshot ?? [], $current, $sites)) {
                            continue;
                        }
                        $live = in_array($row->status, ['requested', 'in_review'], true);
                        if ($live && ($record->status === 'archived' || ! is_array($record->applicable_sites) || ! $access->workingAllowed($record, $current, $sites)
                            || ! $access->allSitesAllowed($record->applicable_sites ?? [], $sites, $current))) {
                            continue;
                        }
                        if ($navigation) {
                            // Exact source/version/audience and working scope have already passed.
                            $items[] = new TaskItem(id: $this->sourceKey().'-'.$row->id, source: $this->sourceKey(), sourceLabel: $this->label(),
                                ref: null, title: '', status: $row->status,
                                bucket: $row->status === 'requested' ? TaskItem::BUCKET_OPEN : TaskItem::BUCKET_IN_PROGRESS,
                                severity: 'info', assignee: ['id' => $current->id]);

                            continue;
                        }
                        $published = $record->status !== 'archived' && $record->publishedVersion
                            && (int) $record->publishedVersion->safe_work_procedure_id === (int) $record->id
                            && $access->isApprovedVersion($record->publishedVersion)
                            && $access->audienceAllows($record->publishedSnapshot(), $current, $sites);
                        $working = $live && ! $published;
                        $href = SafeWorkProcedureReturn::href($record->id, $this->origin($filters), 'connections', ! $live && (! $published || (int) $record->published_version_id !== (int) $version->id) ? $version->id : null, $working);
                        $href .= '&request='.$row->id;
                        $site = $reads->query(Site::query())->find($event->site_id);
                        $snapshot = $version->content_snapshot ?? [];
                        $items[] = new TaskItem(
                            id: $this->sourceKey().'-'.$row->id,
                            source: $this->sourceKey(), sourceLabel: $this->label(),
                            ref: is_string($snapshot['reference_number'] ?? null) ? $snapshot['reference_number'] : null,
                            title: 'Review procedure: '.(is_string($snapshot['title'] ?? null) ? $snapshot['title'] : 'Recorded instructions'),
                            status: $row->status,
                            bucket: match ($row->status) {
                                'requested' => TaskItem::BUCKET_OPEN, 'in_review' => TaskItem::BUCKET_IN_PROGRESS, default => TaskItem::BUCKET_DONE
                            },
                            severity: 'info', assignee: ['id' => $current->id, 'name' => $current->name],
                            site: $site ? ['id' => $site->id, 'name' => $site->name] : null,
                            dueAt: null, createdAt: $row->requested_at?->toIso8601String(), link: $href,
                            type: 'Procedure review request', description: $row->note ? str($row->note)->limit(140)->toString() : null,
                            actionLabel: $live ? 'Open procedure review' : 'Review procedure outcome',
                            displayState: match ($row->status) {
                                'requested' => 'Review requested', 'in_review' => 'Review in progress', 'resolved' => 'Review resolved', 'declined' => 'Review declined', default => 'Request cancelled'
                            },
                            actionHelp: 'The procedure owns this review. Its approval and the source corrective action remain separate.',
                        );
                    } catch (HttpException $error) {
                        if (! in_array($error->getStatusCode(), [403, 404], true)) {
                            throw $error;
                        }
                    }
                }

                return $items;
            };

            return $context === null
                ? $access->withCurrentViewer($user, $operation)
                : $access->withReadContext($context, $operation);
        } catch (HttpException $error) {
            if (in_array($error->getStatusCode(), [403, 404], true)) {
                return [];
            }
            throw $error;
        }
    }

    private function origin(array $filters): string
    {
        $origin = SafeWorkProcedureReturn::leaf($filters['return_to'] ?? null);
        if ($origin !== null && in_array(parse_url($origin, PHP_URL_PATH), ['/tasks', '/my-day'], true)) {
            return $origin;
        }

        return request()->is('my-day') ? '/my-day' : '/tasks';
    }
}
