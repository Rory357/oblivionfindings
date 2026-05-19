import AppLayout from '@/layouts/app-layout';
import { PageHero } from '@/components/page';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { formatDateTime } from '@/lib/datetime';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    AlertTriangle,
    Calendar,
    CheckCircle2,
    Clock,
    Copy,
    Eye,
    ExternalLink,
    FileEdit,
    MoreVertical,
    Plus,
    Search,
    Send,
    ShieldAlert,
    User,
    X,
    XCircle,
    Activity,
    Pill,
    Users,
    Shield,
    HelpCircle,
    FileWarning,
    AlertCircle,
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

const severityConfig: Record<string, { bg: string; text: string; dot: string; border: string; darkBg: string; darkText: string }> = {
    low: { bg: 'bg-status-success-bg', text: 'text-status-success', dot: 'bg-status-success', border: 'border-l-emerald-500', darkBg: '', darkText: 'dark:text-status-success' },
    medium: { bg: 'bg-status-warning-bg', text: 'text-status-warning', dot: 'bg-status-warning', border: 'border-l-amber-500', darkBg: '', darkText: 'dark:text-status-warning' },
    high: { bg: 'bg-status-critical-bg', text: 'text-status-critical', dot: 'bg-status-critical', border: 'border-l-red-500', darkBg: '', darkText: 'dark:text-status-critical' },
    critical: { bg: 'bg-status-critical-bg', text: 'text-status-critical', dot: 'bg-status-critical', border: 'border-l-red-600', darkBg: '', darkText: 'dark:text-status-critical' },
};

const statusConfig: Record<string, { bg: string; text: string; icon: typeof Clock; darkBg: string; darkText: string }> = {
    draft: { bg: 'bg-muted', text: 'text-foreground', icon: FileEdit, darkBg: 'dark:bg-muted-foreground/80/10', darkText: 'dark:text-muted-foreground' },
    submitted: { bg: 'bg-status-info-bg', text: 'text-status-info', icon: Clock, darkBg: '', darkText: 'dark:text-status-info' },
    reviewed: { bg: 'bg-primary/10', text: 'text-primary', icon: CheckCircle2, darkBg: 'dark:bg-primary/10', darkText: 'dark:text-primary/70' },
    closed: { bg: 'bg-status-success-bg', text: 'text-status-success', icon: CheckCircle2, darkBg: '', darkText: 'dark:text-status-success' },
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

/* ------------------------------------------------------------------ */
/*  Stat Card (matches HR pattern)                                     */
/* ------------------------------------------------------------------ */

interface StatCardProps {
    label: string;
    value: number;
    icon: React.ElementType;
    color: 'blue' | 'emerald' | 'amber' | 'red';
}

const STAT_COLORS = {
    blue: {
        bg: 'bg-status-info-bg',
        icon: 'text-status-info dark:text-status-info',
        ring: 'ring-status-info dark:ring-status-info/20',
    },
    emerald: {
        bg: 'bg-status-success-bg',
        icon: 'text-status-success dark:text-status-success',
        ring: 'ring-status-success dark:ring-status-success/20',
    },
    amber: {
        bg: 'bg-status-warning-bg',
        icon: 'text-status-warning dark:text-status-warning',
        ring: 'ring-status-warning dark:ring-status-warning/20',
    },
    red: {
        bg: 'bg-status-critical-bg',
        icon: 'text-status-critical dark:text-status-critical',
        ring: 'ring-status-critical dark:ring-status-critical/20',
    },
};

function StatCard({ label, value, icon: Icon, color }: StatCardProps) {
    const c = STAT_COLORS[color];
    return (
        <div className={`relative flex items-center gap-4 rounded-xl p-4 ring-1 ${c.bg} ${c.ring} transition-shadow hover:shadow-md`}>
            <div className={`flex h-11 w-11 shrink-0 items-center justify-center rounded-lg ${c.bg} ${c.icon}`}>
                <Icon className="h-5 w-5" />
            </div>
            <div className="min-w-0">
                <p className="text-2xl font-bold tracking-tight">{value}</p>
                <p className="truncate text-xs font-medium text-muted-foreground">{label}</p>
            </div>
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Page                                                               */
/* ------------------------------------------------------------------ */

export default function IncidentsIndex({ filters, incidents, clients }: Props) {
    const ANY = '__any__';
    const { auth, labels } = usePage().props as any;
    const can = auth?.can?.incidents ?? {};
    const clientSingular = labels?.['client.singular'] ?? 'Client';

    const onFilter = (next: Partial<typeof filters>) => {
        router.get('/incidents', { ...filters, ...next }, { preserveState: true, preserveScroll: true });
    };

    function clearFilters() {
        router.get('/incidents', {}, { preserveState: true, replace: true });
    }

    const hasFilters = !!(filters.q || filters.type || filters.status || filters.severity || filters.client_id || filters.reviewed || filters.from || filters.to);

    // Compute quick stats from current page data
    const data = incidents?.data ?? [];
    const total = incidents?.total ?? data.length;
    const draftCount = data.filter((i: any) => i.status === 'draft').length;
    const highCount = data.filter((i: any) => i.severity === 'high' || i.severity === 'critical').length;
    const awaitingReview = data.filter((i: any) => i.status === 'submitted').length;

    return (
        <AppLayout breadcrumbs={[{ title: 'Incidents', href: '/incidents' }]}>
            <Head title="Incidents" />

            <div className="flex flex-col gap-6 p-6">
                {/* Hero Header */}
                <PageHero
                    title={filters.type === 'near_miss' ? 'Near Misses' : 'Incidents'}
                    description={filters.type
                        ? `Filtered by type: ${filters.type.replace(/_/g, ' ')}`
                        : `Manage and review incidents — ${total} total`
                    }
                    icon={<ShieldAlert className="h-7 w-7 text-white" />}
                    stats={[
                        { label: 'Total', value: total },
                        { label: 'Drafts', value: draftCount },
                        { label: 'High Severity', value: highCount },
                        { label: 'Awaiting Review', value: awaitingReview },
                    ]}
                    actions={
                        <div className="flex items-center gap-2">
                            {can.templatesManage && (
                                <Link href="/incidents/templates">
                                    <Button variant="outline" size="sm">Templates</Button>
                                </Link>
                            )}
                            {can.create && (
                                <Link href="/incidents/create">
                                    <Button size="sm">
                                        <Plus className="mr-1.5 h-4 w-4" />
                                        New incident
                                    </Button>
                                </Link>
                            )}
                        </div>
                    }
                />

                {/* Filters */}
                <div className="flex flex-wrap items-center gap-3">
                    <div className="relative">
                        <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            placeholder="Search incidents..."
                            className="w-64 pl-9"
                            defaultValue={filters.q}
                            onKeyDown={(e) => {
                                if (e.key === 'Enter') onFilter({ q: (e.target as HTMLInputElement).value });
                            }}
                        />
                    </div>

                    {clients?.length ? (
                        <Select
                            value={filters.client_id ? String(filters.client_id) : ANY}
                            onValueChange={(v) => onFilter({ client_id: v === ANY ? null : v })}
                        >
                            <SelectTrigger className="w-44">
                                <SelectValue placeholder={clientSingular} />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={ANY}>All {clientSingular}s</SelectItem>
                                {clients.map((c) => (
                                    <SelectItem key={c.id} value={String(c.id)}>{c.first_name} {c.last_name}</SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    ) : null}

                    <Select value={filters.type ?? ANY} onValueChange={(v) => onFilter({ type: v === ANY ? null : v })}>
                        <SelectTrigger className="w-40">
                            <SelectValue placeholder="Type" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ANY}>All Types</SelectItem>
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

                    <Select value={filters.status ?? ANY} onValueChange={(v) => onFilter({ status: v === ANY ? null : v })}>
                        <SelectTrigger className="w-36">
                            <SelectValue placeholder="Status" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ANY}>All Status</SelectItem>
                            {['draft', 'submitted', 'reviewed', 'closed'].map((s) => (
                                <SelectItem key={s} value={s} className="capitalize">{s}</SelectItem>
                            ))}
                        </SelectContent>
                    </Select>

                    <Select value={filters.severity ?? ANY} onValueChange={(v) => onFilter({ severity: v === ANY ? null : v })}>
                        <SelectTrigger className="w-36">
                            <SelectValue placeholder="Severity" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ANY}>All Severity</SelectItem>
                            {['low', 'medium', 'high'].map((s) => (
                                <SelectItem key={s} value={s} className="capitalize">{s}</SelectItem>
                            ))}
                        </SelectContent>
                    </Select>

                    <Select value={filters.reviewed ?? ANY} onValueChange={(v) => onFilter({ reviewed: v === ANY ? null : v })}>
                        <SelectTrigger className="w-36">
                            <SelectValue placeholder="Reviewed" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={ANY}>All</SelectItem>
                            <SelectItem value="yes">Reviewed</SelectItem>
                            <SelectItem value="no">Not Reviewed</SelectItem>
                        </SelectContent>
                    </Select>

                    {hasFilters && (
                        <Button variant="ghost" size="sm" onClick={clearFilters} className="gap-1.5 text-muted-foreground">
                            <X className="h-3.5 w-3.5" />
                            Clear
                        </Button>
                    )}
                </div>

                {/* Incident list */}
                <Card>
                    <CardContent className="p-0">
                        <div className="divide-y">
                            {data.map((i: any) => {
                                const sev = severityConfig[i.severity] ?? severityConfig.low;
                                const stat = statusConfig[i.status] ?? statusConfig.draft;
                                const TypeIcon = typeIcons[i.type] ?? AlertTriangle;
                                const StatusIcon = stat.icon;
                                const clientName = i.client ? `${i.client.first_name ?? ''} ${i.client.last_name ?? ''}`.trim() : null;
                                const preview = i.description ? (i.description.length > 120 ? i.description.slice(0, 120) + '...' : i.description) : null;

                                return (
                                    <div
                                        key={i.id}
                                        className={`group relative cursor-pointer border-l-4 transition-colors hover:bg-muted/40 ${sev.border}`}
                                        onClick={() => router.visit(`/incidents/${i.id}`)}
                                    >
                                        <div className="block px-4 py-3 pr-12">
                                            <div className="flex items-start gap-4">
                                                <div className={`mt-0.5 flex h-10 w-10 shrink-0 items-center justify-center rounded-full ${sev.bg} ${sev.darkBg}`}>
                                                    <TypeIcon className={`h-5 w-5 ${sev.text} ${sev.darkText}`} />
                                                </div>
                                                <div className="min-w-0 flex-1">
                                                    <div className="flex flex-wrap items-center gap-2">
                                                        <span className="font-semibold capitalize">{i.type?.replace(/_/g, ' ')}</span>
                                                        <span className="text-muted-foreground/40">|</span>
                                                        <Badge variant="outline" className={`${sev.bg} ${sev.text} ${sev.darkBg} ${sev.darkText} border-0 text-[10px] font-medium`}>
                                                            {i.severity}
                                                        </Badge>
                                                        <Badge variant="outline" className={`${stat.bg} ${stat.text} ${stat.darkBg} ${stat.darkText} border-0 text-[10px] font-medium`}>
                                                            <StatusIcon className="mr-1 h-3 w-3" />
                                                            {i.status}
                                                        </Badge>
                                                        {i.is_notifiable && (
                                                            <Badge variant="outline" className="border-status-critical/30 bg-status-critical-bg text-status-critical dark:border-status-critical/30 dark:bg-status-critical-bg dark:text-status-critical text-[10px]">WorkSafe</Badge>
                                                        )}
                                                        {i.requires_followup && (
                                                            <Badge variant="outline" className="border-primary bg-primary/10 text-primary dark:border-primary/30 dark:bg-primary/10 dark:text-primary/70 text-[10px]">Follow-up</Badge>
                                                        )}
                                                    </div>
                                                    {preview && (
                                                        <p className="mt-1 text-sm text-muted-foreground line-clamp-1">{preview}</p>
                                                    )}
                                                    <div className="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground">
                                                        {clientName && (
                                                            <span className="flex items-center gap-1"><User className="h-3 w-3" />{clientName}</span>
                                                        )}
                                                        {i.occurred_at && (
                                                            <span className="flex items-center gap-1"><Calendar className="h-3 w-3" />{formatDateTime(i.occurred_at)}</span>
                                                        )}
                                                        <span className="text-muted-foreground/60">{i.shift_id ? 'Shift-linked' : 'Standalone'}</span>
                                                        {i.reported_by?.name && <span className="text-muted-foreground/60">by {i.reported_by.name}</span>}
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        {/* Three-dot menu */}
                                        <div className="absolute right-2 top-2.5 z-10" onClick={(e) => e.stopPropagation()}>
                                            <DropdownMenu>
                                                <DropdownMenuTrigger asChild>
                                                    <button className="rounded p-1.5 text-muted-foreground hover:bg-muted hover:text-foreground transition-colors">
                                                        <MoreVertical className="h-4 w-4" />
                                                    </button>
                                                </DropdownMenuTrigger>
                                                <DropdownMenuContent align="end" className="w-48">
                                                    <DropdownMenuItem onClick={() => router.visit(`/incidents/${i.id}`)}>
                                                        <ExternalLink className="mr-2 h-4 w-4" />
                                                        Open incident
                                                    </DropdownMenuItem>
                                                    {i.status === 'draft' && can.update && (
                                                        <DropdownMenuItem onClick={() => router.post(`/incidents/${i.id}/submit`)}>
                                                            <Send className="mr-2 h-4 w-4" />
                                                            Submit for review
                                                        </DropdownMenuItem>
                                                    )}
                                                    {i.status === 'submitted' && can.approve && (
                                                        <DropdownMenuItem onClick={() => router.visit(`/incidents/${i.id}`)}>
                                                            <CheckCircle2 className="mr-2 h-4 w-4" />
                                                            Review incident
                                                        </DropdownMenuItem>
                                                    )}
                                                    <DropdownMenuSeparator />
                                                    <DropdownMenuItem onClick={() => {
                                                        if (i.client) router.visit(`/operations/clients/${i.client.id}`);
                                                    }} disabled={!i.client}>
                                                        <User className="mr-2 h-4 w-4" />
                                                        View {clientSingular.toLowerCase()}
                                                    </DropdownMenuItem>
                                                    <DropdownMenuItem onClick={() => {
                                                        navigator.clipboard.writeText(`${window.location.origin}/incidents/${i.id}`);
                                                    }}>
                                                        <Copy className="mr-2 h-4 w-4" />
                                                        Copy link
                                                    </DropdownMenuItem>
                                                </DropdownMenuContent>
                                            </DropdownMenu>
                                        </div>
                                    </div>
                                );
                            })}

                            {!data.length && (
                                <div className="px-4 py-16 text-center">
                                    <ShieldAlert className="mx-auto mb-3 h-10 w-10 text-muted-foreground/40" />
                                    <p className="font-medium text-muted-foreground">No incidents found</p>
                                    <p className="mt-1 text-sm text-muted-foreground/70">
                                        {hasFilters ? 'Try adjusting your filters' : 'Create an incident to get started'}
                                    </p>
                                </div>
                            )}
                        </div>
                    </CardContent>
                </Card>

                {/* Pagination */}
                {incidents?.last_page > 1 && (
                    <LaravelPagination links={incidents.links} />
                )}
            </div>
        </AppLayout>
    );
}
