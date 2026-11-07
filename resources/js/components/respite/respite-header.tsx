/**
 * The Respite workspace header: the Event Horizon page header
 * (design_styles/PAGE_HEADER_STYLE_GUIDE.md) with the workspace's views on
 * its rail. Every meter opens the view its number comes from. The rail
 * counters carry the referral, request, booking, stay and task counts, so no
 * meter repeats them (guide §11, H8).
 */
import {
    PageHeader,
    PageHeaderMeterBar,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
    PageHeaderRail,
    type PageHeaderRailItem,
} from '@/components/page/page-header';
import {
    CalendarCheck,
    CalendarDays,
    ClipboardCheck,
    Home,
    Inbox,
    LayoutDashboard,
    ListChecks,
    Plus,
} from 'lucide-react';
import type { RespiteStats, RespiteTab } from './types';

const count = (n: number, one: string, many = `${one}s`) =>
    `${n} ${n === 1 ? one : many}`;

export function RespiteHeader({
    stats,
    homeCount,
    openTasks,
    tab,
    onTab,
    onAddReferral,
}: {
    stats: RespiteStats;
    homeCount: number;
    openTasks: number;
    tab: RespiteTab;
    onTab: (tab: RespiteTab) => void;
    /** Omitted when the viewer can't add referrals. */
    onAddReferral?: () => void;
}) {
    const bedsFree = Math.max(0, stats.bedsTotal - stats.bedsOccupied);
    const bedsPct = stats.bedsTotal
        ? Math.round((stats.bedsOccupied / stats.bedsTotal) * 100)
        : 0;
    const compliance = stats.compliance;
    const complianceDetail = [
        compliance.notifiablePastDeadline
            ? `${compliance.notifiablePastDeadline} overdue notifiable`
            : null,
        compliance.notifiableDueSoon
            ? `${compliance.notifiableDueSoon} near deadline`
            : null,
        compliance.restraintsAwaitingReview
            ? `${compliance.restraintsAwaitingReview} restraint review`
            : null,
        compliance.bspAwaitingLink
            ? `${compliance.bspAwaitingLink} BSP link`
            : null,
        compliance.missingConsentRights
            ? `${compliance.missingConsentRights} consent / rights`
            : null,
        compliance.openComplaints
            ? count(compliance.openComplaints, 'complaint')
            : null,
    ].filter(Boolean);

    const views: PageHeaderRailItem<RespiteTab>[] = [
        { key: 'overview', label: 'Overview', icon: LayoutDashboard },
        {
            key: 'referrals',
            label: 'Referrals',
            icon: Inbox,
            count: stats.newReferrals || undefined,
        },
        {
            key: 'requests',
            label: 'Booking requests',
            icon: ClipboardCheck,
            count: stats.awaitingReview || undefined,
        },
        {
            key: 'bookings',
            label: 'Approved bookings',
            icon: CalendarCheck,
            count: stats.confirmedUpcoming || undefined,
        },
        { key: 'calendar', label: 'Calendar', icon: CalendarDays },
        {
            key: 'stays',
            label: 'Stays',
            icon: Home,
            count: stats.inHouse || undefined,
        },
        {
            key: 'tasks',
            label: 'Tasks',
            icon: ListChecks,
            count: openTasks || undefined,
        },
    ];

    return (
        <PageHeader
            icon={Home}
            title="Respite"
            subline={`Referrals, bookings and stays across ${count(homeCount, 'respite home')} · ${count(stats.inHouse, 'guest')} in house`}
            actions={
                onAddReferral ? (
                    <PageHeaderPrimaryButton
                        icon={Plus}
                        onClick={onAddReferral}
                    >
                        Add referral
                    </PageHeaderPrimaryButton>
                ) : undefined
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Respite beds"
                        value={`${stats.bedsOccupied}/${stats.bedsTotal}`}
                        tone={stats.fullHomes > 0 ? 'warning' : 'brand'}
                        ariaLabel="View the respite calendar"
                        onClick={() => onTab('calendar')}
                    >
                        <PageHeaderMeterBar percent={bedsPct} />
                        <PageHeaderMeterCaption>
                            {stats.bedsTotal === 0
                                ? 'No respite beds set up'
                                : stats.fullHomes > 0
                                  ? `${bedsPct}% occupied · ${count(stats.fullHomes, 'home')} full`
                                  : `${bedsPct}% occupied · ${bedsFree} free`}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="To triage"
                        tone={stats.crisisOpen > 0 ? 'critical' : 'brand'}
                        ariaLabel="View referrals to triage"
                        onClick={() => onTab('referrals')}
                    >
                        <PageHeaderMeterBig>
                            {stats.toTriage}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {stats.crisisOpen > 0
                                ? `${count(stats.crisisOpen, 'crisis referral')} open`
                                : 'No crisis referrals open'}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Waitlist"
                        ariaLabel="View waitlisted booking requests"
                        onClick={() => onTab('requests')}
                    >
                        <PageHeaderMeterBig>
                            {stats.waitlisted}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Requests waiting for a bed
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Funding"
                        tone={stats.fundingAttention > 0 ? 'warning' : 'brand'}
                        ariaLabel="View bookings with funding to check"
                        onClick={() => onTab('bookings')}
                    >
                        <PageHeaderMeterBig>
                            {stats.fundingAttention}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            Bookings pending or expiring
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Compliance"
                        tone={
                            stats.complianceAttention > 0 ? 'critical' : 'brand'
                        }
                        ariaLabel="View stays with compliance to follow up"
                        onClick={() => onTab('stays')}
                    >
                        <PageHeaderMeterBig>
                            {stats.complianceAttention}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            {complianceDetail.length > 0
                                ? complianceDetail.join(' · ')
                                : 'Nothing to follow up'}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            rail={
                <PageHeaderRail
                    items={views}
                    value={tab}
                    onSelect={onTab}
                    ariaLabel="Respite views"
                />
            }
        />
    );
}
