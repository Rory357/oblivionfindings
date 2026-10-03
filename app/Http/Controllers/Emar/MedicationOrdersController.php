<?php

namespace App\Http\Controllers\Emar;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\MedicationCovertAuthorisation;
use App\Models\MedicationOrderAction;
use App\Models\MedicationOrderFile;
use App\Models\MedicationOrderRevision;
use App\Models\MedicationReconciliation;
use App\Models\MedicationReviewItem;
use App\Models\RespiteStay;
use App\Models\User;
use App\Services\Medication\Followups\MedicationFollowupService;
use App\Services\Medication\MedicationGovernanceScopeService;
use App\Services\Medication\MedicationOrderLifecycleService;
use App\Services\Medication\MedicationOrderWorkflow;
use App\Services\Medication\MedicationReconciliationWorkflow;
use App\Services\Medication\MedicationRecordAccess;
use App\Services\Medication\MedicationScopeDecisionService;
use App\Services\Medication\OrderAllergyMatcher;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

final class MedicationOrdersController extends Controller
{
    public function __construct(
        private readonly MedicationGovernanceScopeService $scope,
        private readonly MedicationRecordAccess $access,
        private readonly MedicationScopeDecisionService $work,
        private readonly MedicationOrderWorkflow $orders,
        private readonly MedicationReconciliationWorkflow $reconciliations,
        private readonly OrderAllergyMatcher $allergies,
    ) {}

    public function index(Request $request)
    {
        $actor = $request->user();
        $sites = $this->scope->readerSiteIds($actor, 'medications.view', $request->integer('site_id') ?: null);
        $clients = Client::query()->whereIn('site_id', $sites)->with('site:id,name')->orderBy('last_name')->get();
        $readableIds = $this->access->readableClientIds($actor, $clients->modelKeys());
        $clients = $clients->whereIn('id', $readableIds)->values();
        $writableIds = $this->work->clientIdsWithCurrentAuthority($actor, $readableIds, now());
        $query = ClientMedication::query()->current()->whereIn('client_id', $readableIds)
            ->when(! $actor->canDo('medications.controlled.view'), fn ($q) => $q->where('controlled_drug', false));
        $counts = [
            'current' => (clone $query)->where('state', '!=', 'ceased')->count(),
            'to_check' => (clone $query)->where('state', '!=', 'ceased')->where(fn ($q) => $q->where('approval_status', '!=', 'verified')->orWhereIn('id', $this->actionableRevisionIds(true)))->count(),
            'written' => MedicationOrderRevision::query()->canonicalVersion()->whereIn('client_medication_id', (clone $query)->where('state', '!=', 'ceased')->select('id'))
                ->whereNotNull('written_due_at')->whereNull('written_confirmation')->whereIn('status', ['pending', 'checked', 'checked_alone'])->count(),
            'ending' => (clone $query)->where('state', '!=', 'ceased')->whereBetween('end_date', [now()->timezone('Pacific/Auckland')->toDateString(), now()->timezone('Pacific/Auckland')->addDays(14)->toDateString()])->count(),
            'covert' => $this->scope->scopeCanonicalClientMedicationRows(MedicationCovertAuthorisation::query(), $sites, false)->whereIn('client_medication_id', (clone $query)->select('id'))->whereIn('client_id', $readableIds)->active()->count(),
            'reconciliations' => MedicationReconciliation::query()->whereIn('client_id', $readableIds)->whereNull('signed_off_at')->count(),
        ];
        $filter = $request->string('show')->toString();
        if ($filter === 'stopped') {
            $query->where('state', 'ceased');
        } else {
            $query->where('state', '!=', 'ceased');
        }
        if ($filter === 'ending') {
            $query->whereBetween('end_date', [now()->timezone('Pacific/Auckland')->toDateString(), now()->timezone('Pacific/Auckland')->addDays(14)->toDateString()]);
        }
        if ($request->string('view')->toString() === 'to_check' || $filter === 'attention') {
            $query->where(function ($q) {
                $q->where('approval_status', '!=', 'verified')->orWhereIn('id', $this->actionableRevisionIds());
            });
        }
        if ($request->filled('site_id')) {
            $query->whereHas('client', fn ($q) => $q->where('site_id', $request->integer('site_id')));
        }
        if ($request->filled('search')) {
            $search = '%'.addcslashes(mb_substr($request->string('search')->toString(), 0, 200), '%_\\').'%';
            $query->where(fn ($q) => $q->where('name', 'like', $search)->orWhereHas('client', fn ($c) => $c->where('first_name', 'like', $search)->orWhere('last_name', 'like', $search)));
        }
        $page = $query->with('client:id,first_name,last_name,site_id')->orderBy('name')->paginate(40)->withQueryString();
        $revisions = $this->scope->scopeCanonicalClientMedicationRows(MedicationOrderRevision::query()->canonicalVersion(), $sites, false)->whereIn('client_medication_id', $page->pluck('id'))
            ->with(['version', 'enterer:id,name', 'checker:id,name', 'witness:id,name'])->orderBy('id')->get()->groupBy('client_medication_id');
        $page->through(fn ($medication) => $this->summary($medication, $revisions->get($medication->id, collect()), $actor, $writableIds));
        $reconciliations = MedicationReconciliation::query()->whereIn('client_id', $readableIds)->with(['client:id,first_name,last_name', 'items'])->orderByDesc('id')->limit(100)->get()
            ->map(fn ($record) => $this->reconciliationPayload($record, $actor, $writableIds));
        $covert = $this->scope->scopeCanonicalClientMedicationRows(MedicationCovertAuthorisation::query(), $sites, false)->whereIn('client_id', $readableIds)
            ->whereIn('client_medication_id', ClientMedication::query()->whereIn('client_id', $readableIds)
                ->when(! $actor->canDo('medications.controlled.view'), fn ($q) => $q->where('controlled_drug', false))->select('id'))
            ->with(['medication:id,name,client_id,controlled_drug', 'client:id,first_name,last_name', 'files'])->orderByDesc('id')->limit(100)->get()
            ->map(fn ($record) => array_merge($record->toArray(), [
                'authorised_date' => $record->authorised_date?->toDateString(), 'review_date' => $record->review_date?->toDateString(),
                'can_manage' => $actor->canDo('medications.orders.manage') && (! $record->medication->controlled_drug || $actor->canDo('medications.controlled.record')),
            ]));
        $handoff = null;
        if ($request->integer('review_item')) {
            abort_unless($actor->canDo('medications.orders.manage'), 403);
            $person = $this->access->client($actor, $request->integer('client_id'));
            $item = MedicationReviewItem::query()->whereKey($request->integer('review_item'))->where('client_id', $person->id)
                ->where('decision', 'agreed')->whereHas('review', fn ($q) => $q->where('client_id', $person->id)->where('status', 'completed'))->firstOrFail();
            abort_if($item->isRestricted() && ! $actor->canDo('medications.controlled.view'), 404);
            abort_unless($item->isChange() && ($item->client_medication_id === null || (int) $item->medication?->client_id === (int) $person->id), 404);
            $handoff = $item->only('id', 'client_id', 'client_medication_id', 'outcome', 'name_snapshot', 'recommendation') + ['entered' => $item->linked_order_version_id !== null];
        }

        $openAction = in_array($request->input('action'), ['entry', 'check', 'covert'], true) ? $request->input('action') : 'view';
        if ($request->integer('order_id') && $openAction === 'check') {
            $opened = $this->readableOrder($actor, $request->integer('order_id'));
            $waiting = MedicationOrderRevision::query()->canonicalVersion()->where('client_id', $opened->client_id)->where('client_medication_id', $opened->id)
                ->where(fn ($q) => $q->where('status', 'pending')->orWhere(fn ($second) => $second->where('status', 'checked_alone')->whereNull('second_checked_at')))->exists();
            if (! $waiting) {
                $openAction = $opened->approval_status !== 'verified' && $actor->canDo('medications.orders.manage') ? 'entry' : 'view';
            }
        }

        return Inertia::render('emar/Orders', [
            'orders' => $page, 'counts' => $counts, 'houses' => $this->scope->sitePicker($sites),
            'clients' => $clients->map(fn ($client) => ['id' => $client->id, 'name' => trim($client->first_name.' '.$client->last_name), 'site_id' => $client->site_id, 'can_enter' => in_array($client->id, $writableIds, true)]),
            'covert' => $covert, 'reconciliations' => $reconciliations,
            'respite_stays' => RespiteStay::query()->whereIn('client_id', $readableIds)->orderByDesc('id')->limit(200)->get(['id', 'client_id', 'status', 'actual_start', 'actual_end']),
            'can' => ['manage' => $actor->canDo('medications.orders.manage'), 'verify' => $actor->canDo('medications.orders.verify'),
                'controlled_view' => $actor->canDo('medications.controlled.view'), 'controlled_record' => $actor->canDo('medications.controlled.record')],
            'me' => ['id' => $actor->id, 'name' => $actor->name], 'loaded_at' => now()->toIso8601String(),
            'review_default' => now()->timezone('Pacific/Auckland')->addMonthsNoOverflow(3)->toDateString(),
            'open_order_id' => $request->integer('order_id') ?: null,
            'open_order_action' => $openAction,
            'open_check_mode' => in_array($request->input('mode'), ['send_back', 'second'], true) ? $request->input('mode') : 'independent',
            'open_new_order' => $request->input('action') === 'entry' && ! $request->integer('order_id'),
            'review_handoff' => $handoff,
            'prefill_client_id' => in_array($request->integer('client_id'), $readableIds, true) ? $request->integer('client_id') : null,
            'filters' => $request->only('view', 'show', 'search', 'site_id'),
        ]);
    }

    public function detail(Request $request, int $medication)
    {
        $order = $this->readableOrder($request->user(), $medication);
        $revisions = MedicationOrderRevision::query()->canonicalVersion()->where('client_medication_id', $order->id)->where('client_id', $order->client_id)
            ->with(['version', 'enterer:id,name', 'checker:id,name', 'witness:id,name', 'files'])->orderByDesc('id')->get();
        $actions = MedicationOrderAction::query()->where('client_medication_id', $order->id)->orderByDesc('id')->limit(100)->get();
        $last = ClientMedicationAdministration::query()->effectiveClinicalEvidence()->where('client_id', $order->client_id)
            ->where('client_medication_id', $order->id)->where('status', 'given')->where('administered_at', '<=', now())
            ->orderByDesc('administered_at')->orderByDesc('id')->with('administeredBy:id,name')->first();

        return response()->json([
            'summary' => $this->summary($order, $revisions->reverse()->values(), $request->user(), $this->work->clientIdsWithCurrentAuthority($request->user(), [$order->client_id], now())),
            'order' => $order, 'revisions' => $revisions, 'actions' => $actions,
            'allergies' => $this->allergies->inspect($order->client, ($revisions->firstWhere('status', 'pending')?->version->name) ?? $order->name),
            'last_dose' => $last !== null ? ['id' => $last->id, 'given_at' => $last->administered_at?->toIso8601String(), 'dose_given' => $last->dose_given, 'by' => $last->administeredBy?->name, 'source' => 'emar'] : null,
            'stock' => $order->stock()->get(['id', 'on_hand', 'unit', 'batch_number', 'expiry_date']),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function allergyCheck(Request $request, int $client)
    {
        $client = $this->access->client($request->user(), $client);
        $input = $request->validate(['medicine' => 'required|string|max:255']);

        return response()->json($this->allergies->inspect($client, $input['medicine']))->header('Cache-Control', 'private, no-store');
    }

    public function witnesses(Request $request, int $client)
    {
        $person = $this->access->client($request->user(), $client);

        return response()->json(['people' => $this->scope->prescriptionWitnessStaffPicker([(int) $person->site_id], $request->user()->id)])
            ->header('Cache-Control', 'private, no-store');
    }

    public function candidates(Request $request, int $client)
    {
        $person = $this->access->client($request->user(), $client);
        $query = ClientMedication::query()->current()->where('client_id', $person->id)->where('state', '!=', 'ceased')
            ->when(! $request->user()->canDo('medications.controlled.view'), fn ($q) => $q->where('controlled_drug', false));
        $orders = $query->with('client:id,first_name,last_name,site_id')->orderBy('name')->get();
        $revisions = MedicationOrderRevision::query()->canonicalVersion()->where('client_id', $person->id)->whereIn('client_medication_id', $orders->pluck('id'))->with('version')->orderBy('id')->get()->groupBy('client_medication_id');
        $workIds = $this->work->clientIdsWithCurrentAuthority($request->user(), [$person->id], now());

        return response()->json(['orders' => $orders->map(fn ($order) => $this->summary($order, $revisions->get($order->id, collect()), $request->user(), $workIds))])->header('Cache-Control', 'private, no-store');
    }

    public function enter(Request $request)
    {
        $revision = $request->integer('review_item')
            ? $this->orders->enterFromRecommendation($request->user(), $request->integer('client_id'), $request->integer('medication_id') ?: null, $request->integer('review_item'), $request->all(), $request->file('source_file'))
            : $this->orders->enter($request->user(), $request->integer('client_id'), $request->integer('medication_id') ?: null, $request->all(), $request->file('source_file'));

        return back()->with('success', 'Version '.$revision->version->version_number.' saved. Waiting to be checked.');
    }

    public function check(Request $request, int $revision)
    {
        $this->orders->check($request->user(), $revision, $request->all());

        return back()->with('success', 'Order version checked.');
    }

    public function sendBack(Request $request, int $revision)
    {
        $this->orders->sendBack($request->user(), $revision, (string) $request->input('reason', ''));

        return back()->with('success', 'Version sent back. The previous checked version stays in use.');
    }

    public function confirmAllergy(Request $request, int $revision)
    {
        $this->orders->confirmAllergy($request->user(), $revision, $request->all());

        return back()->with('success', 'Prescriber confirmation recorded on this version.');
    }

    public function confirmWritten(Request $request, int $revision)
    {
        $request->validate(['file' => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240']);
        $this->orders->confirmWritten($request->user(), $revision, $request->all(), $request->file('file'));

        return back()->with('success', 'Written confirmation saved.');
    }

    public function stop(Request $request, int $medication)
    {
        if ($request->integer('review_item')) {
            $this->orders->stopFromRecommendation($request->user(), $medication, $request->integer('review_item'), $request->all());

            return back()->with('success', 'Order stopped and cessation evidence linked to the agreed recommendation.');
        }
        $this->orders->forMedication($request->user(), $medication, 'medications.orders.manage', function ($client, $order, $actor) use ($request) {
            $this->access->assertReadable($actor, $client);
            $this->orders->assertControlled($actor, $order);
            $alreadyStopped = $order->state === 'ceased';
            $ended = app(MedicationOrderLifecycleService::class)->discontinue($actor, $order, $request->input('reason'),
                submittedClientId: $request->integer('client_id') ?: null, requestKey: $request->input('request_key'));
            if (! $alreadyStopped) {
                $this->orders->action($actor, $ended, 'stopped', ['version' => $ended->version, 'reason' => $ended->ceased_reason]);
            }
        }, expectedClientId: $request->integer('client_id') ?: null);

        return back()->with('success', 'Order stopped.');
    }

    public function hold(Request $request, int $medication)
    {
        $this->orders->forMedication($request->user(), $medication, 'medications.orders.manage', function (Client $client, ClientMedication $order, User $actor) use ($request) {
            $this->access->assertReadable($actor, $client);
            $this->orders->assertControlled($actor, $order);
            $this->orders->assertOpen($order);
            $reason = $this->orders->reason((string) $request->input('reason', ''));
            if ($order->state !== 'active') {
                throw ValidationException::withMessages(['order' => 'Only an active order can be held.']);
            }
            $order->forceFill(['state' => 'paused', 'paused_at' => now(), 'active' => false])->save();
            $this->orders->action($actor, $order, 'held', ['prescriber_instruction' => $reason]);
        });

        return back()->with('success', 'Order held. The instruction is kept in its history.');
    }

    public function resume(Request $request, int $medication)
    {
        $this->orders->forMedication($request->user(), $medication, 'medications.orders.manage', function (Client $client, ClientMedication $order, User $actor) use ($request) {
            $this->access->assertReadable($actor, $client);
            $this->orders->assertControlled($actor, $order);
            $this->orders->assertOpen($order);
            $reason = $this->orders->reason((string) $request->input('reason', ''));
            if ($order->state !== 'paused') {
                throw ValidationException::withMessages(['order' => 'Only a held order can be resumed.']);
            }
            $order->forceFill(['state' => 'active', 'paused_at' => null, 'active' => true])->save();
            $this->orders->action($actor, $order, 'resumed', ['prescriber_instruction' => $reason]);
        });

        return back()->with('success', 'Order resumed on the previous checked prescription.');
    }

    public function startReconciliation(Request $request)
    {
        $this->reconciliations->start($request->user(), $request->integer('client_id'), $request->all());

        return back()->with('success', 'Reconciliation started. Match each medicine.');
    }

    public function saveReconciliation(Request $request, int $reconciliation)
    {
        $this->reconciliations->save($request->user(), $reconciliation, $request->all());

        return back()->with('success', 'Reconciliation decisions saved.');
    }

    public function applyReconciliation(Request $request, int $reconciliation)
    {
        $this->reconciliations->apply($request->user(), $reconciliation, $request->all());

        return back()->with('success', 'Reconciliation decisions applied. Order changes still require a check.');
    }

    public function signOffReconciliation(Request $request, int $reconciliation)
    {
        $this->reconciliations->signOff($request->user(), $reconciliation);

        return back()->with('success', 'Reconciliation signed off.');
    }

    public function file(Request $request, int $file)
    {
        $record = MedicationOrderFile::query()->findOrFail($file);
        if ($record->medication_order_revision_id !== null) {
            $revision = $record->revision;
            $order = $this->readableOrder($request->user(), $revision->client_medication_id);
            abort_unless((int) $order->client_id === (int) $revision->client_id, 404);
            abort_unless((int) $revision->version?->client_id === (int) $order->client_id
                && (int) $revision->version?->client_medication_id === (int) $order->id, 404);
        } elseif ($record->medication_covert_authorisation_id !== null) {
            $covert = MedicationCovertAuthorisation::query()->findOrFail($record->medication_covert_authorisation_id);
            $order = $this->readableOrder($request->user(), $covert->client_medication_id);
            abort_unless((int) $order->client_id === (int) $covert->client_id, 404);
        } else {
            $reconciliation = $record->reconciliation;
            abort_unless($reconciliation !== null, 404);
            $this->access->client($request->user(), $reconciliation->client_id);
            abort_if(! $request->user()->canDo('medications.controlled.view') && $reconciliation->items()->where('controlled', true)->exists(), 404);
        }
        abort_unless(Storage::disk('local')->exists($record->file_path), 404, 'This source file is unavailable.');
        $headers = ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'];

        return $request->boolean('download')
            ? Storage::disk('local')->download($record->file_path, $record->file_name, $headers)
            : Storage::disk('local')->response($record->file_path, $record->file_name, $headers);
    }

    public function resolveReconciliationQuery(Request $request, int $reconciliation, int $item)
    {
        $this->reconciliations->resolveQuery($request->user(), $reconciliation, $item, $request->all());

        return back()->with('success', 'Prescriber response recorded. Any order change still needs its own source and check.');
    }

    public function authoriseCovert(Request $request, int $medication)
    {
        $paths = [];
        try {
            $this->orders->forOfficeMedication($request->user(), $medication, function (Client $client, ClientMedication $order, User $actor) use ($request, &$paths) {
                $this->access->assertReadable($actor, $client);
                $this->orders->assertControlled($actor, $order);
                $this->orders->assertOpen($order);
                if (! $order->isAdministrable()) {
                    throw ValidationException::withMessages(['order' => 'Covert giving requires an active, checked prescription.']);
                }
                $today = now()->timezone('Pacific/Auckland')->toDateString();
                $evidence = $request->validate([
                    'capacity_lacking' => 'required|accepted', 'capacity_assessor' => 'required|string|max:255',
                    'capacity_date' => 'required|date_format:Y-m-d|before_or_equal:'.$today, 'capacity_record' => 'required|string|max:4000',
                    'consulted_name' => 'required|string|max:255', 'consulted_role' => 'required|string|max:255',
                    'consulted_record' => 'required|string|max:4000', 'pharmacist_name' => 'required|string|max:255',
                    'pharmacist_advice' => 'required|string|max:4000', 'authorised_by_name' => 'required|string|max:255',
                    'authorised_date' => 'required|date_format:Y-m-d|before_or_equal:'.$today, 'legal_basis' => 'required|string|max:255',
                    'administration_method' => 'required|string|max:4000', 'review_date' => 'required|date_format:Y-m-d|after_or_equal:'.$today,
                    'request_key' => 'required|string|max:100', 'gp_file' => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240',
                ]);
                unset($evidence['gp_file']);
                $existing = MedicationCovertAuthorisation::query()->where('client_id', $client->id)->where('client_medication_id', $order->id)->orderBy('id')->lockForUpdate()->get();
                $replay = $existing->first(fn ($entry) => data_get($entry->structured_evidence, 'request_key') === $evidence['request_key']);
                $hash = hash('sha256', json_encode($evidence, JSON_THROW_ON_ERROR).hash_file('sha256', $request->file('gp_file')->getRealPath()));
                if ($replay !== null) {
                    if (! hash_equals((string) data_get($replay->structured_evidence, 'payload_sha256', ''), $hash)) {
                        throw ValidationException::withMessages(['order' => 'This request key belongs to another covert authorisation.']);
                    }

                    return;
                }
                foreach ($existing->where('status', 'active') as $previous) {
                    $previous->forceFill(['status' => 'revoked', 'revoked_at' => now(), 'revoked_by' => $actor->id, 'revoke_reason' => 'Replaced by the recorded review and new authorisation.'])->save();
                }
                $authorisation = MedicationCovertAuthorisation::query()->create([
                    'client_id' => $client->id, 'client_medication_id' => $order->id, 'recorded_by' => $actor->id,
                    'status' => 'active', 'authorised_by_name' => $evidence['authorised_by_name'],
                    'authorised_date' => $evidence['authorised_date'], 'review_date' => $evidence['review_date'],
                    'legal_basis' => $evidence['legal_basis'], 'clinical_justification' => $evidence['capacity_record'],
                    'pharmacist_advice' => $evidence['pharmacist_advice'], 'administration_method' => $evidence['administration_method'],
                    'structured_evidence' => $evidence + ['payload_sha256' => $hash],
                ]);
                $file = $request->file('gp_file');
                $path = $file->store('medication-covert/'.$authorisation->id, 'local');
                if (! is_string($path)) {
                    throw new \RuntimeException('The prescriber’s authorisation could not be stored.');
                }
                $paths[] = $path;
                MedicationOrderFile::query()->create(['medication_covert_authorisation_id' => $authorisation->id, 'purpose' => 'gp_authorisation',
                    'file_name' => $file->getClientOriginalName(), 'file_path' => $path, 'file_size' => $file->getSize(), 'mime_type' => $file->getMimeType(),
                    'sha256' => hash_file('sha256', $file->getRealPath()), 'uploaded_by' => $actor->id, 'created_at' => now()]);
                app(MedicationFollowupService::class)->ensureForSource('covert-review', $authorisation->id, $client, $order, null, null,
                    CarbonImmutable::parse($authorisation->review_date->toDateString(), 'Pacific/Auckland')->subDays(14)->utc(),
                    ['covert_authorisation_id' => $authorisation->id, 'source_url' => '/emar/prescriptions?view=covert']);
                $this->orders->action($actor, $order, 'covert_authorised', ['authorisation_id' => $authorisation->id]);
            });
        } catch (\Throwable $exception) {
            foreach ($paths as $path) {
                Storage::disk('local')->delete($path);
            }
            throw $exception;
        }

        return back()->with('success', 'Covert authorisation and source saved. Earlier authorisations are kept.');
    }

    public function revokeCovert(Request $request, int $authorisation)
    {
        $submitted = MedicationCovertAuthorisation::query()->findOrFail($authorisation);
        $this->orders->forOfficeMedication($request->user(), $submitted->client_medication_id, function (Client $client, ClientMedication $order, User $actor) use ($request, $submitted) {
            $record = MedicationCovertAuthorisation::query()->whereKey($submitted->id)->where('client_id', $client->id)->where('client_medication_id', $order->id)->lockForUpdate()->firstOrFail();
            $this->access->assertReadable($actor, $client);
            $this->orders->assertControlled($actor, $order);
            $reason = $this->orders->reason((string) $request->input('reason', ''));
            if ($record->status !== 'active') {
                return;
            }
            $record->forceFill(['status' => 'revoked', 'revoked_at' => now(), 'revoked_by' => $actor->id, 'revoke_reason' => $reason])->save();
            $this->orders->action($actor, $order, 'covert_revoked', ['authorisation_id' => $record->id, 'reason' => $reason]);
        });

        return back()->with('success', 'Covert giving stopped. The prescription carries on openly.');
    }

    private function readableOrder(User $actor, int $id): ClientMedication
    {
        $sites = $this->scope->readerSiteIds($actor, 'medications.view');
        $order = ClientMedication::query()->current()->whereKey($id)
            ->whereHas('client', fn ($q) => $q->whereIn('site_id', $sites))
            ->when(! $actor->canDo('medications.controlled.view'), fn ($q) => $q->where('controlled_drug', false))->firstOrFail();
        $this->access->assertReadable($actor, $order->client);

        return $order;
    }

    private function summary(ClientMedication $order, $revisions, User $actor, array $workIds): array
    {
        $pending = $revisions->last(fn ($revision) => in_array($revision->status, ['pending', 'sent_back'], true) && ((int) $revision->version->version_number > (int) $order->version || $order->approval_status !== 'verified'));
        $current = $revisions->last(fn ($revision) => in_array($revision->status, ['checked', 'checked_alone'], true) && (int) $revision->version->version_number === (int) $order->version);
        $mutable = in_array((int) $order->client_id, $workIds, true) && (! $order->controlled_drug || $actor->canDo('medications.controlled.record'));

        return [
            'id' => $order->id, 'client_id' => $order->client_id, 'site_id' => $order->client->site_id,
            'person' => trim($order->client->first_name.' '.$order->client->last_name), 'name' => $order->name,
            'dosage' => $order->dosage, 'dose_times' => $order->dose_times, 'frequency' => $order->frequency,
            'is_prn' => $order->is_prn, 'controlled' => $order->controlled_drug, 'version' => $order->version,
            'state' => $order->state, 'approval_status' => $order->approval_status, 'end_date' => $order->end_date?->toDateString(),
            'ceased_reason' => $order->ceased_reason, 'ceased_at' => $order->ceased_at?->toIso8601String(),
            'pending' => $pending, 'current' => $current,
            'can_manage' => $mutable && $actor->canDo('medications.orders.manage'),
            'can_verify' => $mutable && $actor->canDo('medications.orders.verify'),
            'blocked_reason' => $mutable ? null : 'A covering, clocked-in shift or emergency access is required for this person.',
        ];
    }

    private function actionableRevisionIds(bool $checksOnly = false)
    {
        return MedicationOrderRevision::query()->join('medication_order_versions as proposal', 'proposal.id', '=', 'medication_order_revisions.medication_order_version_id')
            ->join('client_medications as chart', 'chart.id', '=', 'medication_order_revisions.client_medication_id')
            ->whereColumn('medication_order_revisions.client_id', 'chart.client_id')->whereColumn('proposal.client_id', 'chart.client_id')
            ->whereColumn('proposal.client_medication_id', 'chart.id')->where(function ($q) use ($checksOnly) {
                $q->where(fn ($waiting) => $waiting->whereIn('medication_order_revisions.status', ['pending', 'sent_back'])
                    ->where(fn ($version) => $version->whereColumn('proposal.version_number', '>', 'chart.version')->orWhere('chart.approval_status', '!=', 'verified')))
                    ->orWhere(fn ($second) => $second->where('medication_order_revisions.status', 'checked_alone')->whereNull('second_checked_at')->whereColumn('proposal.version_number', 'chart.version'));
                if (! $checksOnly) {
                    $q->orWhere(fn ($written) => $written->whereNotNull('written_due_at')->whereNull('written_confirmation')->whereIn('medication_order_revisions.status', ['pending', 'checked', 'checked_alone']));
                }
            })->select('medication_order_revisions.client_medication_id');
    }

    private function reconciliationPayload(MedicationReconciliation $record, User $actor, array $workIds): array
    {
        $data = $record->toArray();
        $hidden = $record->items->contains(fn ($item) => $item->controlled && ! $actor->canDo('medications.controlled.view'));
        $data['items'] = $record->items->filter(fn ($item) => ! $item->controlled || $actor->canDo('medications.controlled.view'))->values()->toArray();
        // Sources may themselves list controlled medicines. Conceal the source
        // text and documents as a whole when that evidence can't be opened.
        if ($hidden) {
            $data['sources'] = 'Source evidence includes restricted medicines. An authorised colleague must open it.';
        }
        $data['restricted_medicines'] = $hidden;
        $data['can_manage'] = $actor->canDo('medications.orders.manage') && in_array((int) $record->client_id, $workIds, true);
        $data['can_apply'] = $data['can_manage'] && ! $hidden
            && (! $record->items->contains('controlled', true) || $actor->canDo('medications.controlled.record'));
        $data['can_sign_off'] = $data['can_apply'] && $actor->canDo('medications.orders.verify');

        return $data;
    }
}
