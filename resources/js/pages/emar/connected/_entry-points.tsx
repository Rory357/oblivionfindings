import { PageHeaderGlassButton } from '@/components/page/page-header';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import {
    ArrowLeftRight,
    ChevronDown,
    FileCheck,
    Image,
    Network,
    ShieldCheck,
    Truck,
    UserRoundCheck,
} from 'lucide-react';
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

/** One visible, permission-aware doorway from the everyday medication pages. */
export function ConnectedServicesMenu({ clientId }: { clientId?: number }) {
    const can = useConnectedAccess();
    const scope = clientId ? '?client_id=' + clientId : '';
    const links = [
        {
            show: can.externalManage,
            label: 'Prescriber access',
            href: '/emar/connected-care' + scope + '#access',
            icon: UserRoundCheck,
        },
        {
            show: can.ordersManage || can.externalManage,
            label: 'Prescriber requests',
            href: '/emar/connected-care' + scope + '#requests',
            icon: FileCheck,
        },
        {
            show: can.transfersManage,
            label: 'Provider handovers',
            href: '/emar/connected-care' + scope + '#transfers',
            icon: ArrowLeftRight,
        },
        {
            show: can.pharmacyConnectManage,
            label: 'Pharmacy connections',
            href: '/emar/pharmacy-connections',
            icon: Truck,
        },
        {
            show: can.catalogueManage,
            label: 'Medicine picture library',
            href: '/emar/catalogue',
            icon: Image,
        },
        {
            show: can.reportsView && can.reportsExport,
            label: 'Protected chart backups',
            href: '/emar/backups',
            icon: ShieldCheck,
        },
    ].filter((link) => link.show);
    if (!links.length) return null;
    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <PageHeaderGlassButton icon={Network}>
                    Connected services <ChevronDown className="size-3.5" />
                </PageHeaderGlassButton>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-64">
                <DropdownMenuLabel>Medication connections</DropdownMenuLabel>
                {links.map((link) => (
                    <DropdownMenuItem key={link.href} asChild>
                        <Link href={link.href}>
                            <link.icon className="size-4" />
                            {link.label}
                        </Link>
                    </DropdownMenuItem>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
