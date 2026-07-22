import SiteCalendar, { type SiteCalendarProps } from '../calendar/SiteCalendar';
import { SiteProfileLockedState } from './site-profile-states';

export type SiteProfileCalendarData = Omit<
    SiteCalendarProps,
    'context' | 'scope'
> & { locked?: boolean };

export function SiteProfileCalendar({
    data,
}: {
    data: SiteProfileCalendarData;
}) {
    if (data.locked) return <SiteProfileLockedState label="Calendar" />;

    return (
        <SiteCalendar
            context="profile"
            scope="site"
            site={data.site}
            sites={data.sites}
            people={data.people}
            sources={data.sources}
            eventTypes={data.eventTypes}
            canCreate={data.canCreate}
            canManage={data.canManage}
            canApprove={data.canApprove}
            feedUrl={data.feedUrl}
            conflictPolicy={data.conflictPolicy}
            pendingApprovalCount={data.pendingApprovalCount}
            mineCount={data.mineCount}
            overdueCount={data.overdueCount}
        />
    );
}
