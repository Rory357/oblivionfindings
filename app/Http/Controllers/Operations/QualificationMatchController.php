<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Models\Shift;
use App\Models\StaffQualificationRequirement;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

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
            ->with(['client:id,first_name,last_name'])
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
                'is_mandatory' => (bool) $requirement->is_mandatory,
                'client' => $requirement->client ? [
                    'id' => $requirement->client->id,
                    'first_name' => $requirement->client->first_name,
                    'last_name' => $requirement->client->last_name,
                ] : null,
            ])
            ->withQueryString();

        return inertia('operations/qualifications/Index', [
            'requirements' => $requirements,
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
        $auth = $request->user();
        abort_unless($auth && $this->canAccessQualifications($auth), 403);

        $data = $request->validate([
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'qualification_name' => ['required', 'string', 'max:255'],
            'qualification_type' => ['nullable', 'string', 'max:100'],
            'is_mandatory' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string'],
            'description' => ['nullable', 'string'],
        ]);

        $this->siteAccess->assertCanAccessClientId($auth, (int) $data['client_id'], ['shifts.manageAny']);

        StaffQualificationRequirement::create([
            'client_id' => $data['client_id'],
            'qualification_name' => $data['qualification_name'],
            'qualification_type' => $data['qualification_type'] ?? 'certification',
            'is_mandatory' => $data['is_mandatory'] ?? true,
            'description' => $data['description'] ?? $data['notes'] ?? null,
        ]);

        return redirect()->back()->with('success', 'Qualification requirement added.');
    }

    public function update(Request $request, $requirement)
    {
        $auth = $request->user();
        abort_unless($auth && $this->canAccessQualifications($auth), 403);

        $requirement = $this->visibleRequirementsQuery($auth)
            ->findOrFail($requirement);

        $data = $request->validate([
            'qualification_name' => ['sometimes', 'required', 'string', 'max:255'],
            'qualification_type' => ['nullable', 'string', 'max:100'],
            'is_mandatory' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string'],
            'description' => ['nullable', 'string'],
        ]);

        $requirement->update(array_filter([
            'qualification_name' => $data['qualification_name'] ?? null,
            'qualification_type' => $data['qualification_type'] ?? null,
            'is_mandatory' => $data['is_mandatory'] ?? null,
            'description' => $data['description'] ?? $data['notes'] ?? null,
        ], fn ($value) => $value !== null));

        return redirect()->back()->with('success', 'Qualification requirement updated.');
    }

    public function destroy(Request $request, $requirement)
    {
        $auth = $request->user();
        abort_unless($auth && $this->canAccessQualifications($auth), 403);

        $requirement = $this->visibleRequirementsQuery($auth)
            ->findOrFail($requirement);

        $requirement->delete();

        return redirect()->back()->with('success', 'Qualification requirement removed.');
    }

    public function checkShift(Request $request, $shift)
    {
        $auth = $request->user();
        abort_unless($auth && $this->canAccessQualifications($auth), 403);

        $shift = $this->siteAccess->applyShiftScope(Shift::query(), $auth, ['shifts.manageAny'])
            ->with(['staff.staffTrainingRecords', 'staff.staffCredentials', 'client'])
            ->findOrFail($shift);

        $requirements = $this->visibleRequirementsQuery($auth)
            ->where('client_id', $shift->client_id)
            ->get();

        $results = [];
        foreach ($requirements as $req) {
            $met = false;
            if ($shift->staff) {
                $met = $shift->staff->staffCredentials()
                    ->where('type', $req->qualification_name)
                    ->where(function ($q) {
                        $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
                    })
                    ->exists();
            }

            $results[] = [
                'requirement' => $req,
                'met' => $met,
                'is_mandatory' => $req->is_mandatory,
            ];
        }

        $allMandatoryMet = collect($results)
            ->where('is_mandatory', true)
            ->every('met', true);

        return inertia('operations/qualifications/CheckShift', [
            'shift' => $shift,
            'results' => $results,
            'allMandatoryMet' => $allMandatoryMet,
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
                $context->whereNull('service_context_id')
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
