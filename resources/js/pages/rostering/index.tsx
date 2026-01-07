import AppLayout from '@/layouts/app-layout';
import { Head } from '@inertiajs/react';

export default function RosteringIndex() {
    return (
        <AppLayout breadcrumbs={[{ title: 'Rostering', href: '/rostering' }]}>
            <Head title="Rostering" />
            <div className="p-4">Rostering (coming next)</div>
        </AppLayout>
    );
}
