import type { HazardDetail } from '@/components/health-safety/hazard-kit';
import { HazardWorkspace } from '@/components/health-safety/hazard-workspace';
import AppLayout from '@/layouts/app-layout';
import { hazardLocation } from '@/lib/health-safety/hazard-navigation';
import { Head, usePage } from '@inertiajs/react';
export default function HazardShow({ detail }: { detail: HazardDetail }) {
    const { returnTo } = hazardLocation(usePage().url);
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Hazards', href: returnTo },
                {
                    title: detail.reference_number,
                    href: '/hazards/' + detail.id,
                },
            ]}
        >
            <Head title={detail.reference_number + ' · Hazard'} />
            <HazardWorkspace key={detail.id} detail={detail} />
        </AppLayout>
    );
}
