import { ListCaption } from '@/components/lists';

import {
    AssuranceSummaryPanel,
    assuranceExtras,
    type AssurancePayload,
} from '@/components/governance/AssuranceSummaryPanel';
import type { WorkflowAction } from '@/components/governance/BoardPriorityCard';
import {
    GovernanceTimeline,
    type TimelinePayload,
} from '@/components/governance/GovernanceTimeline';
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
    type TabKey,
} from '@/components/governance/PriorityOverviewPanel';
import {
    RecentlyCompletedRail,
    type CompletedItem,
} from '@/components/governance/RecentlyCompletedRail';
import {
    canDoGovernance,
    type GovernancePermissionMap,
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
    assurance?: AssurancePayload | null;
    next_meeting: NextMeetingPayload | null;
    timeline: TimelinePayload;
    recently_completed: CompletedItem[];
}

/** Home's "Show" filter: everything, only my own work, or only the board-wide picture. */
export type HomeView = 'all' | 'mine' | 'board';

export interface CockpitLayoutProps {
    cockpit: CockpitPayload;
    workflow: {
        summary: {
            total: number;
            critical: number;
            overdue: number;
            by_tab?: Record<TabKey, number>;
        };
        actions: WorkflowAction[];
        /** Server pagination for `actions` — further pages are fetched on demand. */
        pagination?: PriorityPagination | null;
    };
    /** The viewer's personal obligations — same source/totals as My work. */
    myWork?: MyWorkPreview | null;
    permissions: GovernancePermissionMap;
    view?: HomeView;
    onRefresh?: () => void;
}

/**
 * Governance Home body — one page for everyone, sized to the viewer.
 *
 * Members: Next meeting + My work, one Board assurance summary, then the top
 * 3 board priorities with "See all".
 *
 * Meeting managers (`governance.meetings.manage`) also get the meeting
 * preparation checklist, the other areas the board watches (as rows inside
 * Board assurance), priorities by area, what's changed since the last meeting
 * (with audit access), service and safety signals, and recently completed
 * work. No figure appears in two places.
 */
export function CockpitLayout({
    cockpit,
    workflow,
    myWork,
    permissions,
    view = 'all',
    onRefresh,
}: CockpitLayoutProps) {
    const isManager = canDoGovernance(permissions, 'meetings', 'manage');
    const canViewMeetings = canDoGovernance(permissions, 'meetings', 'view');
    const canViewRecords = canDoGovernance(permissions, 'view');
    const canViewAudit = canDoGovernance(permissions, 'audit', 'view');
    const cardsByKey = cockpit.cards_by_key ?? {};
    const showMine = view !== 'board';
    const showBoard = view !== 'mine';

    return (
        <div className="flex flex-col gap-5" data-dusk="governance-home">
            {showMine ? (
                <>
                    <div className="grid gap-5 lg:grid-cols-[1.25fr_1fr]">
                        <NextMeetingCard
                            nextMeeting={cockpit.next_meeting}
                            canViewMeetings={canViewMeetings}
                            canViewRecords={canViewRecords}
                            canScheduleMeeting={isManager}
                        />
                        <MyNextActionsRail myWork={myWork} />
                    </div>

                    {isManager ? (
                        <MeetingReadinessPanel
                            nextMeeting={cockpit.next_meeting}
                        />
                    ) : null}
                </>
            ) : null}

            {showBoard ? (
                <>
                    <AssuranceSummaryPanel
                        id="board-assurance"
                        assurance={cockpit.assurance}
                        permissions={permissions}
                        extras={isManager ? assuranceExtras(cardsByKey) : []}
                    />

                    <section
                        id="board-priorities"
                        className="flex flex-col gap-3"
                        aria-labelledby="board-priorities-heading"
                    >
                        <ListCaption
                            title={
                                <span id="board-priorities-heading">
                                    Board priorities
                                </span>
                            }
                            caption="Across the areas you can see — not only your own work"
                        />
                        <PriorityOverviewPanel
                            actions={workflow.actions}
                            summary={workflow.summary}
                            pagination={workflow.pagination ?? null}
                            collapsedCount={isManager ? 8 : 3}
                            showTabs={isManager}
                        />
                    </section>

                    {isManager && canViewAudit ? (
                        <GovernanceTimeline
                            timeline={cockpit.timeline}
                            onRetry={onRefresh}
                        />
                    ) : null}

                    {isManager ? (
                        <OperationalSignalsAccordion cardsByKey={cardsByKey} />
                    ) : null}

                    {isManager ? (
                        <RecentlyCompletedRail
                            items={cockpit.recently_completed ?? []}
                        />
                    ) : null}
                </>
            ) : null}
        </div>
    );
}

export default CockpitLayout;
