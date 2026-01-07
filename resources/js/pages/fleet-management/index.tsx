import AppLayout from '@/layouts/app-layout';
import { Head } from '@inertiajs/react';

export default function FleetManagementIndex() {
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Fleet Management', href: '/fleet-management' },
            ]}
        >
            <Head title="Fleet Management" />
            <div className="p-4">Fleet Management (coming next)</div>
        </AppLayout>
    );
}
