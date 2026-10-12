<?php

namespace App\Http\Controllers\Operations;

use App\Domain\Hr\Models\HrComplianceRequirement;
use App\Domain\Hr\Services\HrEligibilityRuleSettings;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\StaffQualificationRequirement;
use App\Models\User;
use App\Models\WorkforceEligibilityRecheck;
use App\Services\AuditLogger;
use App\Services\AuthorizationEvidenceLockService;
use App\Services\CurrentAuthorizationReads;
use App\Services\Eligibility\WorkforceEligibilitySources;
use App\Services\Eligibility\WorkforceQualificationEvidence;
use App\Services\Eligibility\WorkforceRequirementMapping;
use App\Services\Operations\WorkforceMutationGuard;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class QualificationMatchController extends Controller
{
    public function __construct(private readonly UserSiteAccessService $siteAccess) {}

    public function index(Request $request)
    {
        $auth = $request->user();
        abort_unless($auth && $this->canAccessQualifications($auth), 403);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'mandatory' => ['nullable', 'in:mandatory,optional'],
            'client_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $search = trim((string) ($filters['q'] ?? ''));

        $requirements = $this->visibleRequirementsQuery($auth)
            ->with(['client:id,first_name,last_name', 'serviceContext:id,name,site_id', 'hrComplianceRequirement:id,code,name,check_type,is_active'])
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($nested) use ($search) {
                    $nested->where('qualification_name', 'like', '%'.$search.'%')
                        ->orWhereHas('client', fn ($clientQuery) => $clientQuery
                            ->where('first_name', 'like', '%'.$search.'%')
                            ->orWhere('last_name', 'like', '%'.$search.'%'));
                });
            })
            ->when(($filters['mandatory'] ?? null) === 'mandatory', fn ($q) => $q->where('is_mandatory', true))
            ->when(($filters['mandatory'] ?? null) === 'optional', fn ($q) => $q->where('is_mandatory', false))
            ->when($filters['client_id'] ?? null, fn ($q, $clientId) => $q->where('client_id', $clientId))
            ->orderBy('client_id')
            ->paginate(20)
            // The former match_status / matched_workers / total_workers fields
            // were hardcoded placeholders ('unmet', 0, 0) with no backend —
            // dropped rather than shown (DESIGN.md no-fake-data rule).
            ->through(fn (StaffQualificationRequirement $requirement) => [
                'id' => $requirement->id,
                'qualification_name' => $requirement->qualification_name,
                'qualification_type' => $requirement->qualification_type,
                'service_context_id' => $requirement->service_context_id,
                'service_context' => $requirement->serviceContext ? [
                    'id' => (int) $requirement->serviceContext->id, 'name' => $requirement->serviceContext->name,
                    'site_id' => $requirement->serviceContext->site_id === null ? null : (int) $requirement->serviceContext->site_id,
                ] : null,
                'description' => $requirement->description,
                'mapping' => app(WorkforceRequirementMapping::class)->present($requirement),
                'is_mandatory' => (bool) $requirement->is_mandatory,
                'client' => $requirement->client ? [
                    'id' => $requirement->client->id,
                    'first_name' => $requirement->client->first_name,
                    'last_name' => $requirement->client->last_name,
                ] : null,
            ])
            ->withQueryString();

        $canEdit = $auth->isApproved() && ($auth->canDo('qualifications.create') || $auth->canDo('qualifications.edit'));
        $siteIds = $canEdit ? $this->siteAccess->accessibleSiteIds($auth, ['shifts.manageAny']) : [];

        return inertia('operations/qualifications/Index', [
            'requirements' => $requirements,
            'complianceRequirements' => app(WorkforceRequirementMapping::class)->catalog(),
            'editableClients' => $siteIds === [] ? [] : $this->siteAccess->applyClientScope(Client::query(), $auth, ['shifts.manageAny'])
                ->select(['id', 'first_name', 'last_name', 'site_id'])->orderBy('first_name')->orderBy('id')->get(),
            'serviceContexts' => $siteIds === [] ? [] : ServiceContext::query()
                ->where(fn ($query) => $query->whereNull('site_id')->orWhereIn('site_id', $siteIds))
                ->select(['id', 'name', 'site_id', 'is_active'])->orderBy('name')->orderBy('id')->get(),
            'can' => ['create' => $auth->isApproved() && $auth->canDo('qualifications.create'),
                'edit' => $auth->isApproved() && $auth->canDo('qualifications.edit'),
                'delete' => $auth->isApproved() && $auth->canDo('qualifications.delete')],
            'filters' => [
                'q' => $filters['q'] ?? null,
                'mandatory' => $filters['mandatory'] ?? null,
                'client_id' => $filters['client_id'] ?? null,
            ],
            // Header instruments — counted over the whole visible set
            // regardless of the active filters.
            'stats' => [
                'total' => $this->visibleRequirementsQuery($auth)->count(),
                'mandatory' => $this->visibleRequirementsQuery($auth)->where('is_mandatory', true)->count(),
                'clients' => $this->visibleRequirementsQuery($auth)->distinct()->count('client_id'),
            ],
            'clients' => $this->visibleRequirementsQuery($auth)
                ->join('clients', 'clients.id', '=', 'staff_qualification_requirements.client_id')
                ->select('clients.id', 'clients.first_name', 'clients.last_name')
                ->distinct()
                ->orderBy('clients.first_name')
                ->get(),
        ]);
    }

    public function store(Request $request)
    {
        $rootEntry = $this->beginQualificationReceipt($request);
        $actor = $request->user();
        abort_unless($actor?->isApproved() && $actor->canDo('qualifications.create'), 403);
        $data = $request->validate(['client_id' => ['required', 'integer', 'exists:clients,id'],
            'qualification_name' => ['required', 'string', 'max:255'],
            'qualification_type' => ['nullable', 'string', 'max:100'], 'is_mandatory' => ['nullable', 'boolean'],
            'service_context_id' => ['nullable', 'integer', 'exists:service_contexts,id'],
            'hr_compliance_requirement_id' => app(WorkforceRequirementMapping::class)->validationRules(),
            'notes' => ['nullable', 'string'], 'description' => ['nullable', 'string']]);
        $result = $this->mutate($request, 'qualifications.create', null, $data);

        $response = redirect()->back()->with('success', 'Qualification requirement added.');
        if ($this->qualificationReceiptIsRoot($rootEntry)) {
            $response->with('qualification_requirement_result', $result);
        }

        return $response;
    }

    public function update(Request $request, $requirement)
    {
        $rootEntry = $this->beginQualificationReceipt($request);
        $actor = $request->user();
        abort_unless($actor?->isApproved() && $actor->canDo('qualifications.edit'), 403);
        $data = $request->validate(['qualification_name' => ['sometimes', 'required', 'string', 'max:255'],
            'qualification_type' => ['nullable', 'string', 'max:100'], 'is_mandatory' => ['nullable', 'boolean'],
            'service_context_id' => ['nullable', 'integer', 'exists:service_contexts,id'],
            'hr_compliance_requirement_id' => app(WorkforceRequirementMapping::class)->validationRules(),
            'notes' => ['nullable', 'string'], 'description' => ['nullable', 'string']]);
        $result = $this->mutate($request, 'qualifications.edit', (int) $requirement, $data);

        $response = redirect()->back()->with('success', 'Qualification requirement updated.');
        if ($this->qualificationReceiptIsRoot($rootEntry)) {
            $response->with('qualification_requirement_result', $result);
        }

        return $response;
    }

    public function destroy(Request $request, $requirement)
    {
        $rootEntry = $this->beginQualificationReceipt($request);
        $actor = $request->user();
        abort_unless($actor?->isApproved() && $actor->canDo('qualifications.delete'), 403);
        $result = $this->mutate($request, 'qualifications.delete', (int) $requirement, []);

        $response = redirect()->back()->with('success', 'Qualification requirement removed.');
        if ($this->qualificationReceiptIsRoot($rootEntry)) {
            $response->with('qualification_requirement_result', $result);
        }

        return $response;
    }

    private function mutate(Request $request, string $permission, ?int $id, array $data): array
    {
        try {
            return DB::transaction(function () use ($request, $permission, $id, $data): array {
                app(WorkforceMutationGuard::class)->lock();
                $hint = $id ? StaffQualificationRequirement::query()->whereKey($id)->lock('for share nowait')->firstOrFail() : null;
                $clientId = $hint?->client_id ?? ($data['client_id'] ?? null);
                $contextIds = collect([$hint?->service_context_id, $data['service_context_id'] ?? null])->filter()->unique()->sort()->values();
                $contexts = CurrentAuthorizationReads::within(fn ($reads) => $reads->query(ServiceContext::query()->whereIn('id', $contextIds)->orderBy('id'))->get()->keyBy('id'));
                $client = CurrentAuthorizationReads::within(fn ($reads) => $reads->query(Client::query()->whereKey($clientId))->first());
                $row = $id ? StaffQualificationRequirement::query()->whereKey($id)->lockForUpdate()->firstOrFail() : new StaffQualificationRequirement;
                abort_unless(! $hint || ((int) $row->client_id === (int) $hint->client_id && $row->service_context_id === $hint->service_context_id), 409);
                $actor = app(AuthorizationEvidenceLockService::class)->lockForUserWithoutWaiting($request->user(),
                    ['qualifications.create', 'qualifications.edit', 'qualifications.delete', 'rostering.viewAny', 'shifts.manageAny']);
                abort_unless($actor->isApproved() && $actor->canDo($permission) && $actor->canDo('rostering.viewAny'), 403);
                $request->setUserResolver(fn () => $actor);
                $siteIds = CurrentAuthorizationReads::within(fn ($reads) => $this->siteAccess->accessibleSiteIds($actor, ['shifts.manageAny'], $reads));
                $oldAllowed = $client && in_array((int) $client->site_id, $siteIds, true)
                    && (! $hint?->service_context_id || ($contexts->has($hint->service_context_id)
                        && (! $contexts->get($hint->service_context_id)->site_id || (int) $contexts->get($hint->service_context_id)->site_id === (int) $client->site_id)));
                abort_unless($oldAllowed, $id ? 404 : 403);
                if (array_key_exists('service_context_id', $data) && $data['service_context_id'] !== null) {
                    $context = $contexts->get((int) $data['service_context_id']);
                    abort_unless($context && (! $context->site_id || (int) $context->site_id === (int) $client->site_id), 422);
                }
                if (isset($data['hr_compliance_requirement_id'])) {
                    $mapped = CurrentAuthorizationReads::within(fn ($reads) => $reads->query(HrComplianceRequirement::query()->whereKey((int) $data['hr_compliance_requirement_id']))->first());
                    if (! $mapped || ! $mapped->is_active || ! in_array($mapped->check_type, WorkforceRequirementMapping::CHECK_TYPES, true)) {
                        throw ValidationException::withMessages(['hr_compliance_requirement_id' => 'Choose an active recorded HR requirement.']);
                    }
                }
                $action = $permission === 'qualifications.delete' ? 'deleted' : ($id ? 'updated' : 'created');
                if ($action === 'deleted') {
                    abort_unless($row->delete() === true && (int) $row->id === $id
                        && ! StaffQualificationRequirement::query()->whereKey($id)->lockForUpdate()->exists(), 409);
                    $stored = $row;
                } else {
                    $values = $id ? array_filter(['qualification_name' => $data['qualification_name'] ?? null,
                        'qualification_type' => $data['qualification_type'] ?? null, 'is_mandatory' => $data['is_mandatory'] ?? null,
                        'description' => $data['description'] ?? $data['notes'] ?? null], fn ($value) => $value !== null)
                        : ['client_id' => (int) $client->id, 'qualification_name' => $data['qualification_name'],
                            'qualification_type' => $data['qualification_type'] ?? 'certification', 'is_mandatory' => $data['is_mandatory'] ?? true,
                            'description' => $data['description'] ?? $data['notes'] ?? null, 'service_context_id' => null, 'hr_compliance_requirement_id' => null];
                    if ($id && (array_key_exists('description', $data) || array_key_exists('notes', $data))) {
                        $values['description'] = array_key_exists('description', $data) ? $data['description'] : $data['notes'];
                    }
                    foreach (['service_context_id', 'hr_compliance_requirement_id'] as $field) {
                        if (array_key_exists($field, $data)) {
                            $values[$field] = $data[$field] === null ? null : (int) $data[$field];
                        }
                    }
                    $row->fill($values);
                    abort_unless($row->save() === true && (! $id || (int) $row->id === $id) && $row->id > 0, 409);
                    $stored = StaffQualificationRequirement::query()->whereKey($row->id)->lockForUpdate()->firstOrFail();
                    foreach ($values as $field => $value) {
                        abort_unless($stored->getAttribute($field) === ($field === 'is_mandatory' ? (bool) $value : $value), 409);
                    }
                }
                if ($action === 'deleted' || $action === 'created' || $row->wasChanged(['client_id', 'service_context_id', 'hr_compliance_requirement_id', 'is_mandatory'])) {
                    $description = app(WorkforceEligibilitySources::class)->describe($stored, $action === 'deleted');
                    $intent = WorkforceEligibilityRecheck::query()->where('source_type', $stored->getTable())
                        ->where('source_id', $stored->id)->lockForUpdate()->first();
                    abort_unless($description && $intent && $intent->source_version > 0
                        && $intent->source_fingerprint === $description['fingerprint']
                        && in_array((int) $client->id, $intent->client_ids ?? [], true), 409);
                }
                $priorAuditId = AuditLog::query()->where('action', 'workforce.qualification.'.$action)
                    ->where('auditable_type', $stored->getMorphClass())->where('auditable_id', $stored->id)
                    ->orderByDesc('id')->lockForUpdate()->first()?->id ?? 0;
                AuditLogger::logOrFail('workforce.qualification.'.$action, $stored, ['actor_id' => (int) $actor->id,
                    'client_id' => (int) $client->id, 'hr_compliance_requirement_id' => $stored->hr_compliance_requirement_id], $request);
                $audit = AuditLog::query()->where('id', '>', $priorAuditId)->where('action', 'workforce.qualification.'.$action)->where('auditable_type', $stored->getMorphClass())
                    ->where('auditable_id', $stored->id)->where('user_id', $actor->id)->orderByDesc('id')->lockForUpdate()->first();
                abort_unless($audit && (int) $audit->client_id === (int) $client->id, 409);

                return ['action' => $action, 'actor_id' => (int) $actor->id, 'requirement_id' => (int) $stored->id,
                    'values' => $action === 'deleted' ? null : [
                        'client_id' => (int) $stored->client_id, 'qualification_name' => $stored->qualification_name,
                        'qualification_type' => $stored->qualification_type, 'is_mandatory' => (bool) $stored->is_mandatory,
                        'description' => $stored->description,
                        'service_context_id' => $stored->service_context_id === null ? null : (int) $stored->service_context_id,
                        'hr_compliance_requirement_id' => $stored->hr_compliance_requirement_id === null ? null : (int) $stored->hr_compliance_requirement_id,
                    ]];
            });
        } catch (QueryException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) !== 3572) {
                throw $exception;
            }
            throw ValidationException::withMessages(['qualification_name' => 'The current qualification evidence is busy. Reload and try again.'])->status(503);
        }
    }

    private function beginQualificationReceipt(Request $request): bool
    {
        $request->session()->forget('qualification_requirement_result');

        return $this->qualificationReceiptIsRoot(true);
    }

    private function qualificationReceiptIsRoot(bool $rootEntry): bool
    {
        if (! $rootEntry) {
            return false;
        }
        try {
            return DB::transactionLevel() === 0 && ! DB::connection()->getPdo()->inTransaction();
        } catch (Throwable) {
            return false;
        }
    }

    public function checkShift(Request $request, $shift)
    {
        $auth = $request->user();
        abort_unless($auth && $this->canAccessQualifications($auth), 403);

        $shift = $this->siteAccess->applyShiftScope(Shift::query(), $auth, ['shifts.manageAny'])
            ->with(['staff:id,name', 'client:id,first_name,last_name'])
            ->findOrFail($shift);

        $requirements = $this->visibleRequirementsQuery($auth)
            ->where('client_id', $shift->client_id)
            ->where(fn ($query) => $query->whereNull('service_context_id')->orWhere('service_context_id', $shift->service_context_id))
            ->with('hrComplianceRequirement')->get();

        $mode = app(HrEligibilityRuleSettings::class)->values()['unmapped_mandatory_qualification'];
        $results = [];
        foreach ($requirements as $req) {
            $mapping = app(WorkforceRequirementMapping::class)->present($req);
            $mandatory = (bool) $req->is_mandatory;
            $met = false;
            $status = $shift->staff ? $mapping['status'] : 'unassigned';
            $severity = 'info';
            $reasons = [];
            if ($shift->staff) {
                if ($mapping['status'] === 'configured') {
                    $evidence = app(WorkforceQualificationEvidence::class)->check($shift->staff, collect([$req->hrComplianceRequirement]), $shift);
                    $met = $evidence['passed'];
                    $status = $met ? 'met' : ($evidence['failures'][0]['status'] ?? 'unknown');
                    $reasons = array_column($evidence['failures'], 'reason');
                    $severity = $met ? 'info' : ($mandatory ? 'block' : 'warning');
                } elseif ($mapping['status'] === 'unmapped') {
                    $severity = $mandatory && $mode === 'block' ? 'block' : 'warning';
                    $reasons = ['This qualification is not mapped to recorded HR evidence.'];
                } else {
                    $status = 'unavailable';
                    $severity = $mandatory ? 'block' : 'warning';
                    $reasons = ['The configured qualification evidence is unavailable.'];
                }
            } else {
                $reasons = ['Assign a worker before checking their qualification evidence.'];
            }

            $results[] = [
                'requirement' => [
                    'id' => (int) $req->id,
                    'qualification_name' => $req->qualification_name,
                    'qualification_type' => $req->qualification_type,
                    'description' => $req->description,
                ],
                'mapping' => $mapping,
                'met' => $met,
                'is_mandatory' => $mandatory,
                'status' => $status, 'severity' => $severity, 'reasons' => $reasons,
                'requires_acknowledgement' => $mandatory && $status === 'unmapped' && $severity === 'warning',
            ];
        }

        $allMandatoryMet = collect($results)
            ->where('is_mandatory', true)
            ->every('met', true);

        return inertia('operations/qualifications/CheckShift', [
            'shift' => [
                'id' => (int) $shift->id,
                'starts_at' => $shift->starts_at?->toJSON(),
                'ends_at' => $shift->ends_at?->toJSON(),
                'staff' => $shift->staff ? [
                    'id' => (int) $shift->staff->id,
                    'name' => $shift->staff->name,
                ] : null,
                'client' => $shift->client ? [
                    'id' => (int) $shift->client->id,
                    'first_name' => $shift->client->first_name,
                    'last_name' => $shift->client->last_name,
                ] : null,
            ],
            'results' => $results,
            'allMandatoryMet' => $allMandatoryMet,
            'hasBlocks' => collect($results)->contains('severity', 'block'),
            'hasWarnings' => collect($results)->contains('severity', 'warning'),
            'unmappedMandatoryMode' => $mode,
        ]);
    }

    private function visibleRequirementsQuery(User $viewer): Builder
    {
        $siteIds = $this->siteAccess->accessibleSiteIds($viewer, ['shifts.manageAny']);
        if ($siteIds === []) {
            return StaffQualificationRequirement::query()->whereRaw('1 = 0');
        }

        return StaffQualificationRequirement::query()
            ->whereHas('client', fn (Builder $clientQuery) => $clientQuery->whereIn('site_id', $siteIds))
            ->where(function (Builder $context): void {
                $context->whereNull($context->qualifyColumn('service_context_id'))
                    ->orWhereHas('serviceContext', function (Builder $serviceContext): void {
                        $serviceContext->whereNull('site_id')
                            ->orWhereExists(function ($clients): void {
                                $clients->selectRaw('1')
                                    ->from('clients')
                                    ->whereColumn('clients.id', 'staff_qualification_requirements.client_id')
                                    ->whereColumn('clients.site_id', 'service_contexts.site_id');
                            });
                    });
            });
    }

    private function canAccessQualifications($auth): bool
    {
        return $auth->canDo('qualifications.viewAny')
            || $auth->canDo('qualifications.create')
            || $auth->canDo('qualifications.edit')
            || $auth->canDo('qualifications.delete')
            || $auth->canDo('rostering.viewAny');
    }
}
