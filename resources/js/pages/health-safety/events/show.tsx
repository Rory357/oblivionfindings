/* Permanent safety review workspace over the canonical event and its workflows. */
import {
    EventRecordWorkspace,
    type EventActionKey,
    type EventDetail,
    type EventSectionKey,
} from '@/components/health-safety/event-detail-dialog';
import AppLayout from '@/layouts/app-layout';
import {
    safetyReviewHref,
    safetyReviewPaneFromUrl,
    safetyReviewReturn,
} from '@/lib/health-safety/navigation';
import { type BreadcrumbItem } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';

export function actionFromUrl(url: string): EventActionKey | null {
    const query = url.split('?')[1]?.split('#')[0] ?? '';
    const action = new URLSearchParams(query).get('action');

    return (
        (
            {
                'accept-handover': 'accept_handover',
                'worksafe-decision': 'worksafe_decision',
                'worksafe-notify': 'worksafe_notify',
                'worksafe-acknowledge': 'worksafe_acknowledge',
                'worksafe-site-preservation': 'worksafe_site_preservation',
                'worksafe-site-release': 'worksafe_site_release',
                investigation: 'investigation',
                close: 'close',
                'add-action': 'add_action',
            } as const
        )[action?.replaceAll('_', '-') ?? ''] ?? null
    );
}

export function sectionFromUrl(url: string): EventSectionKey | null {
    const query = url.split('?')[1]?.split('#')[0] ?? '';
    const section = new URLSearchParams(query).get('section');
    const sections: EventSectionKey[] = [
        'overview',
        'handover',
        'investigation',
        'actions',
        'risk',
        'timeline',
        'evidence',
    ];

    return sections.includes(section as EventSectionKey)
        ? (section as EventSectionKey)
        : null;
}

export default function HsEventShow({ detail }: { detail: EventDetail }) {
    const pageUrl = usePage().url;
    const initialAction = actionFromUrl(pageUrl);
    const initialPane = safetyReviewPaneFromUrl(pageUrl);
    const initialSection =
        sectionFromUrl(pageUrl) ??
        (initialPane?.kind.startsWith('inv_')
            ? 'investigation'
            : initialPane?.kind.startsWith('ca_')
              ? 'actions'
              : 'overview');
    const params = new URLSearchParams(
        pageUrl.split('?')[1]?.split('#')[0] ?? '',
    );
    const returnTo = safetyReviewReturn(params.get('return_to'));
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Home', href: '/dashboard' },
        { title: 'Health & Safety', href: '/health-safety' },
        { title: 'Safety reviews', href: '/health-safety/events' },
        {
            title: detail.reference_number,
            href: `/health-safety/events/${detail.id}`,
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${detail.reference_number} · Safety review`} />
            <EventRecordWorkspace
                key={detail.id}
                detail={detail}
                initialSection={initialSection}
                initialAction={initialAction}
                initialPane={initialPane}
                returnHref={returnTo}
                onPaneChange={(pane, section) =>
                    router.replace({
                        url: safetyReviewHref(detail.id, {
                            pane,
                            section,
                            returnTo,
                        }),
                        preserveState: true,
                        preserveScroll: true,
                    })
                }
                onSectionChange={(section) =>
                    router.visit(
                        safetyReviewHref(detail.id, { section, returnTo }),
                        { preserveState: true, preserveScroll: true },
                    )
                }
            />
        </AppLayout>
    );
}
