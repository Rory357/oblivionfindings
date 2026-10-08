<?php

namespace App\Http\Controllers\Emar;

use App\Http\Controllers\Concerns\SanitizesCsvOutput;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\MedicationRound;
use App\Models\User;
use App\Services\AuthorizationEvidenceLockService;
use App\Services\CurrentAuthorizationReads;
use App\Services\Medication\Audit\MedicationEventChain;
use App\Services\Medication\Audit\MedicationEventData;
use App\Services\Medication\Audit\MedicationEventReader;
use App\Services\Medication\Audit\MedicationEventRecorder;
use App\Services\Medication\DoseSlots\DoseSlotProjection;
use App\Services\Medication\Downtime\DowntimePackService;
use App\Services\Medication\MedicationGovernanceScopeService;
use App\Services\Medication\Reporting\MedicationExportAudit;
use App\Services\Medication\Reporting\MedicationPdfDataset;
use App\Services\Medication\Reporting\MedicationReportAccess;
use App\Services\Medication\Reporting\MedicationReportDataset;
use App\Services\Medication\Reporting\MedicationReportPeriod;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class MedicationReportsController extends Controller
{
    use SanitizesCsvOutput;

    public function __construct(private readonly MedicationReportAccess $access, private readonly MedicationReportDataset $datasets, private readonly MedicationEventReader $events) {}

    public function index(Request $request)
    {
        $actor = $request->user();
        $period = MedicationReportPeriod::fromRequest($request);
        $filters = $request->validate(['view' => ['nullable', Rule::in(['standard', 'audit', 'exports'])], 'report' => ['nullable', Rule::in(array_keys(MedicationReportDataset::REPORTS))], 'sub' => ['nullable', Rule::in(['events', 'gaps', 'exports'])], 'site_id' => ['nullable', 'integer', 'min:1'], 'client_id' => ['nullable', 'integer', 'min:1'], 'kind' => ['nullable', 'string', 'max:100'], 'q' => ['nullable', 'string', 'max:80'], 'page' => ['nullable', 'integer', 'min:1']]);
        $finance = $this->access->financeOnly($actor);
        $reach = $request->validate(['reached' => ['nullable', Rule::in(['yes', 'no', 'unknown'])]])['reached'] ?? '';
        $report = $filters['report'] ?? ($finance ? 'stock' : 'doses');
        $siteId = isset($filters['site_id']) ? (int) $filters['site_id'] : null;
        $clientId = isset($filters['client_id']) ? (int) $filters['client_id'] : null;
        $controlledLocked = $report === 'controlled' && ! $actor->canDo('medications.controlled.view');
        $siteIds = $this->access->siteIds($actor, $siteId, $clientId, $controlledLocked ? 'doses' : $report);
        $view = $filters['view'] ?? 'standard';
        $sub = $filters['sub'] ?? 'events';
        $data = ['rows' => [], 'totals' => [], 'notice' => null];
        $page = null;
        $locked = null;
        if ($view === 'standard' && $controlledLocked) {
            $locked = 'Named controlled-medicine reports need controlled-medicine access. Controlled doses remain included in the overall dose totals.';
        } elseif ($view === 'standard') {
            $data = $this->datasets->read($actor, $report, $period, $siteIds, $clientId);
            if ($report === 'errors' && $reach !== '') {
                $data['rows'] = array_values(array_filter($data['rows'], fn ($row) => $row['reached'] === $reach));
                $effective = collect($data['rows'])->where('in_error', 0);
                $data['totals'] = ['reached' => $effective->where('reached', 'yes')->count(), 'near_misses' => $effective->where('reached', 'no')->count(), 'with_harm' => $effective->whereIn('harm', ['minor', 'moderate', 'severe', 'severe_permanent', 'death'])->count(), 'open' => $effective->where('status', '!=', 'closed')->count()];
            }
            if (($filters['q'] ?? '') !== '') {
                $q = mb_strtolower($filters['q']);
                $data['rows'] = array_values(array_filter($data['rows'], fn ($row) => str_contains(mb_strtolower(implode(' ', array_filter($row, 'is_scalar'))), $q)));
            }
            $page = $this->paginate($data['rows'], $request);
            $data['rows'] = [];
        } elseif ($view === 'audit') {
            if (! $actor->canDo('medications.audit.view') || $finance) {
                $locked = 'The audit trail is for clinical leads, coordinators, provider managers and auditors.';
            } elseif ($sub === 'gaps') {
                $data['notice'] = app(DoseSlotProjection::class)->coverage($period->from, CarbonImmutable::now('UTC'))['notice'];
                $rows = array_values(array_filter($this->datasets->doseRows($actor, $period, $siteIds, $clientId), fn ($r) => in_array($r['status'], ['late', 'not_recorded'], true)));
                $page = $this->paginate($rows, $request);
                $data['totals'] = ['not_recorded' => count($rows)];
            } else {
                $query = $this->events->query($actor, $siteIds, $period, $clientId, $sub === 'exports' ? 'export.created' : (($filters['kind'] ?? '') ?: null), $filters['q'] ?? '');
                $data['totals'] = ['events' => (clone $query)->count(), 'exports' => (clone $query)->where('kind', 'export.created')->count(), 'doses' => (clone $query)->where('kind', 'like', 'dose.%')->count(), 'errors' => (clone $query)->where('kind', 'like', 'error.%')->count()];
                $paginator = $query->paginate(50)->withQueryString();
                $paginator->setCollection($paginator->getCollection()->map(fn ($e) => $this->events->present($actor, $e)));
                $page = $paginator->toArray();
            }
        }
        $allSites = $this->access->siteIds($actor, null, null, $finance ? 'stock' : 'doses');
        $people = $finance ? collect() : Client::query()->whereIn('id', $this->access->clientIds($actor, $siteIds))->orderBy('first_name')->orderBy('last_name')->limit(100)->get(['id', 'first_name', 'last_name'])->map(fn ($c) => ['id' => $c->id, 'name' => trim($c->first_name.' '.$c->last_name)]);

        return Inertia::render('emar/reports/hub', [
            'filters' => ['view' => $view, 'report' => $report, 'sub' => $sub, 'period' => $period->key, 'date_from' => $period->from, 'date_to' => $period->to, 'site_id' => $siteId, 'client_id' => $clientId, 'kind' => $filters['kind'] ?? '', 'q' => $filters['q'] ?? '', 'reached' => $reach],
            'reports' => $finance ? ['stock' => 'Stock'] : MedicationReportDataset::REPORTS,
            'sites' => app(MedicationGovernanceScopeService::class)->sitePicker($allSites)->map->only(['id', 'name'])->values(), 'people' => $people,
            'data' => $data, 'page' => $page, 'locked' => $locked, 'finance' => $finance,
            'can' => ['audit' => $actor->canDo('medications.audit.view') && ! $finance, 'controlled' => $actor->canDo('medications.controlled.view'), 'verify' => $actor->canDo('medications.audit.view') && ! $finance, 'history' => $actor->canDo('medications.audit.view') && $actor->canDo('medications.view') && ! $finance],
            'exports' => collect(['mar' => ['MAR chart', 'PDF', 'One person, up to 31 NZ days; includes medicines ceased in the period.'], 'cd_register' => ['Controlled drug register', 'PDF', 'One medicine, up to 31 NZ days.'], 'round_sheet' => ['Round sheet', 'PDF', 'One house, one NZ day.'], 'doses' => ['Doses', 'CSV', 'Scheduled dose slots by NZ day, including Away.'], 'errors' => ['Medication errors', 'CSV', 'Factual account; in-error records are excluded unless requested.'], 'stock' => ['Stock', 'CSV', 'Current stock by house and medicine.'], 'syringe_drivers' => ['Syringe drivers', 'CSV', 'Recorded driver use and canonical medicine contents; free-text notes are excluded.'], 'audit' => ['Audit trail', 'CSV', 'The same filtered event list as the screen, over the whole period.']])->map(fn ($spec, $type) => ['type' => $type, 'label' => $spec[0], 'format' => $spec[1], 'description' => $spec[2], 'allowed' => $this->access->canExport($actor, $type)])->filter(fn ($e) => ! $finance || $e['type'] === 'stock')->values(),
            'purposes' => MedicationExportAudit::PURPOSES, 'as_at' => CarbonImmutable::now('UTC')->toIso8601String(),
            'downtime_pack' => $finance ? null : ['allowed' => $actor->isApproved() && $this->access->canExport($actor, 'doses'), 'today' => CarbonImmutable::now('Pacific/Auckland')->toDateString(), 'tomorrow' => CarbonImmutable::now('Pacific/Auckland')->addDay()->toDateString(), 'purpose' => DowntimePackService::PURPOSE],
        ]);
    }

    public function people(Request $request)
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:80'], 'site_id' => ['nullable', 'integer', 'min:1']]);
        $sites = $this->access->siteIds($request->user(), $request->integer('site_id') ?: null);
        $people = Client::query()->whereIn('id', $this->access->clientIds($request->user(), $sites))->where(fn ($q) => $q->where('first_name', 'like', '%'.($data['q'] ?? '').'%')->orWhere('last_name', 'like', '%'.($data['q'] ?? '').'%'))->orderBy('first_name')->limit(50)->get(['id', 'first_name', 'last_name'])->map(fn ($c) => ['id' => $c->id, 'name' => trim($c->first_name.' '.$c->last_name)]);

        return response()->json(['people' => $people]);
    }

    public function event(Request $request, int $event)
    {
        $period = MedicationReportPeriod::fromRequest($request);
        $sites = $this->access->siteIds($request->user(), $request->integer('site_id') ?: null);
        $row = $this->events->query($request->user(), $sites, $period)->whereKey($event)->firstOrFail();

        return response()->json($this->events->present($request->user(), $row, true), 200, ['Cache-Control' => 'no-store']);
    }

    public function medicines(Request $request)
    {
        $data = $request->validate(['client_id' => ['required', 'integer', 'min:1']]);
        $this->access->siteIds($request->user(), null, (int) $data['client_id'], 'controlled');
        $rows = ClientMedication::withTrashed()->where('client_id', $data['client_id'])->where('controlled_drug', true)->orderBy('name')->get()->map(fn ($m) => ['value' => (string) $m->id, 'label' => $m->historicalDisplayName()]);

        return response()->json(['medicines' => $rows], 200, ['Cache-Control' => 'no-store']);
    }

    /** Small context endpoint for the shared record-page export prompt (P02). */
    public function exportOptions(Request $request)
    {
        $data = $request->validate(['type' => ['required', Rule::in(['mar', 'cd_register', 'round_sheet', 'doses', 'errors', 'stock', 'audit', 'syringe_drivers'])], 'site_id' => ['nullable', 'integer', 'min:1'], 'client_id' => ['nullable', 'integer', 'min:1']]);
        $actor = $request->user();
        $type = $data['type'];
        abort_unless($this->access->canExport($actor, $type), 403);
        $period = MedicationReportPeriod::fromRequest($request);
        $sites = $this->access->siteIds($actor, $request->integer('site_id') ?: null, $request->integer('client_id') ?: null, $type === 'stock' ? 'stock' : ($type === 'cd_register' ? 'controlled' : 'doses'));
        $people = Client::query()->whereIn('id', $this->access->clientIds($actor, $sites))->when($request->integer('client_id'), fn ($q) => $q->whereKey($request->integer('client_id')))->orderBy('first_name')->limit(100)->get()->map(fn ($p) => ['id' => $p->id, 'name' => trim($p->first_name.' '.$p->last_name)]);
        $spec = ['mar' => ['MAR chart', 'PDF', 'One person, up to 31 NZ days. Ceased and superseded medicines stay in the period.'], 'cd_register' => ['Controlled drug register', 'PDF', 'One person and one medicine, up to 31 NZ days.'], 'round_sheet' => ['Round sheet', 'PDF', 'One house and one NZ day.'], 'doses' => ['Doses', 'CSV', 'Scheduled dose slots, including Away.'], 'errors' => ['Medication errors', 'CSV', 'Factual accounts, with in-error records excluded unless requested.'], 'stock' => ['Stock', 'CSV', 'Stock as at now.'], 'syringe_drivers' => ['Syringe drivers', 'CSV', 'Recorded driver use and canonical medicine contents; free-text notes are excluded.'], 'audit' => ['Audit trail', 'CSV', 'Filtered events over the selected period.']][$type];

        return response()->json(['filters' => ['view' => 'exports', 'report' => 'doses', 'sub' => 'events', 'period' => 'custom', 'date_from' => $period->from, 'date_to' => $period->to, 'site_id' => count($sites) === 1 ? $sites[0] : null, 'client_id' => $request->integer('client_id') ?: null, 'kind' => '', 'q' => ''], 'sites' => app(MedicationGovernanceScopeService::class)->sitePicker($sites)->map->only(['id', 'name'])->values(), 'people' => $people, 'finance' => $this->access->financeOnly($actor), 'exports' => [['type' => $type, 'label' => $spec[0], 'format' => $spec[1], 'description' => $spec[2], 'allowed' => true]], 'purposes' => MedicationExportAudit::PURPOSES], 200, ['Cache-Control' => 'no-store']);
    }

    public function verify(Request $request)
    {
        abort_unless($request->user()->canDo('medications.audit.view'), 403);
        $data = $request->validate(['site_id' => ['required', 'integer', 'min:1']]);
        $this->access->siteIds($request->user(), (int) $data['site_id']);
        $result = DB::transaction(function () use ($request, $data) {
            $actor = app(AuthorizationEvidenceLockService::class)->lockForUserWithoutWaiting($request->user(), ['*']);
            abort_unless($actor->canDo('medications.audit.view') && ! $this->access->financeOnly($actor), 403);
            CurrentAuthorizationReads::within(fn ($reads) => $this->access->siteIds($actor, (int) $data['site_id'], reads: $reads));
            $result = app(MedicationEventChain::class)->verify((int) $data['site_id']);
            app(MedicationEventRecorder::class)->append(new MedicationEventData((int) $data['site_id'], 'chain.verified', 'site', (string) $data['site_id'], $actor->id, CarbonImmutable::now('UTC'), $result['intact'] ? 'Medication event chain verified' : 'Medication event chain check found a broken link', ['intact' => $result['intact'], 'broken_at' => $result['broken_at']]));

            return $result;
        }, 5);

        return response()->json($result, 200, ['Cache-Control' => 'no-store']);
    }

    public function export(Request $request)
    {
        $data = $request->validate(['type' => ['required', Rule::in(['mar', 'cd_register', 'round_sheet', 'doses', 'errors', 'stock', 'audit', 'syringe_drivers'])], 'site_id' => ['nullable', 'integer', 'min:1'], 'client_id' => ['nullable', 'integer', 'min:1'], 'medication_id' => ['nullable', 'integer', 'min:1'], 'round_id' => ['nullable', 'integer', 'min:1'], 'kind' => ['nullable', 'string', 'max:100'], 'q' => ['nullable', 'string', 'max:80'], 'include_in_error' => ['nullable', 'boolean'], 'include_prn' => ['nullable', 'boolean']]);
        $actor = $request->user();
        $type = $data['type'];
        abort_unless($this->access->canExport($actor, $type), 403);
        $period = MedicationReportPeriod::fromRequest($request);
        $purpose = app(MedicationExportAudit::class)->purpose($request);
        $data['reached'] = $request->validate(['reached' => ['nullable', Rule::in(['yes', 'no', 'unknown'])]])['reached'] ?? null;
        $clientId = $request->integer('client_id') ?: null;
        $sites = $this->access->siteIds($actor, $request->integer('site_id') ?: null, $clientId, $type === 'stock' ? 'stock' : ($type === 'cd_register' ? 'controlled' : 'doses'));
        if (in_array($type, ['mar', 'cd_register', 'round_sheet'], true)) {
            $read = fn (User $current) => app(MedicationPdfDataset::class)->read($current, $type, $period, $sites, $clientId, $request->integer('medication_id') ?: null, ! $request->has('include_prn') || $request->boolean('include_prn'), $type === 'round_sheet' ? ($request->integer('round_id') ?: null) : null);
            $evidence = $read($actor);
            $digest = hash('sha256', json_encode($evidence, JSON_THROW_ON_ERROR));
            $bytes = Pdf::setOption(['defaultFont' => 'DejaVu Sans', 'isRemoteEnabled' => false])->loadView('pdf.medication-report', ['evidence' => $evidence, 'actor' => $actor, 'generated' => CarbonImmutable::now('UTC'), 'purpose' => $purpose])->setPaper('a4', 'landscape')->output();
            app(MedicationExportAudit::class)->record($actor, $type, $sites, $period, $purpose, $clientId, ['rows' => count($evidence['rows']), 'include_prn' => ! $request->has('include_prn') || $request->boolean('include_prn'), 'medication_id' => $request->integer('medication_id') ?: null, 'round_id' => $type === 'round_sheet' ? ($request->integer('round_id') ?: null) : null], function (User $current) use ($read, $digest) {
                abort_unless(hash_equals($digest, hash('sha256', json_encode($read($current), JSON_THROW_ON_ERROR))), 409, 'The records or your access changed while the file was being prepared. Refresh and try again.');
            });

            return response($bytes, 200, ['Content-Type' => 'application/pdf', 'Cache-Control' => 'no-store', 'Content-Disposition' => 'attachment; filename="medication-'.$type.'-'.$period->from.'.pdf"']);
        }
        $read = fn (User $current) => $this->exportRows($current, $type, $period, $sites, $clientId, $data, $request->boolean('include_in_error'));
        $rows = $read($actor);
        $digest = hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
        $rows = array_map(fn ($row) => array_diff_key($row, array_flip(['href', 'client_id', 'subject_id', 'subject_type'])), $rows);
        $columns = array_keys($rows[0] ?? ($type === 'audit' ? ['occurred_at' => null, 'summary' => null, 'person' => null, 'actor' => null, 'sequence' => null] : ['date' => null, 'person' => null, 'status' => null]));
        // Build bytes before auditing so a rendering failure isn't an export.
        $handle = fopen('php://temp', 'w+b');
        fwrite($handle, "\xEF\xBB\xBF");
        $this->putCsv($handle, array_map(fn ($key) => str_replace('_', ' ', ucfirst($key)).(str_ends_with($key, '_at') ? ' (NZDT/NZST)' : ''), $columns));
        foreach ($rows as $row) {
            $this->putCsv($handle, array_map(function ($column) use ($row) {
                $value = $row[$column] ?? null;
                if (str_ends_with($column, '_at') && $value) {
                    return CarbonImmutable::parse($value)->timezone('Pacific/Auckland')->format('Y-m-d H:i:s T');
                }

                return is_array($value) ? json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) : $value;
            }, $columns));
        }
        rewind($handle);
        $bytes = stream_get_contents($handle);
        fclose($handle);
        app(MedicationExportAudit::class)->record($actor, $type, $sites, $period, $purpose, $clientId, ['rows' => count($rows), 'include_prn' => ! $request->has('include_prn') || $request->boolean('include_prn'), 'include_in_error' => $request->boolean('include_in_error')], function (User $current) use ($read, $digest) {
            abort_unless(hash_equals($digest, hash('sha256', json_encode($read($current), JSON_THROW_ON_ERROR))), 409, 'The report or your access changed while the file was being prepared. Refresh the report and try again.');
        });

        return response($bytes, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store', 'Content-Disposition' => 'attachment; filename="medication-'.$type.'-'.now('Pacific/Auckland')->format('Ymd-His-T').'.csv"']);
    }

    public function legacyRoundSheet(Request $request)
    {
        $request->validate(['date' => ['nullable', 'date_format:Y-m-d'], 'site_id' => ['nullable', 'integer', 'min:1'], 'round_id' => ['nullable', 'integer', 'min:1']]);
        $day = $request->input('date', CarbonImmutable::now('Pacific/Auckland')->toDateString());
        if ($request->integer('round_id')) {
            $sites = $this->access->siteIds($request->user(), $request->integer('site_id') ?: null);
            $round = MedicationRound::query()->whereIn('site_id', $sites)->findOrFail($request->integer('round_id'));
            abort_if($request->filled('date') && $day !== $round->round_date->toDateString(), 404);
            $day = $round->round_date->toDateString();
            $request->merge(['site_id' => $round->site_id]);
        }
        $request->merge(['type' => 'round_sheet', 'period' => 'custom', 'date_from' => $day, 'date_to' => $day]);

        return $this->export($request);
    }

    private function exportRows(User $actor, string $type, MedicationReportPeriod $period, array $sites, ?int $clientId, array $data, bool $includeInError): array
    {
        if ($type === 'audit') {
            $query = $this->events->query($actor, $sites, $period, $clientId, $data['kind'] ?? null, $data['q'] ?? '');
            abort_if($query->count() > MedicationReportDataset::MAX_ROWS, 422, 'Choose a shorter period; exports support up to 100,000 events without truncation.');
            $rows = $query->get()->map(fn ($e) => $this->events->present($actor, $e, true))->all();
        } elseif ($type === 'doses') {
            $rows = $this->datasets->doseRows($actor, $period, $sites, $clientId);
            if (! array_key_exists('include_prn', $data) || $data['include_prn']) {
                $rows = [...$rows, ...$this->datasets->prnDoseRows($actor, $period, $sites, $clientId)];
                abort_if(count($rows) > MedicationReportDataset::MAX_ROWS, 422, 'Choose a shorter period; no partial result was created.');
                usort($rows, fn ($a, $b) => [$a['date'], $a['recorded_at'] ?? $a['due_at'], $a['reference']] <=> [$b['date'], $b['recorded_at'] ?? $b['due_at'], $b['reference']]);
            }
        } else {
            $rows = $this->datasets->read($actor, $type, $period, $sites, $clientId)['rows'];
            if ($type === 'errors' && ! $includeInError) {
                $rows = array_values(array_filter($rows, fn ($row) => $row['in_error'] === 0));
            }
            if ($type === 'errors' && ! empty($data['reached'])) {
                $rows = array_values(array_filter($rows, fn ($row) => $row['reached'] === $data['reached']));
            }
        }

        return $rows;
    }

    public function redirect(Request $request)
    {
        $query = $request->query();
        if ($request->routeIs('medications.audit', 'medications.audit.index', 'emar.audit')) {
            $query['view'] = 'audit';
        }

        return redirect('/emar/reports'.($query ? '?'.http_build_query($query) : ''));
    }

    private function paginate(array $rows, Request $request): array
    {
        return (new LengthAwarePaginator(array_slice($rows, max(0, $request->integer('page', 1) - 1) * 50, 50), count($rows), 50, $request->integer('page', 1), ['path' => $request->url(), 'query' => $request->query()]))->toArray();
    }
}
