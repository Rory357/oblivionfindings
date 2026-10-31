<?php

namespace App\Services\Tasks\Providers;

use App\Models\RestraintEvent;
use App\Models\Site;
use App\Models\User;
use App\Services\CurrentAuthorizationReads;
use App\Services\HealthSafety\HealthSafetyReadContext;
use App\Services\HealthSafety\RestraintAccessService;
use App\Services\Tasks\Contracts\ContextualHealthSafetyTaskProvider;
use App\Services\Tasks\Contracts\HasModelClass;
use App\Services\Tasks\Contracts\SiteScopedTaskProvider;
use App\Services\Tasks\TaskItem;
use App\Services\Tasks\TaskProviderAuthorization;
use App\Services\UserSiteAccessService;
use App\Support\HealthSafety\RestraintReturn;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Current source-owned review work; the historical reviewer is not its named owner. */
class RestraintReviewProvider implements ContextualHealthSafetyTaskProvider, HasModelClass, SiteScopedTaskProvider
{
    public function sourceKey(): string
    {
        return 'restraint_review';
    }

    public function label(): string
    {
        return 'Restraint Reviews';
    }

    public function modelClass(): string
    {
        return RestraintEvent::class;
    }

    public function canView(User $user): bool
    {
        return $user->canDo('restraints.review') || $user->canDo('restraints.manage');
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

    private function readTasks(User $viewer, array $filters, ?HealthSafetyReadContext $context = null, bool $navigation = false): array
    {
        $access = app(RestraintAccessService::class);
        try {
            $consume = function (User $current, CurrentAuthorizationReads $reads, array $sites) use ($access, $filters, $navigation): array {
                $query = $reads->query(RestraintEvent::query())->select([
                    'id', 'reference_number', 'client_id', 'site_id', 'stay_id', 'created_by',
                    'source_schema_version', 'recorded_client_id', 'recorded_site_id', 'recorded_stay_id',
                    'behaviour_support_plan_id', 'behaviour_support_plan_version_id',
                    'started_at', 'created_at', 'severity', 'reviewed_at', 'source_revision',
                    'reviewed_source_revision', 'review_owner_id', 'review_due_at',
                ])->when(isset($filters['id']), fn ($q) => $q->whereKey((int) $filters['id']))
                    ->orderByDesc('started_at')->limit(300);
                if (empty($filters['include_done'])) {
                    $query->where(function ($pending): void {
                        $pending->whereNull('reviewed_source_revision')->orWhereColumn('reviewed_source_revision', '<', 'source_revision');
                    });
                }
                $origin = RestraintReturn::leaf($filters['return_to'] ?? null);
                if (! in_array(parse_url($origin ?? '', PHP_URL_PATH), ['/tasks', '/my-day'], true)) {
                    $origin = request()->is('my-day') ? '/my-day' : '/tasks';
                }

                return app(TaskProviderAuthorization::class)->siteScoped($current, $this->canView($current), $query,
                    fn ($scoped) => $current->canDo('healthSafety.viewAllSites') ? $scoped
                        : app(UserSiteAccessService::class)->applyRestraintEventSiteScopeForSiteIds($scoped, $sites),
                    function (RestraintEvent $row) use ($access, $current, $reads, $sites, $origin, $navigation): ?TaskItem {
                        try {
                            $access->assertEvent($row, $current, $reads, $sites, 'review');
                        } catch (HttpException $error) {
                            if (! in_array($error->getStatusCode(), [403, 404], true)) {
                                throw $error;
                            }

                            return null;
                        }
                        $done = ! $row->needsReview();
                        $assignee = null;
                        if ((int) $row->review_owner_id === (int) $current->id) {
                            $assignee = $navigation ? ['id' => (int) $current->id] : ['id' => (int) $current->id, 'name' => $current->name];
                        } elseif (! $navigation && $row->review_owner_id) {
                            try {
                                $owner = $access->eligibleStaff((int) $row->review_owner_id, (int) $row->site_id, $reads, ['restraints.review', 'restraints.manage']);
                                $assignee = ['id' => (int) $owner->id, 'name' => $owner->name];
                            } catch (HttpException $error) {
                                if (! in_array($error->getStatusCode(), [403, 404], true)) {
                                    throw $error;
                                }
                            }
                        }
                        $client = $navigation ? null : $row->client;
                        $site = $navigation || $row->site_id === null ? null : $reads->query(Site::query())->find($row->site_id);

                        return new TaskItem(id: 'restraint_review-'.$row->id, source: $this->sourceKey(), sourceLabel: $this->label(),
                            ref: $navigation ? null : $row->reference_number, title: $navigation ? '' : ($done ? 'Restraint review recorded' : 'Restraint awaiting review'),
                            status: $done ? 'reviewed' : 'awaiting_review', bucket: $done ? TaskItem::BUCKET_DONE : TaskItem::BUCKET_OPEN,
                            severity: TaskItem::normaliseSeverity($row->severity), assignee: $assignee,
                            client: $client ? ['id' => (int) $client->id, 'name' => trim($client->first_name.' '.$client->last_name)] : null,
                            site: $site ? ['id' => (int) $site->id, 'name' => $site->name] : null,
                            dueAt: $row->review_due_at?->toIso8601String(), createdAt: $navigation ? null : $row->created_at?->toIso8601String(),
                            link: $navigation ? null : '/health-safety/restraints/events/'.$row->id.'/review?return_to='.rawurlencode($origin),
                            type: $navigation ? null : 'Restraint review');
                    });
            };

            return $context ? $access->withReadContext($context, $consume) : $access->withCurrentViewer($viewer, $consume);
        } catch (HttpException $error) {
            if (! in_array($error->getStatusCode(), [403, 404], true)) {
                throw $error;
            }

            return [];
        }
    }
}
