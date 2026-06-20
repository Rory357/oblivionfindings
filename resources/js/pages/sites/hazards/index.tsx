/* Per-site hazards register (/sites/{id}/hazards). The global register's
 * chrome scoped to one site — same HazardRegister component, no duplicate
 * surface. Reached from the site profile's Hazards tab "View all". NZ-only. */
import AppLayout from '@/layouts/app-layout';
import { HazardRegister, type HazardRegisterData } from '@/components/health-safety/hazard-register';
import { Head } from '@inertiajs/react';

type Props = HazardRegisterData & {
    site: { id: number; name: string; type: string; suburb?: string | null };
    recommendedHazards?: Array<{ key: string; label: string; hint: string }>;
};

export default function SiteHazardsIndex({ site, recommendedHazards: _recommendedHazards, ...data }: Props) {
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Sites', href: '/sites' },
                { title: site.name, href: `/sites/${site.id}` },
                { title: 'Hazards', href: `/sites/${site.id}/hazards` },
            ]}
        >
            <Head title={`${site.name} — Hazards`} />
            <div className="flex flex-col p-6">
                <HazardRegister baseUrl={`/sites/${site.id}/hazards`} scopedSite={site} data={data} />
            </div>
        </AppLayout>
    );
}
