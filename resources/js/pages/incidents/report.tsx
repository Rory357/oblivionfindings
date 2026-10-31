import {
    IncidentReportDialog,
    type IncidentReportClient,
    type IncidentReportDefaults,
    type IncidentReportSite,
} from '@/components/incidents/incident-report-dialog';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';

type Choice = { id: number; name: string };

/**
 * /incidents/report — report an incident or near miss as a full page (the
 * shared reporting page shell, as for raising a safeguarding concern). It is
 * where people who may report but can't open the incident register land from
 * /incidents/create, so the register crumb and the "Open incident" step show
 * only to people who can open it. Close returns to the caller the server
 * resolved.
 */
export default function IncidentReportPage({
    mode,
    clients,
    sites,
    staff,
    canManageFollowups,
    canOpenIncident,
    defaults,
    returnUrl,
}: {
    mode: 'incident' | 'near_miss';
    clients: IncidentReportClient[];
    sites: IncidentReportSite[];
    staff: Choice[];
    canManageFollowups: boolean;
    canOpenIncident: boolean;
    defaults: IncidentReportDefaults;
    returnUrl: string;
}) {
    const title =
        mode === 'near_miss' ? 'Report a near miss' : 'Report an incident';
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Home', href: '/dashboard' },
        ...(canOpenIncident
            ? [{ title: 'Incidents', href: '/incidents' }]
            : []),
        { title, href: '/incidents/report' },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />
            <IncidentReportDialog
                open
                presentation="page"
                mode={mode}
                entryContext="incidents"
                clients={clients}
                sites={sites}
                staff={staff}
                canManageFollowups={canManageFollowups}
                defaults={defaults}
                doneLabel={returnUrl === '/my-day' ? 'Back to My Day' : 'Done'}
                onClose={() => router.visit(returnUrl)}
                onOpenIncident={
                    canOpenIncident ? (url) => router.visit(url) : undefined
                }
            />
        </AppLayout>
    );
}
