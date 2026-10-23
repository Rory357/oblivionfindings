<?php

namespace App\Http\Controllers\HealthSafety;

use App\Http\Controllers\Concerns\ServesPrivateAttachments;
use App\Http\Controllers\Controller;
use App\Http\Requests\HealthSafety\StoreSafeWorkProcedureRequest;
use App\Http\Requests\HealthSafety\UpdateSafeWorkProcedureRequest;
use App\Models\HsAttachment;
use App\Models\SafeWorkProcedure;
use App\Models\SafeWorkProcedureVersion;
use App\Models\User;
use App\Services\HealthSafety\SafeWorkProcedureAccessService;
use App\Services\HealthSafety\SafeWorkProcedureService;
use App\Support\HealthSafety\SafeWorkProcedurePresenter;
use App\Support\HealthSafety\SafeWorkProcedureReturn;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SafeWorkProcedureController extends Controller
{
    use ServesPrivateAttachments;

    private const TABS = ['all', 'draft', 'under_review', 'approved', 'review_due', 'archived'];

    private const STATES = ['draft', 'under_review', 'approved', 'archived'];

    public function __construct(private readonly SafeWorkProcedureAccessService $access, private readonly SafeWorkProcedureService $service) {}

    public function index(Request $request): Response|RedirectResponse
    {
        if ($request->query->has('procedure')) {
            $id = $request->query('procedure');
            abort_unless(is_string($id) && preg_match('/^[1-9][0-9]*$/D', $id), 404);
            $record = $this->access->recordForViewer(SafeWorkProcedure::findOrFail((int) $id), $request->user());

            return redirect(SafeWorkProcedureReturn::href($record->id, SafeWorkProcedureReturn::fromRegister($request->query()), null, null, $request->boolean('edit')));
        }
        $filters = $this->filters($request);
        [$rows,$current] = $this->rows($request, $filters);
        $facetRows = $this->filterRows($rows, $filters, false);
        $matches = $this->filterRows($facetRows, $filters, true)->values();
        $page = max(1, (int) $request->query('page', 1));
        $paginator = new LengthAwarePaginator($matches->forPage($page, 25)->map(fn ($row) => array_diff_key($row, ['audience_sites' => true]))->values(), $matches->count(), 25, $page, ['path' => $request->url(), 'query' => $request->query()]);
        $counts = collect(self::TABS)->mapWithKeys(fn ($tab) => [$tab => $facetRows->filter(fn ($row) => $this->inTab($row, $tab))->count()])->all();
        $today = now(config('app.worker_timezone', 'Pacific/Auckland'))->toDateString();
        $soon = now(config('app.worker_timezone', 'Pacific/Auckland'))->addDays(30)->toDateString();
        $overdue = $facetRows->filter(fn ($row) => $row['published_version_id'] && $row['review_date'] && $row['review_date'] < $today)->count();
        $dueSoon = $facetRows->filter(fn ($row) => $row['published_version_id'] && $row['review_date'] && $row['review_date'] >= $today && $row['review_date'] <= $soon)->count();
        $categories = $facetRows->filter(fn ($row) => $row['published_version_id'])->pluck('category')->unique()->all();
        $gaps = count(array_diff(SafeWorkProcedureService::CATEGORIES, $categories));

        return Inertia::render('health-safety/procedures/index', [
            'procedures' => $paginator, 'filters' => $filters, 'tab' => $filters['tab'], 'tabCounts' => $counts, 'detail' => null,
            'hero' => ['library' => ['approved' => $counts['approved'], 'under_review' => $counts['under_review'], 'draft' => $counts['draft'], 'archived' => $counts['archived']], 'attention' => ['review_due_soon' => $dueSoon, 'review_overdue' => $overdue, 'review_unknown' => $facetRows->filter(fn ($row) => $row['published_version_id'] && $row['review_date'] === null)->count(), 'unapproved' => $facetRows->filter(fn ($row) => ! $row['published_version_id'] && $row['status'] !== 'archived')->count(), 'coverage_gaps' => $gaps], 'nz' => ['worksafe_approved' => null, 'nga_paerewa_documented' => null, 'review_overdue' => $overdue, 'coverage_gaps' => $gaps, 'high_risk_covered' => count(array_intersect(['manual_handling', 'challenging_behaviour', 'lone_working', 'medication'], $categories)) === 4]],
            'can' => ['view' => true, 'create' => $current->canDo('procedures.create') || $current->canDo('procedures.manage'), 'manage' => $current->canDo('procedures.manage'), 'approve' => $current->canDo('procedures.approve')],
            ...$this->access->pickerOptions($current), 'categories' => collect(SafeWorkProcedureService::CATEGORIES)->map(fn ($key) => ['value' => $key, 'label' => ucwords(str_replace('_', ' ', $key))])->all(),
        ]);
    }

    public function show(Request $request, SafeWorkProcedure $procedure, SafeWorkProcedurePresenter $presenter): Response|JsonResponse
    {
        $returnTo = SafeWorkProcedureReturn::sanitize($request->query('return_to')) ?? SafeWorkProcedureReturn::REGISTER;
        $id = $request->query('version');
        abort_unless($id === null || (is_string($id) && preg_match('/^[1-9][0-9]*$/D', $id)), 404);
        $detail = $presenter->detail($procedure, $request->user(), $returnTo, $id === null ? null : (int) $id, $request->query('revision') === 'working');
        if ($request->wantsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['detail' => $detail]);
        }

        return Inertia::render('health-safety/procedures/show', ['detail' => $detail, 'pickers' => ($detail['can']['edit'] ?? false) ? $this->access->pickerOptions($request->user(), $procedure) : ['sites' => [], 'roles' => [], 'owners' => [], 'trainingCourses' => [], 'categories' => [], 'can_apply_organisation_wide' => false], 'return_to' => $returnTo]);
    }

    public function create(): RedirectResponse
    {
        return redirect()->route('health-safety.procedures.index', ['new' => 1]);
    }

    public function edit(Request $request, SafeWorkProcedure $procedure): RedirectResponse
    {
        abort_unless($this->access->canManage($procedure, $request->user()), 403);

        return redirect(SafeWorkProcedureReturn::href($procedure->id, SafeWorkProcedureReturn::fromRegister($request->query()), null, null, true));
    }

    public function store(StoreSafeWorkProcedureRequest $request): RedirectResponse
    {
        $record = $this->service->create($request->user(), $request->validated());

        return back()->with(['success' => 'Procedure draft created.', 'created_procedure_id' => $record->id]);
    }

    public function update(UpdateSafeWorkProcedureRequest $request, SafeWorkProcedure $procedure): RedirectResponse
    {
        $this->service->update($procedure, $request->user(), $request->validated());

        return back()->with('success', 'Working revision saved. The published copy is preserved.');
    }

    public function submitForReview(Request $request, SafeWorkProcedure $procedure): RedirectResponse
    {
        return $this->transition($request, $procedure, 'submit-for-review');
    }

    public function approve(Request $request, SafeWorkProcedure $procedure): RedirectResponse
    {
        return $this->transition($request, $procedure, 'approve');
    }

    public function requestChanges(Request $request, SafeWorkProcedure $procedure): RedirectResponse
    {
        return $this->transition($request, $procedure, 'request-changes');
    }

    public function recordReview(Request $request, SafeWorkProcedure $procedure): RedirectResponse
    {
        return $this->transition($request, $procedure, 'record-review');
    }

    public function archive(Request $request, SafeWorkProcedure $procedure): RedirectResponse
    {
        return $this->transition($request, $procedure, 'archive');
    }

    public function restore(Request $request, SafeWorkProcedure $procedure): RedirectResponse
    {
        return $this->transition($request, $procedure, 'restore');
    }

    private function transition(Request $request, SafeWorkProcedure $procedure, string $command): RedirectResponse
    {
        $this->service->lifecycle($procedure, $request->user(), $command, $request->all());

        return back()->with('success', 'Procedure change recorded.');
    }

    public function acknowledge(Request $request, SafeWorkProcedure $procedure): RedirectResponse
    {
        $this->service->acknowledge($procedure, $request->user(), $request->all());

        return back()->with('success', 'Reading receipt recorded for this exact copy.');
    }

    public function uploadAttachment(Request $request, SafeWorkProcedure $procedure): RedirectResponse
    {
        $data = $request->validate(['file' => ['required', 'file', 'max:20480', 'mimes:pdf,doc,docx,xls,xlsx,png,jpg,jpeg,webp,txt'], 'description' => ['nullable', 'string', 'max:1000'], 'expected_version' => ['required', 'integer', 'min:1'], 'expected_lock_version' => ['required', 'integer', 'min:1']]);
        $this->service->addEvidence($procedure, $request->user(), $data, $request->file('file'));

        return back()->with('success', 'Document added to a new working revision.');
    }

    public function destroyAttachment(Request $request, SafeWorkProcedure $procedure, HsAttachment $attachment): RedirectResponse
    {
        $this->service->removeEvidence($procedure, $request->user(), $request->all(), $attachment);

        return back()->with('success', 'Document removed from the working revision; prior copies are preserved.');
    }

    public function downloadAttachment(Request $request, SafeWorkProcedure $procedure, HsAttachment $attachment): StreamedResponse
    {
        $record = $this->access->recordForViewer($procedure, $request->user());
        abort_unless($attachment->attachable_type === $record->getMorphClass() && (int) $attachment->attachable_id === (int) $record->id, 404);
        $id = $request->query('version');
        abort_unless($id === null || (is_string($id) && preg_match('/^[1-9][0-9]*$/D', $id)), 404);
        $version = SafeWorkProcedureVersion::query()->where('safe_work_procedure_id', $record->id)->findOrFail($id ?? $record->published_version_id);
        abort_unless($this->access->canViewVersion($record, $version, $request->user()), 403);
        abort_unless(in_array((int) $attachment->id, array_map('intval', $version->document_manifest ?? []), true), 404);

        return $this->streamPrivateAttachment($attachment->disk ?? 'private', $attachment->path, $attachment->original_name, $attachment->mime_type);
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = $this->filters($request);
        [$rows] = $this->rows($request, $filters);
        $rows = $this->filterRows($rows, $filters, true);

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            $this->putCsv($out, ['Reference', 'Title', 'Category', 'Status', 'Version', 'Owner', 'Approved by', 'Next review', 'Sites', 'Roles']);
            foreach ($rows as $row) {
                $this->putCsv($out, [$row['reference_number'], $row['title'], $row['category'], $row['status'], 'v'.$row['version'], $row['owner']['name'] ?? '', $row['approved_by']['name'] ?? '', $row['review_date'] ?? '', $row['sites_count'], $row['roles_count']]);
            } fclose($out);
        }, 'safe-work-procedures-'.now(config('app.worker_timezone', 'Pacific/Auckland'))->toDateString().'.csv', ['Content-Type' => 'text/csv']);
    }

    private function rows(Request $request, array $filters): array
    {
        return $this->access->withCurrentViewer($request->user(), function ($current, $reads, $sites) use ($filters) {
            abort_unless($current->canDo('procedures.view'), 403);
            if ($filters['site_id'] !== null) {
                abort_unless(in_array($filters['site_id'], $sites, true), 403);
            }
            $records = $reads->query(SafeWorkProcedure::query())->with(['publishedVersion.approvedBy', 'versions', 'owner', 'approvedBy'])->orderBy('title')->get();
            $rows = $records->map(function ($record) use ($current, $sites) {
                $published = $record->status !== 'archived' && $record->publishedVersion && (int) $record->publishedVersion->safe_work_procedure_id === (int) $record->id && $this->access->isApprovedVersion($record->publishedVersion) && $this->access->audienceAllows($record->publishedSnapshot(), $current, $sites);
                $working = $this->access->workingAllowed($record, $current, $sites) && $this->access->allSitesAllowed($record->applicable_sites ?? [], $sites, $current);
                if (! $published && ! $working) {
                    return null;
                }
                $snapshot = $published ? $record->publishedSnapshot() : $record->only(SafeWorkProcedureService::CONTENT_FIELDS);
                $version = $published ? $record->publishedVersion : $record->versions->firstWhere('version_number', $record->current_version);
                $owner = isset($snapshot['owner_id']) ? User::find($snapshot['owner_id']) : null;

                return ['id' => $record->id, 'title' => $snapshot['title'] ?? '', 'reference_number' => $snapshot['reference_number'] ?? '', 'purpose' => $snapshot['purpose'] ?? null, 'category' => $snapshot['category'] ?? '', 'status' => $published ? 'approved' : $record->status, 'working_status' => $working ? $record->status : null, 'version' => $version?->version_number ?? $record->current_version, 'published_version_id' => $published ? $version->id : null, 'published_version' => $published ? $version->version_number : null, 'provenance' => $version?->provenance, 'review_date' => $published ? $record->published_review_date?->toDateString() : $record->review_date?->toDateString(), 'owner' => $owner ? ['id' => $owner->id, 'name' => $owner->name] : null, 'approved_by' => $published && $version->approvedBy ? ['id' => $version->approvedBy->id, 'name' => $version->approvedBy->name] : null, 'sites_count' => count(array_intersect(array_map('intval', $snapshot['applicable_sites'] ?? []), $sites)), 'roles_count' => count($snapshot['applicable_roles'] ?? []), 'documents_count' => count($version?->document_manifest ?? []), 'audience_sites' => $snapshot['applicable_sites'] ?? [], 'href' => SafeWorkProcedureReturn::href($record->id)];
            })->filter()->values();

            return [$rows, $current];
        });
    }

    private function filterRows(Collection $rows, array $filters, bool $withTab): Collection
    {
        return $rows->filter(function ($row) use ($filters, $withTab) {
            if ($withTab && ! $this->inTab($row, $filters['tab'])) {
                return false;
            }
            if ($filters['category'] && $row['category'] !== $filters['category']) {
                return false;
            }
            if ($filters['status'] && $row['status'] !== $filters['status']) {
                return false;
            }
            if ($filters['site_id'] && $row['audience_sites'] !== [] && ! in_array($filters['site_id'], array_map('intval', $row['audience_sites']), true)) {
                return false;
            }
            if ($filters['q'] && ! str_contains(mb_strtolower($row['title'].' '.$row['reference_number']), mb_strtolower($filters['q']))) {
                return false;
            }
            $today = now(config('app.worker_timezone', 'Pacific/Auckland'))->toDateString();
            $soon = now(config('app.worker_timezone', 'Pacific/Auckland'))->addDays(30)->toDateString();
            $date = $row['review_date'];

            return match ($filters['review_state']) {
                'overdue' => $row['published_version_id'] && $date !== null && $date < $today,'due_soon' => $row['published_version_id'] && $date !== null && $date >= $today && $date <= $soon,'current' => $row['published_version_id'] && $date !== null && $date > $soon,'none','unknown' => $row['published_version_id'] && $date === null,'today' => $row['published_version_id'] && $date === $today,default => true
            };
        });
    }

    private function inTab(array $row, string $tab): bool
    {
        return match ($tab) {
            'all' => true,'approved' => $row['published_version_id'] !== null,'review_due' => $row['published_version_id'] && $row['review_date'] && $row['review_date'] <= now(config('app.worker_timezone', 'Pacific/Auckland'))->addDays(30)->toDateString(),default => $row['working_status'] === $tab
        };
    }

    private function filters(Request $request): array
    {
        $q = $request->query('q');
        $site = $request->query('site_id');

        return ['tab' => in_array($request->query('tab'), self::TABS, true) ? $request->query('tab') : 'all', 'q' => is_string($q) && trim($q) !== '' ? mb_substr(trim($q), 0, 200) : null, 'category' => in_array($request->query('category'), SafeWorkProcedureService::CATEGORIES, true) ? $request->query('category') : null, 'status' => in_array($request->query('status'), self::STATES, true) ? $request->query('status') : null, 'site_id' => is_scalar($site) && preg_match('/^[1-9][0-9]*$/D', (string) $site) ? (int) $site : null, 'review_state' => in_array($request->query('review_state'), ['overdue', 'today', 'due_soon', 'current', 'none', 'unknown'], true) ? $request->query('review_state') : null];
    }
}
