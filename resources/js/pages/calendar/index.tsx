import AppLayout from '@/layouts/app-layout';
import { Head } from '@inertiajs/react';

export default function CalendarIndex() {
    return (
        <AppLayout breadcrumbs={[{ title: 'Calendar', href: '/calendar' }]}>
            <Head title="Calendar" />
            <div className="p-4">Calendar (coming next)</div>
        </AppLayout>
    );
}
