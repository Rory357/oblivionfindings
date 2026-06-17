import AppLayout from '@/layouts/app-layout';
import { SafeguardingConcernDialog, type ConcernDetail } from '@/components/safeguarding/concern-dialog';
import { Head, router } from '@inertiajs/react';

/**
 * Thin deep-link shell for /safeguarding/{id}. Renders the same
 * SafeguardingConcernDialog as the list's detail-over-list modal; closing
 * returns to the register. Reporters/assignees reach this without global
 * viewAny (the route is policy-protected), so it can't just open over the list.
 */
export default function SafeguardingConcernShell({ detail }: { detail: ConcernDetail }) {
    return (
        <AppLayout breadcrumbs={[{ title: 'Health & Safety', href: '/health-safety' }, { title: 'Safeguarding', href: '/safeguarding' }, { title: detail.reference_number, href: `/safeguarding/${detail.id}` }]}>
            <Head title={`Safeguarding · ${detail.reference_number}`} />
            <SafeguardingConcernDialog detail={detail} open onClose={() => router.visit('/safeguarding')} />
        </AppLayout>
    );
}
