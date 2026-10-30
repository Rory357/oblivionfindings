import { ProcedureWorkspace } from '@/components/health-safety/procedures/procedure-workspace';
import type {
    ProcedurePickers,
    ProcedureWorkspaceDetail,
} from '@/components/health-safety/procedures/types';
import AppLayout from '@/layouts/app-layout';
import {
    PROCEDURE_REGISTER,
    procedureHref,
} from '@/lib/health-safety/procedure-navigation';
import { Head } from '@inertiajs/react';

export default function SafeWorkProcedureShow({
    detail,
    pickers,
}: {
    detail: ProcedureWorkspaceDetail;
    pickers: ProcedurePickers;
    return_to?: string;
}) {
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Health & safety', href: '/health-safety' },
                { title: 'Safe work procedures', href: PROCEDURE_REGISTER },
                {
                    title: detail.title || detail.reference_number || 'Procedure',
                    href: procedureHref(detail.id),
                },
            ]}
        >
            <Head
                title={`${detail.reference_number ?? detail.title} · Safe work procedure`}
            />
            <ProcedureWorkspace
                key={detail.id}
                detail={detail}
                pickers={pickers}
            />
        </AppLayout>
    );
}
