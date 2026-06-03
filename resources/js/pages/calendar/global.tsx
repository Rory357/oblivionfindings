import AppLayout from '@/layouts/app-layout';
import { Head } from '@inertiajs/react';
import SiteCalendar, { type EventTypeOption } from '@/pages/sites/calendar/SiteCalendar';
import { type SourceDef } from '@/pages/sites/calendar/_parts';

type SiteLite = { id: number; name: string; type: string };

interface Props {
    sites: SiteLite[];
    sources: SourceDef[];
    eventTypes: EventTypeOption[];
    canCreate: boolean;
    canManage: boolean;
    canApprove: boolean;
    feedUrl: string | null;
}

export default function CalendarGlobal({ sites, sources, eventTypes, canCreate, canManage, canApprove, feedUrl }: Props) {
    return (
        <AppLayout breadcrumbs={[{ title: 'Calendar', href: '/calendar' }]}>
            <Head title="Site Calendar" />
            <SiteCalendar
                context="page"
                scope="global"
                sites={sites}
                sources={sources}
                eventTypes={eventTypes}
                canCreate={canCreate}
                canManage={canManage}
                canApprove={canApprove}
                feedUrl={feedUrl}
            />
        </AppLayout>
    );
}
