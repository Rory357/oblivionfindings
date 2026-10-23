import {
    SafeguardingConcernDialog,
    type ConcernDetail,
} from '@/components/safeguarding/concern-dialog';
import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';

/**
 * Thin deep-link shell for /safeguarding/{id}. Renders the same
 * SafeguardingConcernDialog as the list's detail-over-list modal; closing
 * returns to the permitted caller. Reporters/assignees reach this without global
 * viewAny (the route is policy-protected), so it can't just open over the list.
 */
export default function SafeguardingConcernShell({
    detail,
    returnUrl,
}: {
    detail: ConcernDetail;
    returnUrl: string;
}) {
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                {
                    title: detail.reference_number,
                    href: `/safeguarding/${detail.id}`,
                },
            ]}
        >
            <Head title={`Safeguarding · ${detail.reference_number}`} />
            <SafeguardingConcernDialog
                detail={detail}
                open
                onClose={() => router.visit(returnUrl)}
            />
        </AppLayout>
    );
}
