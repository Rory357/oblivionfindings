<?php

namespace App\Services\Tasks\Providers;

use App\Models\SiteHazard;
use App\Models\User;
use App\Services\Sites\HazardAssignmentService;
use App\Services\SiteTypeAccessService;
use App\Services\Tasks\Contracts\AssignableTaskProvider;
use App\Services\Tasks\Contracts\HasModelClass;
use App\Services\Tasks\Contracts\ManagesAssignmentNotifications;
use App\Services\Tasks\Contracts\SiteScopedTaskProvider;
use App\Services\Tasks\Contracts\TaskProvider;
use App\Services\Tasks\SiteHazardTaskItem;
use App\Services\Tasks\TaskAggregator;
use App\Services\Tasks\TaskAssignmentNotifier;
use App\Services\Tasks\TaskItem;
use App\Services\Tasks\TaskProviderAuthorization;
use App\Services\UserSiteAccessService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class SiteHazardProvider implements AssignableTaskProvider, HasModelClass, ManagesAssignmentNotifications, SiteScopedTaskProvider, TaskProvider
{
    public function sourceKey(): string
    {
        return 'hazard';
    }

    public function label(): string
    {
        return 'Hazards';
    }

    public function modelClass(): string
    {
        return SiteHazard::class;
    }

    public function canAssign(User $user): bool
    {
        // Mirrors routes/sites.php: POST /hazards/{hazard}/assign → permission:hazards.assign.
        return $user->canDo('hazards.assign') && $user->canDo('sites.viewAny');
    }

    public function assign(User $actor, int $id, ?int $assigneeId): void
    {
        if ($assigneeId === null) {
            throw ValidationException::withMessages(['assignee_id' => 'Hazards must be assigned to a staff member.']);
        }
        $options = request()->validate([
            'expected_assigned_to_user_id' => 'sometimes|nullable|integer',
            'expected_due_date' => 'sometimes|nullable|date_format:Y-m-d',
        ]);
        $result = app(HazardAssignmentService::class)->assign($actor, $id, $assigneeId, $options, notify: false, requireRead: true);
        if ($result['owner_changed']) {
            DB::afterCommit(function () use ($result, $id, $assigneeId): void {
                try {
                    TaskAssignmentNotifier::notify($result['actor'], $this, $id, $assigneeId, app(TaskAggregator::class));
                } catch (Throwable $error) {
                    Log::warning('Hazard task assignment notification failed after commit', ['hazard_id' => $id, 'error_class' => $error::class]);
                }
            });
        }
    }

    public function canView(User $user): bool
    {
        return $user->canDo('hazards.view');
    }

    public function authorizedTasks(User $user, array $filters = []): array
    {
        $query = SiteHazard::query()
            ->with(['site:id,name', 'assignedTo:id,name'])
            ->whereHas('site', fn ($query) => $query->whereIn('type', app(SiteTypeAccessService::class)->allowedTypes($user)))
            ->when(isset($filters['id']), fn ($q) => $q->whereKey((int) $filters['id']))
            ->orderByDesc('created_at')
            ->limit(300);

        if (empty($filters['include_done'])) {
            // Mitigated controls still require source review and closure.
            $query->whereIn('status', ['open', 'in_progress', 'mitigated']);
        }

        return app(TaskProviderAuthorization::class)->siteScoped(
            $user,
            $this->canView($user),
            $query,
            fn ($scoped, User $actor) => $scoped->whereIn(
                'site_id',
                app(UserSiteAccessService::class)->accessibleSiteIds($actor, ['sites.viewAll']),
            ),
            function (SiteHazard $hazard) {
                return new SiteHazardTaskItem(
                    id: 'hazard-'.$hazard->id,
                    source: $this->sourceKey(),
                    sourceLabel: $this->label(),
                    ref: $hazard->reference_number,
                    title: $hazard->custom_hazard_type
                        ?: ucfirst(str_replace('_', ' ', (string) $hazard->hazard_type)).' hazard',
                    status: (string) $hazard->status,
                    bucket: match ($hazard->status) {
                        'open' => TaskItem::BUCKET_OPEN,
                        'in_progress', 'mitigated' => TaskItem::BUCKET_IN_PROGRESS,
                        default => TaskItem::BUCKET_DONE,
                    },
                    severity: TaskItem::normaliseSeverity($hazard->risk_rating),
                    assignee: $hazard->assignedTo
                        ? ['id' => $hazard->assignedTo->id, 'name' => $hazard->assignedTo->name]
                        : null,
                    site: $hazard->site
                        ? ['id' => $hazard->site->id, 'name' => $hazard->site->name]
                        : null,
                    dueAt: $hazard->due_date ? Carbon::parse($hazard->due_date->toDateString(), config('app.worker_timezone', 'Pacific/Auckland'))->endOfDay()->toIso8601String() : null,
                    createdAt: optional($hazard->created_at)->toIso8601String(),
                    link: "/hazards/{$hazard->id}",
                    type: 'Hazard',
                    description: $hazard->description ? str($hazard->description)->limit(140)->toString() : null,
                    displayState: $hazard->status === 'mitigated' ? 'Awaiting closure' : null,
                    actionHelp: $hazard->status === 'mitigated' ? 'Controls are recorded as mitigated. Review the hazard and complete its remaining actions before closure.' : null,
                );
            },
        );
    }
}
