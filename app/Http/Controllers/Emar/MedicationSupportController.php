<?php

namespace App\Http\Controllers\Emar;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\MedicationSelfAdminAssessment;
use App\Models\MedicationSupportAgreement;
use App\Models\MedicationSupportChange;
use App\Services\Medication\Audit\MedicationEventData;
use App\Services\Medication\Audit\MedicationEventRecorder;
use App\Services\Medication\MedicationGovernanceScopeService;
use App\Services\Medication\MedicationRecordAccess;
use App\Services\Medication\Support\MedicationSupport;
use App\Services\Medication\Support\SupportFollowupAdapter;
use App\Services\Medication\Support\SupportMode;
use App\Services\Medication\Support\SupportTime;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

final class MedicationSupportController extends Controller
{
    public function __construct(private readonly MedicationSupport $support, private readonly MedicationGovernanceScopeService $scope, private readonly MedicationRecordAccess $access) {}

    public function index(Request $request)
    {
        $actor = $request->user();
        if ($request->integer('client_id') > 0) {
            $client = $this->access->client($actor, $request->integer('client_id'));

            return redirect()->route('emar.support.show', $client);
        }
        $siteId = $request->integer('site_id') ?: null;
        $sites = $this->scope->readerSiteIds($actor, 'medications.view', $siteId);
        $ids = $this->access->readableClientIds($actor, Client::query()->whereIn('site_id', $siteId ? [$siteId] : $sites)->where('status', 'active')->pluck('id'));
        $clients = Client::query()->with('site:id,name')->whereIn('id', $ids)->orderBy('first_name')->orderBy('id')->get();
        $register = $this->support->summaries($clients, $actor);
        $show = $request->string('show')->toString();
        $query = trim($request->string('search')->toString());
        $filtered = $register->filter(fn ($r) => (! $query || str_contains(mb_strtolower($r['client_name']), mb_strtolower($query)))
            && (! $show || ($show === 'reassess' ? in_array($r['state'], ['reassess', 'overdue']) : ($show === 'self_managed' ? collect($r['medicines'])->contains('mode', 'self_managed') : $r['state'] === $show))));
        $page = max(1, $request->integer('page', 1));

        return Inertia::render('emar/SelfAdmin', [
            'register' => $filtered->forPage($page, 50)->values(), 'total' => $filtered->count(), 'page' => $page,
            'counts' => [
                'people' => $register->count(), 'reassess' => $register->whereIn('state', ['reassess', 'overdue'])->count(),
                'none' => $register->where('state', 'none')->count(),
                'self_managed' => $register->filter(fn ($r) => collect($r['medicines'])->contains('mode', 'self_managed'))->count(),
            ],
            'sites' => $this->scope->sitePicker($sites)->map(fn ($s) => $s->only(['id', 'name']))->values(),
            'filters' => ['site_id' => $siteId, 'show' => $show, 'search' => $query],
            'can_assess' => $actor->canDo('medications.orders.manage'),
            'now' => now('UTC')->toIso8601String(),
        ]);
    }

    public function show(Request $request, Client $client)
    {
        $client = $this->access->client($request->user(), (int) $client->id);
        $summary = $this->support->summary($client, $request->user());
        $historyMedicines = ClientMedication::withTrashed()->where('client_id', $client->id)->when(! $request->user()->canDo('medications.controlled.view'), fn ($q) => $q->where(fn ($ordinary) => $ordinary->where('controlled_drug', false)->orWhereNull('controlled_drug')))->get(['id', 'name'])->keyBy('id');
        $visibleIds = $historyMedicines->keys();
        $history = MedicationSelfAdminAssessment::withTrashed()->where('client_id', $client->id)->with('assessor:id,name')->orderByDesc('id')->get()->map(fn ($a) => [
            ...$a->only(['id', 'assessment_date', 'reassessment_date', 'outcome', 'people_involved', ...MedicationSupport::SCORES,
                'can_identify_medications', 'can_read_labels', 'can_open_packaging', 'can_manage_timing', 'can_store_safely', 'willing_to_self_admin', 'assessor_notes']),
            'assessor_name' => $a->assessor?->name,
        ]);
        $changes = MedicationSupportChange::query()->where('client_id', $client->id)->whereIn('client_medication_id', $visibleIds)->with('recorder:id,name')->latest('id')->limit(100)->get()->map(fn ($c) => [
            ...$c->only(['id', 'client_medication_id', 'mode', 'previous_mode', 'reason', 'notes', 'occurred_at', 'effective_at']), 'recorded_by' => $c->recorder?->name, 'medicine_name' => $historyMedicines->get($c->client_medication_id)?->name,
        ]);

        return Inertia::render('emar/SupportRecord', [
            'support' => $summary, 'history' => $history, 'changes' => $changes,
            'staff' => $this->scope->staffPicker([(int) $client->site_id], (int) $request->user()->id),
            'agreement_history' => MedicationSupportAgreement::query()->where('client_id', $client->id)->latest('id')->get()->map(fn ($a) => $a->only(['id', 'agreed_by_role', 'agreed_by_name', 'method', 'ordering_responsibility', 'person_responsibilities', 'staff_responsibilities', 'created_at'])),
        ]);
    }

    public function store(Request $request)
    {
        return DB::transaction(fn () => $this->scope->forClient($request->user(), $request->integer('client_id'), 'medications.orders.manage', function (Client $client, $actor) use ($request) {
            $this->access->assertReadable($actor, $client);
            $rules = [
                'client_request_uuid' => ['nullable', 'uuid'], 'client_id' => ['required', 'integer'], 'supersedes_id' => ['nullable', 'integer'],
                'wishes_to_self_administer' => ['required', 'boolean'], 'people_involved' => ['required', 'array', 'min:1'],
                'people_involved.*' => ['string', 'max:255'], 'confirmed_with_person' => ['required', 'accepted'],
                'can_identify_medications' => ['required', 'boolean'], 'can_read_labels' => ['required', 'boolean'],
                'can_open_packaging' => ['required', 'boolean'], 'can_manage_timing' => ['required', 'boolean'],
                'can_store_safely' => ['required', 'boolean'], 'willing_to_self_admin' => ['required', 'boolean'],
                'risk_factors' => ['nullable', 'string', 'max:10000'], 'support_needed' => ['nullable', 'string', 'max:10000'],
                'support_adjustments' => ['nullable', 'array'], 'support_adjustments.*' => ['string', 'max:255'],
                'storage_location' => ['required', 'string', 'max:64'], 'safe_storage_notes' => ['nullable', 'string', 'max:10000'],
                'assessor_notes' => ['nullable', 'string', 'max:10000'], 'reassessment_trigger' => ['nullable', 'string', 'max:255'],
                'reassessment_interval_months' => ['required', 'integer', Rule::in([3, 6, 12])],
                'agreement_terms_changed' => ['boolean'], ...$this->scopeRules(),
            ];
            foreach (MedicationSupport::SCORES as $key) {
                $rules[$key] = ['required', 'integer', 'between:1,5'];
            }
            $data = $request->validate($rules);
            if (! collect($data['people_involved'])->contains(fn ($p) => $p === 'The person' || preg_match('/^Welfare guardian or EPOA: .+/', $p))) {
                throw ValidationException::withMessages(['people_involved' => 'The person or a named welfare guardian or EPOA must take part.']);
            }
            $binding = $this->requestBinding($client, $actor, 'assess', $data);
            if ($this->replayed($binding, $data)) {
                return back()->with('success', 'Support assessment saved.');
            }
            $assessment = $this->support->assess($client, $actor, $data);
            $this->remember($binding, $data, ['assessment_id' => $assessment->id]);
            $sourceEvents = [];
            if ($assessment->supersedes_id) {
                $prior = MedicationSelfAdminAssessment::withTrashed()->where('client_id', $client->id)->findOrFail($assessment->supersedes_id);
                app(SupportFollowupAdapter::class)->completed($prior, $actor, (int) $assessment->id, $sourceEvents);
            }
            $this->audit($client, $actor, 'support.assessed', 'support_assessment', $assessment->id, 'Medication support assessment completed.', ['cap' => SupportMode::cap($assessment->outcome)], $sourceEvents);

            return back()->with('success', 'Support assessment saved.');
        }), 5);
    }

    public function update(Request $request, MedicationSelfAdminAssessment $assessment)
    {
        return DB::transaction(fn () => $this->scope->forClientRecord($request->user(), $assessment, 'medications.orders.manage', function (Client $client, $locked, $actor) use ($request) {
            $actor ??= $request->user();
            $this->access->assertReadable($actor, $client);
            $data = $request->validate([...$this->scopeRules(), 'med_scope' => ['required', 'array', 'min:1']]);
            $binding = $this->requestBinding($client, $actor, 'set-'.$locked->id, $data);
            if ($this->replayed($binding, $data)) {
                return back()->with('success', 'Medicine support saved.');
            }
            $this->support->setSupport($client, $actor, $locked, $data['med_scope'] ?? [], (bool) ($data['confirm_loosening'] ?? false));
            $this->remember($binding, $data, ['assessment_id' => $locked->id]);
            $this->audit($client, $actor, 'support.changed', 'support_assessment', $locked->id, 'Medicine support changed.');

            return back()->with('success', 'Medicine support saved.');
        }), 5);
    }

    public function consent(Request $request, MedicationSelfAdminAssessment $assessment)
    {
        $capability = $request->user()->canDo('medications.orders.manage') ? 'medications.orders.manage' : 'medications.administer.record';

        return DB::transaction(fn () => $this->scope->forClientRecord($request->user(), $assessment, $capability, function (Client $client, $locked) use ($request) {
            $this->access->assertReadable($request->user(), $client);
            $data = $request->validate([
                'client_request_uuid' => ['nullable', 'uuid'], 'client_medication_id' => ['nullable', 'integer'], 'direction' => ['required', Rule::in(['less', 'more'])],
                'said' => ['required', 'string', 'max:10000'], 'occurred_at' => ['required', 'string', 'max:32'],
            ]);
            $binding = $this->requestBinding($client, $request->user(), 'consent-'.$locked->id, $data);
            if ($this->replayed($binding, $data)) {
                return $request->expectsJson() ? response()->json(['sync' => ['status' => 'duplicate']]) : back()->with('success', 'The change was recorded and a reassessment is due.');
            }
            $data['occurred_at'] = SupportTime::parse($data['occurred_at'])->toIso8601String();
            $this->support->consent($client, $request->user(), $locked, $data);
            $this->remember($binding, $data, ['assessment_id' => $locked->id]);
            $this->audit($client, $request->user(), 'support.consent_changed', 'support_assessment', $locked->id, 'Medication support consent change recorded.', ['direction' => $data['direction']]);

            return $request->expectsJson() ? response()->json(['sync' => ['status' => 'processed']]) : back()->with('success', 'The change was recorded and a reassessment is due.');
        }), 5);
    }

    public function agreement(Request $request, MedicationSelfAdminAssessment $assessment)
    {
        $stored = null;
        $storedUsed = false;
        try {
            $response = DB::transaction(function () use ($request, $assessment, &$stored, &$storedUsed) {
                $storedUsed = false;

                return $this->scope->forClient($request->user(), (int) $assessment->client_id, 'medications.orders.manage', function (Client $client, $actor, $lockedUsers) use ($request, $assessment, &$stored, &$storedUsed) {
                    $locked = MedicationSelfAdminAssessment::query()->where('client_id', $client->id)->whereKey($assessment->id)->lockForUpdate()->firstOrFail();
                    $this->access->assertReadable($actor, $client);
                    $data = $request->validate([
                        'client_request_uuid' => ['nullable', 'uuid'], 'agreed_by_role' => ['required', Rule::in(['person', 'guardian', 'epoa'])],
                        'agreed_by_name' => ['required_unless:agreed_by_role,person', 'nullable', 'string', 'max:255'],
                        'method' => ['required', Rule::in(['signed', 'verbal'])],
                        'witness_id' => ['required_if:method,verbal', 'nullable', 'integer'],
                        'attachment' => ['required_if:method,signed', 'nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240'],
                        'ordering_responsibility' => ['required', Rule::in(['person', 'service', 'pharmacy'])],
                        'person_responsibilities' => ['required', 'string', 'max:10000'], 'staff_responsibilities' => ['required', 'string', 'max:10000'],
                        'storage_notes' => ['nullable', 'string', 'max:10000'], 'confirm_loosening' => ['required', 'accepted'],
                    ]);
                    $binding = $this->requestBinding($client, $actor, 'agreement-'.$locked->id, $data);
                    if ($this->replayed($binding, $data)) {
                        return back()->with('success', 'Agreement recorded.');
                    }
                    if ($data['method'] === 'verbal') {
                        $witness = $lockedUsers->get((int) $data['witness_id']);
                        abort_unless($witness && (int) $witness->id !== (int) $actor->id, 404);
                        $profile = $witness->hrEmployeeProfile;
                        $sites = [(int) $profile?->primary_site_id, ...array_map('intval', $profile?->secondary_site_ids ?? [])];
                        abort_unless(in_array((int) $client->site_id, $sites, true), 404);
                    } else {
                        $data['witness_id'] = null;
                    }
                    if ($request->hasFile('attachment')) {
                        // Storage survives rollback; every retry rechecks authority before reusing these bytes.
                        if ($stored === null) {
                            $stored = $request->file('attachment')->store('medication-support/'.$client->id, 'private');
                        }
                        abort_unless(is_string($stored) && $stored !== '', 503, 'The signed form could not be stored. Your agreement has not been saved.');
                        $data['attachment_path'] = $stored;
                        $data['attachment_name'] = $request->file('attachment')->getClientOriginalName();
                    }
                    unset($data['attachment'], $data['confirm_loosening']);
                    $agreement = $this->support->recordAgreement($client, $actor, $locked, $data);
                    $this->remember($binding, $data, ['agreement_id' => $agreement->id]);
                    $this->audit($client, $actor, 'support.agreement_recorded', 'support_agreement', $agreement->id, 'Medication support agreement recorded.', ['role' => $agreement->agreed_by_role, 'method' => $agreement->method]);
                    $storedUsed = $stored !== null && $agreement->attachment_path === $stored;

                    return back()->with('success', 'Agreement recorded.');
                }, authorizationUserIds: $request->input('method') === 'verbal' ? array_filter([$request->integer('witness_id')]) : []);
            }, 5);
        } catch (\Throwable $error) {
            if ($stored) {
                // After-commit callbacks may fail after the attachment has become canonical evidence.
                try {
                    if (! MedicationSupportAgreement::query()->where('attachment_path', $stored)->exists()) {
                        Storage::disk('private')->delete($stored);
                    }
                } catch (\Throwable $cleanupFailure) {
                    report($cleanupFailure); // Preserve bytes if their persisted ownership cannot be verified.
                }
            }
            throw $error;
        }
        // A competing request can win after rollback, so the successful retry may only replay its result.
        if ($stored && ! $storedUsed) {
            Storage::disk('private')->delete($stored);
        }

        return $response;
    }

    public function destroy(Request $request, MedicationSelfAdminAssessment $assessment)
    {
        return DB::transaction(fn () => $this->scope->forClientRecord($request->user(), $assessment, 'medications.orders.manage', function (Client $client, $locked, $actor) {
            $this->access->assertReadable($actor, $client);
            $this->support->archive($client, $actor, $locked);
            $this->audit($client, $actor, 'support.archived', 'support_assessment', $locked->id, 'Medication support assessment archived; staff give the medicines.');

            return back()->with('success', 'Assessment archived. Staff give the medicines until reassessed.');
        }), 5);
    }

    public function agreementFile(Request $request, MedicationSupportAgreement $agreement)
    {
        $this->access->client($request->user(), (int) $agreement->client_id);
        abort_unless($agreement->attachment_path && Storage::disk('private')->exists($agreement->attachment_path), 404);

        return Storage::disk('private')->download($agreement->attachment_path, $agreement->attachment_name, ['X-Content-Type-Options' => 'nosniff']);
    }

    private function requestBinding(Client $client, $actor, string $action, array $data): array
    {
        unset($data['client_request_uuid']);
        foreach ($data as $key => $value) {
            if ($value instanceof UploadedFile) {
                $data[$key] = ['name' => $value->getClientOriginalName(), 'sha256' => hash_file('sha256', $value->getRealPath())];
            }
        }
        ksort($data);

        return ['scope' => 'support:'.$action.':'.$client->id.':'.$actor->id, 'fingerprint' => hash('sha256', json_encode($data, JSON_THROW_ON_ERROR))];
    }

    private function replayed(array $binding, array $data): bool
    {
        return $this->scope->idempotencyResult($binding['scope'], $data, $binding['fingerprint'], 'This request was already used for different medication support details.', durable: true) !== null;
    }

    private function remember(array $binding, array $data, array $result): void
    {
        $this->scope->rememberIdempotencyResult($binding['scope'], $data, $result, $binding['fingerprint'], 'This request was already used for different medication support details.', durable: true);
    }

    private function audit(Client $client, $actor, string $kind, string $subjectType, int $id, string $summary, array $facts = [], array $sourceEvents = []): void
    {
        app(MedicationEventRecorder::class)->appendMany([...$sourceEvents, new MedicationEventData(
            siteId: (int) $client->site_id, kind: $kind, subjectType: $subjectType, subjectId: (string) $id,
            actorId: (int) $actor->id, occurredAt: CarbonImmutable::now('UTC'), summary: $summary,
            facts: $facts, clientId: (int) $client->id,
        )]);
    }

    private function scopeRules(): array
    {
        return [
            'client_request_uuid' => ['nullable', 'uuid'], 'med_scope' => ['nullable', 'array'], 'med_scope.*.med_id' => ['required', 'integer', 'min:1'],
            'med_scope.*.scope' => ['required', Rule::in(SupportMode::MODES)], 'confirm_loosening' => ['boolean'],
        ];
    }
}
