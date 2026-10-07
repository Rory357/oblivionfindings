<?php

namespace App\Http\Controllers\Emar;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientMedication;
use App\Models\MedicationExternalClinician;
use App\Models\MedicationExternalGrant;
use App\Models\MedicationExternalProposal;
use App\Models\MedicationProviderTransfer;
use App\Models\User;
use App\Services\CurrentAuthorizationReads;
use App\Services\Medication\ClientAllergyRecordService;
use App\Services\Medication\ExternalClinical\ExternalClinicalAccess;
use App\Services\Medication\ExternalClinical\ExternalClinicalPrescription;
use App\Services\Medication\ExternalClinical\ExternalClinicalProposals;
use App\Services\Medication\ExternalClinical\ProviderMedicationTransfers;
use App\Services\Medication\MedicationGovernanceScopeService;
use App\Services\Medication\MedicationOrderWorkflow;
use App\Services\Medication\MedicationRecordAccess;
use Illuminate\Http\Request;
use Inertia\Inertia;

final class MedicationExternalClinicalController extends Controller
{
    public function __construct(
        private readonly ExternalClinicalAccess $external,
        private readonly ExternalClinicalProposals $proposals,
        private readonly ProviderMedicationTransfers $transfers,
        private readonly MedicationRecordAccess $records,
        private readonly MedicationGovernanceScopeService $scope,
        private readonly MedicationOrderWorkflow $orders,
    ) {}

    public function index(Request $request)
    {
        $actor = $request->user();
        $sites = $this->scope->readerSiteIds($actor, 'medications.view');
        $clients = Client::query()->whereIn('site_id', $sites)->orderBy('last_name')->get();
        $ids = $this->records->readableClientIds($actor, $clients->modelKeys());
        $clients = $clients->whereIn('id', $ids)->values();
        $selected = $request->integer('client_id') ? $this->records->client($actor, $request->integer('client_id')) : null;
        $selectedIds = $selected ? [$selected->id] : $ids;
        $canAccess = $actor->canDo('medications.external.manage');
        $canRevokeIdentity = $this->external->canRevokeIdentity($actor);
        $canOrders = $actor->canDo('medications.orders.manage');
        $canTransfer = $actor->canDo('medications.transfers.manage');
        $grantPage = $canAccess ? MedicationExternalGrant::with(['clinician.user', 'client'])->whereIn('client_id', $selectedIds)->orderByDesc('id')->paginate(100, ['*'], 'grants_page') : null;
        $grants = $grantPage?->getCollection() ?? collect();
        $profilePage = $canAccess ? MedicationExternalClinician::with('user')
            ->when(! $canRevokeIdentity, fn ($q) => $q->where(fn ($q) => $q->where('verified_by', $actor->id)
                ->orWhereHas('grants', fn ($g) => $g->whereIn('client_id', $selectedIds))))
            ->orderByDesc('id')->paginate(100, ['*'], 'clinicians_page') : null;
        $profiles = $profilePage?->getCollection() ?? collect();
        $proposalPage = $canOrders ? MedicationExternalProposal::with(['clinician.user', 'client'])->whereIn('client_id', $selectedIds)
            ->when(! $actor->canDo('medications.controlled.view'), fn ($q) => $q->where('controlled', false))->orderByDesc('id')->paginate(100, ['*'], 'proposals_page') : null;
        $proposals = $proposalPage?->getCollection() ?? collect();
        $transferPage = $canTransfer ? MedicationProviderTransfer::with('client')->whereIn('client_id', $selectedIds)
            ->whereIn('site_id', $sites)->when(! $actor->canDo('medications.controlled.view'), fn ($q) => $q->where('controlled', false))->orderByDesc('id')->paginate(100, ['*'], 'transfers_page') : null;
        $transfers = $transferPage?->getCollection() ?? collect();

        return Inertia::render('emar/ConnectedCare', [
            'clients' => $clients->map(fn ($c) => ['id' => $c->id, 'name' => $c->full_name])->all(),
            'selected_client' => $selected ? $this->person($selected) : null,
            'clinicians' => $profiles->map(fn ($p) => ['id' => $p->id, 'user_id' => $p->user_id, 'name' => $p->user->name, 'email' => $p->user->email,
                'provider_name' => $p->provider_name, 'registration_authority' => $p->registration_authority, 'registration_number' => $p->registration_number,
                'identity_verified_at' => $p->identity_verified_at?->toIso8601String(), 'expires_at' => $p->expires_at?->toIso8601String(), 'revoked_at' => $p->revoked_at?->toIso8601String()])->all(),
            'grants' => $grants->map(fn ($g) => ['id' => $g->id, 'clinician_id' => $g->clinician_id, 'clinician_name' => $g->clinician->user->name,
                'client_id' => $g->client_id, 'site_id' => $g->site_id, 'purpose' => $g->purpose, 'can_propose' => $g->can_propose,
                'include_controlled' => $g->include_controlled, 'expires_at' => $g->expires_at?->toIso8601String(), 'revoked_at' => $g->revoked_at?->toIso8601String(),
                'active' => $this->grantAvailability($g) === 'ready', 'availability' => $this->grantAvailability($g)])->all(),
            'proposals' => $proposals->map(fn ($p) => $this->proposal($p))->all(),
            'transfers' => $transfers->map(fn ($t) => $this->transfer($t))->all(),
            'can' => ['manage_access' => $canAccess, 'revoke_identity' => $canRevokeIdentity, 'manage_orders' => $canOrders, 'transfer' => $canTransfer, 'export' => $actor->canDo('medications.reports.export')],
            'witnesses' => $canOrders ? $this->scope->prescriptionWitnessStaffPicker($sites, $actor->id)->all() : [],
            'pagination' => ['clinicians' => $this->pageMeta($profilePage), 'grants' => $this->pageMeta($grantPage),
                'proposals' => $this->pageMeta($proposalPage), 'transfers' => $this->pageMeta($transferPage)],
        ]);
    }

    public function portal(Request $request)
    {
        return $this->external->page($request->user(), function (User $actor, MedicationExternalClinician $profile, $grants) use ($request) {
            $selected = null;
            if ($request->integer('client_id')) {
                $grant = $grants->firstWhere('client_id', $request->integer('client_id'));
                abort_unless($grant !== null, 404);
                $client = $grant->client;
                $meds = CurrentAuthorizationReads::within(fn (CurrentAuthorizationReads $reads) => $reads->query(
                    ClientMedication::query()->current()->where('client_id', $client->id)->where('state', '!=', 'ceased')
                        ->when(! $grant->include_controlled, fn ($q) => $q->where('controlled_drug', false))->orderBy('name')
                )->get());
                $selected = [...$this->person($client), 'medications' => $meds->map(fn ($m) => [
                    ...ExternalClinicalPrescription::normalise($this->orders->payload($m)), 'id' => $m->id,
                    'version' => $m->version, 'state' => $m->state, 'approval_status' => $m->approval_status,
                ])->all(), 'allergies' => CurrentAuthorizationReads::within(fn (CurrentAuthorizationReads $reads) => array_map(
                    fn (array $entry) => array_intersect_key($entry, array_flip(['allergen', 'reaction', 'severity'])),
                    app(ClientAllergyRecordService::class)->forClient($client, $reads)
                ))];
            }
            $proposalPage = CurrentAuthorizationReads::within(fn (CurrentAuthorizationReads $reads) => $reads->query(
                MedicationExternalProposal::query()->where('clinician_id', $profile->id)
                    ->whereIn('client_id', $grants->pluck('client_id'))->where(fn ($q) => $q->where('controlled', false)
                    ->orWhereIn('client_id', $grants->where('include_controlled', true)->pluck('client_id')))
                    ->orderByDesc('id')
            )->paginate(100, ['*'], 'proposals_page'));
            $proposals = $proposalPage->getCollection();
            foreach ($proposals as $proposal) {
                $proposal->setRelation('clinician', $profile);
                $proposal->setRelation('client', $grants->firstWhere('client_id', $proposal->client_id)->client);
            }

            return Inertia::render('emar/ClinicalPortal', [
                'clinician' => ['name' => $actor->name, 'provider_name' => $profile->provider_name,
                    'registration_authority' => $profile->registration_authority, 'registration_number' => $profile->registration_number,
                    'expires_at' => $profile->expires_at?->toIso8601String()],
                'people' => $grants->map(fn ($g) => [...$this->person($g->client), 'expires_at' => $g->expires_at?->toIso8601String(),
                    'can_propose' => $g->can_propose, 'include_controlled' => $g->include_controlled])->all(),
                'selected_client' => $selected, 'proposals' => $proposals->map(fn ($p) => $this->proposal($p))->all(),
                'pagination' => ['proposals' => $this->pageMeta($proposalPage)],
            ]);
        });
    }

    public function provision(Request $r)
    {
        return $this->mutation($r, $this->external->provision($r->user(), $r->all()), 'Dedicated account created. The clinician can request a password reset, verify their email and set up two-factor authentication.');
    }

    public function grant(Request $r)
    {
        return $this->mutation($r, $this->external->grant($r->user(), $r->all()), 'Named-person clinical access granted.');
    }

    public function revokeIdentity(Request $r, int $clinician)
    {
        $this->external->revokeIdentity($r->user(), $clinician, $r->all());

        return $this->mutation($r, ['id' => $clinician], 'Clinical identity access revoked.');
    }

    public function revokeGrant(Request $r, int $grant)
    {
        $this->external->revokeGrant($r->user(), $grant, $r->all());

        return $this->mutation($r, ['id' => $grant], 'Clinical access revoked.');
    }

    public function submit(Request $r, int $client)
    {
        return $this->mutation($r, $this->proposals->submit($r->user(), $client, $r->except('source_file'), $r->file('source_file')), 'Proposal sent for internal clinical review. The medicine chart has not changed.');
    }

    public function decide(Request $r, int $proposal)
    {
        return $this->mutation($r, $this->proposals->decide($r->user(), $proposal, $r->except('source_file'), $r->file('source_file')), 'Proposal decision recorded. Check pending orders in Orders.');
    }

    public function createTransfer(Request $r)
    {
        return $this->mutation($r, $this->transfers->create($r->user(), $r->all()), 'Handover source recorded for review.');
    }

    public function transition(Request $r, int $transfer)
    {
        return $this->mutation($r, $this->transfers->transition($r->user(), $transfer, $r->all()), 'Handover action recorded.');
    }

    public function source(Request $r, int $proposal)
    {
        $record = MedicationExternalProposal::query()->findOrFail($proposal);
        $this->records->client($r->user(), $record->client_id);
        $this->orders->assertControlled($r->user(), (bool) $record->controlled);
        $file = $this->proposals->source($record);

        return response()->download($file->getRealPath(), $record->source_file_name, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function portalSource(Request $r, int $proposal)
    {
        $record = MedicationExternalProposal::query()->findOrFail($proposal);

        return $this->external->external($r->user(), $record->client_id, function (Client $client, User $locked, MedicationExternalClinician $profile, MedicationExternalGrant $grant) use ($record) {
            abort_unless((int) $record->clinician_id === (int) $profile->id && (! $record->controlled || $grant->include_controlled), 404);
            $file = $this->proposals->source($record);

            return response()->download($file->getRealPath(), $record->source_file_name, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
        });
    }

    public function packet(Request $r, int $transfer)
    {
        $record = $this->transfers->reviewed($r->user(), $transfer);

        return response()->json($this->transfers->packet($record))->header('Content-Disposition', 'attachment; filename="medication-handover-'.$record->id.'-v'.$record->version.'.json"')
            ->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff');
    }

    public function handover(Request $r, int $transfer)
    {
        $record = $this->transfers->reviewed($r->user(), $transfer);

        return response()->json($this->transfers->packet($record))->header('Cache-Control', 'private, no-store');
    }

    private function mutation(Request $request, $record, string $message)
    {
        $data = is_array($record) ? $record : ['id' => $record->id, 'revision_id' => $record->revision_id, 'reconciliation_id' => $record->reconciliation_id];

        return $request->expectsJson()
            ? response()->json(['success' => true, 'message' => $message, ...$data])
            : back()->with('success', $message);
    }

    private function grantAvailability(MedicationExternalGrant $grant): string
    {
        if ($grant->revoked_at !== null) {
            return 'revoked';
        }
        if (! $grant->expires_at?->isFuture()) {
            return 'expired';
        }
        if ($grant->client === null || (int) $grant->client->site_id !== (int) $grant->site_id) {
            return 'site_changed';
        }
        if (! $grant->clinician->active()) {
            return 'identity_unavailable';
        }
        $account = $grant->clinician->user;
        if ($account->approved_at === null) {
            return 'account_unavailable';
        }
        if (! $account->hasVerifiedEmail() || ! $account->two_factor_confirmed_at || blank($account->two_factor_secret)) {
            return 'account_setup_required';
        }

        return 'ready';
    }

    private function pageMeta($page): array
    {
        return $page ? ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()]
            : ['current_page' => 1, 'last_page' => 1, 'total' => 0];
    }

    private function person(Client $client): array
    {
        return ['id' => $client->id, 'name' => $client->full_name, 'date_of_birth' => $client->date_of_birth?->toDateString()];
    }

    private function proposal(MedicationExternalProposal $p): array
    {
        return ['id' => $p->id, 'client_id' => $p->client_id, 'client_name' => $p->client->full_name, 'clinician_name' => $p->clinician->user->name,
            'kind' => $p->kind, 'medication_id' => $p->medication_id, 'expected_version' => $p->expected_version,
            'prescription' => $p->prescription, 'reason' => $p->reason, 'status' => $p->status, 'submitted_at' => $p->submitted_at?->toIso8601String(),
            'revision_id' => $p->revision_id, 'decision_note' => $p->decision_note, 'has_source_file' => $p->source_file_path !== null];
    }

    private function transfer(MedicationProviderTransfer $t): array
    {
        return ['id' => $t->id, 'direction' => $t->direction, 'client_id' => $t->client_id, 'client_name' => $t->client->full_name,
            'provider_name' => $t->provider_name, 'recipient_name' => $t->recipient_name, 'purpose' => $t->purpose, 'disclosure_basis' => $t->disclosure_basis,
            'status' => $t->status, 'version' => $t->version, 'identity_evidence' => $t->identity_evidence,
            'allowed_actions' => match ($t->status) {
                'draft' => ['review', 'cancel'], 'reviewed' => ['receipt', 'cancel'],
                'received' => $t->direction === 'incoming' ? ['start_reconciliation', 'cancel'] : [],
                'reconciliation_started' => ['complete'], default => [],
            },
            'reviewed_at' => $t->reviewed_at?->toIso8601String(), 'received_at' => $t->received_at?->toIso8601String(),
            'reconciliation_id' => $t->reconciliation_id, 'snapshot_sha256' => $t->snapshot_sha256, 'snapshot' => $t->snapshot];
    }
}
