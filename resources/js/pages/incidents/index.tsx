import AppLayout from '@/layouts/app-layout';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { formatDateTime } from '@/lib/date-format';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowRight,
    Calendar,
    CheckCircle2,
    Clock,
    Eye,
    FileEdit,
    Filter,
    Plus,
    Search,
    ShieldAlert,
    User,
    XCircle,
    Activity,
    Pill,
    Users,
    Shield,
    HelpCircle,
} from 'lucide-react';

type Props = {
    filters: {
        q: string;
        type: string | null;
        status: string | null;
        severity: string | null;
        client_id: string | number | null;
        reviewed: string | null;
        from: string | null;
        to: string | null;
    };
    incidents: any;
    clients?: Array<{ id: number; first_name: string; last_name: string }> | null;
};

const severityConfig: Record<string, { bg: string; text: string; dot: string; border: string }> = {
    low: { bg: 'bg-emerald-50', text: 'text-emerald-700', dot: 'bg-emerald-500', border: 'border-l-emerald-500' },
    medium: { bg: 'bg-amber-50', text: 'text-amber-700', dot: 'bg-amber-500', border: 'border-l-amber-500' },
    high: { bg: 'bg-red-50', text: 'text-red-700', dot: 'bg-red-500', border: 'border-l-red-500' },
    critical: { bg: 'bg-red-100', text: 'text-red-800', dot: 'bg-red-600', border: 'border-l-red-600' },
};

const statusConfig: Record<string, { bg: string; text: string; icon: typeof Clock }> = {
    draft: { bg: 'bg-slate-100', text: 'text-slate-700', icon: FileEdit },
    submitted: { bg: 'bg-blue-100', text: 'text-blue-700', icon: Clock },
    reviewed: { bg: 'bg-purple-100', text: 'text-purple-700', icon: CheckCircle2 },
    closed: { bg: 'bg-green-100', text: 'text-green-700', icon: CheckCircle2 },
};

const typeIcons: Record<string, typeof AlertTriangle> = {
    injury: Activity,
    behaviour: Users,
    medication: Pill,
    safeguarding: Shield,
    near_miss: Eye,
    property_damage: AlertTriangle,
    missing_person: Search,
    complaint: XCircle,
    other: HelpCircle,
    fall: AlertTriangle,
};

export default function IncidentsIndex({ filters, incidents, clients }: Props) {
    const ANY = '__any__';
    const { auth, labels } = usePage().props as any;
    const can = auth?.can?.incidents ?? {};
    const clientSingular = labels?.['client.singular'] ?? 'Client';

    const onFilter = (next: Partial<typeof filters>) => {
        router.get('/incidents', { ...filters, ...next }, { preserveState: true, preserveScroll: true });
    };

    // Compute quick stats from current page data
    const data = incidents?.data ?? [];
    const total = incidents?.total ?? data.length;
    const draftCount = data.filter((i: any) => i.status === 'draft').length;
    const highCount = data.filter((i: any) => i.severity === 'high' || i.severity === 'critical').length;

    return (
        <AppLayout breadcrumbs={[{ title: 'Incidents', href: '/incidents' }]}>
            <Head title="Incidents" />

            <div className="space-y-4">
                {/* Header */}
                <div className="flex items-start justify-between gap-3">
                    <div className="flex items-center gap-3">
                        <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-red-100">
                            <ShieldAlert className="h-5 w-5 text-red-600" />
                        </div>
                        <div>
                            <h1 className="text-lg font-semibold">
                                {filters.type === 'near_miss' ? 'Near Misses' : 'Incidents'}
                                {filters.type && filters.type !== 'near_miss' && (
                                    <span className="ml-2 text-sm font-normal text-slate-500 capitalize">({filters.type.replace(/_/g, ' ')})</span>
                                )}
                            </h1>
                            <div className="text-sm text-slate-500">
                                {filters.type
                                    ? <span>Filtered by type &middot; <button className="text-primary underline" onClick={() => onFilter({ type: null })}>Clear filter</button></span>
                                    : (can.viewAny ? 'All incidents' : 'Incidents for assigned clients')
                                }
                            </div>
                        </div>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        {can.templatesManage && (
                            <Link href="/incidents/templates" className="rounded-md border px-3 py-2 text-xs hover:bg-muted">
                                Templates
                            </Link>
                        )}
                        {can.create && (
                            <Link href="/incidents/create">
                                <Button size="sm">
                                    <Plus className="mr-1 h-4 w-4" />
                                    New incident
                                </Button>
                            </Link>
                        )}
                    </div>
                </div>

                {/* Quick stats */}
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <div className="rounded-lg border bg-white p-3">
                        <div className="text-2xl font-bold">{total}</div>
                        <div className="text-xs text-slate-500">Total incidents</div>
                    </div>
                    <div className="rounded-lg border bg-white p-3">
                        <div className="text-2xl font-bold text-slate-600">{draftCount}</div>
                        <div className="text-xs text-slate-500">Drafts on page</div>
                    </div>
                    <div className="rounded-lg border bg-white p-3">
                        <div className={`text-2xl font-bold ${highCount > 0 ? 'text-red-600' : 'text-slate-600'}`}>{highCount}</div>
                        <div className="text-xs text-slate-500">High severity on page</div>
                    </div>
                    <div className="rounded-lg border bg-white p-3">
                        <div className="text-2xl font-bold text-blue-600">{data.filter((i: any) => i.status === 'submitted').length}</div>
                        <div className="text-xs text-slate-500">Awaiting review</div>
                    </div>
                </div>

                {/* Filters */}
                <Card>
                    <CardContent className="pt-4">
                        <div className="mb-3 flex items-center gap-2 text-sm font-medium text-slate-700">
                            <Filter className="h-4 w-4" />
                            Filters
                        </div>
                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-6">
                            <div className="sm:col-span-2">
                                <Label className="text-xs text-slate-500">Search</Label>
                                <div className="relative">
                                    <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-slate-400" />
                                    <Input
                                        placeholder="Type, text, name..."
                                        className="pl-9"
                                        value={filters.q || ''}
                                        onChange={(e) => onFilter({ q: e.target.value })}
                                    />
                                </div>
                            </div>

                            {clients?.length ? (
                                <div>
                                    <Label className="text-xs text-slate-500">{clientSingular}</Label>
                                    <Select
                                        value={filters.client_id ? String(filters.client_id) : ANY}
                                        onValueChange={(v) => onFilter({ client_id: v === ANY ? null : v })}
                                    >
                                        <SelectTrigger><SelectValue placeholder={clientSingular} /></SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value={ANY}>Any</SelectItem>
                                            {clients.map((c) => (
                                                <SelectItem key={c.id} value={String(c.id)}>{c.first_name} {c.last_name}</SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                            ) : null}

                            <div>
                                <Label className="text-xs text-slate-500">Type</Label>
                                <Select
                                    value={filters.type ?? ANY}
                                    onValueChange={(v) => onFilter({ type: v === ANY ? null : v })}
                                >
                                    <SelectTrigger><SelectValue placeholder="Type" /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={ANY}>Any</SelectItem>
                                        {[
                                            { value: 'injury', label: 'Injury' },
                                            { value: 'fall', label: 'Fall' },
                                            { value: 'behaviour', label: 'Behaviour' },
                                            { value: 'medication', label: 'Medication' },
                                            { value: 'safeguarding', label: 'Safeguarding' },
                                            { value: 'near_miss', label: 'Near miss' },
                                            { value: 'property_damage', label: 'Property damage' },
                                            { value: 'missing_person', label: 'Missing person' },
                                            { value: 'complaint', label: 'Complaint' },
                                            { value: 'other', label: 'Other' },
                                        ].map((t) => (
                                            <SelectItem key={t.value} value={t.value}>{t.label}</SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>

                            <div>
                                <Label className="text-xs text-slate-500">Status</Label>
                                <Select
                                    value={filters.status ?? ANY}
                                    onValueChange={(v) => onFilter({ status: v === ANY ? null : v })}
                                >
                                    <SelectTrigger><SelectValue placeholder="Status" /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={ANY}>Any</SelectItem>
                                        {['draft', 'submitted', 'reviewed', 'closed'].map((s) => (
                                            <SelectItem key={s} value={s} className="capitalize">{s}</SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>

                            <div>
                                <Label className="text-xs text-slate-500">Severity</Label>
                                <Select
                                    value={filters.severity ?? ANY}
                                    onValueChange={(v) => onFilter({ severity: v === ANY ? null : v })}
                                >
                                    <SelectTrigger><SelectValue placeholder="Severity" /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={ANY}>Any</SelectItem>
                                        {['low', 'medium', 'high'].map((s) => (
                                            <SelectItem key={s} value={s} className="capitalize">{s}</SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>

                            <div>
                                <Label className="text-xs text-slate-500">Reviewed</Label>
                                <Select
                                    value={filters.reviewed ?? ANY}
                                    onValueChange={(v) => onFilter({ reviewed: v === ANY ? null : v })}
                                >
                                    <SelectTrigger><SelectValue placeholder="Reviewed?" /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={ANY}>Any</SelectItem>
                                        <SelectItem value="yes">Yes</SelectItem>
                                        <SelectItem value="no">No</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>

                            <div>
                                <Label className="text-xs text-slate-500">From</Label>
                                <Input type="date" value={filters.from ?? ''} onChange={(e) => onFilter({ from: e.target.value || null })} />
                            </div>

                            <div>
                                <Label className="text-xs text-slate-500">To</Label>
                                <Input type="date" value={filters.to ?? ''} onChange={(e) => onFilter({ to: e.target.value || null })} />
                            </div>
                        </div>
                    </CardContent>
                </Card>

                {/* Incident list */}
                <div className="space-y-2">
                    {data.map((i: any) => {
                        const sev = severityConfig[i.severity] ?? severityConfig.low;
                        const stat = statusConfig[i.status] ?? statusConfig.draft;
                        const TypeIcon = typeIcons[i.type] ?? AlertTriangle;
                        const StatusIcon = stat.icon;
                        const clientName = i.client ? `${i.client.first_name ?? ''} ${i.client.last_name ?? ''}`.trim() : null;

                        return (
                            <Link
                                key={i.id}
                                href={`/incidents/${i.id}`}
                                className="block rounded-lg border border-l-4 bg-white transition-all hover:shadow-md hover:border-slate-300"
                                style={{ borderLeftColor: `var(--severity-${i.severity})` }}
                            >
                                <div className={`rounded-lg border-l-4 ${sev.border}`}>
                                    <div className="flex items-center gap-4 px-4 py-3">
                                        {/* Type icon */}
                                        <div className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-full ${sev.bg}`}>
                                            <TypeIcon className={`h-5 w-5 ${sev.text}`} />
                                        </div>

                                        {/* Main content */}
                                        <div className="min-w-0 flex-1">
                                            <div className="flex items-center gap-2">
                                                <span className="font-semibold capitalize">{i.type?.replace(/_/g, ' ')}</span>
                                                <Badge className={`${sev.bg} ${sev.text} border-0 text-[10px] font-medium`}>
                                                    {i.severity}
                                                </Badge>
                                                <Badge className={`${stat.bg} ${stat.text} border-0 text-[10px] font-medium`}>
                                                    <StatusIcon className="mr-1 h-3 w-3" />
                                                    {i.status}
                                                </Badge>
                                                {i.is_notifiable && (
                                                    <Badge className="bg-red-100 text-red-700 border-0 text-[10px]">WorkSafe</Badge>
                                                )}
                                            </div>
                                            <div className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-slate-500">
                                                {clientName && (
                                                    <span className="flex items-center gap-1">
                                                        <User className="h-3 w-3" />
                                                        {clientName}
                                                    </span>
                                                )}
                                                {i.occurred_at && (
                                                    <span className="flex items-center gap-1">
                                                        <Calendar className="h-3 w-3" />
                                                        {formatDateTime(i.occurred_at)}
                                                    </span>
                                                )}
                                                <span className="flex items-center gap-1">
                                                    {i.shift_id ? 'Shift-linked' : 'Standalone'}
                                                </span>
                                                {i.reported_by?.name && (
                                                    <span className="text-slate-400">by {i.reported_by.name}</span>
                                                )}
                                            </div>
                                        </div>

                                        {/* Arrow */}
                                        <ArrowRight className="h-4 w-4 shrink-0 text-slate-300" />
                                    </div>
                                </div>
                            </Link>
                        );
                    })}

                    {!data.length && (
                        <div className="flex flex-col items-center justify-center rounded-lg border border-dashed py-12 text-center">
                            <ShieldAlert className="h-10 w-10 text-slate-300" />
                            <div className="mt-2 text-sm font-medium text-slate-500">No incidents found</div>
                            <div className="text-xs text-slate-400">Try adjusting your filters</div>
                        </div>
                    )}
                </div>

                {/* Pagination */}
                {incidents?.links?.length > 3 && (
                    <div className="flex flex-wrap items-center justify-center gap-1">
                        {incidents.links.map((l: any, idx: number) => (
                            <button
                                key={idx}
                                disabled={!l.url}
                                className={`rounded-md border px-3 py-1.5 text-xs transition-colors ${
                                    l.active
                                        ? 'bg-primary text-primary-foreground border-primary'
                                        : l.url
                                            ? 'hover:bg-muted'
                                            : 'opacity-50 cursor-not-allowed'
                                }`}
                                onClick={() => l.url && router.get(l.url, {}, { preserveState: true, preserveScroll: true })}
                                dangerouslySetInnerHTML={{ __html: l.label }}
                            />
                        ))}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
