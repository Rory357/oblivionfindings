import { ListCaption } from '@/components/lists';
import { cn } from '@/lib/utils';

import {
    AssuranceSummaryPanel,
    type AssurancePayload,
} from '@/components/governance/AssuranceSummaryPanel';
import {
    BoardPackPanel,
    type BoardPackPayload,
} from '@/components/governance/BoardPackPanel';
import type { WorkflowAction } from '@/components/governance/BoardPriorityCard';
import { FinancialGovernancePanel } from '@/components/governance/FinancialGovernancePanel';
import type { CalendarEvent } from '@/components/governance/GovernanceCalendar';
import {
    GovernanceTimeline,
    type TimelinePayload,
} from '@/components/governance/GovernanceTimeline';
import type { KpiTile } from '@/components/governance/KpiBand';
import {
    MeetingReadinessPanel,
    type NextMeetingPayload,
} from '@/components/governance/MeetingReadinessPanel';
import {
    MyNextActionsRail,
    type MyWorkPreview,
} from '@/components/governance/MyNextActionsRail';
import { NextMeetingCard } from '@/components/governance/NextMeetingCard';
import { OperationalSignalsAccordion } from '@/components/governance/OperationalSignalsAccordion';
import {
    PriorityOverviewPanel,
    type PriorityPagination,
} from '@/components/governance/PriorityOverviewPanel';
import {
    RecentlyCompletedRail,
    type CompletedItem,
} from '@/components/governance/RecentlyCompletedRail';
import { RiskComplianceWatchlist } from '@/components/governance/RiskComplianceWatchlist';
import {
    canDoGovernance,
    detectRolePreset,
    type GovernancePermissionMap,
    type GovernanceRolePreset,
} from '@/lib/governance-permissions';

interface CockpitCard {
    key: string;
    title: string;
    description: string;
    status: string;
    source: string;
    freshness: { status: string; at: string | null; label: string };
    metrics: Array<{ label: string; value: string; tone: string }>;
    highlights: string[];
    href: string;
}

export interface CockpitPayload {
    period_label: string;
    sections: Array<{
        key: string;
        title: string;
        description: string;
        cards: CockpitCard[];
    }>;
    cards: CockpitCard[];
    cards_by_key: Record<string, CockpitCard>;
    workflow_summary: { total: number; critical: number; overdue: number };
    role_actions: Array<{ label: string; href: string; description: string }>;
    kpi_band: KpiTile[];
    assurance?: AssurancePayload | null;
    next_meeting: NextMeetingPayload | null;
    board_pack: BoardPackPayload | null;
    calendar_events: CalendarEvent[];
    timeline: TimelinePayload;
    recently_completed: CompletedItem[];
}

export interface CockpitLayoutProps {
    cockpit: CockpitPayload;
    workflow: {
        summary: { total: number; critical: number; overdue: number };
        actions: WorkflowAction[];
        /** Server pagination for `actions` — further pages are fetched on demand. */
        pagination?: PriorityPagination | null;
    };
    /** The viewer's personal obligations — same source/totals as My work. */
    myWork?: MyWorkPreview | null;
    permissions: GovernancePermissionMap;
    boardRole?: string | null;
    userRole?: string | null;
    onRefresh?: () => void;
}

/**
 * Compose the detailed assurance grid order based on the user's role preset.
 * Treasurer sees Financial above Risk; others use the default order.
 */
function shouldPinFinancial(role: GovernanceRolePreset): boolean {
    return role === 'treasurer';
}

/**
 * Governance Home body. Everyone gets, in order: Next meeting + My work, the
 * board-wide priorities (labelled as board-wide, distinct from My work), a
 * concise assurance summary, then collapsed operational signals and recently
 * completed work. Meeting managers (`governance.meetings.manage`) also get
 * the administrative preparation checklist, the pack distribution panel and
 * the detailed risk/compliance and financial panels.
 */
export function CockpitLayout({
    cockpit,
    workflow,
    myWork,
    permissions,
    boardRole,
    userRole,
    onRefresh,
}: CockpitLayoutProps) {
    const role = detectRolePreset(boardRole, userRole);
    const cardsByKey = cockpit.cards_by_key ?? {};

    const canApproveSpend = canDoGovernance(permissions, 'spend', 'approve');
    const canApproveBudgets = canDoGovernance(
        permissions,
        'budgets',
        'approve',
    );
    const canManageRisks = canDoGovernance(permissions, 'risks', 'manage');
    const canManageCompliance = canDoGovernance(
        permissions,
        'compliance',
        'manage',
    );
    const canManageMeetings = canDoGovernance(
        permissions,
        'meetings',
        'manage',
    );
    const canViewMeetings = canDoGovernance(permissions, 'meetings', 'view');
    const canViewRecords = canDoGovernance(permissions, 'view');
    const canManagePacks = canDoGovernance(permissions, 'packs', 'manage');
    const canViewAudit = canDoGovernance(permissions, 'audit', 'view');
    const priorityTotal = workflow.summary?.total ?? 0;

    return (
        <div className="flex flex-col gap-5" data-dusk="governance-home">
            {/* 1. Next meeting + My work (personal) */}
            <div className="grid gap-5 lg:grid-cols-[1.25fr_1fr]">
                <NextMeetingCard
                    nextMeeting={cockpit.next_meeting}
                    canViewMeetings={canViewMeetings}
                    canViewRecords={canViewRecords}
                    canScheduleMeeting={canManageMeetings}
                />
                <MyNextActionsRail myWork={myWork} />
            </div>

            {/* Managers only: administrative meeting preparation */}
            {canManageMeetings ? (
                <MeetingReadinessPanel nextMeeting={cockpit.next_meeting} />
            ) : null}

            {/* 2. Board-wide priorities — not personal work */}
            <section
                className="flex flex-col gap-3"
                aria-labelledby="board-priorities-heading"
            >
                <ListCaption
                    title={
                        <span id="board-priorities-heading">
                            Board priorities
                        </span>
                    }
                    caption={`Board-wide · ${priorityTotal} ${priorityTotal === 1 ? 'item' : 'items'} — not only your own work`}
                />
                <PriorityOverviewPanel
                    actions={workflow.actions}
                    summary={workflow.summary}
                    pagination={workflow.pagination ?? null}
                />
            </section>

            {/* 3. Concise assurance summary */}
            <AssuranceSummaryPanel
                assurance={cockpit.assurance}
                permissions={permissions}
            />

            {/* Managers: detailed assurance and pack distribution */}
            {canManageMeetings ? (
                <>
                    <div
                        className={cn(
                            'grid gap-5 lg:grid-cols-2',
                            shouldPinFinancial(role) && 'lg:grid-flow-col-dense',
                        )}
                    >
                        {shouldPinFinancial(role) ? (
                            <>
                                <FinancialGovernancePanel
                                    cardsByKey={cardsByKey}
                                    canApproveSpend={canApproveSpend}
                                    canApproveBudgets={canApproveBudgets}
                                />
                                <RiskComplianceWatchlist
                                    cardsByKey={cardsByKey}
                                    canManageRisks={canManageRisks}
                                    canManageCompliance={canManageCompliance}
                                />
                            </>
                        ) : (
                            <>
                                <RiskComplianceWatchlist
                                    cardsByKey={cardsByKey}
                                    canManageRisks={canManageRisks}
                                    canManageCompliance={canManageCompliance}
                                />
                                <FinancialGovernancePanel
                                    cardsByKey={cardsByKey}
                                    canApproveSpend={canApproveSpend}
                                    canApproveBudgets={canApproveBudgets}
                                />
                            </>
                        )}
                    </div>
                    {cockpit.board_pack ? (
                        <BoardPackPanel
                            pack={cockpit.board_pack}
                            canUploadPack={canManagePacks}
                        />
                    ) : null}
                </>
            ) : null}

            {/* What changed — only if the viewer can read the audit feed */}
            {canViewAudit ? (
                <GovernanceTimeline
                    timeline={cockpit.timeline}
                    onRetry={onRefresh}
                />
            ) : null}

            {/* Context, collapsed by default */}
            <OperationalSignalsAccordion cardsByKey={cardsByKey} />

            {/* Renders nothing when empty */}
            <RecentlyCompletedRail items={cockpit.recently_completed ?? []} />
        </div>
    );
}

export default CockpitLayout;
