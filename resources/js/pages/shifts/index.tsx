import AppLayout from '@/layouts/app-layout';
import { Head } from '@inertiajs/react';

export default function ShiftsIndex() {
    return (
        <AppLayout breadcrumbs={[{ title: 'Shifts', href: '/shifts' }]}>
            <Head title="Shifts" />
            <div className="p-4">Shifts (coming next)</div>
        </AppLayout>
    );
}
