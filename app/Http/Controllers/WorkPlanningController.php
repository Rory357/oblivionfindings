<?php

namespace App\Http\Controllers;

use App\Models\WorkPlanningRecord;
use App\Services\Overview\OverviewPlans;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class WorkPlanningController extends Controller
{
    public function __construct(private readonly OverviewPlans $plans) {}

    public function index(Request $request, string $kind)
    {
        $user = $request->user();
        $definition = $this->plans->definition($kind);
        $query = $this->plans->query($user, $kind);
        $sites = $this->plans->sites($user, $definition);
        $filters = $request->validate(['site' => ['nullable', Rule::in(array_column($sites, 'id'))], 'status' => ['nullable', Rule::in(['open', 'attention', 'overdue', 'planned', 'in_progress', 'blocked', 'completed', 'cancelled', 'all'])], 'record' => ['nullable', 'integer', 'min:1'], 'search' => ['nullable', 'string', 'max:100']]);
        $selected = isset($filters['record']) ? (clone $query)->findOrFail($filters['record']) : null;
        $query->when($filters['site'] ?? null, fn ($q, $site) => $q->where('site_id', $site))
            ->when($filters['search'] ?? null, fn ($q, $search) => $q->where('title', 'like', '%'.addcslashes($search, '%_\\').'%'));
        $counts = (clone $query)->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');
        $today = now(config('app.worker_timezone', 'Pacific/Auckland'))->toDateString();
        $counts['overdue'] = (clone $query)->whereNotIn('status', ['completed', 'cancelled'])->whereDate('due_date', '<', $today)->count();
        $status = $filters['status'] ?? 'open';
        if ($status === 'attention') {
            $this->plans->needingAttention($query);
        } elseif (in_array($status, ['open', 'overdue'], true)) {
            $query->whereNotIn('status', ['completed', 'cancelled']);
            if ($status === 'overdue') {
                $query->whereDate('due_date', '<', $today);
            }
        } elseif ($status !== 'all') {
            $query->where('status', $status);
        }
        $history = $selected ? DB::table('work_planning_revisions')->where('record_id', $selected->id)->orderByDesc('version')->limit(20)->get(['version', 'created_at', 'snapshot'])->map(fn ($revision) => [
            'version' => $revision->version, 'at' => CarbonImmutable::parse($revision->created_at)->toIso8601String(),
            'status' => json_decode($revision->snapshot, true)['status'],
            'progress' => json_decode($revision->snapshot, true)['progress'],
        ]) : [];

        return inertia('work-plans/index', [
            'definition' => $definition, 'sites' => $sites, 'owners' => $this->plans->canWrite($user, $definition) ? $this->plans->owners($user, $definition) : [],
            'canWrite' => $this->plans->canWrite($user, $definition), 'currentUserId' => $user->id,
            'records' => $query->with(['owner:id,name', 'site:id,name'])->orderBy('due_date')->orderBy('id')->paginate(24)->withQueryString()
                ->through(fn ($record) => $record->toArray() + ['overdue' => ! in_array($record->status, ['completed', 'cancelled'], true) && $record->due_date->toDateString() < $today]),
            'selected' => $selected?->load(['owner:id,name', 'site:id,name']), 'history' => $history, 'counts' => $counts, 'filters' => $filters,
        ]);
    }

    public function store(Request $request, string $kind)
    {
        $definition = $this->plans->definition($kind);
        abort_unless($this->plans->canWrite($request->user(), $definition), 403);
        $data = $this->validated($request, $definition);
        $record = DB::transaction(function () use ($request, $kind, $data) {
            $record = WorkPlanningRecord::create($data + ['kind' => $kind, 'created_by' => $request->user()->id, 'version' => 1]);
            $this->revision($record, $request->user()->id);

            return $record;
        });

        return to_route('work-plans.index', ['kind' => $kind, 'site' => $data['site_id'], 'status' => in_array($data['status'], ['completed', 'cancelled'], true) ? $data['status'] : 'open'])->with('success', 'Plan added.');
    }

    public function update(Request $request, string $kind, int $record)
    {
        // Scope the object before validating or disclosing its contents.
        $definition = $this->plans->definition($kind);
        $this->plans->query($request->user(), $kind)->findOrFail($record);
        abort_unless($this->plans->canWrite($request->user(), $definition), 403);
        $data = $this->validated($request, $definition, true);
        DB::transaction(function () use ($request, $kind, $record, $data) {
            $plan = $this->plans->query($request->user(), $kind)->lockForUpdate()->findOrFail($record);
            if ($plan->version !== (int) $data['version']) {
                throw ValidationException::withMessages(['version' => 'This plan changed in another window. Reopen it before saving. Your draft is still here.']);
            }
            // Keep the site audience immutable, including the revision history.
            if ((int) $plan->site_id !== (int) $data['site_id']) {
                throw ValidationException::withMessages(['site_id' => 'The site stays with this record. Create a new plan for a different site.']);
            }
            $data['version'] = $plan->version + 1;
            if ($plan->status === 'completed' && $data['status'] === 'completed') {
                $data['completed_at'] = $plan->completed_at;
            }
            $plan->update($data);
            $this->revision($plan, $request->user()->id);
        });

        return to_route('work-plans.index', ['kind' => $kind, 'site' => $data['site_id'], 'status' => in_array($data['status'], ['completed', 'cancelled'], true) ? $data['status'] : 'open'])->with('success', 'Plan saved.');
    }

    private function validated(Request $request, array $definition, bool $updating = false): array
    {
        $user = $request->user();
        $siteIds = array_column($this->plans->sites($user, $definition), 'id');
        $fields = array_keys($definition['fields']);
        $rules = [
            'title' => ['required', 'string', 'max:160'], 'due_date' => ['required', 'date_format:Y-m-d'],
            'status' => ['required', Rule::in(['planned', 'in_progress', 'blocked', 'completed', 'cancelled'])],
            'progress' => ['required', 'integer', 'between:0,100'],
            'site_id' => $definition['scope'] === 'site' ? ['required', 'integer', Rule::in($siteIds)] : ['present', 'nullable', 'prohibited'],
            'owner_id' => ['required', 'integer'], 'details' => ['required', 'array:'.implode(',', $fields)],
            'completion_note' => ['nullable', 'required_if:status,completed', 'string', 'max:2000'],
            'version' => $updating ? ['required', 'integer', 'min:1'] : ['prohibited'],
        ];
        foreach ($fields as $field) {
            $rules['details.'.$field] = in_array($field, $definition['numbers'] ?? [], true)
                ? ['required', 'integer', 'min:0', 'max:1000000'] : ['nullable', 'string', 'max:2000'];
        }
        if ($definition['kind'] === 'it-licences') {
            $rules['details.product'] = ['required', 'string', 'max:160'];
        }
        $data = $request->validate($rules);
        $data['site_id'] ??= null;
        $existingOwner = $updating ? $this->plans->query($user, $definition['kind'])->whereKey($request->route('record'))->value('owner_id') : null;
        if (! $updating || (int) $existingOwner !== (int) $data['owner_id']) {
            $owner = collect($this->plans->owners($user, $definition))->firstWhere('id', (int) $data['owner_id']);
            if (! $owner || ($definition['scope'] === 'site' && ! in_array((int) $data['site_id'], $owner['site_ids'], true))) {
                throw ValidationException::withMessages(['owner_id' => 'Choose an owner who can access this type of plan and its site.']);
            }
        }
        $data['progress'] = $data['status'] === 'completed' ? 100 : ($data['status'] === 'planned' ? 0 : min(99, (int) $data['progress']));
        $data['completed_at'] = $data['status'] === 'completed' ? now() : null;

        return $data;
    }

    private function revision(WorkPlanningRecord $record, int $actor): void
    {
        DB::table('work_planning_revisions')->insert(['record_id' => $record->id, 'actor_id' => $actor, 'version' => $record->version,
            'snapshot' => json_encode($record->only(['title', 'due_date', 'site_id', 'owner_id', 'status', 'progress', 'details', 'completion_note']), JSON_THROW_ON_ERROR), 'created_at' => now()]);
    }
}
