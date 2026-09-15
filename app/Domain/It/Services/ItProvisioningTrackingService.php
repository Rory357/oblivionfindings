<?php

namespace App\Domain\It\Services;

use App\Models\ItCatalogSubmission;
use App\Models\ItProvisioningRequest;
use App\Models\ItTicketEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/** Public projection of canonical provisioning; never serialize work notes or HR context. */
final class ItProvisioningTrackingService
{
    private const EVENTS = [
        'created' => 'Request submitted',
        'assigned' => 'Assigned to IT',
        'approval_requested' => 'Approval requested',
        'approval_withdrawn' => 'Approval needs a new review',
        'approved' => 'Approval recorded',
        'rejected' => 'Approval declined',
        'fulfilled' => 'Work completed',
        'failed' => 'Needs IT attention',
        'retried' => 'IT work queued for another attempt',
        'reopened' => 'IT work reopened',
        'cancelled' => 'Request cancelled',
        'rescheduled' => 'Work date updated',
        'approval_expired' => 'Approval deadline passed; a new review is needed',
    ];

    private const WORKFLOW_EVENTS = [
        'cancelled' => 'Request workflow cancelled; completed work remains recorded',
        'rescheduled' => 'Workflow date updated',
        'resumed' => 'Request workflow resumed',
        'source_cancelled' => 'Source checklist cancelled; completed work remains recorded',
        'source_resumed' => 'Source checklist resumed',
        'source_rescheduled' => 'Workflow date updated from its source',
        'reversal_requested' => 'Corrective work requested for completed tasks',
    ];

    private ?bool $hasReversals = null;

    private ?bool $hasCancellation = null;

    public function __construct(
        private readonly ItProvisioningAccessService $access,
        private readonly ItCatalogFieldOptionService $fieldOptions,
    ) {}

    public function listing(User $actor, Request $http): array
    {
        $query = $this->access->applyTrackingScope(ItProvisioningRequest::query(), $actor);
        $total = (clone $query)->count();
        $search = mb_substr(trim((string) $http->query('my_q', '')), 0, 200);
        if ($search !== '') {
            $query->where(function ($matching) use ($search): void {
                $text = '%'.addcslashes($search, '%_\\').'%';
                // Search the public retained title. Internal task renames must not
                // become a discovery channel for requester-private work notes.
                $matching->where(function (Builder $legacy) use ($text): void {
                    $legacy->whereDoesntHave('catalogSubmissions', fn (Builder $source) => $source->whereNotNull('contract_snapshot->name'))
                        ->where('item', 'like', $text);
                })->orWhereHas('catalogSubmissions', fn (Builder $source) => $source->where('contract_snapshot->name', 'like', $text));
                if (preg_match('/^(?:IT-P)?0*([1-9][0-9]*)$/i', $search, $matches)) {
                    $matching->orWhere('it_provisioning_requests.id', (int) $matches[1]);
                }
            });
        }
        $status = $http->query('my_status');
        if (is_string($status) && $status !== '' && $status !== 'all') {
            if (! in_array($status, ItProvisioningRequest::STATUSES, true)) {
                $query->whereRaw('1 = 0');
            } else {
                $query->where(function (Builder $rows) use ($status): void {
                    $rows->where(fn (Builder $single) => $single->whereNull('provisioning_workflow_id')->where('status', $status))
                        ->orWhereHas('workflow', fn (Builder $workflow) => $this->whereWorkflowStatus($workflow, $status));
                });
            }
        }
        $page = $query->with($this->relations())->orderByDesc('created_at')->orderByDesc('id')
            ->paginate(20, ['*'], 'my_provisioning_page')->withQueryString();

        return [
            'total' => $total,
            'matched' => $page->total(),
            'data' => $page->getCollection()->map(fn (ItProvisioningRequest $row) => $this->row($row))->all(),
            'links' => $page->linkCollection()->all(),
            'last_page' => $page->lastPage(),
        ];
    }

    public function detail(User $actor, ItProvisioningRequest $request): array
    {
        abort_unless($this->access->canTrack($actor, $request), 404);
        $request = $request->fresh();
        $submission = $request->catalogSubmissions()
            ->when((int) $request->employeeProfile?->user_id !== (int) $actor->id,
                fn ($source) => $source->where('requester_user_id', $actor->id))
            ->oldest('id')->firstOrFail();

        return [
            ...$this->row($request),
            'viewer_user_id' => (int) $actor->id,
            'catalogue_version' => $submission->schema_version,
            'submitted_at' => $submission->submitted_at?->toIso8601String(),
            'answers' => $this->answers($submission, $actor),
            'attachments' => app(ItCatalogAttachmentService::class)->forResult($actor, $request, publicOnly: true),
            'events' => $this->events($request),
            // Per-task progress without internal task titles, notes or approver identity.
            'tasks' => $this->tasks($request)->sortBy([['stage', 'asc'], ['id', 'asc']])->values()->map(fn (ItProvisioningRequest $task) => [
                'stage' => (int) $task->stage, 'type' => $task->type, 'status' => $task->status,
                'approval_status' => $task->approval_required ? $task->approval_status : 'not_required',
                'due_date' => $task->due_date?->toDateString(),
            ])->all(),
        ];
    }

    private function relations(): array
    {
        return [
            'workflow.requests' => fn ($tasks) => $this->originals($tasks),
            'catalogSubmissions' => fn ($source) => $source->oldest('id')
                ->select(['id', 'result_type', 'result_id', 'contract_snapshot']),
        ];
    }

    private function originals($tasks)
    {
        // Older installations have no corrective tasks yet.
        return ($this->hasReversals ??= Schema::hasColumn('it_provisioning_requests', 'reversal_of_request_id'))
            ? $tasks->whereNull('reversal_of_request_id') : $tasks;
    }

    private function tasks(ItProvisioningRequest $request): Collection
    {
        $request->loadMissing($this->relations());

        return $request->workflow
            ? $request->workflow->requests->filter(fn ($task) => $task->reversal_of_request_id === null)->values()
            : collect([$request]);
    }

    private function row(ItProvisioningRequest $request): array
    {
        $tasks = $this->tasks($request);
        $statuses = $tasks->pluck('status');
        $status = match (true) {
            $request->workflow?->cancelled_at !== null => 'cancelled',
            $statuses->isNotEmpty() && $statuses->every(fn ($value) => $value === 'cancelled') => 'cancelled',
            $statuses->contains('failed'), $statuses->contains('cancelled') => 'failed',
            $statuses->isNotEmpty() && $statuses->every(fn ($value) => $value === 'done') => 'done',
            $statuses->contains('in_progress'), $statuses->contains('done') => 'in_progress',
            default => 'pending',
        };
        $approvals = $tasks->where('approval_required', true)->pluck('approval_status');
        $approval = match (true) {
            $approvals->isEmpty() => 'not_required',
            $approvals->contains('rejected') => 'rejected',
            $approvals->contains('expired') => 'expired',
            $approvals->contains('cancelled') => 'cancelled',
            $approvals->every(fn ($value) => $value === 'approved') => 'approved',
            default => 'pending',
        };
        $open = $tasks->whereNotIn('status', ['done', 'cancelled']);
        $due = ($open->isNotEmpty() ? $open : $tasks)->pluck('due_date')->filter()->sort()->first();
        $updated = $tasks->pluck('updated_at')->push($request->workflow?->updated_at)->filter()->sort()->last();
        $contract = $request->catalogSubmissions->first()?->contract_snapshot;

        return [
            'id' => $request->id,
            'reference' => 'IT-P'.str_pad((string) $request->id, 6, '0', STR_PAD_LEFT),
            'title' => $contract['name'] ?? $request->item,
            'type' => $request->workflow?->lifecycle_type ?? $request->type,
            'status' => $status,
            'approval_status' => $approval,
            'progress' => [
                'total' => $tasks->count(), 'done' => $tasks->where('status', 'done')->count(),
                'failed' => $tasks->where('status', 'failed')->count(),
                'cancelled' => $tasks->where('status', 'cancelled')->count(),
            ],
            'created_at' => $request->created_at?->toIso8601String(),
            'updated_at' => $updated?->toIso8601String(),
            'due_date' => $due?->toDateString(),
            'href' => '/it/provisioning/'.$request->id,
        ];
    }

    /** Apply the same original-task verdict as row(), even if corrective work changed the stored workflow status. */
    private function whereWorkflowStatus(Builder $workflow, string $status): void
    {
        $hasCancellation = $this->hasCancellation ??= Schema::hasColumn('it_provisioning_workflows', 'cancelled_at');
        $allCancelled = function (Builder $query): void {
            $query->whereHas('requests', fn ($tasks) => $this->originals($tasks))
                ->whereDoesntHave('requests', fn ($tasks) => $this->originals($tasks)->where('status', '!=', 'cancelled'));
        };
        if ($status === 'cancelled') {
            $workflow->where(function (Builder $query) use ($hasCancellation, $allCancelled): void {
                if ($hasCancellation) {
                    $query->whereNotNull('cancelled_at')->orWhere($allCancelled);
                } else {
                    $allCancelled($query);
                }
            });

            return;
        }
        if ($hasCancellation) {
            $workflow->whereNull('cancelled_at');
        }
        $workflow->whereHas('requests', fn ($tasks) => $this->originals($tasks));
        if ($status === 'failed') {
            $workflow->where(function (Builder $query): void {
                $query->whereHas('requests', fn ($tasks) => $this->originals($tasks)->where('status', 'failed'))
                    ->orWhere(function (Builder $partial): void {
                        $partial->whereHas('requests', fn ($tasks) => $this->originals($tasks)->where('status', 'cancelled'))
                            ->whereHas('requests', fn ($tasks) => $this->originals($tasks)->where('status', '!=', 'cancelled'));
                    });
            });
        } elseif ($status === 'done') {
            $workflow->whereDoesntHave('requests', fn ($tasks) => $this->originals($tasks)->where('status', '!=', 'done'));
        } elseif ($status === 'pending') {
            $workflow->whereDoesntHave('requests', fn ($tasks) => $this->originals($tasks)->where('status', '!=', 'pending'));
        } else {
            $workflow->whereDoesntHave('requests', fn ($tasks) => $this->originals($tasks)->whereIn('status', ['failed', 'cancelled']))
                ->whereHas('requests', fn ($tasks) => $this->originals($tasks)->whereIn('status', ['done', 'in_progress']))
                ->whereHas('requests', fn ($tasks) => $this->originals($tasks)->where('status', '!=', 'done'));
        }
    }

    private function events(ItProvisioningRequest $request): array
    {
        $tasks = $this->tasks($request);
        $events = ItTicketEvent::query()->where('subject_type', $request->getMorphClass())
            ->whereIn('subject_id', $tasks->pluck('id'))->whereIn('type', array_keys(self::EVENTS))
            ->where(fn ($query) => $query->where('type', '!=', 'created')->orWhere('subject_id', $request->id))
            ->orderBy('created_at')->orderBy('id')->get(['id', 'type', 'subject_id', 'created_at']);
        $completedEvent = $tasks->isNotEmpty() && $tasks->every(fn ($task) => $task->status === 'done')
            ? $events->where('type', 'fulfilled')->last()?->id : null;
        $public = $events->map(function ($event) use ($request, $completedEvent): array {
            $label = self::EVENTS[$event->type];
            if ($request->workflow) {
                $label = match ($event->type) {
                    'fulfilled' => $event->id === $completedEvent ? 'All request tasks completed' : 'A request task completed',
                    'cancelled' => 'A request task cancelled',
                    'rescheduled' => 'A request task date updated',
                    default => $label,
                };
            }

            return ['id' => $event->id, 'label' => $label, 'at' => $event->created_at?->toIso8601String()];
        });
        if ($request->workflow) {
            $public = $public->concat($request->workflow->events()->whereIn('type', array_keys(self::WORKFLOW_EVENTS))
                ->get(['id', 'type', 'created_at'])->map(fn ($event) => [
                    'id' => $event->id, 'label' => self::WORKFLOW_EVENTS[$event->type],
                    'at' => $event->created_at?->toIso8601String(),
                ]));
        }

        return $public->sortBy([['at', 'asc'], ['id', 'asc']])->values()->all();
    }

    private function answers(ItCatalogSubmission $submission, User $actor): array
    {
        $fields = collect($submission->schema_snapshot['fields'] ?? [])
            ->filter(fn ($field) => is_array($field) && ($field['visibility'] ?? 'requester') === 'requester');
        $options = $this->fieldOptions->forTypes($actor, $fields->pluck('type')->intersect(ItCatalogFieldOptionService::TYPES)->all());
        $answers = [];
        foreach ($fields as $field) {
            $key = $field['key'] ?? null;
            if (! is_string($key) || ! array_key_exists($key, $submission->submitted_values ?? [])) {
                continue;
            }
            $value = $submission->submitted_values[$key];
            if (in_array($field['type'] ?? '', ItCatalogFieldOptionService::TYPES, true)) {
                $value = collect($options[$field['type']] ?? [])->firstWhere('id', $value)['name'] ?? 'Selection no longer available to your access';
            }
            if (is_array($value)) {
                $value = implode(', ', array_filter($value, fn ($part) => is_string($part) || is_numeric($part)));
            }
            if (! is_scalar($value) && $value !== null) {
                continue;
            }
            $answers[] = [
                'label' => (string) ($field['label'] ?? $key),
                'value' => is_bool($value) ? ($value ? 'Yes' : 'No') : (filled($value) ? (string) $value : 'Not supplied'),
            ];
        }

        return $answers;
    }
}
