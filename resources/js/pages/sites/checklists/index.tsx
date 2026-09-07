import { Head } from '@inertiajs/react';

import type { ChecklistsData, SiteRef } from '@/components/checklists/types';
import { ChecklistsWorkspace } from '@/components/checklists/workspace';
import AppLayout from '@/layouts/app-layout';

type Props = ChecklistsData & {
    site: SiteRef;
    backHref: string;
    recommendedChecklists?: unknown;
};

export default function SiteChecklistsIndex(props: Props) {
    const { site, backHref, recommendedChecklists: _ignored, ...data } = props;

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Sites', href: '/sites' },
                { title: site.name, href: `/sites/${site.id}` },
                { title: 'Checklists', href: `/sites/${site.id}/checklists` },
            ]}
        >
            <Head title={`${site.name} — Checklists`} />
            <ChecklistsWorkspace
                scope={{ mode: 'site', site, backHref }}
                data={data as ChecklistsData}
            />
        </AppLayout>
    );
}
