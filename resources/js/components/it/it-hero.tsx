import {
    PageHeader,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
} from '@/components/page/page-header';
import { Plus, Ticket } from 'lucide-react';
import type { ReactNode } from 'react';

export interface ItHeroSummary {
    my: { open: number; waiting: number; resolved_30d: number };
    tickets?: {
        open: number;
        unassigned: number;
        at_risk: number;
        breached: number;
        awaiting_reply: number;
        resolved_30d: number;
    };
}

/** Service-desk content composed into the canonical Event Horizon header. */
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
    const stats =
        can.view && tickets
            ? [
                  {
                      label: 'Open tickets',
                      value: tickets.open,
                      view: 'all_open',
                      caption: 'Current workload',
                  },
                  {
                      label: 'Unassigned',
                      value: tickets.unassigned,
                      view: 'unassigned',
                      caption: 'Needs an owner',
                  },
                  {
                      label: 'Breaching soon',
                      value: tickets.at_risk,
                      view: 'breaching',
                      caption: 'SLA at risk',
                      tone: 'warning' as const,
                  },
                  {
                      label: 'Breached',
                      value: tickets.breached,
                      view: 'breached',
                      caption: 'SLA overdue',
                      tone: 'critical' as const,
                  },
                  {
                      label: 'Awaiting reply',
                      value: tickets.awaiting_reply,
                      view: 'awaiting_reply',
                      caption: 'Conversation needs attention',
                  },
                  {
                      label: 'Resolved',
                      value: tickets.resolved_30d,
                      view: 'recently_resolved',
                      caption: 'Last 30 days',
                  },
              ]
            : [
                  {
                      label: 'My open tickets',
                      value: summary.my.open,
                      caption: 'Track your requests',
                  },
                  {
                      label: 'Waiting',
                      value: summary.my.waiting,
                      caption: 'Check the latest update',
                  },
                  {
                      label: 'Resolved',
                      value: summary.my.resolved_30d,
                      caption: 'Last 30 days',
                  },
              ];
    return (
        <PageHeader
            icon={Ticket}
            title="IT & Support"
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
            meters={stats.map((stat) => (
                <PageHeaderMeterBlock
                    key={stat.label}
                    label={stat.label}
                    tone={'tone' in stat ? stat.tone : undefined}
                    href={
                        can.view && 'view' in stat
                            ? `/it?tab=tickets&view=${stat.view}`
                            : '/it?tab=my-tickets'
                    }
                >
                    <PageHeaderMeterBig>{stat.value}</PageHeaderMeterBig>
                    <PageHeaderMeterCaption>
                        {stat.caption}
                    </PageHeaderMeterCaption>
                </PageHeaderMeterBlock>
            ))}
            filters={filters}
            rail={rail}
        />
    );
}

export default ItHero;
