/* H&S Event — thin deep-link / share shell. The register opens the governance
 * modal over the list; this page renders the *same* HsEventDialog on its own so
 * a shared `/health-safety/events/{id}` URL works. Closing returns to the register. */
import AppLayout from '@/layouts/app-layout';
import { EventDetailDialog, type EventDetail } from '@/components/health-safety/event-detail-dialog';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';

export default function HsEventShow({ detail }: { detail: EventDetail }) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Health & Safety', href: '/health-safety' },
        { title: 'Events', href: '/health-safety/events' },
        { title: detail.reference_number, href: `/health-safety/events/${detail.id}` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${detail.reference_number} · H&S Event`} />
            <EventDetailDialog detail={detail} open onClose={() => router.visit('/health-safety/events')} />
        </AppLayout>
    );
}
