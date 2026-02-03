import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import AppLayout from '@/layouts/app-layout';
import { Head } from '@inertiajs/react';

export default function FleetMapsUsage({ rows }) {
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Fleet Management', href: '/fleet-management' },
                { title: 'Map Usage', href: '/fleet-management/maps-usage' },
            ]}
        >
            <Head title="Fleet Map Usage" />
            <PageShell>
                <PageHeader
                    title="Fleet Map Usage"
                    description="Basic counts for Google Maps usage by context."
                />
                <div className="rounded-md border p-4">
                    <div className="mb-3 text-sm font-medium">Usage</div>
                    <div className="grid gap-2 text-sm">
                        {rows?.length ? (
                            rows.map((row) => (
                                <div
                                    key={row.context}
                                    className="flex items-center justify-between rounded-md border p-2"
                                >
                                    <div>{row.context}</div>
                                    <div className="font-medium">
                                        {row.total}
                                    </div>
                                </div>
                            ))
                        ) : (
                            <div className="text-muted-foreground">
                                No usage recorded.
                            </div>
                        )}
                    </div>
                </div>
            </PageShell>
        </AppLayout>
    );
}
