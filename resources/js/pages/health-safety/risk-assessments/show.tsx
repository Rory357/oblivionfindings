import { RaWorkspace } from '@/components/health-safety/risk-assessments/ra-workspace';
import type {
    RaDetail,
    RaPickers,
} from '@/components/health-safety/risk-assessments/types';
import AppLayout from '@/layouts/app-layout';
import { riskHref, riskReturn } from '@/lib/health-safety/risk-navigation';
import { Head } from '@inertiajs/react';

export default function RiskAssessmentShow({
    detail,
    pickers,
    return_to,
}: {
    detail: RaDetail;
    pickers: RaPickers;
    return_to?: string;
}) {
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Health & safety', href: '/health-safety' },
                { title: 'Risk assessments', href: riskReturn(return_to) },
                { title: detail.title, href: riskHref(detail.id) },
            ]}
        >
            <Head title={`${detail.reference_number} · Risk assessment`} />
            <RaWorkspace key={detail.id} detail={detail} pickers={pickers} />
        </AppLayout>
    );
}
