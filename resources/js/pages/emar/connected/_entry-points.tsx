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
import { EmptyState } from '@/components/ui/empty-state';
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

export type ConnectedFeature =
    | 'prescriber_portal'
    | 'provider_transfers'
    | 'pharmacy_bridge'
    | 'picture_catalogue'
    | 'protected_backups';

/**
 * Whether a connected-care feature runs now (Settings › Connected services,
 * D4). eMAR pages receive the switches; elsewhere they are unknown and the
 * link follows the permission alone. The server always re-checks.
 */
export function featureRuns(
    can: { connected?: Record<string, boolean> },
    feature: ConnectedFeature,
): boolean {
    return can.connected === undefined ? true : Boolean(can.connected[feature]);
}

export function ConnectedEntryPoints() {
    const can = useConnectedAccess();
    const portal = featureRuns(can, 'prescriber_portal');
    const links = [
        {
            show:
                can.pharmacyConnectManage &&
                featureRuns(can, 'pharmacy_bridge'),
            title: 'Pharmacy connections',
            body: 'Approved house connections, sending and pharmacy acknowledgements.',
            href: '/emar/pharmacy-connections',
        },
        {
            // EA-086: order checkers reach it only while the portal runs.
            show:
                ((can.externalManage || can.ordersManage) && portal) ||
                (can.transfersManage && featureRuns(can, 'provider_transfers')),
            title: 'Connected care',
            body: 'Named prescriber access, medication requests and provider handovers.',
            href: '/emar/connected-care',
        },
        {
            show: can.catalogueManage && featureRuns(can, 'picture_catalogue'),
            title: 'Medicine picture library',
            body: 'Licensed product sources, exact image matching and review expiry.',
            href: '/emar/catalogue',
        },
        {
            // EA-144: only while backups run; recipients still need it.
            show:
                (can.backupsManage || (can.reportsView && can.reportsExport)) &&
                featureRuns(can, 'protected_backups'),
            title: 'Protected chart backups',
            body: 'House schedules, approved recipients and encrypted chart delivery.',
            href: '/emar/backups',
        },
    ].filter((l) => l.show);
    if (!links.length) {
        return (
            <EmptyState
                icon={Network}
                heading="No connected services are switched on"
                description={
                    can.settingsManage
                        ? 'Turn each service on in Medication settings › Connected services once it is set up.'
                        : 'A medication settings manager turns these on in Medication settings › Connected services.'
                }
                action={
                    can.settingsManage ? (
                        <Button asChild variant="outline">
                            <Link href="/emar/settings#connections/services">
                                Open Connected services settings
                            </Link>
                        </Button>
                    ) : undefined
                }
            />
        );
    }
    return (
        <div className="grid grid-cols-2 gap-5">
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
    const portal = featureRuns(can, 'prescriber_portal');
    const links = [
        {
            show: can.externalManage && portal,
            label: 'Prescriber access',
            href: '/emar/connected-care' + scope + '#access',
            icon: UserRoundCheck,
        },
        {
            show: (can.ordersManage || can.externalManage) && portal,
            label: 'Prescriber requests',
            href: '/emar/connected-care' + scope + '#requests',
            icon: FileCheck,
        },
        {
            show: can.transfersManage && featureRuns(can, 'provider_transfers'),
            label: 'Provider handovers',
            href: '/emar/connected-care' + scope + '#transfers',
            icon: ArrowLeftRight,
        },
        {
            show:
                can.pharmacyConnectManage &&
                featureRuns(can, 'pharmacy_bridge'),
            label: 'Pharmacy connections',
            href: '/emar/pharmacy-connections',
            icon: Truck,
        },
        {
            show: can.catalogueManage && featureRuns(can, 'picture_catalogue'),
            label: 'Medicine picture library',
            href: '/emar/catalogue',
            icon: Image,
        },
        {
            // EA-144: only while backups run; recipients still need it.
            show:
                (can.backupsManage || (can.reportsView && can.reportsExport)) &&
                featureRuns(can, 'protected_backups'),
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
