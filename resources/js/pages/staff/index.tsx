import AppLayout from '@/layouts/app-layout';
import { Head } from '@inertiajs/react';

export default function StaffIndex() {
    return (
        <AppLayout breadcrumbs={[{ title: 'Staff', href: '/staff' }]}>
            <Head title="Staff" />
            <div className="p-4">Staff (coming next)</div>
        </AppLayout>
    );
}
