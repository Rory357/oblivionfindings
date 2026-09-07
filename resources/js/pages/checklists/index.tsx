import { Head } from '@inertiajs/react';

import type { ChecklistsData } from '@/components/checklists/types';
import { ChecklistsWorkspace } from '@/components/checklists/workspace';
import AppLayout from '@/layouts/app-layout';

export default function ChecklistsIndex(props: ChecklistsData) {
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Checklists', href: '/checklists' },
            ]}
        >
            <Head title="Checklists" />
            <ChecklistsWorkspace scope={{ mode: 'org' }} data={props} />
        </AppLayout>
    );
}
