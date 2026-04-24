import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
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
import { Activity, ClipboardList, Filter, X } from 'lucide-react';
import { useState } from 'react';

type PaginatedData<T> = {
    data: T[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
    current_page: number;
    last_page: number;
    total: number;
};

type Observation = {
    id: number;
    client_id: number;
    observation_type: string;
    recorded_at: string;
    data: Record<string, unknown>;
    notes: string | null;
    is_flagged: boolean;
    client: { id: number; first_name: string; last_name: string; site_id: number | null } | null;
    recorder: { id: number; name: string } | null;
    shift: { id: number; starts_at: string; ends_at: string } | null;
    site: { id: number; name: string } | null;
    protocol_schedule: {
        id: number;
        due_at: string | null;
        status: string;
        protocol: { id: number; name: string; observation_type: string; frequency: string } | null;
    } | null;
};

type Stats = {
    total_7d: number;
    total_30d: number;
    by_type: Record<string, number>;
};

type FilterOptions = {
    clients: Array<{ id: number; first_name: string; last_name: string }>;
    sites: Array<{ id: number; name: string }>;
    staff: Array<{ id: number; name: string }>;
    observation_types: Array<{ value: string; label: string }>;
};

type Filters = {
    client_id?: string;
    observation_type?: string;
    recorded_by?: string;
    site_id?: string;
    date_from?: string;
    date_to?: string;
};

type Props = {
    observations: PaginatedData<Observation>;
    stats: Stats;
    filters: Filters;
    filter_options: FilterOptions;
};

function formatNzDate(iso: string): string {
    return new Date(iso).toLocaleDateString('en-NZ', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function summariseData(type: string, data: Record<string, unknown>): string {
    switch (type) {
        case 'vitals':
            return [
                data.systolic && data.diastolic ? `BP ${data.systolic}/${data.diastolic}` : null,
                data.pulse ? `Pulse ${data.pulse}` : null,
                data.temperature ? `Temp ${data.temperature}°C` : null,
                data.o2_saturation ? `O₂ ${data.o2_saturation}%` : null,
            ].filter(Boolean).join(', ');
        case 'weight':
            return data.weight_kg ? `${data.weight_kg} kg` : '';
        case 'bowel':
            return data.bristol_type ? `Bristol type ${data.bristol_type}` : '';
        case 'sleep':
            return [
                data.quality ? `${String(data.quality).charAt(0).toUpperCase()}${String(data.quality).slice(1)} sleep` : null,
                data.interruptions && Number(data.interruptions) > 0 ? `${data.interruptions} interruptions` : null,
            ].filter(Boolean).join(', ');
        case 'fluid_intake':
            return [data.amount_ml ? `${data.amount_ml}ml` : null, data.fluid_type].filter(Boolean).join(', ');
        case 'pain':
            return [data.score ? `Pain ${data.score}/10` : null, data.location].filter(Boolean).join(', ');
        default:
            return '';
    }
}

const ALL_SENTINEL = '__all__';

export default function ObservationRegister({
    observations,
    stats,
    filters,
    filter_options,
}: Props) {
    const [local, setLocal] = useState<Filters>({
        client_id: filters.client_id ?? '',
        observation_type: filters.observation_type ?? '',
        recorded_by: filters.recorded_by ?? '',
        site_id: filters.site_id ?? '',
        date_from: filters.date_from ?? '',
        date_to: filters.date_to ?? '',
    });

    const applyFilters = () => {
        const clean = Object.fromEntries(
            Object.entries(local).filter(([, v]) => v !== '' && v !== undefined),
        );
        router.get('/health-clinical/observations', clean, {
            preserveState: true,
            replace: true,
        });
    };

    const clearFilters = () => {
        setLocal({});
        router.get('/health-clinical/observations', {}, {
            preserveState: true,
            replace: true,
        });
    };

    const hasFilters = Object.values(local).some((v) => v !== '' && v !== undefined);

    const typeLabel = (value: string) =>
        filter_options.observation_types.find((t) => t.value === value)?.label ?? value;

    return (
        <AppLayout>
            <Head title="Observation Register — Health & Clinical" />

            <div className="mx-auto max-w-7xl space-y-6 p-4 sm:p-6">
                {/* Header */}
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight">
                            Observation Register
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            All clinical observations across clients.
                        </p>
                    </div>
                    <Link href="/health-clinical">
                        <Button variant="outline" size="sm">
                            Dashboard
                        </Button>
                    </Link>
                </div>

                {/* Hero Stats */}
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <div className="rounded-xl border bg-gradient-to-br from-violet-50 to-purple-50 p-4">
                        <p className="text-[10px] font-semibold uppercase tracking-wider text-primary">
                            Last 7 days
                        </p>
                        <p className="mt-1 text-2xl font-bold text-primary">
                            {stats.total_7d}
                        </p>
                    </div>
                    <div className="rounded-xl border bg-gradient-to-br from-blue-50 to-cyan-50 p-4">
                        <p className="text-[10px] font-semibold uppercase tracking-wider text-blue-500">
                            Last 30 days
                        </p>
                        <p className="mt-1 text-2xl font-bold text-blue-700">
                            {stats.total_30d}
                        </p>
                    </div>
                    {filter_options.observation_types.slice(0, 2).map((t) => (
                        <div
                            key={t.value}
                            className="rounded-xl border bg-gradient-to-br from-slate-50 to-gray-50 p-4"
                        >
                            <p className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
                                {t.label} (30d)
                            </p>
                            <p className="mt-1 text-2xl font-bold text-foreground">
                                {stats.by_type[t.value] ?? 0}
                            </p>
                        </div>
                    ))}
                </div>

                {/* Filters */}
                <Card>
                    <CardHeader className="pb-3">
                        <CardTitle className="flex items-center gap-2 text-sm">
                            <Filter className="h-4 w-4" /> Filters
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div className="grid grid-cols-2 gap-3 sm:grid-cols-6">
                            <div>
                                <Label className="text-xs">Client</Label>
                                <Select
                                    value={local.client_id || ALL_SENTINEL}
                                    onValueChange={(v) =>
                                        setLocal((f) => ({ ...f, client_id: v === ALL_SENTINEL ? '' : v }))
                                    }
                                >
                                    <SelectTrigger className="h-8 text-xs">
                                        <SelectValue placeholder="All clients" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={ALL_SENTINEL}>All clients</SelectItem>
                                        {filter_options.clients.map((c) => (
                                            <SelectItem key={c.id} value={String(c.id)}>
                                                {c.first_name} {c.last_name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div>
                                <Label className="text-xs">Type</Label>
                                <Select
                                    value={local.observation_type || ALL_SENTINEL}
                                    onValueChange={(v) =>
                                        setLocal((f) => ({ ...f, observation_type: v === ALL_SENTINEL ? '' : v }))
                                    }
                                >
                                    <SelectTrigger className="h-8 text-xs">
                                        <SelectValue placeholder="All types" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={ALL_SENTINEL}>All types</SelectItem>
                                        {filter_options.observation_types.map((t) => (
                                            <SelectItem key={t.value} value={t.value}>
                                                {t.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div>
                                <Label className="text-xs">Recorded by</Label>
                                <Select
                                    value={local.recorded_by || ALL_SENTINEL}
                                    onValueChange={(v) =>
                                        setLocal((f) => ({ ...f, recorded_by: v === ALL_SENTINEL ? '' : v }))
                                    }
                                >
                                    <SelectTrigger className="h-8 text-xs">
                                        <SelectValue placeholder="All staff" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={ALL_SENTINEL}>All staff</SelectItem>
                                        {filter_options.staff.map((s) => (
                                            <SelectItem key={s.id} value={String(s.id)}>
                                                {s.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div>
                                <Label className="text-xs">Site</Label>
                                <Select
                                    value={local.site_id || ALL_SENTINEL}
                                    onValueChange={(v) =>
                                        setLocal((f) => ({ ...f, site_id: v === ALL_SENTINEL ? '' : v }))
                                    }
                                >
                                    <SelectTrigger className="h-8 text-xs">
                                        <SelectValue placeholder="All sites" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={ALL_SENTINEL}>All sites</SelectItem>
                                        {filter_options.sites.map((s) => (
                                            <SelectItem key={s.id} value={String(s.id)}>
                                                {s.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div>
                                <Label className="text-xs">From</Label>
                                <Input
                                    type="date"
                                    className="h-8 text-xs"
                                    value={local.date_from ?? ''}
                                    onChange={(e) =>
                                        setLocal((f) => ({ ...f, date_from: e.target.value }))
                                    }
                                />
                            </div>
                            <div>
                                <Label className="text-xs">To</Label>
                                <Input
                                    type="date"
                                    className="h-8 text-xs"
                                    value={local.date_to ?? ''}
                                    onChange={(e) =>
                                        setLocal((f) => ({ ...f, date_to: e.target.value }))
                                    }
                                />
                            </div>
                        </div>
                        <div className="mt-3 flex gap-2">
                            <Button size="sm" onClick={applyFilters}>
                                Apply
                            </Button>
                            {hasFilters && (
                                <Button size="sm" variant="ghost" onClick={clearFilters} className="gap-1">
                                    <X className="h-3 w-3" /> Clear
                                </Button>
                            )}
                        </div>
                    </CardContent>
                </Card>

                {/* Table */}
                <Card>
                    <CardContent className="p-0">
                        {observations.data.length === 0 ? (
                            <div className="p-8 text-center text-sm text-muted-foreground">
                                No observations match the selected filters.
                            </div>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b bg-muted/40">
                                            <th className="px-4 py-3 text-left font-medium">Client</th>
                                            <th className="px-4 py-3 text-left font-medium">Type</th>
                                            <th className="px-4 py-3 text-left font-medium">Summary</th>
                                            <th className="px-4 py-3 text-left font-medium">Recorded</th>
                                            <th className="px-4 py-3 text-left font-medium">By</th>
                                            <th className="px-4 py-3 text-left font-medium">Site</th>
                                            <th className="px-4 py-3 text-left font-medium">Shift</th>
                                            <th className="px-4 py-3 text-left font-medium">Protocol</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {observations.data.map((obs) => (
                                            <tr
                                                key={obs.id}
                                                className={`border-b transition-colors hover:bg-muted/20 ${obs.is_flagged ? 'bg-red-50/40' : ''}`}
                                            >
                                                <td className="px-4 py-3">
                                                    {obs.client ? (
                                                        <Link
                                                            href={`/operations/clients/${obs.client.id}?tab=observations`}
                                                            className="font-medium text-blue-600 hover:underline"
                                                        >
                                                            {obs.client.first_name} {obs.client.last_name}
                                                        </Link>
                                                    ) : (
                                                        <span className="text-muted-foreground">—</span>
                                                    )}
                                                </td>
                                                <td className="px-4 py-3">
                                                    <Badge variant="outline" className="text-[10px]">
                                                        {typeLabel(obs.observation_type)}
                                                    </Badge>
                                                    {obs.is_flagged && (
                                                        <Badge variant="destructive" className="ml-1 text-[10px]">
                                                            Flagged
                                                        </Badge>
                                                    )}
                                                </td>
                                                <td className="max-w-[220px] truncate px-4 py-3 text-xs text-muted-foreground">
                                                    {summariseData(obs.observation_type, obs.data) || obs.notes || '—'}
                                                </td>
                                                <td className="whitespace-nowrap px-4 py-3 text-xs text-muted-foreground">
                                                    {formatNzDate(obs.recorded_at)}
                                                </td>
                                                <td className="px-4 py-3 text-xs">
                                                    {obs.recorder?.name ?? '—'}
                                                </td>
                                                <td className="px-4 py-3 text-xs text-muted-foreground">
                                                    {obs.site?.name ?? '—'}
                                                </td>
                                                <td className="px-4 py-3 text-xs">
                                                    {obs.shift ? (
                                                        <Badge variant="outline" className="text-[10px]">
                                                            Shift
                                                        </Badge>
                                                    ) : (
                                                        <span className="text-muted-foreground">Ad-hoc</span>
                                                    )}
                                                </td>
                                                <td className="px-4 py-3 text-xs">
                                                    {obs.protocol_schedule?.protocol ? (
                                                        <Badge
                                                            variant={obs.protocol_schedule.status === 'completed' ? 'secondary' : 'outline'}
                                                            className="text-[10px]"
                                                        >
                                                            {obs.protocol_schedule.protocol.name}
                                                        </Badge>
                                                    ) : (
                                                        <span className="text-muted-foreground">—</span>
                                                    )}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}

                        {/* Pagination */}
                        {observations.last_page > 1 && (
                            <div className="flex items-center justify-between border-t px-4 py-3">
                                <p className="text-xs text-muted-foreground">
                                    Page {observations.current_page} of {observations.last_page} ({observations.total} total)
                                </p>
                                <div className="flex gap-1">
                                    {observations.links.map((link, i) => (
                                        <Button
                                            key={i}
                                            variant={link.active ? 'default' : 'outline'}
                                            size="sm"
                                            className="h-7 min-w-[28px] px-2 text-xs"
                                            disabled={!link.url}
                                            onClick={() =>
                                                link.url &&
                                                router.get(link.url, {}, { preserveState: true })
                                            }
                                            dangerouslySetInnerHTML={{ __html: link.label }}
                                        />
                                    ))}
                                </div>
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
