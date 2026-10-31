/* Per-site hazards register (/sites/{id}/hazards). The global register's
 * chrome scoped to one site — same HazardRegister component, no duplicate
 * surface. Reached from the site profile's Hazards tab "View all". NZ-only. */
import {
    HazardRegister,
    type HazardRegisterData,
} from '@/components/health-safety/hazard-register';
import AppLayout from '@/layouts/app-layout';
import type { SharedData } from '@/types';
import { Head, usePage } from '@inertiajs/react';

type Props = HazardRegisterData & {
    site: { id: number; name: string; type: string; suburb?: string | null };
    recommendedHazards?: Array<{ key: string; label: string; hint: string }>;
};

export default function SiteHazardsIndex({
    site,
    recommendedHazards: _recommendedHazards,
    ...data
}: Props) {
    const canReadSite = !!usePage<SharedData>().props.auth.can?.sites?.viewAny;
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Hazards', href: '/compliance/hazards' },
                {
                    title: site.name,
                    href: canReadSite
                        ? `/sites/${site.id}`
                        : `/sites/${site.id}/hazards`,
                },
                { title: 'Hazards', href: `/sites/${site.id}/hazards` },
            ]}
        >
            <Head title={`${site.name} — Hazards`} />
            <div className="flex min-w-0 flex-col gap-5">
                <HazardRegister
                    baseUrl={`/sites/${site.id}/hazards`}
                    scopedSite={site}
                    data={data}
                />
            </div>
        </AppLayout>
    );
}
