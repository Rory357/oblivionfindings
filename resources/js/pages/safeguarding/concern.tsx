import type { ConcernDetail } from '@/components/safeguarding/concern-dialog';
import {
    SafeguardingConcernRecord,
    concernActionFromUrl,
    concernRecordHref,
    concernSectionFromUrl,
} from '@/components/safeguarding/concern-record';
import { titleCase } from '@/components/safeguarding/concern-stages';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { useEffect } from 'react';

/**
 * /safeguarding/{id} — the concern's own page (approved drawings pg-hsn-22 …
 * 22i). Reporters and leads reach it without register access (the route is
 * policy-protected), so Back returns to the permitted caller the server
 * resolved, and the register crumb shows only to people who can open it.
 * `?section=` and `?action=` choose where the record opens; neither grants
 * anything.
 */
export default function SafeguardingConcernPage({
    detail,
    returnUrl,
}: {
    detail: ConcernDetail;
    returnUrl: string;
}) {
    const page = usePage<SharedData>();
    const canSeeRegister = !!(
        page.props.auth.can as { safeguarding?: { viewAny?: boolean } } | undefined
    )?.safeguarding?.viewAny;
    const returnTo = new URLSearchParams(page.url.split('?')[1]?.split('#')[0] ?? '').get('return_to');
    const initialSection = concernSectionFromUrl(page.url) ?? 'overview';
    const initialAction = concernActionFromUrl(page.url);
    // The pane opens once; the address drops ?action= so a save (which reloads the page) doesn't reopen it.
    useEffect(() => {
        if (!initialAction) return;
        router.replace({
            url: concernRecordHref(detail.id, { section: initialSection, returnTo }),
            preserveState: true,
            preserveScroll: true,
        });
        // eslint-disable-next-line react-hooks/exhaustive-deps -- once, on arrival
    }, []);
    const title = detail.restricted
        ? 'Restricted concern'
        : `${titleCase(detail.concern_type)} — ${detail.people?.subject?.name ?? 'Subject withheld'}`;
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Home', href: '/dashboard' },
        ...(canSeeRegister
            ? [
                  { title: 'Health & safety', href: '/health-safety' },
                  { title: 'Safeguarding', href: '/safeguarding' },
              ]
            : []),
        { title, href: `/safeguarding/${detail.id}` },
    ];
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Safeguarding · ${detail.reference_number}`} />
            <SafeguardingConcernRecord
                key={detail.id}
                detail={detail}
                returnUrl={returnUrl}
                initialSection={initialSection}
                initialAction={initialAction}
                // Client-side only: a server load would record another viewing in the access log.
                onSectionChange={(section) =>
                    router.replace({
                        url: concernRecordHref(detail.id, { section, returnTo }),
                        preserveState: true,
                        preserveScroll: true,
                    })
                }
            />
        </AppLayout>
    );
}
