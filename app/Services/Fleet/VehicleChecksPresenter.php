<?php

namespace App\Services\Fleet;

use App\Models\Asset;
use App\Models\AssetDocument;
use App\Models\FleetChecklistRun;
use App\Models\FleetChecklistRunAmendment;
use App\Models\FleetChecklistTemplateVersion;
use App\Models\FleetVehicleCheckRequirement;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Read model for the vehicle's Checks & inspections: the check requirement,
 * the checklist library this vehicle can use (with the approved check rule
 * that covers each version, if any), and recent submitted checks with their
 * original answers, files, amendments and follow-up. Bounded; nothing here
 * writes. Callers resolve the vehicle in the viewer's scope first.
 */
class VehicleChecksPresenter
{
    private const RUNS = 50;

    /** Files that failed storage or scanning are not counted as kept evidence. */
    private const NOT_KEPT = ['quarantined', 'storage_failed'];

    public function __construct(
        private readonly VehicleCheckLibraryService $library,
        private readonly MaintenanceAccessService $maintenance,
        private readonly MaintenancePolicyService $policy,
    ) {}

    /** @return array<string,mixed> */
    public function present(User $viewer, Asset $asset): array
    {
        $can = $this->permissions($viewer, $asset);
        $definitions = $this->library->forVehicle($asset);
        $rule = $asset->site_id && $asset->category
            ? $this->policy->current((int) $asset->site_id, (string) $asset->category, 'check')
            : null;

        return [
            'requirement' => $this->requirement($asset, $definitions, $rule),
            'templates' => array_map(fn (array $definition): array => $this->template($definition, $rule, $asset), $definitions),
            'runs' => $this->runs($asset, $can),
            'route' => $can['report'] ? $this->route($asset) : null,
            'can' => $can,
        ];
    }

    /** @return array<string,bool> */
    private function permissions(User $viewer, Asset $asset): array
    {
        $atSite = $asset->site_id !== null
            && in_array((int) $asset->site_id, $this->maintenance->approvedSiteIds($viewer), true);
        $manage = $this->maintenance->canManage($viewer);

        return [
            // Checks are recorded by maintenance managers (MaintenanceCheckService).
            'start' => $manage && $atSite,
            'amend' => $manage && $atSite,
            'manage_templates' => $manage,
            'manage_requirement' => $viewer->canDo('fleet.manage'),
            'upload' => Gate::forUser($viewer)->allows('manageDocuments', $asset),
            'report' => $this->maintenance->canReport($viewer) && $atSite,
            'link_work' => $manage && $atSite,
            'view_maintenance' => $this->maintenance->canRead($viewer),
            'view_files' => Gate::forUser($viewer)->allows('view', $asset),
            // Answer files download through ChecklistController::evidence, which
            // needs the approved site and maintenance or reviewer authority.
            'view_answer_files' => $atSite && ($manage || $this->maintenance->canReview($viewer, $asset)),
        ];
    }

    /**
     * The requirement shown on the card: the vehicle's own choice, otherwise
     * the checklist the site's approved check rule names, otherwise the first
     * checklist available to the vehicle.
     *
     * @param  list<array<string,mixed>>  $definitions
     * @param  array<string,mixed>|null  $rule
     * @return array<string,mixed>
     */
    private function requirement(Asset $asset, array $definitions, ?array $rule): array
    {
        $requirement = FleetVehicleCheckRequirement::query()->where('asset_id', $asset->id)->with('owner:id,name')->first();
        $available = array_column($definitions, 'template_id');
        $ruleTemplate = (int) ($rule['rules']['template_id'] ?? 0);
        [$templateId, $source] = match (true) {
            $requirement?->template_id !== null && in_array((int) $requirement->template_id, $available, true) => [(int) $requirement->template_id, 'vehicle'],
            $ruleTemplate > 0 && in_array($ruleTemplate, $available, true) => [$ruleTemplate, 'approved_rule'],
            $definitions !== [] => [(int) $definitions[0]['template_id'], 'library'],
            default => [null, null],
        };

        return [
            'template_id' => $templateId,
            'source' => $source,
            'due_on' => $asset->inspection_due_at?->toDateString(),
            'owner' => $requirement?->owner ? ['id' => (int) $requirement->owner->id, 'name' => $requirement->owner->name] : null,
            'lock_version' => (int) ($requirement?->lock_version ?? 0),
        ];
    }

    /**
     * @param  array<string,mixed>  $definition
     * @param  array<string,mixed>|null  $rule
     * @return array<string,mixed>
     */
    private function template(array $definition, ?array $rule, Asset $asset): array
    {
        $covered = $rule !== null
            && (int) ($rule['rules']['template_id'] ?? 0) === $definition['template_id']
            && is_string($rule['rules']['template_sha256'] ?? null)
            && hash_equals($rule['rules']['template_sha256'], $definition['items_sha256']);
        $label = VehicleCheckLibraryService::ASSIGNMENT_LABELS[$definition['assignment']] ?? 'All vehicles';

        return [
            'id' => $definition['template_id'],
            'version_id' => $definition['version_id'],
            'version' => $definition['version'],
            'name' => $definition['name'],
            'use' => $definition['use'],
            'assignment' => $definition['assignment'],
            'assignment_label' => $definition['assignment'] === 'vehicle'
                ? $label.' · '.($asset->asset_tag ?: $asset->name) : $label,
            'evidence_required' => $definition['evidence_required'],
            'items_sha256' => $definition['items_sha256'],
            'questions' => $definition['questions'],
            'source' => $definition['source'],
            'published_at' => $this->iso($definition['published_at']),
            'published_by' => $definition['published_by'],
            // Only a version the site's approved check rule covers can pass or fail.
            'rule_version_id' => $covered ? (int) $rule['id'] : null,
        ];
    }

    /**
     * @param  array<string,bool>  $can
     * @return array{data: list<array<string,mixed>>, total: int}
     */
    private function runs(Asset $asset, array $can): array
    {
        $query = FleetChecklistRun::query()->where('asset_id', $asset->id)->whereNotNull('submitted_at');
        $total = (clone $query)->count();
        $runs = $query->with(['user:id,name', 'template:id,name'])
            ->orderByDesc('submitted_at')->orderByDesc('id')->limit(self::RUNS)->get();
        if ($runs->isEmpty()) {
            return ['data' => [], 'total' => $total];
        }
        $ids = $runs->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $versions = FleetChecklistTemplateVersion::query()
            ->whereIn('id', $runs->pluck('template_version_id')->filter()->unique()->values())
            ->get(['id', 'version'])->keyBy('id');
        $files = AssetDocument::query()->where('asset_id', $asset->id)->where('source_type', 'checklist_run')
            ->whereIn('source_id', $ids)->whereNull('archived_at')->orderBy('id')->get()->groupBy('source_id');
        $amendments = FleetChecklistRunAmendment::query()->whereIn('run_id', $ids)->with('recordedBy:id,name')
            ->orderBy('recorded_at')->orderBy('id')->get()->groupBy('run_id');
        $links = $this->links($runs);

        return [
            'data' => $runs->map(function (FleetChecklistRun $run) use ($asset, $can, $versions, $files, $amendments, $links): array {
                $presented = is_array($run->presented_template_json) ? $run->presented_template_json : [];
                $questions = $this->library->questions(is_array($presented['items'] ?? null) ? $presented['items'] : []);
                $responses = is_array($run->responses) ? $run->responses : [];
                $answers = array_map(function (array $question) use ($responses, $run, $can): array {
                    $answer = $responses[$question['id']] ?? null;
                    $file = is_array($answer) && is_array($answer['evidence_file'] ?? null) ? $answer['evidence_file'] : null;

                    return [
                        'id' => $question['id'],
                        'label' => $question['label'],
                        'value' => $this->library->answerLabel($question, $answer),
                        'note' => is_array($answer) && is_string($answer['notes'] ?? null) && trim($answer['notes']) !== ''
                            ? trim($answer['notes']) : null,
                        'evidence' => $file === null ? null : [
                            'name' => (string) ($file['original_name'] ?? 'Evidence file'),
                            'url' => $can['view_answer_files']
                                ? route('fleet-assets.checklists.evidence', ['run' => $run->id, 'question' => $question['id']], false)
                                : null,
                        ],
                    ];
                }, $questions);
                /** @var Collection<int, AssetDocument> $kept */
                $kept = ($files->get($run->id) ?? collect())->reject(fn (AssetDocument $file): bool => in_array($file->state, self::NOT_KEPT, true));
                $version = $run->template_version_id ? $versions->get((int) $run->template_version_id) : null;
                $link = $links[(int) $run->id] ?? null;

                return [
                    'id' => (int) $run->id,
                    'reference' => 'CHK-'.$run->id,
                    'template' => (string) ($presented['name'] ?? $run->template?->name ?? 'Vehicle check'),
                    'template_id' => (int) $run->template_id,
                    'version' => $version ? (int) $version->version : null,
                    'outcome' => $run->outcome ?? 'needs_assessment',
                    'check_kind' => $run->check_kind ?? 'check',
                    'rule_applied' => $run->rule_version_id !== null,
                    'observed_at' => $this->iso($run->observed_at),
                    'submitted_at' => $this->iso($run->submitted_at),
                    'recorded_by' => $run->user?->name,
                    'notes' => $run->notes,
                    'answers' => $answers,
                    'files' => $kept->values()->map(fn (AssetDocument $file): array => [
                        'id' => (int) $file->id,
                        'name' => $file->original_name ?: $file->title,
                        'state' => $file->state,
                        'url' => $can['view_files'] && $file->isOpenable()
                            ? route('fleet-assets.vehicles.documents.file', ['asset' => $asset->id, 'document' => $file->id], false)
                            : null,
                    ])->all(),
                    'evidence_count' => count(array_filter($answers, fn (array $answer): bool => $answer['evidence'] !== null)) + $kept->count(),
                    'amendments' => ($amendments->get($run->id) ?? collect())->map(fn (FleetChecklistRunAmendment $amendment): array => [
                        'id' => (int) $amendment->id,
                        'note' => $amendment->note,
                        'recorded_by' => $amendment->recordedBy?->name,
                        'recorded_at' => $this->iso($amendment->recorded_at),
                    ])->values()->all(),
                    'linked' => $link !== null,
                    // Work details follow Maintenance read access.
                    'linked_work' => $link !== null && $can['view_maintenance'] ? $link : null,
                ];
            })->values()->all(),
            'total' => $total,
        ];
    }

    /**
     * Maintenance work each check leads to: the work its first report opened
     * (or joined), otherwise the work order the check was recorded on.
     *
     * @param  Collection<int, FleetChecklistRun>  $runs
     * @return array<int, array{id:int, reference:?string, title:?string, status:string}>
     */
    private function links(Collection $runs): array
    {
        $links = [];
        DB::table('fleet_maintenance_reports as report')
            ->join('fleet_work_orders as work', 'work.id', '=', 'report.work_order_id')
            ->where('report.source_type', 'fleet_checklist_run')->whereIn('report.source_id', $runs->pluck('id'))
            ->whereNull('report.duplicate_of_report_id')->orderBy('report.id')
            ->get(['report.source_id', 'work.id', 'work.reference_number', 'work.title', 'work.status'])
            ->each(function (object $row) use (&$links): void {
                $links[(int) $row->source_id] ??= [
                    'id' => (int) $row->id, 'reference' => $row->reference_number, 'title' => $row->title, 'status' => (string) $row->status,
                ];
            });
        $recordedOn = $runs->filter(fn (FleetChecklistRun $run): bool => $run->work_order_id !== null && ! isset($links[(int) $run->id]));
        if ($recordedOn->isNotEmpty()) {
            $orders = DB::table('fleet_work_orders')->whereIn('id', $recordedOn->pluck('work_order_id')->unique())
                ->get(['id', 'reference_number', 'title', 'status'])->keyBy('id');
            foreach ($recordedOn as $run) {
                $order = $orders->get((int) $run->work_order_id);
                if ($order !== null) {
                    $links[(int) $run->id] = [
                        'id' => (int) $order->id, 'reference' => $order->reference_number, 'title' => $order->title, 'status' => (string) $order->status,
                    ];
                }
            }
        }

        return $links;
    }

    /**
     * Who assesses a report at this vehicle's site. Reporting is refused until
     * the site has an approved Coordinator and a different backup.
     *
     * @return array{approved: bool, coordinator: ?string, backup: ?string, site: ?string}
     */
    private function route(Asset $asset): array
    {
        $asset->loadMissing('site:id,name');
        $route = $asset->site_id ? DB::table('fleet_maintenance_site_routes')->where('site_id', $asset->site_id)->first() : null;
        if ($route === null) {
            return ['approved' => false, 'coordinator' => null, 'backup' => null, 'site' => $asset->site?->name];
        }
        $names = User::query()->whereIn('id', [(int) $route->coordinator_user_id, (int) $route->backup_user_id])->pluck('name', 'id');

        return [
            'approved' => $route->approved_at !== null && (int) $route->coordinator_user_id !== (int) $route->backup_user_id,
            'coordinator' => $names[(int) $route->coordinator_user_id] ?? null,
            'backup' => $names[(int) $route->backup_user_id] ?? null,
            'site' => $asset->site?->name,
        ];
    }

    private function iso(mixed $value): ?string
    {
        return $value instanceof CarbonInterface ? $value->copy()->utc()->toIso8601String() : null;
    }
}
