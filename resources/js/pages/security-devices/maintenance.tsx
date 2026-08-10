import { PageHero, PageTabs } from '@/components/page';
import PageShell from '@/components/page-shell';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import {
    CheckCircle2,
    ClipboardCheck,
    Search,
    ShieldCheck,
    Wrench,
} from 'lucide-react';
import { FormEvent, useState } from 'react';

type NamedLink = { id: number; name: string; href: string };

export type MaintenanceRow = {
    id: number;
    type: string;
    status: string;
    schedule_state: string;
    description: string;
    scheduled_for: string | null;
    completed_at: string | null;
    performed_by: string | null;
    vendor_reference: string | null;
    device: NamedLink & { domain: string | null; category: string | null };
    site: NamedLink | null;
};

export type MaintenanceWorkspace = {
    tabs: Array<{ key: string; label: string }>;
    active_tab: string;
    boundary: { title: string; description: string; finance_note: string };
    summary: {
        total: number;
        overdue: number;
        due_soon: number;
        planned: number;
        in_progress: number;
        completed: number;
        calibration: number;
        firmware_configuration: number;
    };
    records: MaintenanceRow[];
    inventory: { total: number; shown: number; truncated: boolean };
    filters: {
        search: string | null;
        status: string | null;
        type: string | null;
        site_id: number | null;
        device_id: number | null;
        domain: string | null;
    };
    filter_options: {
        statuses: string[];
        types: string[];
        sites: Array<{ value: number; label: string }>;
        devices: Array<{ value: number; label: string }>;
    };
    permissions: { manage: boolean };
};

function title(value: string): string {
    return value
        .replace(/[_-]/g, ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function tone(value: string): StatusVariant {
    if (['completed'].includes(value)) return 'success';
    if (['overdue', 'cancelled'].includes(value)) return 'critical';
    if (['due_soon', 'in_progress', 'planned_unscheduled'].includes(value))
        return 'warning';
    if (['planned'].includes(value)) return 'info';
    return 'neutral';
}

function date(value: string | null): string {
    if (!value) return 'Not scheduled';
    return new Date(value).toLocaleDateString('en-NZ', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}

function Metric({
    label,
    value,
    note,
}: {
    label: string;
    value: number;
    note: string;
}) {
    return (
        <Card className="shadow-xs">
            <CardContent className="p-4">
                <p className="text-2xl font-semibold tabular-nums">{value}</p>
                <p className="text-sm font-medium">{label}</p>
                <p className="mt-1 text-xs text-muted-foreground">{note}</p>
            </CardContent>
        </Card>
    );
}

function Filters({ workspace }: { workspace: MaintenanceWorkspace }) {
    const [search, setSearch] = useState(workspace.filters.search ?? '');
    const apply = (changes: Record<string, string | number | null>) =>
        router.get(
            '/security-devices/maintenance',
            { ...workspace.filters, tab: workspace.active_tab, ...changes },
            { preserveState: true, preserveScroll: true },
        );
    const submit = (event: FormEvent) => {
        event.preventDefault();
        apply({ search: search || null });
    };

    return (
        <form
            className="grid gap-3 rounded-xl border bg-card p-4 md:grid-cols-2 xl:grid-cols-6"
            onSubmit={submit}
        >
            {workspace.filters.domain ? (
                <div className="flex min-h-11 flex-wrap items-center justify-between gap-2 rounded-lg bg-muted/40 px-3 text-sm md:col-span-2 xl:col-span-6">
                    <span className="font-medium">
                        Device domain: {title(workspace.filters.domain)}
                    </span>
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={() => apply({ domain: null })}
                    >
                        Clear domain scope
                    </Button>
                </div>
            ) : null}
            <label className="xl:col-span-2">
                <span className="sr-only">Search maintenance work</span>
                <div className="relative">
                    <Search className="absolute top-3.5 left-3 h-4 w-4 text-muted-foreground" />
                    <Input
                        className="min-h-11 pl-9"
                        placeholder="Search work, device, site, or reference"
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                    />
                </div>
            </label>
            <select
                aria-label="Filter by site"
                className="min-h-11 rounded-md border bg-background px-3 text-sm"
                value={workspace.filters.site_id ?? ''}
                onChange={(event) =>
                    apply({ site_id: event.target.value || null })
                }
            >
                <option value="">All sites</option>
                {workspace.filter_options.sites.map((site) => (
                    <option key={site.value} value={site.value}>
                        {site.label}
                    </option>
                ))}
            </select>
            <select
                aria-label="Filter by maintenance type"
                className="min-h-11 rounded-md border bg-background px-3 text-sm"
                value={workspace.filters.type ?? ''}
                onChange={(event) =>
                    apply({ type: event.target.value || null })
                }
            >
                <option value="">All work types</option>
                {workspace.filter_options.types.map((type) => (
                    <option key={type} value={type}>
                        {title(type)}
                    </option>
                ))}
            </select>
            <select
                aria-label="Filter by status"
                className="min-h-11 rounded-md border bg-background px-3 text-sm"
                value={workspace.filters.status ?? ''}
                onChange={(event) =>
                    apply({ status: event.target.value || null })
                }
            >
                <option value="">All statuses</option>
                {workspace.filter_options.statuses.map((status) => (
                    <option key={status} value={status}>
                        {title(status)}
                    </option>
                ))}
            </select>
            <Button className="min-h-11" type="submit">
                Apply search
            </Button>
        </form>
    );
}

export function MaintenanceCard({
    row,
    canManage,
}: {
    row: MaintenanceRow;
    canManage: boolean;
}) {
    const complete = () =>
        router.post(
            `/security-devices/maintenance/${row.id}/complete`,
            {},
            { preserveScroll: true },
        );

    return (
        <div className="rounded-xl border p-4">
            <div className="flex flex-col justify-between gap-4 lg:flex-row lg:items-start">
                <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-2">
                        <p className="font-semibold">{row.description}</p>
                        <StatusBadge variant={tone(row.schedule_state)}>
                            {title(row.schedule_state)}
                        </StatusBadge>
                        <StatusBadge variant="neutral">
                            {title(row.type)}
                        </StatusBadge>
                    </div>
                    <p className="mt-1 text-sm text-muted-foreground">
                        <Link
                            className="font-medium text-foreground hover:underline"
                            href={row.device.href}
                        >
                            {row.device.name}
                        </Link>
                        {row.site ? (
                            <>
                                {' · '}
                                <Link
                                    className="hover:underline"
                                    href={row.site.href}
                                >
                                    {row.site.name}
                                </Link>
                            </>
                        ) : null}
                    </p>
                    {row.vendor_reference ? (
                        <p className="mt-2 text-xs text-muted-foreground">
                            Work reference {row.vendor_reference}
                        </p>
                    ) : null}
                </div>
                <div className="flex flex-col items-start gap-2 lg:items-end">
                    <p className="text-sm font-medium">
                        {row.status === 'completed'
                            ? `Completed ${date(row.completed_at)}`
                            : `Scheduled ${date(row.scheduled_for)}`}
                    </p>
                    {row.performed_by ? (
                        <p className="text-xs text-muted-foreground">
                            Recorded by {row.performed_by}
                        </p>
                    ) : null}
                    {canManage &&
                    !['completed', 'cancelled'].includes(row.status) ? (
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            className="min-h-11"
                            onClick={complete}
                        >
                            <CheckCircle2 className="mr-2 h-4 w-4" />
                            Mark complete
                        </Button>
                    ) : null}
                </div>
            </div>
        </div>
    );
}

export default function Maintenance({
    workspace,
}: {
    workspace: MaintenanceWorkspace;
}) {
    const changeTab = (tab: string) =>
        router.get(
            '/security-devices/maintenance',
            { ...workspace.filters, tab },
            { preserveState: true, preserveScroll: true },
        );

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Security & Devices', href: '/security-devices' },
                { title: 'Maintenance', href: '/security-devices/maintenance' },
            ]}
        >
            <Head title="Maintenance - Security & Devices" />
            <PageShell>
                <PageHero
                    variant="compact"
                    icon={Wrench}
                    title="Maintenance"
                    description="Plan, perform, and reconcile device servicing, calibration, firmware, and configuration work across every site."
                    stats={[
                        { label: 'Overdue', value: workspace.summary.overdue },
                        {
                            label: 'Due soon',
                            value: workspace.summary.due_soon,
                        },
                        {
                            label: 'In progress',
                            value: workspace.summary.in_progress,
                        },
                        {
                            label: 'Completed',
                            value: workspace.summary.completed,
                        },
                    ]}
                />

                <Card className="border-primary/20 bg-primary/5 shadow-xs">
                    <CardContent className="grid gap-4 p-4 lg:grid-cols-[1.2fr_1fr]">
                        <div>
                            <div className="flex items-center gap-2 font-semibold">
                                <ShieldCheck className="h-5 w-5 text-primary" />
                                {workspace.boundary.title}
                            </div>
                            <p className="mt-1 text-sm text-muted-foreground">
                                {workspace.boundary.description}
                            </p>
                        </div>
                        <p className="text-xs text-muted-foreground">
                            {workspace.boundary.finance_note}
                        </p>
                    </CardContent>
                </Card>

                <PageTabs
                    value={workspace.active_tab}
                    onValueChange={changeTab}
                    items={workspace.tabs.map((tab) => ({
                        value: tab.key,
                        label: tab.label,
                    }))}
                />

                <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <Metric
                        label="Due or overdue"
                        value={
                            workspace.summary.overdue +
                            workspace.summary.due_soon
                        }
                        note={`${workspace.summary.overdue} already overdue`}
                    />
                    <Metric
                        label="Planned"
                        value={workspace.summary.planned}
                        note="Scheduled later or awaiting a date"
                    />
                    <Metric
                        label="Calibration"
                        value={workspace.summary.calibration}
                        note="All calibration records"
                    />
                    <Metric
                        label="Firmware & configuration"
                        value={workspace.summary.firmware_configuration}
                        note="Governed technical change work"
                    />
                </div>

                <Filters workspace={workspace} />

                <Card>
                    <CardHeader>
                        <CardTitle>
                            {workspace.tabs.find(
                                (tab) => tab.key === workspace.active_tab,
                            )?.label ?? 'Maintenance work'}
                        </CardTitle>
                        <CardDescription>
                            {workspace.inventory.total} matching records. Device
                            and site links use the same canonical profile
                            records as the rest of the application.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        {workspace.records.length ? (
                            workspace.records.map((row) => (
                                <MaintenanceCard
                                    key={row.id}
                                    row={row}
                                    canManage={workspace.permissions.manage}
                                />
                            ))
                        ) : (
                            <EmptyState
                                variant="compact"
                                icon={ClipboardCheck}
                                title="No maintenance work matches this view"
                                description="Choose another tab or clear a filter."
                            />
                        )}
                        {workspace.inventory.truncated ? (
                            <p className="rounded-lg bg-status-warning-bg p-3 text-sm text-status-warning">
                                Showing the first {workspace.inventory.shown} of{' '}
                                {workspace.inventory.total} records. Narrow the
                                filters to reconcile the full set.
                            </p>
                        ) : null}
                    </CardContent>
                </Card>
            </PageShell>
        </AppLayout>
    );
}
