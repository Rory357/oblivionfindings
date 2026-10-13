import { PageHeader } from '@/components/page/page-header';
import { useEmarBreadcrumbs } from '@/hooks/use-emar-breadcrumbs';
import AppLayout from '@/layouts/app-layout';
import { Head } from '@inertiajs/react';
import { Link2 } from 'lucide-react';
import { ConnectedEntryPoints } from './connected/_entry-points';
export default function ConnectedServices() {
    // EA-079 (Lane C8): Home is the dashboard; Medication is the viewer's landing.
    const breadcrumbs = useEmarBreadcrumbs();
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
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
