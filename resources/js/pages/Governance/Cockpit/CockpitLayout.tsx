import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import {
    BookOpen,
    ClipboardList,
    FileText,
    FolderOpen,
    HeartPulse,
    Landmark,
    ShieldCheck,
    Star,
} from 'lucide-react';

import {
    BoardPackPanel,
    type BoardPackPayload,
} from '@/components/governance/BoardPackPanel';
import type { WorkflowAction } from '@/components/governance/BoardPriorityCard';
import { FinancialGovernancePanel } from '@/components/governance/FinancialGovernancePanel';
import {
    GovernanceCalendar,
    type CalendarEvent,
} from '@/components/governance/GovernanceCalendar';
import {
    GovernanceTimeline,
    type TimelinePayload,
} from '@/components/governance/GovernanceTimeline';
import { KpiBand, type KpiTile } from '@/components/governance/KpiBand';
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
    currentUserName?: string | null;
    boardRole?: string | null;
    userRole?: string | null;
}

const MODULE_TILES = [
    {
        label: 'Policies',
        href: '/governance/policies',
        icon: BookOpen,
        tone: 'text-status-info bg-status-info-bg',
    },
    {
        label: 'CEO Reports',
        href: '/governance/ceo-reports',
        icon: FileText,
        tone: 'text-primary bg-primary/10',
    },
    {
        label: 'Interests',
        href: '/governance/interests/mine',
        icon: ClipboardList,
        tone: 'text-primary bg-primary/10',
    },
    {
        label: 'Evaluations',
        href: '/governance/evaluations',
        icon: Star,
        tone: 'text-status-warning bg-status-warning-bg',
    },
    {
        label: 'Documents',
        href: '/governance/documents',
        icon: FolderOpen,
        tone: 'text-status-success bg-status-success-bg',
    },
    {
        label: 'Clinical',
        href: '/governance/clinical',
        icon: HeartPulse,
        tone: 'text-status-critical bg-status-critical-bg',
    },
    {
        label: 'Te Tiriti',
        href: '/governance/te-tiriti',
        icon: Landmark,
        tone: 'text-status-info bg-status-info-bg',
    },
    // Operational compliance command centre (org-wide exception roll-up — board assurance).
    // Distinct from the governance "Compliance" obligations register (/governance/compliance).
    {
        label: 'Compliance Centre',
        href: '/compliance',
        icon: ShieldCheck,
        tone: 'text-primary bg-primary/10',
    },
] as const;

/**
 * Compose the 3-zone main grid order based on the user's role preset.
 * Treasurer sees Financial above Risk; others use the default order.
 */
function shouldPinFinancial(role: GovernanceRolePreset): boolean {
    return role === 'treasurer';
}

export function CockpitLayout({
    cockpit,
    workflow,
    permissions,
    currentUserName,
    boardRole,
    userRole,
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
        <div className="space-y-6">
            {/* Period label */}
            {cockpit.period_label && (
                <p className="text-xs tracking-wide text-muted-foreground uppercase">
                    {cockpit.period_label}
                </p>
            )}

            {/* KPI band (4 tiles) */}
            <KpiBand kpis={cockpit.kpi_band} />

            {/* Main 3-zone grid */}
            <div className="grid gap-6 lg:grid-cols-[2fr_1fr]">
                <PriorityOverviewPanel
                    actions={workflow.actions}
                    summary={workflow.summary}
                />

                <div className="space-y-6">
                    <MyNextActionsRail
                        actions={workflow.actions}
                        currentUserName={currentUserName}
                    />
                    <GovernanceCalendar
                        events={cockpit.calendar_events ?? []}
                    />
                </div>
            </div>

            {/* Next Meeting Readiness */}
            <MeetingReadinessPanel
                nextMeeting={cockpit.next_meeting}
                canScheduleMeeting={canManageMeetings}
            />

            {/* Risk + Compliance and Financial — pinning order depends on role */}
            <div
                className={cn(
                    'grid gap-6 lg:grid-cols-2',
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

            {/* Board Pack */}
            <BoardPackPanel
                pack={cockpit.board_pack}
                canUploadPack={canManagePacks}
            />

            {/* Timeline — only show if user can view audit feed */}
            {canViewAudit && <GovernanceTimeline timeline={cockpit.timeline} />}

            {/* Recently Completed (renders nothing if empty) */}
            <RecentlyCompletedRail items={cockpit.recently_completed ?? []} />

            {/* Operational Signals (collapsed accordion preserving old widgets) */}
            <OperationalSignalsAccordion cardsByKey={cardsByKey} />

            {/* Governance Modules tile grid */}
            <div>
                <h2 className="mb-3 text-base font-semibold text-foreground">
                    Governance Modules
                </h2>
                <div className="grid grid-cols-2 gap-3 md:grid-cols-4 xl:grid-cols-7">
                    {MODULE_TILES.map((tile) => (
                        <Link
                            key={tile.href}
                            href={tile.href}
                            className="flex flex-col items-center gap-2 rounded-lg border border-border bg-card p-4 text-center transition hover:border-primary/40 hover:bg-muted/50"
                        >
                            <div className={cn('rounded-lg p-2', tile.tone)}>
                                <tile.icon
                                    className="h-5 w-5"
                                    aria-hidden="true"
                                />
                            </div>
                            <span className="text-sm font-medium text-foreground">
                                {tile.label}
                            </span>
                        </Link>
                    ))}
                </div>
            </div>
        </div>
    );
}

export default CockpitLayout;
