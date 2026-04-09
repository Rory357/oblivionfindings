<?php

namespace App\Http\Controllers\HealthSafety;

use App\Http\Controllers\Controller;
use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\HsInvestigation;
use App\Models\HsRiskAssessment;
use Illuminate\Http\Request;
use Inertia\Inertia;

class HsEventController extends Controller
{
    /**
     * H&S Events listing with filters.
     */
    public function index(Request $request): \Inertia\Response
    {
        $query = HsEvent::query()
            ->with(['site:id,name', 'client:id,first_name,last_name', 'staff:id,name'])
            ->orderByDesc('reported_at');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('severity')) {
            $query->where('severity', $request->input('severity'));
        }

        if ($request->filled('category')) {
            $query->where('event_category', $request->input('category'));
        }

        if ($request->filled('site_id')) {
            $query->where('site_id', $request->input('site_id'));
        }

        $events = $query->paginate(25)->through(fn (HsEvent $e) => [
            'id' => $e->id,
            'reference_number' => $e->reference_number,
            'event_category' => $e->event_category,
            'severity' => $e->severity,
            'status' => $e->status,
            'occurred_at' => $e->occurred_at?->toIso8601String(),
            'reported_at' => $e->reported_at?->toIso8601String(),
            'site_name' => $e->site?->name,
            'client_name' => $e->client ? trim($e->client->first_name . ' ' . $e->client->last_name) : null,
            'staff_name' => $e->staff?->name,
            'worksafe_notifiable' => $e->worksafe_notifiable,
            'investigation_required' => $e->investigation_required,
            'has_investigation' => $e->latestInvestigation()->exists(),
            'has_open_actions' => $e->openCorrectiveActions()->exists(),
        ]);

        return Inertia::render('health-safety/events/index', [
            'events' => $events,
            'filters' => $request->only(['status', 'severity', 'category', 'site_id']),
        ]);
    }

    /**
     * H&S Event detail with investigation, corrective actions, and risk assessments.
     */
    public function show(HsEvent $hsEvent): \Inertia\Response
    {
        $hsEvent->load([
            'site:id,name',
            'client:id,first_name,last_name',
            'staff:id,name,email',
            'asset:id,name,asset_tag',
            'shift:id,starts_at,ends_at',
            'controlRoomAlert:id,severity,status,triggered_at',
            'creator:id,name',
        ]);

        $investigations = HsInvestigation::where('hs_event_id', $hsEvent->id)
            ->with(['leadInvestigator:id,name', 'reviewedBy:id,name', 'approvedBy:id,name'])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (HsInvestigation $inv) => [
                'id' => $inv->id,
                'reference_number' => $inv->reference_number,
                'investigation_type' => $inv->investigation_type,
                'status' => $inv->status,
                'methodology' => $inv->methodology,
                'lead_investigator_name' => $inv->leadInvestigator?->name,
                'started_at' => $inv->started_at?->toIso8601String(),
                'target_completion_date' => $inv->target_completion_date?->toDateString(),
                'completed_at' => $inv->completed_at?->toIso8601String(),
                'is_overdue' => $inv->isOverdue(),
                'has_findings' => $inv->hasFindings(),
                'has_recommendations' => $inv->hasRecommendations(),
                'recommendation_count' => count($inv->recommendations ?? []),
                'immediate_causes' => $inv->immediate_causes,
                'root_causes' => $inv->root_causes,
                'contributing_factors' => $inv->contributing_factors,
                'findings_summary' => $inv->findings_summary,
                'recommendations' => $inv->recommendations,
                'lessons_learned' => $inv->lessons_learned,
            ]);

        $correctiveActions = HsCorrectiveAction::where('hs_event_id', $hsEvent->id)
            ->with(['assignedTo:id,name', 'completedBy:id,name', 'verifiedBy:id,name'])
            ->orderBy('priority')
            ->orderBy('due_date')
            ->get()
            ->map(fn (HsCorrectiveAction $a) => [
                'id' => $a->id,
                'reference_number' => $a->reference_number,
                'title' => $a->title,
                'action_type' => $a->action_type,
                'priority' => $a->priority,
                'status' => $a->status,
                'assigned_to_name' => $a->assignedTo?->name,
                'due_date' => $a->due_date?->toDateString(),
                'is_overdue' => $a->isOverdue(),
                'completed_at' => $a->completed_at?->toIso8601String(),
                'verified_at' => $a->verified_at?->toIso8601String(),
                'effectiveness_confirmed' => $a->effectiveness_confirmed,
            ]);

        $riskAssessments = HsRiskAssessment::where('hs_event_id', $hsEvent->id)
            ->with(['assessedBy:id,name'])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (HsRiskAssessment $ra) => [
                'id' => $ra->id,
                'reference_number' => $ra->reference_number,
                'title' => $ra->title,
                'status' => $ra->status,
                'risk_score' => $ra->risk_score,
                'risk_level' => $ra->risk_level,
                'residual_risk_score' => $ra->residual_risk_score,
                'residual_risk_level' => $ra->residual_risk_level,
                'risk_acceptable' => $ra->risk_acceptable,
                'assessed_by_name' => $ra->assessedBy?->name,
                'review_due_at' => $ra->review_due_at?->toDateString(),
                'is_due_for_review' => $ra->isDueForReview(),
            ]);

        return Inertia::render('health-safety/events/show', [
            'event' => [
                'id' => $hsEvent->id,
                'reference_number' => $hsEvent->reference_number,
                'event_category' => $hsEvent->event_category,
                'severity' => $hsEvent->severity,
                'status' => $hsEvent->status,
                'occurred_at' => $hsEvent->occurred_at?->toIso8601String(),
                'reported_at' => $hsEvent->reported_at?->toIso8601String(),
                'site_name' => $hsEvent->site?->name,
                'site_id' => $hsEvent->site_id,
                'client_name' => $hsEvent->client ? trim($hsEvent->client->first_name . ' ' . $hsEvent->client->last_name) : null,
                'client_id' => $hsEvent->client_id,
                'staff_name' => $hsEvent->staff?->name,
                'staff_id' => $hsEvent->staff_id,
                'asset_name' => $hsEvent->asset?->name,
                'asset_id' => $hsEvent->asset_id,
                'shift_id' => $hsEvent->shift_id,
                'worksafe_notifiable' => $hsEvent->worksafe_notifiable,
                'worksafe_status' => $hsEvent->worksafe_status,
                'worksafe_reference' => $hsEvent->worksafe_reference,
                'investigation_required' => $hsEvent->investigation_required,
                'control_room_alert' => $hsEvent->controlRoomAlert ? [
                    'id' => $hsEvent->controlRoomAlert->id,
                    'severity' => $hsEvent->controlRoomAlert->severity,
                    'status' => $hsEvent->controlRoomAlert->status,
                ] : null,
                'closed_at' => $hsEvent->closed_at?->toIso8601String(),
                'closure_summary' => $hsEvent->closure_summary,
                'created_by_name' => $hsEvent->creator?->name,
                'source_type' => class_basename($hsEvent->source_type),
                'source_id' => $hsEvent->source_id,
                'can_create_investigation' => $hsEvent->canCreateInvestigation(),
                'has_open_corrective_actions' => $hsEvent->hasOpenCorrectiveActions(),
                'all_corrective_actions_resolved' => $hsEvent->allCorrectiveActionsResolved(),
            ],
            'investigations' => $investigations,
            'corrective_actions' => $correctiveActions,
            'risk_assessments' => $riskAssessments,
        ]);
    }

    /**
     * Corrective actions listing — all actions across all events.
     */
    public function correctiveActions(Request $request): \Inertia\Response
    {
        $query = HsCorrectiveAction::query()
            ->with([
                'hsEvent:id,reference_number,event_category,severity,site_id',
                'hsEvent.site:id,name',
                'assignedTo:id,name',
            ])
            ->orderByRaw("FIELD(status, 'open', 'in_progress', 'completed', 'verified', 'closed')")
            ->orderBy('due_date');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('priority')) {
            $query->where('priority', $request->input('priority'));
        }

        if ($request->input('overdue') === 'true') {
            $query->overdue();
        }

        if ($request->input('awaiting_verification') === 'true') {
            $query->awaitingVerification();
        }

        $actions = $query->paginate(25)->through(fn (HsCorrectiveAction $a) => [
            'id' => $a->id,
            'reference_number' => $a->reference_number,
            'title' => $a->title,
            'action_type' => $a->action_type,
            'priority' => $a->priority,
            'status' => $a->status,
            'assigned_to_name' => $a->assignedTo?->name,
            'due_date' => $a->due_date?->toDateString(),
            'is_overdue' => $a->isOverdue(),
            'event_reference' => $a->hsEvent?->reference_number,
            'event_category' => $a->hsEvent?->event_category,
            'site_name' => $a->hsEvent?->site?->name,
        ]);

        return Inertia::render('health-safety/corrective-actions/index', [
            'actions' => $actions,
            'filters' => $request->only(['status', 'priority', 'overdue', 'awaiting_verification']),
        ]);
    }

    /**
     * Risk assessments listing.
     */
    public function riskAssessments(Request $request): \Inertia\Response
    {
        $query = HsRiskAssessment::query()
            ->with(['assessedBy:id,name'])
            ->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('risk_level')) {
            $query->where('risk_level', $request->input('risk_level'));
        }

        if ($request->input('due_for_review') === 'true') {
            $query->dueForReview();
        }

        $assessments = $query->paginate(25)->through(fn (HsRiskAssessment $ra) => [
            'id' => $ra->id,
            'reference_number' => $ra->reference_number,
            'title' => $ra->title,
            'status' => $ra->status,
            'risk_score' => $ra->risk_score,
            'risk_level' => $ra->risk_level,
            'residual_risk_level' => $ra->residual_risk_level,
            'risk_acceptable' => $ra->risk_acceptable,
            'assessed_by_name' => $ra->assessedBy?->name,
            'review_due_at' => $ra->review_due_at?->toDateString(),
            'is_due_for_review' => $ra->isDueForReview(),
            'assessable_type' => $ra->assessable_type ? class_basename($ra->assessable_type) : null,
        ]);

        return Inertia::render('health-safety/risk-assessments/index', [
            'assessments' => $assessments,
            'filters' => $request->only(['status', 'risk_level', 'due_for_review']),
        ]);
    }
}
