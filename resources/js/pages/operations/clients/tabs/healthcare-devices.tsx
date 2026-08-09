import { OperationalStateBadge } from '@/components/security-devices/estate-operations';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { formatDateTime } from '@/lib/datetime';
import { Link } from '@inertiajs/react';
import {
    Activity,
    ArrowRight,
    Battery,
    ExternalLink,
    HeartPulse,
    RadioTower,
    ShieldCheck,
    TicketCheck,
    Wrench,
} from 'lucide-react';

type TechnicalState = {
    battery: {
        level: number | null;
        updatedAt: string | null;
        state: string;
    };
    connectivity: { state: string; source: string };
    integration: { state: string; source: string };
    delivery: {
        state: string;
        lastSuccessfulAt: string | null;
        staleAfterMinutes: number;
    };
    flow: { state: string; label: string; description: string };
};

type HealthcareDevice = {
    id: number;
    name: string;
    category: string;
    subcategory: string | null;
    provider: string | null;
    status: string;
    health: string;
    last_seen_at: string | null;
    href: string;
    assignment: {
        type: string;
        assignmentType: string | null;
        label: string;
        assignedAt: string | null;
    } | null;
    technical: TechnicalState;
    monitoring: { state: string; enabledCount: number };
    maintenance: {
        nextServiceDue: string | null;
        openCount: number;
        overdueCount: number;
        next: unknown | null;
    } | null;
    it_tickets: Array<{
        id: number;
        reference: string;
        title: string;
        status: string;
        href: string;
    }>;
};

export type ClientHealthcareDevicesProjection = {
    boundary: { title: string; description: string };
    summary: {
        total: number;
        offline: number;
        data_flow_issues: number;
        overdue_calibration: number | null;
        maintenance_due: number | null;
    };
    devices: HealthcareDevice[];
    truncated: boolean;
    permissions: {
        clientContext: boolean;
        maintenance: boolean;
        it: boolean;
    };
    links: { healthcare: string; clinical: string | null };
};

function Stat({
    label,
    value,
    icon: Icon,
}: {
    label: string;
    value: number | string;
    icon: typeof Activity;
}) {
    return (
        <Card>
            <CardContent className="flex items-center justify-between gap-3 p-4">
                <div>
                    <p className="text-xs font-medium text-muted-foreground">
                        {label}
                    </p>
                    <p className="mt-1 text-2xl font-semibold">{value}</p>
                </div>
                <Icon className="h-5 w-5 text-muted-foreground" aria-hidden />
            </CardContent>
        </Card>
    );
}

function technicalLabel(value: string | null | undefined): string {
    return String(value ?? 'unknown').replace(/_/g, ' ');
}

function DeviceCard({ device }: { device: HealthcareDevice }) {
    return (
        <Card
            data-test={`client-healthcare-device-${device.id}`}
            data-testid={`client-healthcare-device-${device.id}`}
        >
            <CardContent className="space-y-4 p-5">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="min-w-0 space-y-1">
                        <div className="flex flex-wrap items-center gap-2">
                            <h3 className="font-semibold">{device.name}</h3>
                            <OperationalStateBadge
                                state={device.technical.flow.state}
                            />
                        </div>
                        <p className="text-sm text-muted-foreground">
                            {[
                                device.subcategory ?? device.category,
                                device.provider,
                            ]
                                .filter(Boolean)
                                .join(' · ')}
                        </p>
                        {device.assignment ? (
                            <p className="text-xs text-muted-foreground">
                                {device.assignment.label}
                            </p>
                        ) : null}
                    </div>
                    <Button asChild variant="outline" size="sm">
                        <Link href={device.href}>
                            Open device
                            <ArrowRight className="ml-2 h-4 w-4" aria-hidden />
                        </Link>
                    </Button>
                </div>

                <p className="text-sm text-muted-foreground">
                    {device.technical.flow.description}
                </p>

                <div className="flex flex-wrap gap-2">
                    <Badge variant="outline">
                        Connectivity:{' '}
                        {technicalLabel(device.technical.connectivity.state)}
                    </Badge>
                    <Badge variant="outline">
                        Integration:{' '}
                        {technicalLabel(device.technical.integration.state)}
                    </Badge>
                    <Badge variant="outline">
                        Delivery:{' '}
                        {technicalLabel(device.technical.delivery.state)}
                    </Badge>
                    <Badge variant="outline">
                        {device.monitoring.enabledCount} monitor
                        {device.monitoring.enabledCount === 1 ? '' : 's'}
                    </Badge>
                </div>

                <div className="grid gap-3 text-sm md:grid-cols-3">
                    <div>
                        <p className="text-xs text-muted-foreground">Battery</p>
                        <p className="mt-1 font-medium">
                            {device.technical.battery.level === null
                                ? 'Not reported'
                                : `${device.technical.battery.level}%`}
                        </p>
                    </div>
                    <div>
                        <p className="text-xs text-muted-foreground">
                            Last seen
                        </p>
                        <p className="mt-1 font-medium">
                            {device.last_seen_at
                                ? formatDateTime(device.last_seen_at)
                                : 'Not observed'}
                        </p>
                    </div>
                    <div>
                        <p className="text-xs text-muted-foreground">
                            Open work
                        </p>
                        <p className="mt-1 font-medium">
                            {device.maintenance
                                ? `${device.maintenance.openCount} maintenance`
                                : 'Maintenance restricted'}
                            {` · ${device.it_tickets.length} IT`}
                        </p>
                    </div>
                </div>

                {device.it_tickets.length > 0 ? (
                    <div className="space-y-2 border-t pt-3">
                        <p className="flex items-center gap-2 text-xs font-medium text-muted-foreground">
                            <TicketCheck className="h-3.5 w-3.5" aria-hidden />
                            Linked IT work
                        </p>
                        {device.it_tickets.map((ticket) => (
                            <Link
                                key={ticket.id}
                                href={ticket.href}
                                className="frontline-focus flex items-center justify-between gap-3 rounded-md px-1 py-1 text-sm hover:underline"
                            >
                                <span className="truncate">
                                    {ticket.reference} · {ticket.title}
                                </span>
                                <Badge variant="outline">
                                    {technicalLabel(ticket.status)}
                                </Badge>
                            </Link>
                        ))}
                    </div>
                ) : null}
            </CardContent>
        </Card>
    );
}

export function ClientHealthcareDevicesTab({
    data,
    isLoading = false,
    loadFailed = false,
}: {
    data: ClientHealthcareDevicesProjection | null | undefined;
    isLoading?: boolean;
    loadFailed?: boolean;
}) {
    if (loadFailed) {
        return (
            <EmptyState
                icon={RadioTower}
                title="Healthcare device status could not be loaded"
                description="The technical projection is temporarily unavailable. Leave this tab and return to try again."
            />
        );
    }

    if (isLoading || data === undefined) {
        return (
            <Card data-testid="client-healthcare-devices-loading">
                <CardContent className="p-6">
                    <p className="text-sm text-muted-foreground">
                        Loading technical device status…
                    </p>
                </CardContent>
            </Card>
        );
    }

    if (data === null) {
        return (
            <EmptyState
                icon={ShieldCheck}
                title="Healthcare device context unavailable"
                description="Your current Client and Security & Devices access does not allow this technical projection."
            />
        );
    }

    return (
        <div
            className="space-y-4"
            data-test="client-healthcare-devices"
            data-testid="client-healthcare-devices"
        >
            <Card className="border-primary/20">
                <CardContent className="flex flex-col gap-4 p-5 lg:flex-row lg:items-center lg:justify-between">
                    <div className="space-y-1">
                        <h2 className="flex items-center gap-2 text-lg font-semibold">
                            <HeartPulse className="h-5 w-5" aria-hidden />
                            Healthcare devices
                        </h2>
                        <p className="max-w-3xl text-sm text-muted-foreground">
                            Read-only technical status from the canonical
                            Security &amp; Devices register. Device assignment,
                            monitoring, maintenance, and IT work remain in their
                            owning modules.
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {data.links.clinical ? (
                            <Button asChild variant="outline" size="sm">
                                <Link href={data.links.clinical}>
                                    Open health monitoring
                                </Link>
                            </Button>
                        ) : null}
                        <Button asChild size="sm">
                            <Link href={data.links.healthcare}>
                                Open full device workspace
                                <ExternalLink
                                    className="ml-2 h-4 w-4"
                                    aria-hidden
                                />
                            </Link>
                        </Button>
                    </div>
                </CardContent>
            </Card>

            <Alert>
                <ShieldCheck className="h-4 w-4" aria-hidden />
                <AlertTitle>{data.boundary.title}</AlertTitle>
                <AlertDescription>{data.boundary.description}</AlertDescription>
            </Alert>

            <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                <Stat
                    label="Assigned devices"
                    value={data.summary.total}
                    icon={HeartPulse}
                />
                <Stat
                    label="Offline"
                    value={data.summary.offline}
                    icon={RadioTower}
                />
                <Stat
                    label="Data-flow issues"
                    value={data.summary.data_flow_issues}
                    icon={Activity}
                />
                <Stat
                    label="Calibration overdue"
                    value={data.summary.overdue_calibration ?? 'Restricted'}
                    icon={Wrench}
                />
            </div>

            <Card>
                <CardHeader>
                    <CardTitle className="text-base">
                        Assigned equipment
                    </CardTitle>
                    <p className="text-sm text-muted-foreground">
                        Connectivity, delivery, calibration, maintenance, and
                        linked IT context only.
                    </p>
                </CardHeader>
                <CardContent className="space-y-3">
                    {data.devices.length > 0 ? (
                        data.devices.map((device) => (
                            <DeviceCard key={device.id} device={device} />
                        ))
                    ) : (
                        <EmptyState
                            variant="compact"
                            icon={Battery}
                            title="No healthcare devices assigned"
                            description="No canonical healthcare-connected Device is currently assigned to this client."
                        />
                    )}
                    {data.truncated ? (
                        <p className="text-xs text-muted-foreground">
                            Only the first 50 devices are shown here. Open the
                            full device workspace for the complete inventory.
                        </p>
                    ) : null}
                </CardContent>
            </Card>
        </div>
    );
}
