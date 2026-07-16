/* H&S Event — thin deep-link / share shell. The register opens the governance
 * modal over the list; this page renders the *same* HsEventDialog on its own so
 * a shared `/health-safety/events/{id}` URL works. Closing returns to the register. */
import {
    EventDetailDialog,
    type EventActionKey,
    type EventDetail,
} from '@/components/health-safety/event-detail-dialog';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';

function actionFromUrl(url: string): EventActionKey | null {
    const query = url.split('?')[1]?.split('#')[0] ?? '';
    const action = new URLSearchParams(query).get('action');

    return (
        (
            {
                'worksafe-decision': 'worksafe_decision',
                'worksafe-notify': 'worksafe_notify',
                'worksafe-acknowledge': 'worksafe_acknowledge',
            } as const
        )[action ?? ''] ?? null
    );
}

export default function HsEventShow({ detail }: { detail: EventDetail }) {
    const initialAction = actionFromUrl(usePage().url);
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Health & Safety', href: '/health-safety' },
        { title: 'Events', href: '/health-safety/events' },
        {
            title: detail.reference_number,
            href: `/health-safety/events/${detail.id}`,
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${detail.reference_number} · H&S Event`} />
            <EventDetailDialog
                detail={detail}
                open
                initialAction={initialAction}
                onClose={() => router.visit('/health-safety/events')}
            />
        </AppLayout>
    );
}
