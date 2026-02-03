<?php

namespace App\Http\Controllers\Respite;

use App\Http\Controllers\Controller;
use App\Models\RespiteRiskPlanActivation;
use App\Models\RespiteStay;
use App\Models\ClientRisk;
use App\Models\RespiteAuditLog;
use App\Events\Respite\RespiteEvent;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class RespiteRiskPlanActivationController extends Controller
{
    public function index(Request $request): Response
    {
        $activations = RespiteRiskPlanActivation::query()
            ->with(['stay.client', 'client', 'reviewedBy'])
            ->when($request->stay_id, fn ($q, $stayId) => $q->forStay($stayId))
            ->when($request->client_id, fn ($q, $clientId) => $q->forClient($clientId))
            ->when($request->plan_type, fn ($q, $type) => $q->byType($type))
            ->when($request->status, fn ($q, $status) => $q->where('status', $status))
            ->when($request->active, fn ($q) => $q->active())
            ->when($request->pending_review, fn ($q) => $q->pendingReview())
            ->when($request->needs_acknowledgment, fn ($q) => $q->needingAcknowledgment())
            ->orderByDesc('created_at')
            ->paginate(20);

        return Inertia::render('respite/risk-plan-activations/index', [
            'activations' => $activations,
            'filters' => $request->only(['stay_id', 'client_id', 'plan_type', 'status', 'active', 'pending_review', 'needs_acknowledgment']),
            'planTypes' => $this->getPlanTypes(),
            'statuses' => $this->getStatuses(),
        ]);
    }

    public function create(Request $request): Response
    {
        $stays = RespiteStay::query()
            ->with('client')
            ->whereIn('status', ['admitted', 'active', 'extended'])
            ->orderByDesc('created_at')
            ->get();

        $clientRisks = [];
        if ($request->client_id) {
            $clientRisks = ClientRisk::where('client_id', $request->client_id)
                ->where('status', 'active')
                ->get();
        }

        return Inertia::render('respite/risk-plan-activations/create', [
            'stays' => $stays,
            'stayId' => $request->stay_id,
            'clientId' => $request->client_id,
            'clientRisks' => $clientRisks,
            'planTypes' => $this->getPlanTypes(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'stay_id' => 'required|exists:respite_stays,id',
            'client_id' => 'required|exists:clients,id',
            'risk_assessment_id' => 'nullable|exists:client_risks,id',
            'plan_type' => 'required|in:behaviour,safety,medical,mobility,communication',
            'plan_name' => 'required|string|max:255',
            'plan_details' => 'nullable|array',
            'triggers' => 'nullable|array',
            'interventions' => 'nullable|array',
            'escalation_steps' => 'nullable|array',
        ]);

        $validated['status'] = RespiteRiskPlanActivation::STATUS_PENDING_REVIEW;
        $validated['staff_acknowledgments'] = [];
        $validated['created_by'] = auth()->id();

        $activation = RespiteRiskPlanActivation::create($validated);

        RespiteAuditLog::log(
            $activation,
            RespiteAuditLog::ACTION_CREATED,
            auth()->id(),
            null,
            ['plan_type' => $validated['plan_type'], 'plan_name' => $validated['plan_name']],
            null,
            RespiteAuditLog::CATEGORY_STAY
        );

        event(new RespiteEvent('respite.risk_plan.created', [
            'id' => $activation->id,
            'stay_id' => $activation->stay_id,
            'client_id' => $activation->client_id,
            'plan_type' => $activation->plan_type,
        ]));

        return redirect()
            ->route('respite.risk-plan-activations.show', $activation)
            ->with('success', 'Risk plan activation created.');
    }

    public function show(RespiteRiskPlanActivation $riskPlanActivation): Response
    {
        $riskPlanActivation->load(['stay.client', 'client', 'riskAssessment', 'reviewedBy', 'creator']);

        RespiteAuditLog::log(
            $riskPlanActivation,
            RespiteAuditLog::ACTION_VIEWED,
            auth()->id(),
            null,
            null,
            null,
            RespiteAuditLog::CATEGORY_STAY
        );

        return Inertia::render('respite/risk-plan-activations/show', [
            'activation' => $riskPlanActivation,
            'hasAcknowledged' => $riskPlanActivation->hasStaffAcknowledged(auth()->id()),
        ]);
    }

    public function update(Request $request, RespiteRiskPlanActivation $riskPlanActivation): RedirectResponse
    {
        $oldValues = $riskPlanActivation->only(['plan_details', 'triggers', 'interventions', 'escalation_steps']);

        $validated = $request->validate([
            'plan_name' => 'sometimes|string|max:255',
            'plan_details' => 'nullable|array',
            'triggers' => 'nullable|array',
            'interventions' => 'nullable|array',
            'escalation_steps' => 'nullable|array',
        ]);

        $validated['status'] = RespiteRiskPlanActivation::STATUS_MODIFIED;
        $validated['updated_by'] = auth()->id();
        $riskPlanActivation->update($validated);

        RespiteAuditLog::log(
            $riskPlanActivation,
            RespiteAuditLog::ACTION_UPDATED,
            auth()->id(),
            $oldValues,
            array_intersect_key($validated, $oldValues),
            null,
            RespiteAuditLog::CATEGORY_STAY
        );

        event(new RespiteEvent('respite.risk_plan.updated', [
            'id' => $riskPlanActivation->id,
            'stay_id' => $riskPlanActivation->stay_id,
        ]));

        return back()->with('success', 'Risk plan activation updated.');
    }

    public function review(Request $request, RespiteRiskPlanActivation $riskPlanActivation): RedirectResponse
    {
        $validated = $request->validate([
            'review_notes' => 'nullable|string|max:2000',
        ]);

        $riskPlanActivation->markReviewed(auth()->id(), $validated['review_notes'] ?? null);

        RespiteAuditLog::log(
            $riskPlanActivation,
            'reviewed',
            auth()->id(),
            ['status' => $riskPlanActivation->getOriginal('status')],
            ['reviewed_by_user_id' => auth()->id()],
            $validated['review_notes'] ?? null,
            RespiteAuditLog::CATEGORY_STAY
        );

        return back()->with('success', 'Risk plan reviewed.');
    }

    public function activate(RespiteRiskPlanActivation $riskPlanActivation): RedirectResponse
    {
        if ($riskPlanActivation->isActive()) {
            return back()->with('error', 'Risk plan is already active.');
        }

        $riskPlanActivation->activate();

        RespiteAuditLog::log(
            $riskPlanActivation,
            RespiteAuditLog::ACTION_STATUS_CHANGED,
            auth()->id(),
            ['status' => $riskPlanActivation->getOriginal('status')],
            ['status' => RespiteRiskPlanActivation::STATUS_ACTIVE],
            null,
            RespiteAuditLog::CATEGORY_STAY
        );

        event(new RespiteEvent('respite.risk_plan.activated', [
            'id' => $riskPlanActivation->id,
            'stay_id' => $riskPlanActivation->stay_id,
            'client_id' => $riskPlanActivation->client_id,
            'plan_type' => $riskPlanActivation->plan_type,
        ]));

        return back()->with('success', 'Risk plan activated.');
    }

    public function deactivate(Request $request, RespiteRiskPlanActivation $riskPlanActivation): RedirectResponse
    {
        $validated = $request->validate([
            'deactivation_reason' => 'required|string|max:500',
        ]);

        $riskPlanActivation->deactivate($validated['deactivation_reason']);

        RespiteAuditLog::log(
            $riskPlanActivation,
            RespiteAuditLog::ACTION_STATUS_CHANGED,
            auth()->id(),
            ['status' => $riskPlanActivation->getOriginal('status')],
            ['status' => RespiteRiskPlanActivation::STATUS_COMPLETED],
            $validated['deactivation_reason'],
            RespiteAuditLog::CATEGORY_STAY
        );

        event(new RespiteEvent('respite.risk_plan.deactivated', [
            'id' => $riskPlanActivation->id,
            'stay_id' => $riskPlanActivation->stay_id,
        ]));

        return back()->with('success', 'Risk plan deactivated.');
    }

    public function suspend(Request $request, RespiteRiskPlanActivation $riskPlanActivation): RedirectResponse
    {
        $validated = $request->validate([
            'suspension_reason' => 'required|string|max:500',
        ]);

        $riskPlanActivation->suspend($validated['suspension_reason']);

        RespiteAuditLog::log(
            $riskPlanActivation,
            RespiteAuditLog::ACTION_STATUS_CHANGED,
            auth()->id(),
            ['status' => $riskPlanActivation->getOriginal('status')],
            ['status' => RespiteRiskPlanActivation::STATUS_SUSPENDED],
            $validated['suspension_reason'],
            RespiteAuditLog::CATEGORY_STAY
        );

        return back()->with('success', 'Risk plan suspended.');
    }

    public function acknowledge(RespiteRiskPlanActivation $riskPlanActivation): RedirectResponse
    {
        if ($riskPlanActivation->hasStaffAcknowledged(auth()->id())) {
            return back()->with('error', 'You have already acknowledged this risk plan.');
        }

        $user = auth()->user();
        $riskPlanActivation->addStaffAcknowledgment(auth()->id(), $user->name);

        RespiteAuditLog::log(
            $riskPlanActivation,
            'acknowledged',
            auth()->id(),
            null,
            ['acknowledgment_count' => $riskPlanActivation->getAcknowledgmentCount()],
            null,
            RespiteAuditLog::CATEGORY_STAY
        );

        event(new RespiteEvent('respite.risk_plan.acknowledged', [
            'id' => $riskPlanActivation->id,
            'acknowledged_by' => auth()->id(),
        ]));

        return back()->with('success', 'Risk plan acknowledged.');
    }

    public function forStay(RespiteStay $stay): Response
    {
        $activations = RespiteRiskPlanActivation::query()
            ->forStay($stay->id)
            ->with(['reviewedBy'])
            ->orderByDesc('created_at')
            ->get();

        return Inertia::render('respite/risk-plan-activations/for-stay', [
            'stay' => $stay->load('client'),
            'activations' => $activations,
            'planTypes' => $this->getPlanTypes(),
        ]);
    }

    public function forClient(int $clientId): Response
    {
        $activations = RespiteRiskPlanActivation::query()
            ->forClient($clientId)
            ->with(['stay', 'reviewedBy'])
            ->orderByDesc('created_at')
            ->paginate(20);

        return Inertia::render('respite/risk-plan-activations/for-client', [
            'clientId' => $clientId,
            'activations' => $activations,
            'planTypes' => $this->getPlanTypes(),
        ]);
    }

    public function needingAcknowledgment(): Response
    {
        $activations = RespiteRiskPlanActivation::query()
            ->needingAcknowledgment()
            ->with(['stay.client'])
            ->orderByDesc('activated_at')
            ->paginate(20);

        return Inertia::render('respite/risk-plan-activations/needing-acknowledgment', [
            'activations' => $activations,
        ]);
    }

    protected function getPlanTypes(): array
    {
        return [
            'behaviour' => 'Behaviour Support Plan',
            'safety' => 'Safety Plan',
            'medical' => 'Medical Management Plan',
            'mobility' => 'Mobility Support Plan',
            'communication' => 'Communication Plan',
        ];
    }

    protected function getStatuses(): array
    {
        return [
            'pending_review' => 'Pending Review',
            'active' => 'Active',
            'modified' => 'Modified',
            'suspended' => 'Suspended',
            'completed' => 'Completed',
        ];
    }
}
