import { IncidentDetailDialog, type IncidentDetail } from '@/components/incidents/incident-detail-dialog';
import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';

/**
 * Deep-link / shareable incident view. The full incident surface is the
 * IncidentDetailDialog (opened over the register); this thin shell renders the same
 * modal content for a direct /incidents/{id} link. Closing returns to the register.
 */
export default function IncidentShow({ detail }: { detail: IncidentDetail }) {
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Health & Safety', href: '/health-safety' },
                { title: 'Incidents', href: '/incidents' },
                { title: `INC-${detail.id}`, href: `/incidents/${detail.id}` },
            ]}
        >
            <Head title={`Incident INC-${detail.id}`} />
            <IncidentDetailDialog detail={detail} open onClose={() => router.visit('/incidents')} />
        </AppLayout>
    );
}
