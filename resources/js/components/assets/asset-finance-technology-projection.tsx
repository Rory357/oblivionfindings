import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Link } from '@inertiajs/react';
import {
    AlertTriangle,
    Building2,
    CheckCircle2,
    Cpu,
    ExternalLink,
    Landmark,
    LockKeyhole,
    Package,
} from 'lucide-react';

type ProjectionAction = {
    owner: string;
    label: string;
    href: string;
};

type ProjectionDevice = {
    id: number;
    device_uid: string;
    name: string | null;
    domain: string | null;
    category: string | null;
    provider: string | null;
    status: string | null;
    health: string | null;
    battery: number | null;
    last_seen_at: string | null;
    link_type: string | null;
    linked_at: string | null;
    href: string;
};

export type AssetFinanceTechnologyProjection = {
    boundary: {
        title: string;
        description: string;
        management: string;
    };
    reconciliation: {
        state: string;
        title: string;
        description: string;
        tone: 'success' | 'warning' | 'critical' | 'neutral';
        attention: boolean;
        actions: ProjectionAction[];
    };
    operational_asset: {
        id: number;
        name: string;
        asset_tag: string | null;
        category: string | null;
        status: string | null;
        site: string | null;
        active_assignments: number;
        href: string;
    } | null;
    finance: {
        id: number;
        name: string;
        asset_tag: string | null;
        category: string;
        status: string;
        purchase_date: string | null;
        purchase_cost: number;
        accumulated_depreciation: number;
        book_value: number;
        disposed_date: string | null;
        disposal_proceeds: number | null;
        capitalised: boolean;
        href: string;
    } | null;
    technology: {
        devices: ProjectionDevice[];
        truncated: boolean;
    } | null;
    permissions: {
        operational_asset: boolean;
        finance: boolean;
        technology: boolean;
    };
    links: {
        assets: string | null;
        finance: string | null;
        devices: string | null;
    };
};

const toneClasses = {
    success: 'border-status-success/30 bg-status-success-bg',
    warning: 'border-status-warning/30 bg-status-warning-bg',
    critical: 'border-status-critical/30 bg-status-critical-bg',
    neutral: 'border-border bg-muted/40',
};

const toneIconClasses = {
    success: 'text-status-success',
    warning: 'text-status-warning',
    critical: 'text-status-critical',
    neutral: 'text-muted-foreground',
};

function label(value: string | null | undefined): string {
    return value
        ? value
              .replace(/_/g, ' ')
              .replace(/\b\w/g, (letter) => letter.toUpperCase())
        : 'Not recorded';
}

function date(value: string | null): string {
    if (!value) return 'Not recorded';
    return new Intl.DateTimeFormat('en-NZ', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    }).format(new Date(value));
}

function money(value: number): string {
    return new Intl.NumberFormat('en-NZ', {
        style: 'currency',
        currency: 'NZD',
    }).format(value);
}

export function AssetFinanceTechnologyProjectionPanel({
    projection,
}: {
    projection: AssetFinanceTechnologyProjection;
}) {
    const {
        boundary,
        reconciliation,
        operational_asset: operationalAsset,
        finance,
        technology,
        permissions,
        links,
    } = projection;
    const ReconciliationIcon =
        reconciliation.tone === 'success' ? CheckCircle2 : AlertTriangle;

    return (
        <div className="space-y-5">
            <Card className="border-dashed">
                <CardContent className="flex flex-col gap-3 p-5 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <p className="text-sm font-semibold">
                            {boundary.title}
                        </p>
                        <p className="mt-1 max-w-4xl text-sm text-muted-foreground">
                            {boundary.description}
                        </p>
                        <p className="mt-2 text-xs text-muted-foreground">
                            {boundary.management}
                        </p>
                    </div>
                    <Badge variant="outline" className="w-fit shrink-0">
                        Read-only reconciliation
                    </Badge>
                </CardContent>
            </Card>

            <div
                className={`rounded-xl border p-4 ${toneClasses[reconciliation.tone]}`}
                data-reconciliation-state={reconciliation.state}
            >
                <div className="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                    <div className="flex items-start gap-3">
                        <ReconciliationIcon
                            className={`mt-0.5 h-5 w-5 shrink-0 ${toneIconClasses[reconciliation.tone]}`}
                        />
                        <div>
                            <p className="text-sm font-semibold">
                                {reconciliation.title}
                            </p>
                            <p className="mt-1 max-w-4xl text-sm text-muted-foreground">
                                {reconciliation.description}
                            </p>
                        </div>
                    </div>
                    {reconciliation.actions.length > 0 ? (
                        <div className="flex shrink-0 flex-wrap gap-2">
                            {reconciliation.actions.map((action) => (
                                <Button
                                    key={`${action.owner}-${action.href}`}
                                    asChild
                                    size="sm"
                                    variant="outline"
                                >
                                    <Link href={action.href}>
                                        {action.label}
                                        <ExternalLink className="ml-1.5 h-3.5 w-3.5" />
                                    </Link>
                                </Button>
                            ))}
                        </div>
                    ) : null}
                </div>
            </div>

            <div className="grid gap-5 xl:grid-cols-3">
                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2 text-base">
                            <Package className="h-4 w-4" />
                            Fleet &amp; Assets
                        </CardTitle>
                        <p className="text-sm text-muted-foreground">
                            Operational status, location, and assignments
                        </p>
                    </CardHeader>
                    <CardContent>
                        {operationalAsset ? (
                            <div className="space-y-3">
                                <div className="flex items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <Link
                                            href={operationalAsset.href}
                                            className="font-semibold text-primary hover:underline"
                                        >
                                            {operationalAsset.name}
                                        </Link>
                                        <p className="mt-1 text-xs text-muted-foreground">
                                            {[
                                                operationalAsset.asset_tag,
                                                operationalAsset.category,
                                                operationalAsset.site,
                                            ]
                                                .filter(Boolean)
                                                .join(' · ')}
                                        </p>
                                    </div>
                                    <Badge variant="outline">
                                        {label(operationalAsset.status)}
                                    </Badge>
                                </div>
                                <div className="rounded-lg bg-muted/40 px-3 py-2 text-sm">
                                    <span className="font-semibold tabular-nums">
                                        {operationalAsset.active_assignments}
                                    </span>{' '}
                                    active assignment
                                    {operationalAsset.active_assignments === 1
                                        ? ''
                                        : 's'}
                                </div>
                            </div>
                        ) : (
                            <RestrictedState
                                permitted={permissions.operational_asset}
                                available={false}
                                label="No accessible operational Asset is linked."
                                restrictedLabel="Fleet & Assets access required."
                            />
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2 text-base">
                            <Landmark className="h-4 w-4" />
                            Finance
                        </CardTitle>
                        <p className="text-sm text-muted-foreground">
                            Capitalisation, depreciation, and disposal
                        </p>
                    </CardHeader>
                    <CardContent>
                        {finance ? (
                            <div className="space-y-3">
                                <div className="flex items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <Link
                                            href={finance.href}
                                            className="font-semibold text-primary hover:underline"
                                        >
                                            {finance.name}
                                        </Link>
                                        <p className="mt-1 text-xs text-muted-foreground">
                                            Purchased{' '}
                                            {date(finance.purchase_date)}
                                        </p>
                                    </div>
                                    <Badge variant="outline">
                                        {label(finance.status)}
                                    </Badge>
                                </div>
                                <div className="grid grid-cols-2 gap-2 text-sm">
                                    <Metric
                                        label="Purchase cost"
                                        value={money(finance.purchase_cost)}
                                    />
                                    <Metric
                                        label="Book value"
                                        value={money(finance.book_value)}
                                    />
                                </div>
                                {finance.disposed_date ? (
                                    <p className="text-xs text-muted-foreground">
                                        Disposed {date(finance.disposed_date)}
                                        {finance.disposal_proceeds !== null
                                            ? ` · proceeds ${money(finance.disposal_proceeds)}`
                                            : ''}
                                    </p>
                                ) : (
                                    <p className="text-xs text-muted-foreground">
                                        {finance.capitalised
                                            ? 'Acquisition posted to the general ledger.'
                                            : 'Awaiting Finance capitalisation decision.'}
                                    </p>
                                )}
                            </div>
                        ) : (
                            <RestrictedState
                                permitted={permissions.finance}
                                available={false}
                                label="No Fixed Asset record is linked."
                                restrictedLabel="Finance Fixed Assets access required."
                            />
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader className="flex-row items-start justify-between gap-3 space-y-0">
                        <div>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <Cpu className="h-4 w-4" />
                                Security &amp; Devices
                            </CardTitle>
                            <p className="mt-1 text-sm text-muted-foreground">
                                Installed technology and technical health
                            </p>
                        </div>
                        {links.devices ? (
                            <Button asChild size="sm" variant="outline">
                                <Link href={links.devices}>Open Devices</Link>
                            </Button>
                        ) : null}
                    </CardHeader>
                    <CardContent className="p-0">
                        {technology ? (
                            technology.devices.length > 0 ? (
                                <div className="divide-y">
                                    {technology.devices.map((device) => (
                                        <Link
                                            key={device.id}
                                            href={device.href}
                                            className="flex items-center gap-3 px-5 py-3.5 hover:bg-muted/30"
                                        >
                                            <span className="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-muted text-muted-foreground">
                                                <Cpu className="h-4 w-4" />
                                            </span>
                                            <div className="min-w-0 flex-1">
                                                <p className="truncate text-sm font-semibold">
                                                    {device.name ||
                                                        device.device_uid}
                                                </p>
                                                <p className="mt-0.5 truncate text-xs text-muted-foreground">
                                                    {[
                                                        device.category,
                                                        device.provider,
                                                    ]
                                                        .filter(Boolean)
                                                        .join(' · ') ||
                                                        'Device details not recorded'}
                                                </p>
                                            </div>
                                            <Badge variant="outline">
                                                {label(
                                                    device.health ||
                                                        device.status,
                                                )}
                                            </Badge>
                                        </Link>
                                    ))}
                                    {technology.truncated ? (
                                        <p className="px-5 py-3 text-xs text-status-warning">
                                            Showing the first 50 installed
                                            Devices.
                                        </p>
                                    ) : null}
                                </div>
                            ) : (
                                <p className="px-5 py-8 text-center text-sm text-muted-foreground">
                                    No installed technology is linked.
                                </p>
                            )
                        ) : (
                            <div className="px-5 py-8">
                                <RestrictedState
                                    permitted={permissions.technology}
                                    available={false}
                                    label="Installed technology is not available."
                                    restrictedLabel="Security & Devices access required."
                                />
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>

            <div className="flex flex-wrap gap-2">
                {links.assets ? (
                    <Button asChild size="sm" variant="ghost">
                        <Link href={links.assets}>
                            <Building2 className="mr-1.5 h-4 w-4" />
                            Operational Asset
                        </Link>
                    </Button>
                ) : null}
                {links.finance ? (
                    <Button asChild size="sm" variant="ghost">
                        <Link href={links.finance}>
                            <Landmark className="mr-1.5 h-4 w-4" />
                            Fixed Assets register
                        </Link>
                    </Button>
                ) : null}
            </div>
        </div>
    );
}

function Metric({ label, value }: { label: string; value: string }) {
    return (
        <div className="rounded-lg bg-muted/40 px-3 py-2">
            <p className="text-xs text-muted-foreground">{label}</p>
            <p className="mt-0.5 font-semibold tabular-nums">{value}</p>
        </div>
    );
}

function RestrictedState({
    permitted,
    available,
    label,
    restrictedLabel,
}: {
    permitted: boolean;
    available: boolean;
    label: string;
    restrictedLabel: string;
}) {
    return (
        <div className="flex items-start gap-2 text-sm text-muted-foreground">
            <LockKeyhole className="mt-0.5 h-4 w-4 shrink-0" />
            <p>
                {!permitted ? restrictedLabel : available ? 'Available' : label}
            </p>
        </div>
    );
}
