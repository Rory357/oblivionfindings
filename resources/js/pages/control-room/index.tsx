import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
import AppLayout from '@/layouts/app-layout';
import { Head } from '@inertiajs/react';

export default function ControlRoomIndex({ alerts }) {
    return (
        <AppLayout
            breadcrumbs={[{ title: 'Control Room', href: '/control-room' }]}
        >
            <Head title="Control Room" />
            <PageShell>
                <PageHeader
                    title="Control Room"
                    description="Alerts created from Fleet signals. Triage and escalation live here."
                />

                <div className="rounded-md border p-4">
                    <div className="mb-3 text-sm font-medium">Recent alerts</div>
                    <div className="grid gap-2">
                        {alerts?.length ? (
                            alerts.map((alert) => (
                                <div
                                    key={alert.id}
                                    className="flex items-center justify-between rounded-md border p-2 text-xs"
                                >
                                    <div>
                                        <div className="font-medium">
                                            {alert.alert_type}
                                        </div>
                                        <div className="text-muted-foreground">
                                            {alert.triggered_at}
                                        </div>
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <Badge variant="secondary">
                                            {alert.severity}
                                        </Badge>
                                        <Badge>{alert.status}</Badge>
                                    </div>
                                </div>
                            ))
                        ) : (
                            <div className="text-sm text-muted-foreground">
                                No alerts yet.
                            </div>
                        )}
                    </div>
                </div>
            </PageShell>
        </AppLayout>
    );
}
