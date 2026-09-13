import { cn } from '@/lib/utils';

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
import { MyNextActionsRail } from '@/components/governance/MyNextActionsRail';
import { OperationalSignalsAccordion } from '@/components/governance/OperationalSignalsAccordion';
import { PriorityOverviewPanel } from '@/components/governance/PriorityOverviewPanel';
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
    };
    permissions: GovernancePermissionMap;
    currentUserId?: number | null;
    currentUserName?: string | null;
    boardRole?: string | null;
    userRole?: string | null;
    onRefresh?: () => void;
}

/**
 * Compose the main assurance grid order based on the user's role preset.
 * Treasurer sees Financial above Risk; others use the default order.
 */
function shouldPinFinancial(role: GovernanceRolePreset): boolean {
    return role === 'treasurer';
}

export function CockpitLayout({
    cockpit,
    workflow,
    permissions,
    currentUserId,
    currentUserName,
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
    const canManagePacks = canDoGovernance(permissions, 'packs', 'manage');
    const canViewAudit = canDoGovernance(permissions, 'audit', 'view');

    return (
        <div className="space-y-5">
            {/* L1 Row 1: Next Authorised Meeting + Needs My Attention */}
            <div className="grid gap-5 lg:grid-cols-[1.5fr_1fr]">
                <MeetingReadinessPanel
                    nextMeeting={cockpit.next_meeting}
                    canScheduleMeeting={canManageMeetings}
                />
                <MyNextActionsRail
                    actions={workflow.actions}
                    currentUserId={currentUserId}
                    currentUserName={currentUserName}
                    showFallback={false}
                />
            </div>

            {/* L1 Row 2: Board Priorities Table */}
            <PriorityOverviewPanel
                actions={workflow.actions}
                summary={workflow.summary}
            />

            {/* L1 Row 3: What Changed (Timeline) — only if user can view audit feed */}
            {canViewAudit && (
                <GovernanceTimeline
                    timeline={cockpit.timeline}
                    onRetry={onRefresh}
                />
            )}

            {/* L1 Row 4: Compact Assurance Groups (Financial & Risk/Compliance) */}
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

            {/* L1 Row 5: Board Pack (if present) */}
            {cockpit.board_pack && (
                <BoardPackPanel
                    pack={cockpit.board_pack}
                    canUploadPack={canManagePacks}
                />
            )}

            {/* L1 Row 6: Operational Signals (progressively disclosed accordion) */}
            <OperationalSignalsAccordion cardsByKey={cardsByKey} />

            {/* L1 Row 7: Recently Completed (renders nothing if empty) */}
            <RecentlyCompletedRail items={cockpit.recently_completed ?? []} />
        </div>
    );
}

export default CockpitLayout;
