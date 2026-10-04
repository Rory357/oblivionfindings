<?php

namespace App\Http\Controllers\Emar;

use App\Http\Controllers\Controller;
use App\Models\MedicationFollowup;
use App\Services\Medication\Followups\MedicationFollowupService;
use App\Services\Medication\MedicationRecordAccess;
use Illuminate\Http\Request;
use Inertia\Inertia;

final class MedicationFollowupController extends Controller
{
    public function __construct(private readonly MedicationFollowupService $work) {}

    public function index(Request $request)
    {
        $data = $request->validate([
            'client_id' => ['nullable', 'integer', 'min:1'], 'site_id' => ['nullable', 'integer', 'min:1'],
            'administration' => ['nullable', 'integer', 'min:1'],
            'type' => ['nullable', 'in:'.implode(',', array_keys(MedicationFollowupService::TYPES))],
            'state' => ['nullable', 'in:open,overdue,lead,unscheduled,done'], 'q' => ['nullable', 'string', 'max:100'],
        ]);
        $actor = $request->user();
        if (! empty($data['client_id'])) {
            app(MedicationRecordAccess::class)->client($actor, (int) $data['client_id']);
        }
        $scope = $this->work->visibleQuery($actor)
            ->when($data['client_id'] ?? null, fn ($q, $id) => $q->where('client_id', $id))
            ->when($data['site_id'] ?? null, fn ($q, $id) => $q->whereHas('client', fn ($c) => $c->where('site_id', $id)))
            ->when($data['type'] ?? null, fn ($q, $type) => $q->where('type', $type))
            ->when($data['q'] ?? null, fn ($q, $term) => $q->where(fn ($q) => $q
                ->whereHas('client', fn ($c) => $c->where('first_name', 'like', '%'.$term.'%')->orWhere('last_name', 'like', '%'.$term.'%'))
                ->orWhereHas('medication', fn ($m) => $m->where('name', 'like', '%'.$term.'%'))));
        $meters = [
            'open' => (clone $scope)->whereNull('completed_at')->count(),
            'overdue' => (clone $scope)->whereNull('completed_at')->where('due_at', '<', now('UTC'))->count(),
            'lead' => (clone $scope)->whereNull('completed_at')->whereIn('type', MedicationFollowupService::LEAD_TYPES)->count(),
            'unscheduled' => (clone $scope)->whereNull('completed_at')->whereNull('due_at')->count(),
        ];
        $state = $data['state'] ?? 'open';
        $query = clone $scope;
        if ($state === 'done') {
            $query->whereNotNull('completed_at');
        } else {
            $query->whereNull('completed_at');
        }
        if ($state === 'overdue') {
            $query->where('due_at', '<', now('UTC'));
        }
        if ($state === 'lead') {
            $query->whereIn('type', MedicationFollowupService::LEAD_TYPES);
        }
        if ($state === 'unscheduled') {
            $query->whereNull('due_at');
        }
        $rows = $query->with(['client.site', 'medication', 'owner', 'originalOwner'])
            ->orderByRaw('due_at IS NULL')->orderBy('due_at')->orderBy('id')
            ->paginate(25)->withQueryString()->through(fn (MedicationFollowup $row) => $this->work->present($row, $actor));
        // A neutral caption only: searching a concealed medicine must not expose
        // whether its name matched. Person access precedes even this count.
        $hiddenControlled = $actor->canDo('medications.controlled.view') ? 0
            : $this->work->visibleQuery($actor, includeControlled: true)
                ->when($data['client_id'] ?? null, fn ($q, $id) => $q->where('client_id', $id))
                ->when($data['site_id'] ?? null, fn ($q, $id) => $q->whereHas('client', fn ($c) => $c->where('site_id', $id)))
                ->whereNull('completed_at')->whereHas('medication', fn ($m) => $m->where('controlled_drug', true))->count();
        // An explicit dose link resolves independently of list pagination and
        // filters, through the same authorized scope as the detail GET.
        $selectedFollowupId = empty($data['administration']) ? null
            : $this->work->visibleQuery($actor)->where('type', 'effect')
                ->where('administration_id', (int) $data['administration'])->orderBy('id')->value('id');
        $payload = ['followups' => $rows, 'meters' => $meters, 'filters' => $data, 'hidden_controlled' => $hiddenControlled,
            'legacy_effect_checks' => $this->work->legacyEffectChecks($actor, $data),
            'selected_followup_id' => $selectedFollowupId === null ? null : (int) $selectedFollowupId,
            'can_manage' => $actor->canDo(MedicationFollowupService::MANAGE), 'types' => MedicationFollowupService::TYPES];
        if ($request->expectsJson()) {
            return response()->json($payload)->header('Cache-Control', 'private, no-store');
        }

        return Inertia::render('emar/Followups', $payload);
    }

    public function show(Request $request, int $followup)
    {
        return response()->json($this->work->details($request->user(), $followup))->header('Cache-Control', 'private, no-store');
    }

    public function prepare(Request $request, int $administration)
    {
        $data = $request->validate(['type' => ['nullable', 'in:effect,reoffer']]);
        $row = $this->work->prepareAdministration($request->user(), $administration, $data['type'] ?? 'effect');

        return response()->json($this->work->details($request->user(), $row->id))->header('Cache-Control', 'private, no-store');
    }

    public function transition(Request $request, int $followup)
    {
        $data = $request->validate([
            'request_uuid' => ['required', 'uuid'], 'revision' => ['required', 'integer', 'min:1'],
            'action' => ['required', 'in:effect,amend_effect,refusal,couldnt_check,reassign,confirmation,signoff,complete'],
            'outcome' => ['nullable', 'string', 'max:2000'], 'reason' => ['nullable', 'string', 'max:2000'],
            'again_at' => ['nullable', 'string', 'max:40'], 'owner_id' => ['nullable', 'integer', 'min:1'],
            'observations' => ['nullable', 'string', 'max:2000'], 'escalation_needed' => ['nullable', 'boolean'],
            'review_minutes_after' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'told' => ['nullable', 'string', 'max:255'], 'escalation_action' => ['nullable', 'string', 'max:2000'],
            'reason_category' => ['nullable', 'string', 'max:50'], 'capacity' => ['nullable', 'string', 'max:50'],
            'offered_alternative' => ['nullable', 'boolean'], 'alternative_details' => ['nullable', 'string', 'max:2000'],
            'gp_told' => ['nullable', 'boolean'], 'gp_response' => ['nullable', 'string', 'max:2000'],
            'family_told' => ['nullable', 'boolean'], 'family_details' => ['nullable', 'string', 'max:2000'],
            'next_action' => ['nullable', 'string', 'max:2000'],
            'written_confirmation_reference' => ['nullable', 'string', 'max:2000'],
        ]);
        $result = $this->work->transition($request->user(), $followup, $data);

        return response()->json($result + ['sync' => [
            'status' => ! empty($result['duplicate']) ? 'duplicate' : 'processed',
            'message' => 'Medication follow-up saved.',
        ]])->header('Cache-Control', 'private, no-store');
    }
}
