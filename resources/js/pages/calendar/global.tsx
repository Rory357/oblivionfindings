import AppLayout from '@/layouts/app-layout';
import SiteCalendar, {
    type EventTypeOption,
} from '@/pages/sites/calendar/SiteCalendar';
import { type SourceDef } from '@/pages/sites/calendar/_parts';
import { Head } from '@inertiajs/react';

type SiteLite = { id: number; name: string; type: string };

interface Props {
    sites: SiteLite[];
    people?: { id: number; name: string }[];
    sources: SourceDef[];
    eventTypes: EventTypeOption[];
    canCreate: boolean;
    canManage: boolean;
    canApprove: boolean;
    feedUrl: string | null;
    pendingApprovalCount?: number;
    mineCount?: number;
    overdueCount?: number;
}

export default function CalendarGlobal({
    sites,
    people,
    sources,
    eventTypes,
    canCreate,
    canManage,
    canApprove,
    feedUrl,
    pendingApprovalCount,
    mineCount,
    overdueCount,
}: Props) {
    return (
        <AppLayout breadcrumbs={[{ title: 'Calendar', href: '/calendar' }]}>
            <Head title="Site Calendar" />
            <SiteCalendar
                context="page"
                scope="global"
                sites={sites}
                people={people}
                sources={sources}
                eventTypes={eventTypes}
                canCreate={canCreate}
                canManage={canManage}
                canApprove={canApprove}
                feedUrl={feedUrl}
                pendingApprovalCount={pendingApprovalCount}
                mineCount={mineCount}
                overdueCount={overdueCount}
            />
        </AppLayout>
    );
}
