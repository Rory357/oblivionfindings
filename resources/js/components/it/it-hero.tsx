import {
    PageHeader,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderMeterDonut,
    PageHeaderPrimaryButton,
    PageHeaderStatusChip,
} from '@/components/page/page-header';
import { Plus, Ticket } from 'lucide-react';
import type { ReactNode } from 'react';

export interface ItHeroSummary {
    my: { open: number; waiting: number; resolved_30d: number };
    tickets?: {
        open: number;
        unassigned: number;
        urgent_unassigned: number;
        urgent_open: number;
        at_risk: number;
        breached: number;
        awaiting_reply: number;
        waiting: number;
        resolved_30d: number;
        met_30d: number;
    };
    provisioning?: {
        pending: number;
        in_progress: number;
        failed: number;
        overdue: number;
    };
}

/**
 * Service-desk content composed into the canonical Event Horizon header
 * (design_styles/PAGE_HEADER_STYLE_GUIDE.md). Graph-first meter row: the
 * 30-day SLA donut carries the real met/resolved fraction, queue counts
 * take safety tones with urgency detail in the captions, and provisioning
 * health gets its own block — every block deep-links to its queue view.
 */
export function ItHero({
    summary,
    can,
    onRaise,
    onLog,
    rail,
    filters,
    search,
    actions,
}: {
    summary: ItHeroSummary;
    can: { view: boolean; manage: boolean; request: boolean };
    onRaise: () => void;
    onLog: () => void;
    rail?: ReactNode;
    filters?: ReactNode;
    search?: ReactNode;
    actions?: ReactNode;
}) {
    const tickets = summary.tickets;
    const prov = summary.provisioning;
    const agent = can.view && tickets;

    const slaPercent =
        tickets && tickets.resolved_30d > 0
            ? Math.round((tickets.met_30d / tickets.resolved_30d) * 100)
            : null;

    const titleChip = agent ? (
        tickets.breached > 0 ? (
            <PageHeaderStatusChip variant="critical">
                {tickets.breached} breached
            </PageHeaderStatusChip>
        ) : tickets.at_risk > 0 ? (
            <PageHeaderStatusChip variant="warning">
                {tickets.at_risk} at risk
            </PageHeaderStatusChip>
        ) : (
            <PageHeaderStatusChip variant="success">
                SLA healthy
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
            icon={Ticket}
            title="IT & Support"
            titleChip={titleChip}
            subline={
                can.view
                    ? 'Service desk · Tickets, requests and service delivery'
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
                                tone={
                                    tickets.at_risk > 0 ? 'warning' : 'success'
                                }
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
                                    tickets.breached > 0
                                        ? 'critical'
                                        : 'success'
                                }
                                ariaLabel="View tickets with a breached SLA"
                                href="/it?tab=tickets&view=breached"
                            >
                                <PageHeaderMeterBig>
                                    {tickets.breached}
                                </PageHeaderMeterBig>
                                <PageHeaderMeterCaption>
                                    SLA overdue
                                </PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                        </div>
                        {/* Row 2 — flow & delivery. */}
                        <div className="flex min-h-[80px] w-full flex-wrap items-stretch gap-2 empty:hidden">
                            <PageHeaderMeterBlock
                                label="Awaiting reply"
                                tone={
                                    tickets.awaiting_reply > 0
                                        ? 'warning'
                                        : 'success'
                                }
                                ariaLabel="View tickets awaiting an agent reply"
                                href="/it?tab=tickets&view=awaiting_reply"
                            >
                                <PageHeaderMeterBig>
                                    {tickets.awaiting_reply}
                                </PageHeaderMeterBig>
                                <PageHeaderMeterCaption>
                                    conversation needs an agent
                                </PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                            <PageHeaderMeterBlock
                                label="Waiting"
                                ariaLabel="View all waiting work"
                                href="/it?tab=tickets&view=waiting"
                            >
                                <PageHeaderMeterBig>
                                    {tickets.waiting}
                                </PageHeaderMeterBig>
                                <PageHeaderMeterCaption>
                                    paused on requester or vendor
                                </PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                            {/* Real met/resolved fraction → donut; with nothing
                            resolved there is no honest share, so the block
                            falls back to the plain count (no-fake-data). */}
                            <PageHeaderMeterBlock
                                label="SLA met · 30d"
                                value={
                                    slaPercent !== null
                                        ? tickets.resolved_30d
                                        : undefined
                                }
                                ariaLabel="View recently resolved tickets"
                                href="/it?tab=tickets&view=recently_resolved"
                            >
                                {slaPercent !== null ? (
                                    <PageHeaderMeterDonut
                                        percent={slaPercent}
                                        caption={
                                            <>
                                                {tickets.met_30d} of{' '}
                                                {tickets.resolved_30d}
                                                <br />
                                                resolved in SLA
                                            </>
                                        }
                                    />
                                ) : (
                                    <>
                                        <PageHeaderMeterBig>
                                            0
                                        </PageHeaderMeterBig>
                                        <PageHeaderMeterCaption>
                                            nothing resolved yet
                                        </PageHeaderMeterCaption>
                                    </>
                                )}
                            </PageHeaderMeterBlock>
                            {prov ? (
                                <PageHeaderMeterBlock
                                    label="Provisioning"
                                    tone={
                                        prov.failed > 0
                                            ? 'critical'
                                            : prov.overdue > 0
                                              ? 'warning'
                                              : 'brand'
                                    }
                                    ariaLabel="View the provisioning queue"
                                    href="/it?tab=provisioning"
                                >
                                    <PageHeaderMeterBig>
                                        {prov.pending + prov.in_progress}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        {prov.failed > 0 || prov.overdue > 0
                                            ? `${prov.failed} failed · ${prov.overdue} overdue`
                                            : 'queue healthy'}
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                            ) : null}
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
