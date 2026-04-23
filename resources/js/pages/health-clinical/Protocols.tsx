import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { Filter, FilePlus2, X } from 'lucide-react';
import { useState } from 'react';

type PaginatedData<T> = {
    data: T[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
    current_page: number;
    last_page: number;
    total: number;
};

type Protocol = {
    id: number;
    client_id: number;
    name: string;
    observation_type: string;
    observation_type_label: string;
    frequency: string;
    frequency_label: string;
    custom_frequency_hours: number | null;
    instructions: string | null;
    alert_if_missed_hours: number;
    is_active: boolean;
    starts_at: string | null;
    ends_at: string | null;
    created_at: string | null;
    updated_at: string | null;
    client: { id: number; first_name: string; last_name: string } | null;
    creator: { id: number; name: string } | null;
    schedule_counts: {
        total: number;
        pending: number;
        overdue: number;
        completed_30d: number;
    };
    has_schedule_history: boolean;
};

type Stats = {
    active_protocols: number;
    inactive_protocols: number;
    schedules_due: number;
    schedules_overdue: number;
    compliance_rate_30d: number;
};

type FilterOptions = {
    clients: Array<{ id: number; first_name: string; last_name: string }>;
    observation_types: Array<{ value: string; label: string }>;
    frequencies: Array<{ value: string; label: string }>;
    statuses: Array<{ value: string; label: string }>;
};

type Filters = {
    client_id?: string;
    observation_type?: string;
    frequency?: string;
    status?: string;
};

type Props = {
    protocols: PaginatedData<Protocol>;
    stats: Stats;
    filters: Filters;
    filter_options: FilterOptions;
    can_manage: boolean;
};

const ALL_SENTINEL = '__all__';

function formatDate(date: string | null): string {
    if (!date) {
        return '—';
    }

    return new Date(date).toLocaleDateString('en-NZ', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function formatFrequency(protocol: Protocol): string {
    if (protocol.frequency !== 'custom' || !protocol.custom_frequency_hours) {
        return protocol.frequency_label;
    }

    return `${protocol.frequency_label} (${protocol.custom_frequency_hours}h)`;
}

function describeWindow(protocol: Protocol): string {
    if (!protocol.starts_at && !protocol.ends_at) {
        return 'No date window';
    }

    if (protocol.starts_at && protocol.ends_at) {
        return `${protocol.starts_at} to ${protocol.ends_at}`;
    }

    if (protocol.starts_at) {
        return `Starts ${protocol.starts_at}`;
    }

    return `Ends ${protocol.ends_at}`;
}

export default function Protocols({
    protocols,
    stats,
    filters,
    filter_options,
    can_manage,
}: Props) {
    const [local, setLocal] = useState<Filters>({
        client_id: filters.client_id ?? '',
        observation_type: filters.observation_type ?? '',
        frequency: filters.frequency ?? '',
        status: filters.status ?? '',
    });

    const hasFilters = Object.values(local).some((value) => value !== '' && value !== undefined);

    const applyFilters = () => {
        const clean = Object.fromEntries(
            Object.entries(local).filter(([, value]) => value !== '' && value !== undefined),
        );

        router.get('/health-clinical/protocols', clean, {
            preserveState: true,
            replace: true,
        });
    };

    const clearFilters = () => {
        setLocal({});
        router.get('/health-clinical/protocols', {}, {
            preserveState: true,
            replace: true,
        });
    };

    const toggleActive = (protocol: Protocol) => {
        const action = protocol.is_active ? 'Deactivate' : 'Activate';

        if (!window.confirm(`${action} ${protocol.name}?`)) {
            return;
        }

        router.patch(
            `/health-clinical/protocols/${protocol.id}/toggle-active`,
            {},
            { preserveScroll: true },
        );
    };

    return (
        <AppLayout>
            <Head title="Protocol Management — Health & Clinical" />

            <div className="mx-auto max-w-7xl space-y-6 p-4 sm:p-6">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight">
                            Protocol Management
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Manage observation protocols and monitor basic adherence across clients.
                        </p>
                    </div>
                    <div className="flex gap-2">
                        {can_manage ? (
                            <Link href="/health-clinical/protocols/create">
                                <Button size="sm" className="gap-2">
                                    <FilePlus2 className="h-4 w-4" />
                                    New Protocol
                                </Button>
                            </Link>
                        ) : null}
                        <Link href="/health-clinical">
                            <Button variant="outline" size="sm">
                                Dashboard
                            </Button>
                        </Link>
                    </div>
                </div>

                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                    <div className="rounded-xl border bg-gradient-to-br from-emerald-50 to-green-50 p-4">
                        <p className="text-[10px] font-semibold uppercase tracking-wider text-emerald-600">
                            Active
                        </p>
                        <p className="mt-1 text-2xl font-bold text-emerald-700">
                            {stats.active_protocols}
                        </p>
                    </div>
                    <div className="rounded-xl border bg-gradient-to-br from-slate-50 to-gray-50 p-4">
                        <p className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
                            Inactive
                        </p>
                        <p className="mt-1 text-2xl font-bold text-foreground">
                            {stats.inactive_protocols}
                        </p>
                    </div>
                    <div className="rounded-xl border bg-gradient-to-br from-blue-50 to-cyan-50 p-4">
                        <p className="text-[10px] font-semibold uppercase tracking-wider text-blue-500">
                            Due Schedules
                        </p>
                        <p className="mt-1 text-2xl font-bold text-blue-700">
                            {stats.schedules_due}
                        </p>
                    </div>
                    <div className="rounded-xl border bg-gradient-to-br from-rose-50 to-orange-50 p-4">
                        <p className="text-[10px] font-semibold uppercase tracking-wider text-rose-500">
                            Overdue
                        </p>
                        <p className="mt-1 text-2xl font-bold text-rose-700">
                            {stats.schedules_overdue}
                        </p>
                    </div>
                    <div className="rounded-xl border bg-gradient-to-br from-violet-50 to-fuchsia-50 p-4">
                        <p className="text-[10px] font-semibold uppercase tracking-wider text-primary">
                            Compliance (30d)
                        </p>
                        <p className="mt-1 text-2xl font-bold text-primary">
                            {stats.compliance_rate_30d}%
                        </p>
                    </div>
                </div>

                <Card>
                    <CardHeader className="pb-3">
                        <CardTitle className="flex items-center gap-2 text-sm">
                            <Filter className="h-4 w-4" /> Filters
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                            <div>
                                <Label className="text-xs">Client</Label>
                                <Select
                                    value={local.client_id || ALL_SENTINEL}
                                    onValueChange={(value) =>
                                        setLocal((current) => ({
                                            ...current,
                                            client_id: value === ALL_SENTINEL ? '' : value,
                                        }))
                                    }
                                >
                                    <SelectTrigger className="h-8 text-xs">
                                        <SelectValue placeholder="All clients" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={ALL_SENTINEL}>All clients</SelectItem>
                                        {filter_options.clients.map((client) => (
                                            <SelectItem key={client.id} value={String(client.id)}>
                                                {client.first_name} {client.last_name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>

                            <div>
                                <Label className="text-xs">Observation Type</Label>
                                <Select
                                    value={local.observation_type || ALL_SENTINEL}
                                    onValueChange={(value) =>
                                        setLocal((current) => ({
                                            ...current,
                                            observation_type: value === ALL_SENTINEL ? '' : value,
                                        }))
                                    }
                                >
                                    <SelectTrigger className="h-8 text-xs">
                                        <SelectValue placeholder="All types" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={ALL_SENTINEL}>All types</SelectItem>
                                        {filter_options.observation_types.map((type) => (
                                            <SelectItem key={type.value} value={type.value}>
                                                {type.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>

                            <div>
                                <Label className="text-xs">Frequency</Label>
                                <Select
                                    value={local.frequency || ALL_SENTINEL}
                                    onValueChange={(value) =>
                                        setLocal((current) => ({
                                            ...current,
                                            frequency: value === ALL_SENTINEL ? '' : value,
                                        }))
                                    }
                                >
                                    <SelectTrigger className="h-8 text-xs">
                                        <SelectValue placeholder="All frequencies" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={ALL_SENTINEL}>All frequencies</SelectItem>
                                        {filter_options.frequencies.map((frequency) => (
                                            <SelectItem key={frequency.value} value={frequency.value}>
                                                {frequency.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>

                            <div>
                                <Label className="text-xs">Status</Label>
                                <Select
                                    value={local.status || ALL_SENTINEL}
                                    onValueChange={(value) =>
                                        setLocal((current) => ({
                                            ...current,
                                            status: value === ALL_SENTINEL ? '' : value,
                                        }))
                                    }
                                >
                                    <SelectTrigger className="h-8 text-xs">
                                        <SelectValue placeholder="All statuses" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={ALL_SENTINEL}>All statuses</SelectItem>
                                        {filter_options.statuses.map((status) => (
                                            <SelectItem key={status.value} value={status.value}>
                                                {status.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>

                        <div className="mt-3 flex gap-2">
                            <Button size="sm" onClick={applyFilters}>
                                Apply
                            </Button>
                            {hasFilters ? (
                                <Button size="sm" variant="ghost" onClick={clearFilters} className="gap-1">
                                    <X className="h-3 w-3" /> Clear
                                </Button>
                            ) : null}
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardContent className="p-0">
                        {protocols.data.length === 0 ? (
                            <div className="p-8 text-center text-sm text-muted-foreground">
                                No protocols match the selected filters.
                            </div>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b bg-muted/40">
                                            <th className="px-4 py-3 text-left font-medium">Client</th>
                                            <th className="px-4 py-3 text-left font-medium">Protocol</th>
                                            <th className="px-4 py-3 text-left font-medium">Observation</th>
                                            <th className="px-4 py-3 text-left font-medium">Frequency</th>
                                            <th className="px-4 py-3 text-left font-medium">Status</th>
                                            <th className="px-4 py-3 text-left font-medium">Adherence</th>
                                            <th className="px-4 py-3 text-left font-medium">Updated</th>
                                            {can_manage ? (
                                                <th className="px-4 py-3 text-right font-medium">Actions</th>
                                            ) : null}
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {protocols.data.map((protocol) => (
                                            <tr key={protocol.id} className="border-b align-top transition-colors hover:bg-muted/20">
                                                <td className="px-4 py-3">
                                                    {protocol.client ? (
                                                        <Link
                                                            href={`/operations/clients/${protocol.client.id}`}
                                                            className="font-medium text-blue-600 hover:underline"
                                                        >
                                                            {protocol.client.first_name} {protocol.client.last_name}
                                                        </Link>
                                                    ) : (
                                                        <span className="text-muted-foreground">—</span>
                                                    )}
                                                </td>
                                                <td className="max-w-[260px] px-4 py-3">
                                                    <p className="font-medium text-foreground">{protocol.name}</p>
                                                    <p className="mt-1 text-xs text-muted-foreground">
                                                        {protocol.instructions || 'No instructions'}
                                                    </p>
                                                </td>
                                                <td className="px-4 py-3">
                                                    <Badge variant="outline" className="text-[10px]">
                                                        {protocol.observation_type_label}
                                                    </Badge>
                                                </td>
                                                <td className="px-4 py-3 text-xs text-muted-foreground">
                                                    <p>{formatFrequency(protocol)}</p>
                                                    <p className="mt-1">Alert after {protocol.alert_if_missed_hours}h</p>
                                                </td>
                                                <td className="px-4 py-3">
                                                    <Badge
                                                        className={protocol.is_active
                                                            ? 'bg-emerald-100 text-emerald-800'
                                                            : 'bg-muted text-foreground'}
                                                    >
                                                        {protocol.is_active ? 'Active' : 'Inactive'}
                                                    </Badge>
                                                    <p className="mt-2 text-xs text-muted-foreground">
                                                        {describeWindow(protocol)}
                                                    </p>
                                                </td>
                                                <td className="px-4 py-3 text-xs">
                                                    {protocol.frequency === 'every_shift' && !protocol.has_schedule_history ? (
                                                        <span className="text-muted-foreground">
                                                            Shift-driven protocol
                                                        </span>
                                                    ) : (
                                                        <div className="space-y-1">
                                                            <p>Pending: {protocol.schedule_counts.pending}</p>
                                                            <p>Overdue: {protocol.schedule_counts.overdue}</p>
                                                            <p>Completed (30d): {protocol.schedule_counts.completed_30d}</p>
                                                        </div>
                                                    )}
                                                </td>
                                                <td className="px-4 py-3 text-xs text-muted-foreground">
                                                    <p>{formatDate(protocol.updated_at)}</p>
                                                    <p className="mt-1">{protocol.creator?.name ?? '—'}</p>
                                                </td>
                                                {can_manage ? (
                                                    <td className="px-4 py-3">
                                                        <div className="flex justify-end gap-2">
                                                            <Link href={`/health-clinical/protocols/${protocol.id}/edit`}>
                                                                <Button size="sm" variant="outline">
                                                                    Edit
                                                                </Button>
                                                            </Link>
                                                            <Button
                                                                size="sm"
                                                                variant="ghost"
                                                                onClick={() => toggleActive(protocol)}
                                                            >
                                                                {protocol.is_active ? 'Deactivate' : 'Activate'}
                                                            </Button>
                                                        </div>
                                                    </td>
                                                ) : null}
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}

                        {protocols.last_page > 1 ? (
                            <div className="flex items-center justify-between border-t px-4 py-3">
                                <p className="text-xs text-muted-foreground">
                                    Page {protocols.current_page} of {protocols.last_page} ({protocols.total} total)
                                </p>
                                <div className="flex gap-1">
                                    {protocols.links.map((link, index) => (
                                        <Button
                                            key={index}
                                            variant={link.active ? 'default' : 'outline'}
                                            size="sm"
                                            className="h-7 min-w-[28px] px-2 text-xs"
                                            disabled={!link.url}
                                            onClick={() =>
                                                link.url
                                                    ? router.get(link.url, {}, { preserveState: true })
                                                    : null
                                            }
                                            dangerouslySetInnerHTML={{ __html: link.label }}
                                        />
                                    ))}
                                </div>
                            </div>
                        ) : null}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
