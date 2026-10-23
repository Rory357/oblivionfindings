import { DrillWorkspace } from '@/components/health-safety/drills/drill-workspace';
import type {
    DrillPickers,
    DrillWorkspaceDetail,
} from '@/components/health-safety/drills/types';
import AppLayout from '@/layouts/app-layout';
import {
    DRILL_REGISTER,
    drillHref,
} from '@/lib/health-safety/drill-navigation';
import { Head } from '@inertiajs/react';

export default function DrillShow({
    detail,
    pickers,
}: {
    detail: DrillWorkspaceDetail;
    pickers: DrillPickers;
    return_to?: string;
}) {
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Emergency drills', href: DRILL_REGISTER },
                {
                    title: detail.reference,
                    href: drillHref(detail.id),
                },
            ]}
        >
            <Head title={`${detail.reference} · Emergency drill`} />
            <DrillWorkspace key={detail.id} detail={detail} pickers={pickers} />
        </AppLayout>
    );
}
