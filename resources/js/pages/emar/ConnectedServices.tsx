import { PageHeader } from '@/components/page/page-header';
import AppLayout from '@/layouts/app-layout';
import { Head } from '@inertiajs/react';
import { Link2 } from 'lucide-react';
import { ConnectedEntryPoints } from './connected/_entry-points';
export default function ConnectedServices() {
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/my-day' },
                { title: 'Medication', href: '/meds/today' },
                { title: 'Connected services', href: '/emar/connections' },
            ]}
        >
            <Head title="Connected medication services" />
            <div className="space-y-5">
                <PageHeader
                    icon={Link2}
                    title="Connected medication services"
                    subline="Approved connections, named clinical access and protected chart delivery"
                />
                <ConnectedEntryPoints />
            </div>
        </AppLayout>
    );
}
