<?php

namespace App\Domain\Governance\Support;

use App\Domain\Governance\Models\ActionItem;
use App\Domain\Governance\Models\BoardCommittee;
use App\Domain\Governance\Models\ComplianceObligation;
use App\Domain\Governance\Models\GovernanceMeeting;
use App\Domain\Governance\Models\RiskRegisterEntry;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class GovernancePresenter
{
    public function dashboard(array $widgets, array $period, array $freshness, array $workflow, ?User $user = null): array
    {
        $cards = collect([
            $this->meetingReadinessCard(),
            $this->followThroughCard(),
            $this->widgetCard('decisions_required', $widgets['decisions_required'] ?? null, $freshness),
            $this->widgetCard('roadmap', $widgets['roadmap'] ?? null, $freshness),
            $this->widgetCard('top_risks', $widgets['top_risks'] ?? null, $freshness),
            $this->widgetCard('risk_changes', $widgets['risk_changes'] ?? null, $freshness),
            $this->widgetCard('voided_risks', $widgets['voided_risks'] ?? null, $freshness),
            $this->widgetCard('compliance_calendar', $widgets['compliance_calendar'] ?? null, $freshness),
            $this->widgetCard('privacy_data', $widgets['privacy_data'] ?? null, $freshness),
            $this->widgetCard('client_safety', $widgets['client_safety'] ?? null, $freshness),
            $this->widgetCard('operational_safety', $widgets['operational_safety'] ?? null, $freshness),
            $this->widgetCard('workforce', $widgets['workforce'] ?? null, $freshness),
            $this->widgetCard('financial', $widgets['financial'] ?? null, $freshness),
            $this->widgetCard('control_room', $widgets['control_room'] ?? null, $freshness),
            $this->widgetCard('it_cyber', $widgets['it_cyber'] ?? null, $freshness),
            $this->widgetCard('incidents', $widgets['incidents'] ?? null, $freshness),
            $this->widgetCard('safeguarding', $widgets['safeguarding'] ?? null, $freshness),
            $this->widgetCard('fleet_assets', $widgets['fleet_assets'] ?? null, $freshness),
            $this->widgetCard('hs_backbone', $widgets['hs_backbone'] ?? null, $freshness),
        ])->filter()->values();

        $financialWidget = is_array($widgets['financial'] ?? null) ? $widgets['financial'] : [];
        $spendCard = $this->presentSpendApprovalsCard($financialWidget, $freshness);
        $sitesCard = $this->presentSitesOverBudgetCard($financialWidget, $freshness);

        if ($spendCard) {
            $cards->push($spendCard);
        }
        if ($sitesCard) {
            $cards->push($sitesCard);
        }

        $cardsByKey = $cards->keyBy('key');

        return [
            'period_label' => $this->periodLabel($period),
            'sections' => [
                [
                    'key' => 'board_focus',
                    'title' => 'Board Focus',
                    'description' => 'Decisions, meeting readiness, plan delivery, and follow-through.',
                    'cards' => $this->cardsForKeys($cardsByKey, ['meeting_readiness', 'follow_through', 'decisions_required', 'roadmap']),
                ],
                [
                    'key' => 'financial_governance',
                    'title' => 'Financial Governance',
                    'description' => 'Budget posture, sites over budget, pending spend approvals, donor funding.',
                    'cards' => $this->cardsForKeys($cardsByKey, ['financial', 'sites_over_budget', 'spend_approvals']),
                ],
                [
                    'key' => 'assurance',
                    'title' => 'Assurance & Compliance',
                    'description' => 'Risk posture, changes, privacy, and upcoming obligations.',
                    'cards' => $this->cardsForKeys($cardsByKey, ['top_risks', 'risk_changes', 'voided_risks', 'compliance_calendar', 'privacy_data']),
                ],
                [
                    'key' => 'operations',
                    'title' => 'Operations & People',
                    'description' => 'Safety, staffing, and workforce posture.',
                    'cards' => $this->cardsForKeys($cardsByKey, ['client_safety', 'operational_safety', 'workforce']),
                ],
                [
                    'key' => 'controls',
                    'title' => 'Controls & Backbone',
                    'description' => 'Control room, cyber posture, safeguarding, fleet, and H&S.',
                    'cards' => $this->cardsForKeys($cardsByKey, ['control_room', 'it_cyber', 'incidents', 'safeguarding', 'fleet_assets', 'hs_backbone']),
                ],
            ],
            'cards' => $cards->all(),
            'cards_by_key' => $cardsByKey->all(),
            'workflow_summary' => $workflow['summary'] ?? ['total' => 0, 'critical' => 0, 'overdue' => 0],
            'role_actions' => $this->roleActions($user),
        ];
    }

    public function boardMonthly(array $widgets, array $freshness, array $workflow): array
    {
        $dashboard = $this->dashboard($widgets, ['type' => 'month'], $freshness, $workflow);
        $cards = collect($dashboard['cards_by_key']);

        return [
            'headline' => [
                $this->metric('Decisions required', $widgets['decisions_required']['count'] ?? 0, ($widgets['decisions_required']['overdue'] ?? 0) > 0 ? 'critical' : 'default'),
                $this->metric('Overdue actions', $workflow['summary']['overdue'] ?? 0, ($workflow['summary']['overdue'] ?? 0) > 0 ? 'critical' : 'default'),
                $this->metric('Critical risks', $widgets['top_risks']['critical'] ?? 0, ($widgets['top_risks']['critical'] ?? 0) > 0 ? 'critical' : 'default'),
                $this->metric('Budget variance', $this->formatPercent($widgets['financial']['variance'] ?? null), abs((float) ($widgets['financial']['variance'] ?? 0)) >= 5 ? 'warning' : 'default'),
            ],
            'sections' => [
                ['key' => 'board_focus', 'title' => 'Board Focus', 'cards' => $this->cardsForKeys($cards, ['meeting_readiness', 'follow_through', 'decisions_required', 'roadmap'])],
                ['key' => 'assurance', 'title' => 'Assurance', 'cards' => $this->cardsForKeys($cards, ['top_risks', 'risk_changes', 'compliance_calendar', 'privacy_data'])],
                ['key' => 'delivery', 'title' => 'Service Delivery & Controls', 'cards' => $this->cardsForKeys($cards, ['client_safety', 'operational_safety', 'workforce', 'financial', 'control_room', 'it_cyber', 'incidents', 'safeguarding'])],
            ],
        ];
    }

    public function committee(string $committeeType, ?BoardCommittee $committee, Collection $risks, array $widgets): array
    {
        $cards = collect();

        foreach ($widgets as $key => $widget) {
            $card = $this->widgetCard($key, $widget, []);
            if ($card !== null) {
                $cards->push($card);
            }
        }

        return [
            'committee' => [
                'name' => $committee?->name ?? $this->titleize($committeeType),
                'type' => $committeeType,
                'description' => $committee?->description,
            ],
            'headline' => [
                $this->metric('Tracked risks', $risks->count()),
                $this->metric('High / critical', $risks->where('residual_score', '>=', 15)->count(), $risks->where('residual_score', '>=', 20)->count() > 0 ? 'critical' : 'warning'),
            ],
            'sections' => [
                ['key' => 'committee_overview', 'title' => 'Committee Overview', 'cards' => $cards->values()->all()],
            ],
            'risks' => $risks->map(fn (RiskRegisterEntry $risk) => [
                'id' => $risk->id,
                'reference' => $risk->risk_reference,
                'title' => $risk->title,
                'category' => $this->titleize($risk->category),
                'residual_score' => $risk->residual_score,
                'owner' => $risk->riskOwner?->name,
                'within_appetite' => (bool) $risk->within_appetite,
            ])->values()->all(),
        ];
    }

    public function complianceStatus(Collection $obligations, array $summary): array
    {
        $frameworks = $obligations->map(function (Collection $items, string $framework) {
            return [
                'key' => $framework,
                'title' => $items->first()?->getFrameworkLabel() ?? $this->titleize($framework),
                'count' => $items->count(),
                'items' => $items->map(fn (ComplianceObligation $obligation) => [
                    'id' => $obligation->id,
                    'title' => $obligation->obligation_title,
                    'code' => $obligation->obligation_code,
                    'owner' => $obligation->owner?->name,
                    'due_date' => $obligation->due_date?->toDateString(),
                    'status' => $obligation->status,
                    'days_remaining' => $obligation->due_date ? now()->diffInDays($obligation->due_date, false) : null,
                ])->values()->all(),
            ];
        })->values()->all();

        $completionRate = ($summary['total'] ?? 0) > 0
            ? round((($summary['complete'] ?? 0) / $summary['total']) * 100, 1)
            : 0;

        return [
            'summary' => [...$summary, 'completion_rate' => $completionRate],
            'frameworks' => $frameworks,
        ];
    }

    public function meetingCockpit(GovernanceMeeting $meeting, array $quorum, array $workflowChecklist): array
    {
        $ceoStatus = $meeting->ceoReport?->status;
        $ceoSubmitted = in_array($ceoStatus, ['submitted', 'included_in_pack'], true);
        $pack = $meeting->boardPack;

        $previousOpenItems = $this->openFollowThroughForMeeting($this->previousMeeting($meeting));
        $pendingResolutions = $meeting->resolutions->whereIn('status', ['draft', 'open'])->count();
        $readCount = $pack?->readCount() ?? 0;
        $distributedCount = count(array_unique($pack?->distributed_to ?? []));
        $minutesStatus = $meeting->minutes?->status;

        return [
            'cards' => [
                [
                    'key' => 'ceo_report',
                    'title' => 'CEO Report',
                    'status' => $ceoSubmitted ? 'done' : ($meeting->ceo_report_deadline && $meeting->ceo_report_deadline->isPast() ? 'warning' : 'todo'),
                    'value' => $ceoSubmitted ? 'Submitted' : 'Pending',
                    'detail' => $meeting->ceo_report_deadline
                        ? 'Due ' . $meeting->ceo_report_deadline->timezone('Pacific/Auckland')->format('j M Y g:i A')
                        : 'No deadline set',
                    'href' => $meeting->ceoReport ? "/governance/ceo-reports/{$meeting->ceoReport->id}" : '/governance/ceo-reports',
                ],
                [
                    'key' => 'pack_readiness',
                    'title' => 'Board Pack',
                    'status' => $pack?->distributed_at ? 'done' : ($pack ? 'in_progress' : 'todo'),
                    'value' => $pack?->distributed_at ? 'Distributed' : ($pack ? 'Generated' : 'Not started'),
                    'detail' => $pack?->distributed_at
                        ? "{$readCount} read / {$distributedCount} distributed"
                        : ($pack ? 'Ready to distribute to the board' : 'Generate once agenda and papers are ready'),
                    'href' => $pack ? "/governance/packs/{$pack->id}" : "/governance/meetings/{$meeting->id}",
                ],
                [
                    'key' => 'quorum',
                    'title' => 'Quorum',
                    'status' => $quorum['met'] ? 'done' : ($quorum['present'] > 0 ? 'in_progress' : 'todo'),
                    'value' => "{$quorum['present']} / {$quorum['required']}",
                    'detail' => $quorum['met'] ? 'Quorum confirmed for decision-making.' : 'Attendance still needs to be confirmed.',
                    'href' => "/governance/meetings/{$meeting->id}?tab=attendance",
                ],
                [
                    'key' => 'resolutions',
                    'title' => 'Pending Resolutions',
                    'status' => $pendingResolutions > 0 ? 'in_progress' : 'done',
                    'value' => $pendingResolutions,
                    'detail' => $pendingResolutions > 0 ? 'Decision papers still open for this meeting.' : 'Decision papers are prepared or complete.',
                    'href' => "/governance/meetings/{$meeting->id}?tab=resolutions",
                ],
                [
                    'key' => 'minutes',
                    'title' => 'Minutes',
                    'status' => in_array($minutesStatus, ['signed', 'archived'], true) ? 'done' : ($meeting->minutes ? 'in_progress' : 'todo'),
                    'value' => $meeting->minutes ? $this->titleize($meeting->minutes->status) : 'Not drafted',
                    'detail' => $meeting->minutes
                        ? 'Version ' . $meeting->minutes->version_number . ' is currently ' . $this->titleize($meeting->minutes->status) . '.'
                        : 'Minutes will be drafted after the meeting.',
                    'href' => "/governance/meetings/{$meeting->id}?tab=minutes",
                ],
                [
                    'key' => 'follow_through',
                    'title' => 'Previous Follow-through',
                    'status' => $previousOpenItems->isEmpty() ? 'done' : 'warning',
                    'value' => $previousOpenItems->count(),
                    'detail' => $previousOpenItems->isEmpty()
                        ? 'No open follow-through from the previous governance cycle.'
                        : 'Open action items remain from the last meeting cycle.',
                    'href' => '/governance/actions',
                ],
            ],
            'next_step' => $workflowChecklist['next_step'] ?? null,
        ];
    }

    protected function widgetCard(string $key, mixed $widget, array $freshness): ?array
    {
        if ($widget === null) {
            return null;
        }

        return match ($key) {
            'top_risks' => $this->presentTopRisksCard($widget, $freshness),
            'risk_changes' => $this->presentRiskChangesCard($widget, $freshness),
            'voided_risks' => $this->presentVoidedRisksCard($widget, $freshness),
            'client_safety' => $this->presentClientSafetyCard($widget, $freshness),
            'operational_safety' => $this->presentOperationalSafetyCard($widget, $freshness),
            'privacy_data' => $this->presentPrivacyCard($widget, $freshness),
            'workforce' => $this->presentWorkforceCard($widget, $freshness),
            'financial' => $this->presentFinancialCard($widget, $freshness),
            'it_cyber' => $this->presentItCyberCard($widget, $freshness),
            'fleet_assets' => $this->presentFleetAssetsCard($widget, $freshness),
            'compliance_calendar' => $this->presentComplianceCalendarCard($widget, $freshness),
            'decisions_required' => $this->presentDecisionsCard($widget, $freshness),
            'roadmap' => $this->presentRoadmapCard($widget, $freshness),
            'control_room' => $this->presentControlRoomCard($widget, $freshness),
            'incidents' => $this->presentIncidentsCard($widget, $freshness),
            'safeguarding' => $this->presentSafeguardingCard($widget, $freshness),
            'hs_backbone' => $this->presentHsBackboneCard($widget, $freshness),
            default => null,
        };
    }

    protected function meetingReadinessCard(): array
    {
        $meeting = GovernanceMeeting::query()
            ->with(['agendaItems', 'boardPack', 'ceoReport', 'resolutions', 'attendances'])
            ->where('scheduled_at', '>=', now())
            ->whereNotIn('status', ['archived', 'cancelled'])
            ->orderBy('scheduled_at')
            ->first();

        if (! $meeting) {
            return $this->makeCard(
                'meeting_readiness',
                'Meeting readiness',
                'Preparation status for the next governance cycle.',
                'good',
                'Governance meetings',
                $this->derivedFreshness(),
                [$this->metric('Next meeting', 'Not scheduled'), $this->metric('Agenda items', 0)],
                ['No upcoming governance meeting is currently scheduled.'],
                '/governance/meetings'
            );
        }

        $daysUntilMeeting = now()->startOfDay()->diffInDays($meeting->scheduled_at?->copy()->startOfDay(), false);
        $quorum = $meeting->calculateQuorum();
        $agendaCount = $meeting->agendaItems->count();
        $packDistributed = (bool) $meeting->boardPack?->distributed_at;
        $ceoSubmitted = in_array($meeting->ceoReport?->status, ['submitted', 'included_in_pack'], true);
        $pendingResolutions = $meeting->resolutions->whereIn('status', ['draft', 'open'])->count();

        $status = 'good';
        if ($daysUntilMeeting <= 7 && (! $packDistributed || ! $ceoSubmitted || ! $quorum['met'])) {
            $status = 'warning';
        }
        if ($daysUntilMeeting <= 3 && ($agendaCount === 0 || ! $packDistributed)) {
            $status = 'critical';
        }

        return $this->makeCard(
            'meeting_readiness',
            'Meeting readiness',
            'Preparation status for the next governance cycle.',
            $status,
            'Governance meetings, board packs, CEO reports',
            $this->derivedFreshness(),
            [
                $this->metric('Next meeting', $meeting->scheduled_at?->timezone('Pacific/Auckland')->format('j M g:i A') ?? 'TBC'),
                $this->metric('Agenda items', $agendaCount, $agendaCount === 0 ? 'warning' : 'default'),
                $this->metric('Pack', $packDistributed ? 'Distributed' : ($meeting->boardPack ? 'Generated' : 'Not started'), $packDistributed ? 'default' : 'warning'),
                $this->metric('CEO report', $ceoSubmitted ? 'Submitted' : 'Pending', $ceoSubmitted ? 'default' : 'warning'),
                $this->metric('Pending resolutions', $pendingResolutions, $pendingResolutions > 0 ? 'warning' : 'default'),
            ],
            array_values(array_filter([
                $meeting->title,
                $meeting->location ? "Location: {$meeting->location}" : null,
                $quorum['met'] ? 'Quorum is currently met.' : "Quorum is {$quorum['present']} / {$quorum['required']}.",
            ])),
            "/governance/meetings/{$meeting->id}"
        );
    }

    protected function followThroughCard(): array
    {
        $items = ActionItem::query()
            ->with('assignedTo:id,name')
            ->whereIn('status', ['open', 'in_progress', 'blocked'])
            ->orderBy('due_date')
            ->limit(5)
            ->get();

        $overdue = $items->filter(fn (ActionItem $item) => $item->due_date && $item->due_date->isPast() && in_array($item->status, ['open', 'in_progress'], true))->count();
        $blocked = $items->where('status', 'blocked')->count();

        return $this->makeCard(
            'follow_through',
            'Follow-through',
            'Open board and committee actions that still need closure.',
            $overdue > 0 ? 'critical' : ($blocked > 0 || $items->isNotEmpty() ? 'warning' : 'good'),
            'Governance action items',
            $this->derivedFreshness(),
            [
                $this->metric('Open actions', $items->count(), $items->isNotEmpty() ? 'warning' : 'default'),
                $this->metric('Overdue', $overdue, $overdue > 0 ? 'critical' : 'default'),
                $this->metric('Blocked', $blocked, $blocked > 0 ? 'warning' : 'default'),
            ],
            $items->isEmpty()
                ? ['No open governance follow-through is currently outstanding.']
                : $items->take(3)->map(fn (ActionItem $item) => $item->action_reference . ' ' . $item->description)->values()->all(),
            '/governance/actions'
        );
    }

    protected function previousMeeting(GovernanceMeeting $meeting): ?GovernanceMeeting
    {
        return GovernanceMeeting::query()
            ->where('scheduled_at', '<', $meeting->scheduled_at)
            ->whereNotIn('status', ['cancelled'])
            ->orderByDesc('scheduled_at')
            ->first();
    }

    protected function openFollowThroughForMeeting(?GovernanceMeeting $meeting): Collection
    {
        if (! $meeting) {
            return collect();
        }

        $resolutionIds = $meeting->resolutions()->pluck('id');

        return ActionItem::query()
            ->whereIn('status', ['open', 'in_progress', 'blocked'])
            ->where(function ($query) use ($meeting, $resolutionIds) {
                $query
                    ->where(function ($subQuery) use ($meeting) {
                        $subQuery->where('source_type', 'meeting')
                            ->where('source_id', $meeting->id);
                    })
                    ->orWhere(function ($subQuery) use ($resolutionIds) {
                        $subQuery->where('source_type', 'resolution')
                            ->whereIn('source_id', $resolutionIds);
                    });
            })
            ->orderBy('due_date')
            ->get();
    }

    protected function presentTopRisksCard(array $widget, array $freshness): array
    {
        return $this->makeCard(
            'top_risks',
            'Risk posture',
            'Active risks, critical exposure, and items outside appetite.',
            ($widget['critical'] ?? 0) > 0 || ($widget['above_appetite'] ?? 0) > 0 ? 'critical' : (($widget['high'] ?? 0) > 0 ? 'warning' : 'good'),
            'Governance risk register',
            $this->freshnessFor('top_risks', $freshness),
            [
                $this->metric('Critical', $widget['critical'] ?? 0, ($widget['critical'] ?? 0) > 0 ? 'critical' : 'default'),
                $this->metric('High', $widget['high'] ?? 0, ($widget['high'] ?? 0) > 0 ? 'warning' : 'default'),
                $this->metric('Above appetite', $widget['above_appetite'] ?? 0, ($widget['above_appetite'] ?? 0) > 0 ? 'warning' : 'default'),
                $this->metric('Tracked', $widget['count'] ?? count($widget['items'] ?? [])),
            ],
            collect($widget['items'] ?? [])->take(3)->map(fn (array $item) => "{$item['reference']} {$item['title']}")->values()->all(),
            '/governance/risks'
        );
    }

    protected function presentRiskChangesCard(array $widget, array $freshness): array
    {
        return $this->makeCard(
            'risk_changes',
            'Risk movement',
            'New, escalated, and closed risks during the reporting period.',
            ($widget['escalated'] ?? 0) > ($widget['closed'] ?? 0) ? 'warning' : 'good',
            'Governance risk register',
            $this->freshnessFor('risk_changes', $freshness),
            [
                $this->metric('New', $widget['new'] ?? 0),
                $this->metric('Escalated', $widget['escalated'] ?? 0, ($widget['escalated'] ?? 0) > 0 ? 'warning' : 'default'),
                $this->metric('Closed', $widget['closed'] ?? 0),
                $this->metric('Net change', $widget['net_change'] ?? 0, ($widget['net_change'] ?? 0) > 0 ? 'warning' : 'default'),
            ],
            [],
            '/governance/risks/trends'
        );
    }

    protected function presentVoidedRisksCard(array $widget, array $freshness): array
    {
        return $this->makeCard(
            'voided_risks',
            'Recently voided risks',
            'Items removed or retired from the register this period.',
            'good',
            'Governance risk register',
            $this->freshnessFor('voided_risks', $freshness),
            [
                $this->metric('Voided', $widget['count'] ?? 0),
                $this->metric('Latest review', collect($widget['items'] ?? [])->first()['closed_at'] ?? 'None'),
            ],
            collect($widget['items'] ?? [])->take(3)->map(fn (array $item) => "{$item['reference']} {$item['title']}")->values()->all(),
            '/governance/risks'
        );
    }

    protected function presentClientSafetyCard(array $widget, array $freshness): array
    {
        return $this->makeCard(
            'client_safety',
            'Client safety',
            'High-risk clients and serious incident posture.',
            $widget['status'] ?? 'unknown',
            'Client risk and incident records',
            $this->freshnessFor('client_safety', $freshness),
            [
                $this->metric('High-risk clients', $widget['high_risk_clients'] ?? 0, ($widget['high_risk_clients'] ?? 0) > 0 ? 'warning' : 'default'),
                $this->metric('Serious incidents', $widget['serious_incidents_period'] ?? 0, ($widget['serious_incidents_period'] ?? 0) > 0 ? 'warning' : 'default'),
                $this->metric('Open critical', $widget['open_critical_incidents'] ?? 0, ($widget['open_critical_incidents'] ?? 0) > 0 ? 'critical' : 'default'),
            ],
            [],
            '/incidents'
        );
    }

    protected function presentOperationalSafetyCard(array $widget, array $freshness): array
    {
        return $this->makeCard(
            'operational_safety',
            'Operational safety',
            'Near misses and injury trends affecting service delivery.',
            $widget['status'] ?? 'unknown',
            'Incident register',
            $this->freshnessFor('operational_safety', $freshness),
            [
                $this->metric('Near misses', $widget['near_misses'] ?? 0),
                $this->metric('Injuries', $widget['injuries'] ?? 0, ($widget['injuries'] ?? 0) > 0 ? 'critical' : 'default'),
            ],
            [],
            '/incidents'
        );
    }

    protected function presentPrivacyCard(array $widget, array $freshness): array
    {
        return $this->makeCard(
            'privacy_data',
            'Privacy & data',
            'Breaches, DPIAs, and data subject request backlog.',
            $widget['status'] ?? 'unknown',
            'Privacy register',
            $this->freshnessFor('privacy_data', $freshness),
            [
                $this->metric('Breaches (90d)', $widget['breaches_90d'] ?? 0, ($widget['breaches_90d'] ?? 0) > 0 ? 'warning' : 'default'),
                $this->metric('Open breaches', $widget['open_breaches'] ?? 0, ($widget['open_breaches'] ?? 0) > 0 ? 'critical' : 'default'),
                $this->metric('Open DPIAs', $widget['open_dpias'] ?? 0),
                $this->metric('DSR backlog', $widget['dsr_backlog'] ?? 0, ($widget['dsr_backlog'] ?? 0) > 0 ? 'warning' : 'default'),
            ],
            [],
            '/privacy'
        );
    }

    protected function presentWorkforceCard(array $widget, array $freshness): array
    {
        return $this->makeCard(
            'workforce',
            'Workforce',
            'Capacity pressure, unfilled shifts, and compliance training.',
            $widget['status'] ?? 'unknown',
            'HR scheduling and compliance records',
            $this->freshnessFor('workforce', $freshness),
            [
                $this->metric('Overtime', $this->formatPercent($widget['overtime_percentage'] ?? null), ((float) ($widget['overtime_percentage'] ?? 0)) > 10 ? 'warning' : 'default'),
                $this->metric('Unfilled shifts', $widget['unfilled_shifts'] ?? 0, ($widget['unfilled_shifts'] ?? 0) > 0 ? 'warning' : 'default'),
                $this->metric('Training compliance', $this->formatPercent($widget['training_compliance'] ?? null), $widget['training_compliance'] === null ? 'muted' : (((float) $widget['training_compliance']) < 95 ? 'warning' : 'default')),
            ],
            $widget['training_compliance'] === null ? ['Training compliance is not yet integrated for this environment.'] : [],
            '/hr'
        );
    }

    protected function presentFinancialCard(array $widget, array $freshness): array
    {
        $highlights = array_values(array_filter([
            ! empty($widget['fiscal_year']) ? "Budget year {$widget['fiscal_year']}" : null,
            isset($widget['sites_over_budget_count']) && $widget['sites_over_budget_count'] > 0
                ? "{$widget['sites_over_budget_count']} site(s) over budget this month"
                : null,
            isset($widget['pending_spend_count']) && $widget['pending_spend_count'] > 0
                ? "{$widget['pending_spend_count']} spend approval(s) pending"
                : null,
            isset($widget['pending_board_approvals']) && $widget['pending_board_approvals'] > 0
                ? "{$widget['pending_board_approvals']} require board sign-off"
                : null,
            isset($widget['funding_gaps_count']) && $widget['funding_gaps_count'] > 0
                ? "{$widget['funding_gaps_count']} donor fund(s) over-committed"
                : null,
            isset($widget['roadmap_forecast_total']) ? 'Roadmap forecast ' . $this->formatCurrency($widget['roadmap_forecast_total']) : null,
            isset($widget['governance_envelope_total']) ? 'Governance envelope ' . $this->formatCurrency($widget['governance_envelope_total']) : null,
        ]));

        return $this->makeCard(
            'financial',
            'Financial governance',
            'Approved budget, actuals, variance, sites over budget, and pending spend approvals.',
            $widget['status'] ?? 'unknown',
            'Governance budget and finance posted journals',
            $this->freshnessFor('financial', $freshness),
            [
                $this->metric('Utilisation', $this->formatPercent($widget['budget_utilization'] ?? null)),
                $this->metric('Variance', $this->formatPercent($widget['variance'] ?? null), abs((float) ($widget['variance'] ?? 0)) >= 5 ? 'warning' : 'default'),
                $this->metric('Budget total', $this->formatCurrency($widget['budget_total'] ?? null)),
                $this->metric('Actuals', $this->formatCurrency($widget['actual_total'] ?? null)),
            ],
            $highlights,
            '/governance/budgets'
        );
    }

    /**
     * Build a Pending Spend Approvals card. Built directly from the financial
     * widget so the dashboard surface can reach this without a separate
     * snapshot key.
     */
    protected function presentSpendApprovalsCard(array $financialWidget, array $freshness): ?array
    {
        if (! array_key_exists('pending_spend_count', $financialWidget)) {
            return null;
        }

        $pending = (int) ($financialWidget['pending_spend_count'] ?? 0);
        $boardSignoff = (int) ($financialWidget['pending_board_approvals'] ?? 0);
        $status = match (true) {
            $pending === 0 => 'good',
            $boardSignoff > 0 => 'warning',
            default => 'in_progress',
        };

        return $this->makeCard(
            'spend_approvals',
            'Spend approvals',
            'Items above the configured spend threshold awaiting board or finance-committee sign-off.',
            $status,
            'Governance spend approval workflow',
            $this->freshnessFor('financial', $freshness),
            [
                $this->metric('Pending', $pending, $pending > 0 ? 'warning' : 'default'),
                $this->metric('Board sign-off', $boardSignoff, $boardSignoff > 0 ? 'warning' : 'default'),
                $this->metric('Pending value', $this->formatCurrency($financialWidget['pending_spend_total'] ?? 0)),
            ],
            [],
            '/governance/spend-approvals'
        );
    }

    /**
     * Build a Sites Over Budget card.
     */
    protected function presentSitesOverBudgetCard(array $financialWidget, array $freshness): ?array
    {
        if (! array_key_exists('sites_over_budget_count', $financialWidget)) {
            return null;
        }

        $count = (int) $financialWidget['sites_over_budget_count'];
        $amount = (float) ($financialWidget['sites_over_budget_amount'] ?? 0);
        $status = $count === 0 ? 'good' : ($count >= 3 ? 'critical' : 'warning');

        return $this->makeCard(
            'sites_over_budget',
            'Sites over budget',
            'Operational site/house budgets exceeding allocation this month (sourced from Finance variance).',
            $status,
            'Finance site budget lines',
            $this->freshnessFor('financial', $freshness),
            [
                $this->metric('Sites', $count, $count > 0 ? 'warning' : 'default'),
                $this->metric('Overspend', $this->formatCurrency($amount), $amount > 0 ? 'warning' : 'default'),
            ],
            [],
            '/finance/budget-actuals'
        );
    }

    protected function presentItCyberCard(array $widget, array $freshness): array
    {
        return $this->makeCard(
            'it_cyber',
            'IT & cyber',
            'Security incidents, uptime, and critical technical exposure.',
            $widget['status'] ?? 'unknown',
            'Control room alerts',
            $this->freshnessFor('it_cyber', $freshness),
            [
                $this->metric('Security incidents', $widget['security_incidents'] ?? 0, ($widget['security_incidents'] ?? 0) > 0 ? 'warning' : 'default'),
                $this->metric('Uptime', $this->formatPercent($widget['uptime_percentage'] ?? null), $widget['uptime_percentage'] === null ? 'muted' : (((float) $widget['uptime_percentage']) < 99 ? 'warning' : 'default')),
                $this->metric('Open critical alerts', $widget['critical_open_alerts'] ?? 0, ($widget['critical_open_alerts'] ?? 0) > 0 ? 'critical' : 'default'),
            ],
            $widget['uptime_percentage'] === null ? ['No authoritative uptime signal is available yet.'] : [],
            '/control-room'
        );
    }

    protected function presentFleetAssetsCard(array $widget, array $freshness): array
    {
        return $this->makeCard(
            'fleet_assets',
            'Fleet & assets',
            'Active assets, overdue inspections, and asset incidents.',
            $widget['status'] ?? 'unknown',
            'Asset and vehicle registers',
            $this->freshnessFor('fleet_assets', $freshness),
            [
                $this->metric('Active assets', $widget['total_assets'] ?? 0),
                $this->metric('Fleet vehicles', $widget['fleet_vehicles'] ?? 0),
                $this->metric('Overdue inspections', $widget['overdue_inspections'] ?? 0, ($widget['overdue_inspections'] ?? 0) > 0 ? 'warning' : 'default'),
                $this->metric('Asset incidents', $widget['asset_incidents'] ?? 0, ($widget['asset_incidents'] ?? 0) > 0 ? 'warning' : 'default'),
            ],
            [],
            '/fleet'
        );
    }

    protected function presentComplianceCalendarCard(array $widget, array $freshness): array
    {
        $collection = collect($widget);

        return $this->makeCard(
            'compliance_calendar',
            'Compliance calendar',
            'Upcoming and overdue governance obligations.',
            $collection->contains(fn (array $item) => ($item['days_remaining'] ?? 1) < 0) ? 'critical' : ($collection->isNotEmpty() ? 'warning' : 'good'),
            'Compliance register',
            $this->freshnessFor('compliance_calendar', $freshness),
            [
                $this->metric('Upcoming obligations', $collection->count()),
                $this->metric('Overdue', $collection->filter(fn (array $item) => ($item['days_remaining'] ?? 1) < 0)->count(), $collection->contains(fn (array $item) => ($item['days_remaining'] ?? 1) < 0) ? 'critical' : 'default'),
                $this->metric('Due this week', $collection->filter(fn (array $item) => ($item['days_remaining'] ?? 999) >= 0 && ($item['days_remaining'] ?? 999) <= 7)->count()),
            ],
            $collection->take(3)->map(fn (array $item) => "{$item['title']} ({$item['due_date']})")->values()->all(),
            '/governance/compliance/calendar'
        );
    }

    protected function presentDecisionsCard(array $widget, array $freshness): array
    {
        return $this->makeCard(
            'decisions_required',
            'Decisions required',
            'Open board resolutions and roadmap decision requests.',
            ($widget['overdue'] ?? 0) > 0 ? 'critical' : (($widget['count'] ?? 0) > 0 ? 'warning' : 'good'),
            'Governance resolutions and roadmap requests',
            $this->freshnessFor('decisions_required', $freshness),
            [
                $this->metric('Pending', $widget['count'] ?? 0, ($widget['count'] ?? 0) > 0 ? 'warning' : 'default'),
                $this->metric('Overdue', $widget['overdue'] ?? 0, ($widget['overdue'] ?? 0) > 0 ? 'critical' : 'default'),
            ],
            collect($widget['items'] ?? [])->take(3)->map(fn (array $item) => "{$item['reference']} {$item['title']}")->values()->all(),
            '/governance/resolutions'
        );
    }

    protected function presentRoadmapCard(array $widget, array $freshness): array
    {
        return $this->makeCard(
            'roadmap',
            'Plans & roadmap',
            'Initiative delivery, blocked work, and governance budget alignment.',
            ($widget['status'] ?? null) === 'unavailable' ? 'unknown' : (($widget['initiatives']['blocked'] ?? 0) > 0 || ($widget['decisions_required'] ?? 0) > 0 ? 'warning' : 'good'),
            'Roadmap governance widget',
            $this->freshnessFor('roadmap', $freshness),
            [
                $this->metric('Initiatives', $widget['initiatives']['total'] ?? 0),
                $this->metric('In progress', $widget['initiatives']['in_progress'] ?? 0),
                $this->metric('Blocked', $widget['initiatives']['blocked'] ?? 0, ($widget['initiatives']['blocked'] ?? 0) > 0 ? 'warning' : 'default'),
                $this->metric('Decision requests', $widget['decisions_required'] ?? 0, ($widget['decisions_required'] ?? 0) > 0 ? 'warning' : 'default'),
            ],
            collect($widget['initiatives']['top'] ?? [])->take(3)->map(fn (array $item) => "{$item['code']} {$item['title']}")->values()->all(),
            '/roadmap'
        );
    }

    protected function presentControlRoomCard(array $widget, array $freshness): array
    {
        return $this->makeCard(
            'control_room',
            'Control room',
            'Critical alerts and response performance.',
            ($widget['open_critical'] ?? 0) > 0 ? 'critical' : (($widget['critical_alerts'] ?? 0) > 0 ? 'warning' : 'good'),
            'Control room operations',
            $this->freshnessFor('control_room', $freshness),
            [
                $this->metric('Critical alerts', $widget['critical_alerts'] ?? 0, ($widget['critical_alerts'] ?? 0) > 0 ? 'warning' : 'default'),
                $this->metric('High alerts', $widget['high_alerts'] ?? 0),
                $this->metric('Open critical', $widget['open_critical'] ?? 0, ($widget['open_critical'] ?? 0) > 0 ? 'critical' : 'default'),
                $this->metric('MTTA', $this->formatMinutes($widget['mtta_minutes'] ?? null)),
                $this->metric('MTTR', $this->formatMinutes($widget['mttr_minutes'] ?? null)),
            ],
            [],
            '/control-room'
        );
    }

    protected function presentIncidentsCard(array $widget, array $freshness): array
    {
        return $this->makeCard(
            'incidents',
            'Incident closure',
            'Incident volume, severity mix, and close-out pace.',
            (($widget['by_severity']['critical'] ?? 0) > 0 || ($widget['open_count'] ?? 0) > 0) ? 'warning' : 'good',
            'Incident register',
            $this->freshnessFor('incidents', $freshness),
            [
                $this->metric('Total this period', $widget['total_period'] ?? 0),
                $this->metric('Open', $widget['open_count'] ?? 0, ($widget['open_count'] ?? 0) > 0 ? 'warning' : 'default'),
                $this->metric('Critical', $widget['by_severity']['critical'] ?? 0, ($widget['by_severity']['critical'] ?? 0) > 0 ? 'critical' : 'default'),
                $this->metric('Avg close', $this->formatHours($widget['avg_close_hours'] ?? null)),
            ],
            [],
            '/incidents'
        );
    }

    protected function presentSafeguardingCard(array $widget, array $freshness): array
    {
        return $this->makeCard(
            'safeguarding',
            'Safeguarding',
            'Open and critical safeguarding concerns.',
            $widget['status'] ?? 'unknown',
            'Safeguarding concerns',
            $this->freshnessFor('safeguarding', $freshness),
            [
                $this->metric('New concerns', $widget['new_concerns'] ?? 0),
                $this->metric('Critical', $widget['critical_concerns'] ?? 0, ($widget['critical_concerns'] ?? 0) > 0 ? 'critical' : 'default'),
                $this->metric('Open', $widget['open_concerns'] ?? 0, ($widget['open_concerns'] ?? 0) > 0 ? 'warning' : 'default'),
                $this->metric('Investigations', $widget['investigations_opened'] ?? 0),
            ],
            [],
            '/safeguarding'
        );
    }

    protected function presentHsBackboneCard(array $widget, array $freshness): array
    {
        return $this->makeCard(
            'hs_backbone',
            'Health & safety backbone',
            'Investigations, corrective actions, extreme risks, and WorkSafe posture.',
            $widget['status'] ?? 'unknown',
            'H&S governance backbone',
            $this->freshnessFor('hs_backbone', $freshness),
            [
                $this->metric('Open events', $widget['open_events'] ?? 0),
                $this->metric('Overdue investigations', $widget['overdue_investigations'] ?? 0, ($widget['overdue_investigations'] ?? 0) > 0 ? 'warning' : 'default'),
                $this->metric('Overdue actions', $widget['overdue_corrective_actions'] ?? 0, ($widget['overdue_corrective_actions'] ?? 0) > 0 ? 'warning' : 'default'),
                $this->metric('Extreme risks', $widget['extreme_risks'] ?? 0, ($widget['extreme_risks'] ?? 0) > 0 ? 'critical' : 'default'),
            ],
            [],
            '/health-safety'
        );
    }

    protected function cardsForKeys(Collection $cardsByKey, array $keys): array
    {
        return collect($keys)->map(fn (string $key) => $cardsByKey->get($key))->filter()->values()->all();
    }

    protected function makeCard(string $key, string $title, string $description, string $status, string $source, array $freshness, array $metrics, array $highlights, string $href): array
    {
        return compact('key', 'title', 'description', 'status', 'source', 'freshness', 'metrics', 'highlights', 'href');
    }

    protected function metric(string $label, mixed $value, string $tone = 'default'): array
    {
        return ['label' => $label, 'value' => is_string($value) ? $value : (string) $value, 'tone' => $tone];
    }

    protected function roleActions(?User $user): array
    {
        if (! $user) {
            return [];
        }

        $actions = [];
        if ($user->canDo('governance.meetings.view')) { $actions[] = ['label' => 'Meetings', 'href' => '/governance/meetings', 'description' => 'Open the next meeting cockpit.']; }
        if ($user->canDo('governance.resolutions.view')) { $actions[] = ['label' => 'Decisions', 'href' => '/governance/resolutions', 'description' => 'Review open resolutions and board decisions.']; }
        if ($user->boardMember && $user->canDo('governance.interests.view')) { $actions[] = ['label' => 'My interests', 'href' => '/governance/interests/mine', 'description' => 'Maintain your current interest declarations.']; }
        if ($user->canDo('governance.evaluations.view')) { $actions[] = ['label' => 'Evaluations', 'href' => '/governance/evaluations', 'description' => 'Review or respond to board evaluations.']; }
        if ($user->canDo('governance.packs.view')) { $actions[] = ['label' => 'Board packs', 'href' => '/governance/meetings', 'description' => 'Open pack generation and distribution workflows.']; }
        if ($user->canDo('governance.budgets.view')) { $actions[] = ['label' => 'Budgets', 'href' => '/governance/budgets', 'description' => 'Review governance budget position and approvals.']; }

        return $actions;
    }

    protected function freshnessFor(string $widgetKey, array $freshness): array
    {
        $map = ['top_risks' => 'risks', 'risk_changes' => 'risks', 'voided_risks' => 'risks', 'client_safety' => 'incidents', 'operational_safety' => 'incidents', 'incidents' => 'incidents', 'safeguarding' => 'incidents', 'compliance_calendar' => 'compliance', 'privacy_data' => 'compliance', 'control_room' => 'control_room', 'it_cyber' => 'control_room'];
        $timestamp = $freshness[$map[$widgetKey] ?? ''] ?? null;

        if (! $timestamp) {
            return ['status' => 'unknown', 'at' => null, 'label' => 'Freshness unavailable'];
        }

        $at = Carbon::parse($timestamp);
        $minutes = max(0, $at->diffInMinutes(now()));
        $status = match (true) { $minutes <= 15 => 'fresh', $minutes <= 60 => 'stable', default => 'stale' };

        return ['status' => $status, 'at' => $at->toIso8601String(), 'label' => $minutes < 1 ? 'Updated just now' : ($minutes < 60 ? "Updated {$minutes} min ago" : 'Updated ' . $at->timezone('Pacific/Auckland')->format('j M g:i A'))];
    }

    protected function derivedFreshness(): array
    {
        return ['status' => 'fresh', 'at' => now()->toIso8601String(), 'label' => 'Updated just now'];
    }

    protected function periodLabel(array $period): string
    {
        $type = $period['type'] ?? 'month';
        $start = isset($period['start']) ? Carbon::parse($period['start']) : null;
        $end = isset($period['end']) ? Carbon::parse($period['end']) : null;
        return $start && $end ? $this->titleize($type) . ' (' . $start->format('j M Y') . ' to ' . $end->format('j M Y') . ')' : $this->titleize($type);
    }

    protected function titleize(?string $value): string
    {
        return $value === null ? '' : str($value)->replace('_', ' ')->title()->toString();
    }

    protected function formatPercent(?float $value): string
    {
        return $value === null ? 'Unavailable' : number_format($value, 1) . '%';
    }

    protected function formatCurrency(mixed $value): string
    {
        return $value === null || $value === '' ? 'Unavailable' : '$' . number_format((float) $value, 2);
    }

    protected function formatMinutes(?float $value): string
    {
        return $value === null ? 'Unavailable' : number_format($value, 1) . ' min';
    }

    protected function formatHours(?float $value): string
    {
        return $value === null ? 'Unavailable' : number_format($value, 1) . ' h';
    }
}
