/* Global Homes & Sites Hazards register (/compliance/hazards). Thin wrapper
 * around the shared HazardRegister so this surface, the per-site register and
 * the site-profile embed all share one chrome + modal set. NZ-only, web-only. */
import {
    HazardRegister,
    type HazardRegisterData,
} from '@/components/health-safety/hazard-register';
import AppLayout from '@/layouts/app-layout';
import { Head } from '@inertiajs/react';

export default function ComplianceHazardsIndex(props: HazardRegisterData) {
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Hazards', href: '/compliance/hazards' },
            ]}
        >
            <Head title="Hazards" />
            <div className="flex min-w-0 flex-col gap-5">
                <HazardRegister baseUrl="/compliance/hazards" data={props} />
            </div>
        </AppLayout>
    );
}
