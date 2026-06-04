import AppLayout from '@/layouts/app-layout';
import { Head } from '@inertiajs/react';
import SiteCalendar, { type EventTypeOption } from './SiteCalendar';
import { type SourceDef } from './_parts';

type SiteLite = { id: number; name: string; type: string };

interface Props {
    site: SiteLite;
    sites?: SiteLite[];
    people?: { id: number; name: string }[];
    sources: SourceDef[];
    eventTypes: EventTypeOption[];
    canCreate: boolean;
    canManage: boolean;
    canApprove: boolean;
    feedUrl: string | null;
}

export default function SiteCalendarPage({ site, sites, people, sources, eventTypes, canCreate, canManage, canApprove, feedUrl }: Props) {
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Sites', href: '/sites' },
                { title: site.name, href: `/sites/${site.id}` },
                { title: 'Calendar', href: `/sites/${site.id}/calendar` },
            ]}
        >
            <Head title={`${site.name} — Calendar`} />
            <SiteCalendar
                context="page"
                scope="site"
                site={site}
                sites={sites}
                people={people}
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
