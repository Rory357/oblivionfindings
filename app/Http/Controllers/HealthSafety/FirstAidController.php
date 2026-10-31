<?php

namespace App\Http\Controllers\HealthSafety;

use App\Http\Controllers\Concerns\ServesPrivateAttachments;
use App\Http\Controllers\Controller;
use App\Models\FirstAidAttachment;
use App\Models\FirstAidFollowup;
use App\Models\FirstAidRecord;
use App\Models\Site;
use App\Models\User;
use App\Services\CurrentAuthorizationReads;
use App\Services\HealthSafety\FirstAidAccessService;
use App\Services\HealthSafety\FirstAidService;
use App\Support\HealthSafety\FirstAidPresenter;
use App\Support\HealthSafety\FirstAidReturn;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Factual treatment capture, guarded source commands and independently authorized destinations. */
class FirstAidController extends Controller
{
    use ServesPrivateAttachments;

    public function __construct(private readonly FirstAidAccessService $access, private readonly FirstAidService $service, private readonly FirstAidPresenter $presenter) {}

    public function index(Request $request): Response|RedirectResponse|JsonResponse
    {
        if ($request->has('record')) {
            $id = $this->positive($request->input('record'));
            $record = $this->access->recordForViewer(FirstAidRecord::withTrashed()->findOrFail($id), $request->user(), true);
            if (! $request->expectsJson() || $request->header('X-Inertia')) {
                return redirect(FirstAidReturn::href($record->id, FirstAidReturn::fromRegister($request->except(['record', 'report']))));
            }
        }
        $filters = $this->filters($request);
        $tab = $filters['tab'];
        $origin = FirstAidReturn::fromRegister($request->query());
        $props = $this->access->withCurrentViewer($request->user(), function (User $current, CurrentAuthorizationReads $reads, array $sites) use ($request, $filters, $tab, $origin) {
            abort_unless($current->canDo('hazards.view'), 403);
            $authorized = $this->access->readableQuery($current, $reads, $sites);
            // Filter choices are recorded attribution from readable source rows, not capture eligibility or an HR directory.
            $filterSource = clone $authorized;
            if ($filters['site_id'] !== null) {
                $filterSource->where('site_id', $filters['site_id']);
            }
            $recordedAiderIds = $filterSource->whereNotNull('first_aider_id')->pluck('first_aider_id')->unique()->values()->all();
            $filterOptions = ['first_aiders' => $reads->query(User::query())->whereIn('id', $recordedAiderIds)->orderBy('name')->orderBy('id')->get(['id', 'name'])->map(fn ($person) => ['id' => (int) $person->id, 'name' => $person->name])->all()];
            $base = $this->filtered(clone $authorized, $filters);
            $period = $this->period(clone $base, $filters['period']);
            $records = $this->tab(clone $period, $tab)->orderByDesc('treatment_date')->orderByDesc('id')->paginate(25)->withQueryString();
            $records->setCollection($records->getCollection()->map(fn ($row) => $this->presenter->row($row, $current, $origin)));
            $pickers = $current->canDo('hazards.create') || $current->canDo('hazards.manage') ? $this->presenter->pickers($current, null, $filters['site_id']) : $this->emptyPickers();
            if ($pickers['sites'] === []) {
                $pickers['sites'] = $reads->query(Site::query())->active()->notArchived()->whereNull('archived_at')->whereIn('id', $sites)->orderBy('name')->get(['id', 'name'])->map(fn ($site) => ['id' => (int) $site->id, 'name' => $site->name])->all();
            }
            $live = (clone $base)->where('treatment_date', '>=', now()->subDays(30));
            $counts = [];
            foreach (['all', 'ambulance', 'linked', 'reportable', 'followup'] as $key) {
                $counts[$key] = $this->tab(clone $period, $key)->count();
            }
            $review = $this->tab(clone $base, 'reportable')->count();

            return ['records' => $records, 'tab' => $tab, 'tabCounts' => $counts,
                'hero' => ['live' => ['treated' => (clone $live)->count(), 'ambulance' => (clone $live)->where('ambulance_called', true)->count(), 'hospital' => (clone $live)->where('treatment_outcome', 'sent_to_hospital')->count(), 'linked' => (clone $live)->whereNotNull('related_incident_id')->count()],
                    'attention' => ['reportable_unlinked' => $review, 'followups_open' => (clone $base)->whereHas('followups', fn ($q) => $q->whereNull('completed_at'))->count(), 'no_aider' => (clone $base)->whereNull('first_aider_id')->count(), 'today' => (clone $base)->whereBetween('treatment_date', [now(config('app.worker_timezone', 'Pacific/Auckland'))->startOfDay()->utc(), now(config('app.worker_timezone', 'Pacific/Auckland'))->endOfDay()->utc()])->count()],
                    // Compatibility keys describe outstanding source review, never a regulatory decision or claim receipt.
                    'badges' => ['first_aiders' => $current->canDo('hazards.create') || $current->canDo('hazards.manage') ? count($pickers['first_aiders']) : null, 'worksafe_awaiting' => null, 'acc45_month' => null]],
                'filters' => $filters, 'filterOptions' => $filterOptions, 'pickers' => $pickers, 'sites' => $pickers['sites'], 'firstAiders' => $pickers['first_aiders'], 'clients' => $pickers['clients'], 'staff' => $pickers['staff'], 'incidents' => $pickers['incidents'],
                'can' => ['view' => true, 'create' => $current->canDo('hazards.create') || $current->canDo('hazards.manage'), 'manage' => $current->canDo('hazards.manage')],
                'detail' => null,
                'report' => $request->boolean('report')];
        });
        // A JSON legacy selection is resolved under the same canonical reader.
        if ($request->has('record')) {
            $props['detail'] = $this->presenter->detail($record, $request->user(), $origin);
        }
        $props['first_aid_confirmation'] = $this->confirmation($request);
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json($props);
        }

        return Inertia::render('health-safety/first-aid/index', $props);
    }

    public function report(Request $request): Response
    {
        $props = $this->access->withCurrentViewer($request->user(), function ($current) {
            abort_unless($current->canDo('hazards.create') || $current->canDo('hazards.manage'), 403);

            return ['pickers' => $this->presenter->pickers($current), 'can' => ['create' => true, 'view' => $current->canDo('hazards.view')]];
        });
        $proof = $this->confirmation($request);

        return Inertia::render('health-safety/first-aid/report', [...$props, 'first_aid_confirmation' => $proof, 'record_href' => $proof ? $this->readableHref($proof['id'], $request->user()) : null]);
    }

    public function options(Request $request): JsonResponse
    {
        $values = $request->validate(['site_id' => ['nullable', 'integer', 'min:1'], 'record_id' => ['nullable', 'integer', 'min:1'], 'client_id' => ['nullable', 'integer', 'min:1']]);
        if (isset($values['client_id']) && ! isset($values['record_id']) && ! isset($values['site_id'])) {
            throw ValidationException::withMessages(['site_id' => 'Choose the approved source Site before selecting a Client incident.']);
        }
        $options = $this->access->withCurrentViewer($request->user(), function ($current, $reads, $sites) use ($values) {
            abort_unless($current->canDo('hazards.create') || $current->canDo('hazards.manage'), 403);
            $record = null;
            if (isset($values['record_id'])) {
                abort_unless($current->canDo('hazards.view'), 403);
                $record = $reads->query(FirstAidRecord::query())->findOrFail($values['record_id']);
                $this->access->assertSource($record, $current, $reads, $sites);
            }

            return $this->presenter->pickers($current, $record, isset($values['site_id']) ? (int) $values['site_id'] : null, isset($values['client_id']) ? (int) $values['client_id'] : null);
        });

        return response()->json($options);
    }

    public function show(Request $request, FirstAidRecord $record): Response|JsonResponse
    {
        $origin = FirstAidReturn::sanitize($request->input('return_to'));
        $detail = $this->presenter->detail($record, $request->user(), $origin);
        $followup = $request->has('followup') ? $this->positive($request->input('followup')) : null;
        if ($followup !== null) {
            abort_unless(FirstAidFollowup::where('first_aid_record_id', $record->id)->whereKey($followup)->exists(), 404);
        }
        $section = in_array($request->input('section'), FirstAidReturn::SECTIONS, true) ? $request->input('section') : 'overview';
        $props = ['detail' => $detail, 'pickers' => $detail['status'] !== 'archived' && ($detail['can']['edit'] ?? false) ? $this->presenter->pickers($request->user(), $record) : $this->emptyPickers(), 'return_to' => $origin, 'section' => $section, 'followup' => $followup, 'action' => $this->action($request->input('action')), 'first_aid_confirmation' => $this->confirmation($request)];
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['record' => $detail, 'first_aid_confirmation' => $props['first_aid_confirmation']]);
        }

        return Inertia::render('health-safety/first-aid/show', $props);
    }

    public function store(Request $request): RedirectResponse|JsonResponse
    {
        return $this->confirmed($request, $this->service->create($request->user(), $request->all()));
    }

    public function update(Request $request, FirstAidRecord $record): RedirectResponse|JsonResponse
    {
        return $this->confirmed($request, $this->service->update($record, $request->user(), $request->all()));
    }

    public function destroy(Request $request, FirstAidRecord $record): RedirectResponse|JsonResponse
    {
        return $this->confirmed($request, $this->service->archive($record, $request->user(), $request->all()));
    }

    public function linkIncident(Request $request, FirstAidRecord $record): RedirectResponse|JsonResponse
    {
        return $this->confirmed($request, $this->service->linkIncident($record, $request->user(), $request->all()));
    }

    public function addFollowup(Request $request, FirstAidRecord $record): RedirectResponse|JsonResponse
    {
        return $this->confirmed($request, $this->service->addFollowup($record, $request->user(), $request->all()));
    }

    public function updateFollowup(Request $request, FirstAidRecord $record, FirstAidFollowup $followup): RedirectResponse|JsonResponse
    {
        return $this->confirmed($request, $this->service->updateFollowup($record, $followup, $request->user(), $request->all()));
    }

    public function completeFollowup(Request $request, FirstAidRecord $record, FirstAidFollowup $followup): RedirectResponse|JsonResponse
    {
        return $this->confirmed($request, $this->service->completeFollowup($record, $followup, $request->user(), $request->all()));
    }

    public function uploadAttachment(Request $request, FirstAidRecord $record): RedirectResponse|JsonResponse
    {
        $request->validate(['file' => ['required', 'file']]);

        return $this->confirmed($request, $this->service->addEvidence($record, $request->user(), $request->except('file'), $request->file('file')));
    }

    public function downloadAttachment(Request $request, FirstAidRecord $record, FirstAidAttachment $attachment): StreamedResponse
    {
        return $this->access->withCurrentViewer($request->user(), function ($current, $reads, $sites) use ($record, $attachment) {
            abort_unless($current->canDo('hazards.view'), 403);
            $row = $reads->query(FirstAidRecord::withTrashed())->findOrFail($record->id);
            $this->access->assertSource($row, $current, $reads, $sites);
            $file = $reads->query(FirstAidAttachment::query())->where('first_aid_record_id', $row->id)->findOrFail($attachment->id);
            abort_unless($file->disk === 'private', 404);

            return $this->streamPrivateAttachment($file->disk, $file->path, basename(str_replace(chr(92), '/', $file->original_name)), $file->mime);
        });
    }

    public function destroyAttachment(Request $request, FirstAidRecord $record, FirstAidAttachment $attachment): RedirectResponse|JsonResponse
    {
        return $this->confirmed($request, $this->service->removeEvidence($record, $attachment, $request->user(), $request->all()));
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = $this->filters($request);
        // Materialize current authorized rows before ending the lock callback; no deferred stale query.
        $rows = $this->access->withCurrentViewer($request->user(), function ($current, $reads, $sites) use ($filters) {
            abort_unless($current->canDo('hazards.view'), 403);
            $query = $this->tab($this->period($this->filtered($this->access->readableQuery($current, $reads, $sites), $filters), $filters['period']), $filters['tab']);

            return $query->orderByDesc('treatment_date')->orderByDesc('id')->get()->map(fn ($record) => $this->presenter->detail($record, $current));
        });

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Reference', 'Treated', 'Person type', 'Site', 'Treatment date', 'Injury / illness', 'Body part', 'Treatment given', 'Outcome', 'Ambulance', 'First-aider', 'Linked incident']);
            foreach ($rows as $row) {
                fputcsv($out, array_map($this->csvCell(...), [$row['reference'], $row['treated_person_name'], $row['treated_person_type'], $row['site']['name'] ?? '', $row['treatment_date'], $row['injury_illness_type'], $row['body_part'], $row['treatment_given'], $row['treatment_outcome'], $row['ambulance_called'] ? 'Yes' : 'No', $row['first_aider']['name'] ?? '', $row['related_incident']['reference'] ?? '']));
            }
            fclose($out);
        }, 'first-aid-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function emptyPickers(): array
    {
        return array_fill_keys(['sites', 'staff', 'first_aiders', 'followup_assignees', 'clients', 'incidents', 'person_types', 'injury_types', 'outcomes', 'attachment_kinds'], []);
    }

    private function filters(Request $request): array
    {
        $values = $request->validate(['site_id' => ['nullable', 'integer', 'min:1'], 'first_aider_id' => ['nullable', 'integer', 'min:1'],
            'treated_person_type' => ['nullable', Rule::in(['staff', 'client', 'visitor', 'contractor'])],
            'treatment_outcome' => ['nullable', Rule::in(['returned_to_activity', 'sent_home', 'medical_centre', 'sent_to_hospital', 'ongoing_monitoring', 'refused_treatment', 'other'])],
            'period' => ['nullable', Rule::in(['week', '30d', 'quarter', 'all'])], 'tab' => ['nullable', Rule::in(['all', 'ambulance', 'linked', 'reportable', 'followup'])], 'q' => ['nullable', 'string', 'max:200'], 'page' => ['nullable', 'integer', 'min:1']]);

        return ['site_id' => isset($values['site_id']) ? (int) $values['site_id'] : null, 'first_aider_id' => isset($values['first_aider_id']) ? (int) $values['first_aider_id'] : null, 'treated_person_type' => $values['treated_person_type'] ?? null, 'treatment_outcome' => $values['treatment_outcome'] ?? null, 'period' => $values['period'] ?? '30d', 'tab' => $values['tab'] ?? 'all', 'q' => $values['q'] ?? null];
    }

    private function filtered(Builder $query, array $filters): Builder
    {
        foreach (['site_id', 'first_aider_id', 'treated_person_type', 'treatment_outcome'] as $key) {
            if ($filters[$key] !== null) {
                $query->where($key, $filters[$key]);
            }
        }
        if ($filters['q'] !== null) {
            $query->where(fn ($q) => $q->where('treated_person_name', 'like', '%'.$filters['q'].'%')->orWhere('injury_illness_description', 'like', '%'.$filters['q'].'%')->orWhere('treatment_given', 'like', '%'.$filters['q'].'%')->orWhere('body_part', 'like', '%'.$filters['q'].'%'));
        }

        return $query;
    }

    private function period(Builder $query, string $period): Builder
    {
        $from = match ($period) {
            'week' => now()->subWeek(), '30d' => now()->subDays(30), 'quarter' => now()->subMonths(3), default => null
        };

        return $from ? $query->where('treatment_date', '>=', $from) : $query;
    }

    private function tab(Builder $query, string $tab): Builder
    {
        return match ($tab) {
            'ambulance' => $query->where('ambulance_called', true), 'linked' => $query->whereNotNull('related_incident_id'),
            'reportable' => $query->whereNull('related_incident_id')->where(fn ($q) => $q->where('incident_reported', true)->orWhere('ambulance_called', true)->orWhere('treatment_outcome', 'sent_to_hospital')),
            'followup' => $query->whereHas('followups', fn ($q) => $q->whereNull('completed_at')), default => $query
        };
    }

    private function confirmed(Request $request, FirstAidRecord $record): RedirectResponse|JsonResponse
    {
        $proof = $this->service->confirmationForViewer($request->user(), ['id' => (int) $record->id, 'lock_version' => (int) $record->lock_version, 'idempotency_key' => $request->input('idempotency_key')]);
        abort_unless($proof, 403);
        $origin = FirstAidReturn::sanitize($request->input('return_to'));
        $href = $this->readableHref($record->id, $request->user(), $origin);
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['status' => 'accepted', 'id' => (int) $record->id, 'first_aid_confirmation' => $proof, 'record_href' => $href]);
        }
        $target = $href ?? '/health-safety/first-aid/report'.($origin !== null ? '?return_to='.rawurlencode($origin) : '');
        if ($href !== null) {
            $section = in_array($request->input('section'), FirstAidReturn::SECTIONS, true) ? $request->input('section') : null;
            $child = null;
            if ($request->filled('followup') && preg_match('/^[1-9]\d*$/D', (string) $request->input('followup'))) {
                $candidate = (int) $request->input('followup');
                $child = FirstAidFollowup::where('first_aid_record_id', $record->id)->whereKey($candidate)->exists() ? $candidate : null;
            }
            $target = FirstAidReturn::href($record->id, $origin, $section, $child);
            if ($action = $this->action($request->input('action'))) {
                $target .= (str_contains($target, '?') ? '&' : '?').'action='.rawurlencode($action);
            }
        }

        return redirect($target)->with('success', 'First aid command recorded.')->with('first_aid_confirmation', $proof);
    }

    private function confirmation(Request $request): ?array
    {
        return $this->service->confirmationForViewer($request->user(), $request->session()->get('first_aid_confirmation'));
    }

    private function readableHref(int $id, User $viewer, ?string $origin = null): ?string
    {
        try {
            $this->access->recordForViewer(FirstAidRecord::withTrashed()->findOrFail($id), $viewer, true);

            return FirstAidReturn::href($id, $origin);
        } catch (HttpException $error) {
            if (! in_array($error->getStatusCode(), [403, 404], true)) {
                throw $error;
            }

            return null;
        }
    }

    private function action(mixed $value): ?string
    {
        return is_string($value) && in_array($value, ['edit', 'archive', 'link-incident', 'add-followup', 'edit-followup', 'complete-followup', 'add-evidence', 'remove-evidence'], true) ? $value : null;
    }

    private function positive(mixed $value): int
    {
        abort_unless((is_string($value) || is_int($value)) && preg_match('/^[1-9]\d*$/D', (string) $value) && strlen((string) $value) <= 16 && (float) $value <= 9007199254740991, 404);

        return (int) $value;
    }

    private function csvCell(mixed $value): string
    {
        $value = (string) ($value ?? '');

        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }
}
