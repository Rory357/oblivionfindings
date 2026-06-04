import { Head } from '@inertiajs/react';

import { ChecklistsWorkspace } from '@/components/checklists/workspace';
import type { ChecklistsData } from '@/components/checklists/types';
import AppLayout from '@/layouts/app-layout';

export default function ChecklistsIndex(props: ChecklistsData) {
    return (
        <AppLayout breadcrumbs={[{ title: 'Checklists', href: '/checklists' }]}>
            <Head title="Checklists" />
            <div className="p-4">
                <ChecklistsWorkspace scope={{ mode: 'org' }} data={props} />
            </div>
        </AppLayout>
    );
}
