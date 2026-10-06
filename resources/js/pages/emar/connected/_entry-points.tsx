import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import type { SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';
export function useConnectedAccess() {
    return usePage<SharedData>().props.auth?.can?.medications ?? {};
}
export function ConnectedEntryPoints() {
    const can = useConnectedAccess();
    const links = [
        {
            show: can.pharmacyConnectManage,
            title: 'Pharmacy connections',
            body: 'Approved house connections, sending and pharmacy acknowledgements.',
            href: '/emar/pharmacy-connections',
        },
        {
            show: can.externalManage || can.transfersManage || can.ordersManage,
            title: 'Connected care',
            body: 'Named prescriber access, medication requests and provider handovers.',
            href: '/emar/connected-care',
        },
        {
            show: can.catalogueManage,
            title: 'Medicine picture library',
            body: 'Licensed product sources, exact image matching and review expiry.',
            href: '/emar/catalogue',
        },
        {
            show: can.backupsManage && can.reportsView && can.reportsExport,
            title: 'Protected chart backups',
            body: 'House schedules, approved recipients and encrypted chart delivery.',
            href: '/emar/backups',
        },
    ].filter((l) => l.show);
    return (
        <div className="grid grid-cols-2 gap-4">
            {links.map((l) => (
                <Card key={l.href}>
                    <CardHeader>
                        <CardTitle>{l.title}</CardTitle>
                        <CardDescription>{l.body}</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <Button asChild variant="outline">
                            <Link href={l.href}>
                                Open {l.title.toLowerCase()}
                            </Link>
                        </Button>
                    </CardContent>
                </Card>
            ))}
        </div>
    );
}
