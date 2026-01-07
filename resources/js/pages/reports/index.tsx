import AppLayout from '@/layouts/app-layout';
import { Head } from '@inertiajs/react';

export default function ReportsIndex() {
    return (
        <AppLayout breadcrumbs={[{ title: 'Reports', href: '/reports' }]}>
            <Head title="Reports" />
            <div className="p-4">Reports (coming next)</div>
        </AppLayout>
    );
}
