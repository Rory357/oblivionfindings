import type { SlaSummary, SlaWatchdog } from '@/components/it/sla-evidence';
import {
    PageHeader,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderMeterDonut,
    PageHeaderPrimaryButton,
    PageHeaderStatusChip,
} from '@/components/page/page-header';
import { formatDateTime } from '@/lib/datetime';
import { BarChart3, BookOpen, Plus, Server, Ticket } from 'lucide-react';
import type { ReactNode } from 'react';

export interface ItHeroSummary {
    my: { total?: number; open: number; waiting: number; resolved_30d: number };
    tickets?: {
        open: number;
        unassigned: number;
        urgent_unassigned: number;
        urgent_open: number;
        at_risk: number;
        breached: number;
        awaiting_reply: number;
        conversation_ready?: boolean;
        awaiting_it?: number | null;
        waiting: number;
        resolved_30d: number;
        met_30d: number;
        measured_30d?: number;
        sla_open?: SlaSummary | null;
        sla_resolved_30d?: SlaSummary | null;
        sla_watchdog?: SlaWatchdog | null;
        by_status?: Record<string, number>;
        views?: Record<string, number | null>;
    };
    provisioning?: {
        pending: number;
        in_progress: number;
        failed: number;
        overdue: number;
        done_30d?: number;
        pending_over_7d?: number;
    };
}

/**
 * Service-desk content composed into the canonical Event Horizon header
 * (design_styles/PAGE_HEADER_STYLE_GUIDE.md). Graph-first meter row: the
 * 30-day SLA donut carries the real met/fully-measured fraction, queue counts
 * take safety tones with urgency detail in the captions, and provisioning
 * health gets its own block — every block deep-links to its queue view.
 */
export function ItHero({
    workspace = 'desk',
    workspaceTitle,
    knowledgeArticles = [],
    knowledgeSummary,
    onNewKnowledge,
    onNewProvisioning,
    summary,
    can,
    onRaise,
    onLog,
    rail,
    filters,
    search,
    actions,
}: {
    workspace?: 'desk' | 'provisioning' | 'knowledge' | 'reports';
    workspaceTitle?: string;
    knowledgeArticles?: { id: number; status?: string }[];
    knowledgeSummary?: {
        total?: number;
        published?: number;
        in_review?: number;
        draft?: number;
        overdue?: number;
    };
    onNewKnowledge?: () => void;
    onNewProvisioning?: () => void;
    summary: ItHeroSummary | null;
    can: { view: boolean; manage: boolean; request: boolean };
    onRaise: () => void;
    onLog: () => void;
    rail?: ReactNode;
    filters?: ReactNode;
    search?: ReactNode;
    actions?: ReactNode;
}) {
    if (workspace !== 'desk') {
        const provisioning = summary?.provisioning;
        const knowledgeCounts = [
            {
                label: 'All articles',
                count: knowledgeSummary?.total ?? knowledgeArticles.length,
                href: '/it/knowledge',
            },
            ...(['published', 'in_review', 'draft'] as const).map((status) => ({
                label: {
                    published: 'Published',
                    in_review: 'In review',
                    draft: 'Drafts',
                }[status],
                count:
                    knowledgeSummary?.[status] ??
                    knowledgeArticles.filter(
                        (article) => (article.status ?? 'published') === status,
                    ).length,
                href: `/it/knowledge?status=${status}`,
            })),
        ];
        const provisioningCounts = provisioning
            ? [
                  {
                      label: 'Pending',
                      count: provisioning.pending,
                      href: '/it/provisioning?status=pending',
                  },
                  {
                      label: 'In progress',
                      count: provisioning.in_progress,
                      href: '/it/provisioning?status=in_progress',
                  },
                  {
                      label: 'Failed',
                      count: provisioning.failed,
                      href: '/it/provisioning?status=failed',
                  },
              ]
            : [];
        const meters =
            workspace === 'knowledge'
                ? workspaceTitle === 'Guides'
                    ? knowledgeCounts.slice(0, 1)
                    : knowledgeCounts
                : workspace === 'provisioning'
                  ? provisioningCounts
                  : [];
        const create =
            workspace === 'knowledge'
                ? onNewKnowledge
                : workspace === 'provisioning'
                  ? onNewProvisioning
                  : undefined;

        return (
            <PageHeader
                className="overflow-clip!"
                icon={
                    workspace === 'knowledge'
                        ? BookOpen
                        : workspace === 'reports'
                          ? BarChart3
                          : Server
                }
                title={workspaceTitle ?? workspace}
                subline={
                    {
                        knowledge:
                            'Systems, runbooks and support guidance · Publications, revisions and review ownership',
                        provisioning:
                            'Account, access and equipment requests · Joiner, mover and leaver workflows',
                        reports:
                            'Service performance · Ticket demand, response times and delivery outcomes',
                    }[workspace]
                }
                actions={
                    <>
                        {search}
                        {actions}
                        {create && (
                            <PageHeaderPrimaryButton
                                icon={Plus}
                                onClick={create}
                            >
                                {workspace === 'knowledge'
                                    ? 'New KB article'
                                    : 'New request'}
                            </PageHeaderPrimaryButton>
                        )}
                    </>
                }
                meters={
                    meters.length > 0 ? (
                        <>
                            {meters.map((meter) => (
                                <PageHeaderMeterBlock
                                    key={meter.label}
                                    label={meter.label}
                                    href={meter.href}
                                    ariaLabel={`View ${meter.label.toLowerCase()}`}
                                >
                                    <PageHeaderMeterBig>
                                        {meter.count}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        Within your access
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                            ))}
                        </>
                    ) : undefined
                }
                filters={filters}
            />
        );
    }
    if (!summary) {
        return (
            <PageHeader
                className="overflow-clip!"
                icon={Ticket}
                title="IT & Support"
                subline="Knowledge · Author and review support documentation"
                actions={
                    <>
                        {search}
                        {actions}
                    </>
                }
                rail={rail}
                filters={filters}
            />
        );
    }
    const tickets = summary.tickets;
    const agent = can.view && tickets;

    const slaPercent =
        tickets && (tickets.measured_30d ?? 0) > 0
            ? Math.round((tickets.met_30d / tickets.measured_30d!) * 100)
            : null;
    const openCoverage = tickets?.sla_open;
    const incomplete = openCoverage
        ? openCoverage.total - openCoverage.by_coverage.full
        : (tickets?.open ?? 0);
    const watchdog = tickets?.sla_watchdog;

    const titleChip = agent ? (
        tickets.breached > 0 ? (
            <PageHeaderStatusChip variant="critical">
                {tickets.breached} breached
            </PageHeaderStatusChip>
        ) : tickets.at_risk > 0 ? (
            <PageHeaderStatusChip variant="warning">
                {tickets.at_risk} at risk
            </PageHeaderStatusChip>
        ) : incomplete > 0 ? (
            <PageHeaderStatusChip variant="neutral">
                {incomplete} incompletely measured
            </PageHeaderStatusChip>
        ) : watchdog?.state !== 'fresh' ? (
            <PageHeaderStatusChip variant="warning">
                {watchdog?.state === 'stale'
                    ? 'SLA watchdog stale'
                    : watchdog?.state === 'failed'
                      ? 'SLA watchdog failed'
                      : 'SLA watchdog unverified'}
            </PageHeaderStatusChip>
        ) : (
            <PageHeaderStatusChip variant="info">
                {tickets.open > 0
                    ? 'Measured clocks on track'
                    : 'No open tickets'}
            </PageHeaderStatusChip>
        )
    ) : summary.my.waiting > 0 ? (
        <PageHeaderStatusChip variant="warning">
            {summary.my.waiting} waiting
        </PageHeaderStatusChip>
    ) : summary.my.open > 0 ? (
        <PageHeaderStatusChip variant="info">
            {summary.my.open} open
        </PageHeaderStatusChip>
    ) : (
        <PageHeaderStatusChip variant="success">All clear</PageHeaderStatusChip>
    );

    return (
        <PageHeader
            className="overflow-clip!"
            icon={Ticket}
            title="Service Desk"
            titleChip={titleChip}
            subline={
                can.view
                    ? 'Ticket overview, queues and service requests'
                    : 'Get help · Raise and track your requests'
            }
            actions={
                <>
                    {search}
                    {actions}
                    {(can.manage || can.request) && (
                        <PageHeaderPrimaryButton
                            icon={Plus}
                            onClick={can.manage ? onLog : onRaise}
                        >
                            {can.manage ? 'Log ticket' : 'Get help'}
                        </PageHeaderPrimaryButton>
                    )}
                </>
            }
            meters={
                agent ? (
                    <>
                        {/* Row 1 — queue pressure (§5 two-row variant). */}
                        <div className="flex min-h-[80px] w-full flex-wrap items-stretch gap-2 empty:hidden">
                            <PageHeaderMeterBlock
                                label="Open tickets"
                                ariaLabel="View all open tickets"
                                href="/it?tab=tickets&view=all_open"
                            >
                                <PageHeaderMeterBig>
                                    {tickets.open}
                                </PageHeaderMeterBig>
                                <PageHeaderMeterCaption>
                                    {tickets.urgent_open} urgent
                                </PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                            <PageHeaderMeterBlock
                                label="Unassigned"
                                tone={
                                    tickets.urgent_unassigned > 0
                                        ? 'critical'
                                        : tickets.unassigned > 0
                                          ? 'warning'
                                          : 'success'
                                }
                                ariaLabel="View unassigned tickets"
                                href="/it?tab=tickets&view=unassigned"
                            >
                                <PageHeaderMeterBig>
                                    {tickets.unassigned}
                                </PageHeaderMeterBig>
                                <PageHeaderMeterCaption>
                                    {tickets.unassigned > 0
                                        ? `${tickets.urgent_unassigned} urgent · need an owner`
                                        : 'every ticket owned'}
                                </PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                            <PageHeaderMeterBlock
                                label="Breaching soon"
                                tone={tickets.at_risk > 0 ? 'warning' : 'brand'}
                                ariaLabel="View tickets breaching SLA soon"
                                href="/it?tab=tickets&view=breaching"
                            >
                                <PageHeaderMeterBig>
                                    {tickets.at_risk}
                                </PageHeaderMeterBig>
                                <PageHeaderMeterCaption>
                                    SLA at risk
                                </PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                            <PageHeaderMeterBlock
                                label="Breached"
                                tone={
                                    tickets.breached > 0 ? 'critical' : 'brand'
                                }
                                ariaLabel="View tickets with a breached SLA"
                                href="/it?tab=tickets&view=breached"
                            >
                                <PageHeaderMeterBig>
                                    {tickets.breached}
                                </PageHeaderMeterBig>
                                <PageHeaderMeterCaption>
                                    includes earlier breaches
                                </PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                        </div>
                        {/* Row 2 — flow & delivery. */}
                        <div className="flex min-h-[80px] w-full flex-wrap items-stretch gap-2 empty:hidden">
                            <PageHeaderMeterBlock
                                label="Awaiting first reply"
                                tone={
                                    tickets.awaiting_reply > 0
                                        ? 'warning'
                                        : 'success'
                                }
                                ariaLabel="View tickets awaiting their first reply"
                                href="/it?tab=tickets&view=awaiting_reply"
                            >
                                <PageHeaderMeterBig>
                                    {tickets.awaiting_reply}
                                </PageHeaderMeterBig>
                                <PageHeaderMeterCaption>
                                    first response not recorded
                                </PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                            {tickets.conversation_ready === true &&
                                typeof tickets.awaiting_it === 'number' && (
                                    <PageHeaderMeterBlock
                                        label="Awaiting IT"
                                        tone={
                                            tickets.awaiting_it > 0
                                                ? 'warning'
                                                : 'brand'
                                        }
                                        ariaLabel="View tickets currently awaiting IT"
                                        href="/it?tab=tickets&view=awaiting_it"
                                    >
                                        <PageHeaderMeterBig>
                                            {tickets.awaiting_it}
                                        </PageHeaderMeterBig>
                                        <PageHeaderMeterCaption>
                                            next public response is with IT
                                        </PageHeaderMeterCaption>
                                    </PageHeaderMeterBlock>
                                )}
                            <PageHeaderMeterBlock
                                label="Waiting"
                                ariaLabel="View all waiting work"
                                href="/it?tab=tickets&view=waiting"
                            >
                                <PageHeaderMeterBig>
                                    {tickets.waiting}
                                </PageHeaderMeterBig>
                                <PageHeaderMeterCaption>
                                    waiting for the next action
                                </PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                            {/* Only fully measured outcomes belong in the denominator. */}
                            <PageHeaderMeterBlock
                                label="SLA met · rolling 30d"
                                value={
                                    slaPercent !== null
                                        ? tickets.measured_30d
                                        : undefined
                                }
                                ariaLabel="View tickets resolved in the last 30 days"
                                href="/it?tab=tickets&view=recently_resolved"
                            >
                                {slaPercent !== null ? (
                                    <PageHeaderMeterDonut
                                        percent={slaPercent}
                                        caption={
                                            <>
                                                {tickets.met_30d} of{' '}
                                                {tickets.measured_30d}
                                                <br />
                                                fully measured met SLA
                                                <br />
                                                {tickets.resolved_30d -
                                                    (tickets.measured_30d ??
                                                        0)}{' '}
                                                excluded · incomplete
                                            </>
                                        }
                                    />
                                ) : (
                                    <>
                                        <PageHeaderMeterBig>
                                            —
                                        </PageHeaderMeterBig>
                                        <PageHeaderMeterCaption>
                                            {tickets.resolved_30d > 0
                                                ? `${tickets.resolved_30d} resolved · none fully measured`
                                                : 'nothing resolved yet'}
                                        </PageHeaderMeterCaption>
                                    </>
                                )}
                            </PageHeaderMeterBlock>
                            <PageHeaderMeterBlock
                                label="SLA measurement"
                                href="/it?tab=tickets&view=all_open"
                                ariaLabel="View open tickets and SLA measurement"
                                tone="brand"
                            >
                                {openCoverage && openCoverage.total > 0 ? (
                                    <PageHeaderMeterDonut
                                        percent={
                                            (openCoverage.by_coverage.full /
                                                openCoverage.total) *
                                            100
                                        }
                                        caption={
                                            <>
                                                {openCoverage.by_coverage.full}{' '}
                                                of {openCoverage.total} fully
                                                measured
                                                <br />
                                                {
                                                    openCoverage.by_state.paused
                                                }{' '}
                                                paused ·{' '}
                                                {
                                                    openCoverage.by_state
                                                        .unmeasured
                                                }{' '}
                                                unmeasured
                                            </>
                                        }
                                    />
                                ) : (
                                    <>
                                        <PageHeaderMeterBig>
                                            —
                                        </PageHeaderMeterBig>
                                        <PageHeaderMeterCaption>
                                            {openCoverage
                                                ? 'No open tickets to measure'
                                                : 'Measurement unavailable'}
                                        </PageHeaderMeterCaption>
                                    </>
                                )}
                            </PageHeaderMeterBlock>
                            <PageHeaderMeterBlock
                                label="SLA watchdog"
                                href="/it/reports"
                                ariaLabel="View SLA watchdog freshness in reports"
                                tone={
                                    watchdog?.state === 'fresh'
                                        ? 'brand'
                                        : 'warning'
                                }
                            >
                                <PageHeaderMeterBig>
                                    {watchdog?.state === 'fresh'
                                        ? 'Current'
                                        : watchdog?.state === 'stale'
                                          ? 'Stale'
                                          : watchdog?.state === 'failed'
                                            ? 'Failed'
                                            : watchdog?.state === 'running'
                                              ? 'Running'
                                              : 'Unverified'}
                                </PageHeaderMeterBig>
                                <PageHeaderMeterCaption>
                                    {watchdog?.last_success_at
                                        ? `Last success ${formatDateTime(watchdog.last_success_at)}`
                                        : 'No successful check recorded'}
                                    <br />
                                    Clocks calculated on page load
                                </PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                        </div>
                    </>
                ) : (
                    <>
                        <PageHeaderMeterBlock
                            label="My open tickets"
                            ariaLabel="View my tickets"
                            href="/it?tab=my-tickets"
                        >
                            <PageHeaderMeterBig>
                                {summary.my.open}
                            </PageHeaderMeterBig>
                            <PageHeaderMeterCaption>
                                track your requests
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                        <PageHeaderMeterBlock
                            label="Waiting"
                            tone={summary.my.waiting > 0 ? 'warning' : 'brand'}
                            ariaLabel="View my waiting tickets"
                            href="/it?tab=my-tickets"
                        >
                            <PageHeaderMeterBig>
                                {summary.my.waiting}
                            </PageHeaderMeterBig>
                            <PageHeaderMeterCaption>
                                check the latest update
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                        <PageHeaderMeterBlock
                            label="Resolved"
                            ariaLabel="View my resolved tickets"
                            href="/it?tab=my-tickets"
                        >
                            <PageHeaderMeterBig>
                                {summary.my.resolved_30d}
                            </PageHeaderMeterBig>
                            <PageHeaderMeterCaption>
                                last 30 days
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                    </>
                )
            }
            filters={filters}
            rail={rail}
        />
    );
}

export default ItHero;
