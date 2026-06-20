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
import {
    ShiftContextMenu,
    type ShiftCtxItem,
    type ShiftCtxState,
} from '@/components/rostering';
import {
    HealthClinicalShell,
    RegisterStatStrip,
    type HealthClinicalKpis,
} from '@/pages/health-clinical/components/health-clinical-shell';
import { cn } from '@/lib/utils';
import { Link, router, usePage } from '@inertiajs/react';
import {
    ArrowUpRight,
    Check,
    Clock,
    Filter,
    ListChecks,
    MoreVertical,
    Stethoscope,
    User,
    X,
} from 'lucide-react';
import { useState, type MouseEvent as ReactMouseEvent } from 'react';

type PaginatedData<T> = {
    data: T[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
    current_page: number;
    last_page: number;
    total: number;
};

type EventRecord = {
    id: number;
    client_id: number;
    event_type: string;
    severity: string;
    occurred_at: string;
    description: string;
    requires_followup: boolean;
    followup_completed_at: string | null;
    reviewed_at: string | null;
    status: string;
    client:
        | { id: number; first_name: string; last_name: string; site_id: number | null; site?: { id: number; name: string } | null }
        | null;
    site: { id: number; name: string } | null;
    reporter: { id: number; name: string } | null;
    reviewer: { id: number; name: string } | null;
};

type Stats = { total_7d: number; total_30d: number; pending_follow_ups: number; unreviewed: number };
type SelectOption = { value: string; label: string };
type FilterOptions = {
    clients: Array<{ id: number; first_name: string; last_name: string }>;
    sites: Array<{ id: number; name: string }>;
    event_types: SelectOption[];
    severities: SelectOption[];
    follow_up_statuses: SelectOption[];
    review_statuses: SelectOption[];
};
type Filters = {
    client_id?: string;
    event_type?: string;
    severity?: string;
    site_id?: string;
    follow_up_status?: string;
    review_status?: string;
    date_from?: string;
    date_to?: string;
};
type Props = {
    events: PaginatedData<EventRecord>;
    stats: Stats;
    filters: Filters;
    filter_options: FilterOptions;
    kpis: HealthClinicalKpis;
    tab_counts?: Record<string, number>;
};

type ClinicalAbilities = { eventsReview?: boolean; eventsEscalate?: boolean; eventsRecord?: boolean };

const ALL_SENTINEL = '__all__';

const SEV: Record<string, { border: string; pill: string; tile: string }> = {
    low: { border: 'border-l-status-info', pill: 'bg-status-info-bg text-status-info', tile: 'bg-status-info-bg text-status-info' },
    medium: { border: 'border-l-status-warning', pill: 'bg-status-warning-bg text-status-warning', tile: 'bg-status-warning-bg text-status-warning' },
    high: { border: 'border-l-status-warning', pill: 'bg-status-warning-bg text-status-warning', tile: 'bg-status-warning-bg text-status-warning' },
    critical: { border: 'border-l-status-critical', pill: 'bg-status-critical-bg text-status-critical', tile: 'bg-status-critical-bg text-status-critical' },
};

function formatNzDate(iso: string): string {
    return new Date(iso).toLocaleDateString('en-NZ', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}

export default function EventRegister({ events, stats, filters, filter_options, kpis, tab_counts }: Props) {
    const page = usePage<{ auth?: { can?: { clinical?: ClinicalAbilities } } }>();
    const can = page.props.auth?.can?.clinical ?? {};

    const [local, setLocal] = useState<Filters>({
        client_id: filters.client_id ?? '',
        event_type: filters.event_type ?? '',
        severity: filters.severity ?? '',
        site_id: filters.site_id ?? '',
        follow_up_status: filters.follow_up_status ?? '',
        review_status: filters.review_status ?? '',
        date_from: filters.date_from ?? '',
        date_to: filters.date_to ?? '',
    });
    const [ctx, setCtx] = useState<ShiftCtxState | null>(null);

    const applyFilters = () => {
        const clean = Object.fromEntries(Object.entries(local).filter(([, v]) => v !== '' && v !== undefined));
        router.get('/health-clinical/events', clean, { preserveState: true, replace: true });
    };
    const clearFilters = () => {
        setLocal({});
        router.get('/health-clinical/events', {}, { preserveState: true, replace: true });
    };
    const hasFilters = Object.values(local).some((v) => v !== '' && v !== undefined);
    const eventTypeLabel = (value: string) => filter_options.event_types.find((t) => t.value === value)?.label ?? value;
    const siteName = (e: EventRecord) => e.site?.name ?? e.client?.site?.name ?? '—';
    const clientName = (e: EventRecord) => (e.client ? `${e.client.first_name} ${e.client.last_name}`.trim() : 'No client');

    const reload = { preserveScroll: true } as const;
    const review = (id: number) => router.patch(`/health-clinical/events/${id}/review`, {}, reload);
    const completeFollowup = (id: number) => router.patch(`/health-clinical/events/${id}/follow-up/complete`, {}, reload);
    const escalate = (id: number) => router.post(`/health-clinical/events/${id}/escalate`, {}, reload);

    const openRowCtx = (e: ReactMouseEvent, ev: EventRecord) => {
        e.preventDefault();
        const needsSignOff = !ev.reviewed_at;
        const followUpDue = ev.requires_followup && !ev.followup_completed_at;
        const items: ShiftCtxItem[] = [
            ...(ev.client
                ? [{ icon: <User className="h-3.5 w-3.5" />, label: 'View client', sub: clientName(ev), onClick: () => router.visit(`/operations/clients/${ev.client!.id}`) } satisfies ShiftCtxItem]
                : []),
            { sep: true },
            ...(can.eventsReview && needsSignOff
                ? [{ icon: <Check className="h-3.5 w-3.5" />, label: 'Review & sign off', tone: 'primary', onClick: () => review(ev.id) } satisfies ShiftCtxItem]
                : []),
            ...(followUpDue && (can.eventsReview || can.eventsRecord)
                ? [{ icon: <ListChecks className="h-3.5 w-3.5" />, label: 'Mark follow-up complete', onClick: () => completeFollowup(ev.id) } satisfies ShiftCtxItem]
                : []),
            ...(can.eventsEscalate
                ? [{ icon: <ArrowUpRight className="h-3.5 w-3.5" />, label: 'Escalate', tone: 'critical', onClick: () => escalate(ev.id) } satisfies ShiftCtxItem]
                : []),
        ];
        setCtx({ x: e.clientX, y: e.clientY, tag: ev.severity.toUpperCase(), meta: `${clientName(ev)} · ${eventTypeLabel(ev.event_type)}`, items });
    };

    return (
        <HealthClinicalShell activeTab="clinical_events" kpis={kpis} tabCounts={tab_counts}>
            <RegisterStatStrip
                stats={[
                    { label: 'Events · 7d', value: stats.total_7d },
                    { label: '30d', value: stats.total_30d },
                    { label: 'Pending follow-up', value: stats.pending_follow_ups, tone: stats.pending_follow_ups > 0 ? 'warning' : 'default' },
                    { label: 'Unreviewed', value: stats.unreviewed, tone: stats.unreviewed > 0 ? 'warning' : 'default' },
                ]}
            />

            <Card>
                <CardHeader className="pb-3">
                    <CardTitle className="flex items-center gap-2 text-sm">
                        <Filter className="h-4 w-4" /> Filters
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    <div className="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-8">
                        <FilterSelect label="Client" value={local.client_id} onChange={(v) => setLocal((c) => ({ ...c, client_id: v }))} placeholder="All clients"
                            options={filter_options.clients.map((c) => ({ value: String(c.id), label: `${c.first_name} ${c.last_name}` }))} />
                        <FilterSelect label="Type" value={local.event_type} onChange={(v) => setLocal((c) => ({ ...c, event_type: v }))} placeholder="All types" options={filter_options.event_types} />
                        <FilterSelect label="Severity" value={local.severity} onChange={(v) => setLocal((c) => ({ ...c, severity: v }))} placeholder="All severities" options={filter_options.severities} />
                        <FilterSelect label="Follow-up" value={local.follow_up_status} onChange={(v) => setLocal((c) => ({ ...c, follow_up_status: v }))} placeholder="Any status" options={filter_options.follow_up_statuses} />
                        <FilterSelect label="Review" value={local.review_status} onChange={(v) => setLocal((c) => ({ ...c, review_status: v }))} placeholder="Any review" options={filter_options.review_statuses} />
                        <FilterSelect label="Site" value={local.site_id} onChange={(v) => setLocal((c) => ({ ...c, site_id: v }))} placeholder="All sites"
                            options={filter_options.sites.map((s) => ({ value: String(s.id), label: s.name }))} />
                        <div>
                            <Label className="text-xs">From</Label>
                            <Input type="date" className="h-8 text-xs" value={local.date_from ?? ''} onChange={(e) => setLocal((c) => ({ ...c, date_from: e.target.value }))} />
                        </div>
                        <div>
                            <Label className="text-xs">To</Label>
                            <Input type="date" className="h-8 text-xs" value={local.date_to ?? ''} onChange={(e) => setLocal((c) => ({ ...c, date_to: e.target.value }))} />
                        </div>
                    </div>
                    <div className="mt-3 flex gap-2">
                        <Button size="sm" onClick={applyFilters}>Apply</Button>
                        {hasFilters && (
                            <Button size="sm" variant="ghost" onClick={clearFilters} className="gap-1">
                                <X className="h-3 w-3" /> Clear
                            </Button>
                        )}
                    </div>
                </CardContent>
            </Card>

            {events.data.length === 0 ? (
                <Card>
                    <CardContent className="p-12 text-center">
                        <Stethoscope className="mx-auto mb-3 h-10 w-10 text-muted-foreground/40" />
                        <p className="font-medium text-muted-foreground">No clinical events here</p>
                        <p className="mt-1 text-sm text-muted-foreground/70">Nothing matches the selected filters.</p>
                    </CardContent>
                </Card>
            ) : (
                <div className="flex flex-col gap-2.5">
                    {events.data.map((ev) => {
                        const sev = SEV[ev.severity] ?? SEV.low;
                        return (
                            <div
                                key={ev.id}
                                onContextMenu={(e) => openRowCtx(e, ev)}
                                className={cn('rounded-xl border border-l-4 border-border bg-card p-3.5 shadow-sm transition-colors hover:bg-muted/30', sev.border)}
                            >
                                <div className="flex items-start gap-3">
                                    <span className={cn('grid h-10 w-10 shrink-0 place-items-center rounded-lg', sev.tile)}>
                                        <Stethoscope className="h-5 w-5" />
                                    </span>
                                    <div className="min-w-0 flex-1">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span className="text-sm font-semibold">{eventTypeLabel(ev.event_type)}</span>
                                            <span className={cn('rounded-full px-2 py-0.5 text-[11px] font-medium capitalize', sev.pill)}>{ev.severity}</span>
                                            {!ev.reviewed_at ? (
                                                <Badge variant="outline" className="border-status-warning/40 text-[10px] text-status-warning">Needs sign-off</Badge>
                                            ) : (
                                                <Badge variant="outline" className="border-status-success/40 text-[10px] text-status-success">Reviewed</Badge>
                                            )}
                                            {ev.requires_followup && !ev.followup_completed_at ? (
                                                <Badge variant="outline" className="border-status-critical/40 text-[10px] text-status-critical">Follow-up due</Badge>
                                            ) : null}
                                        </div>
                                        <p className="mt-0.5 text-xs text-muted-foreground">
                                            {ev.client ? (
                                                <Link href={`/operations/clients/${ev.client.id}`} className="font-medium text-status-info hover:underline">{clientName(ev)}</Link>
                                            ) : clientName(ev)}
                                            {' · '}{siteName(ev)}
                                        </p>
                                        {ev.description ? <p className="mt-1 line-clamp-2 text-[13px] text-foreground">{ev.description}</p> : null}
                                        <div className="mt-1.5 flex items-center gap-3 text-[11px] text-muted-foreground">
                                            <span className="inline-flex items-center gap-1"><Clock className="h-3 w-3" />{formatNzDate(ev.occurred_at)}</span>
                                            {ev.reporter ? <span>· {ev.reporter.name}</span> : null}
                                        </div>
                                    </div>
                                    {/* eslint-disable-next-line no-restricted-syntax -- icon-only context-menu trigger, not a shadcn Button */}
                                    <button
                                        type="button"
                                        aria-label="Event actions"
                                        onClick={(e) => openRowCtx(e, ev)}
                                        className="shrink-0 rounded-md p-1 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                                    >
                                        <MoreVertical className="h-4 w-4" />
                                    </button>
                                </div>
                            </div>
                        );
                    })}
                </div>
            )}

            {events.last_page > 1 ? (
                <div className="flex items-center justify-between px-1">
                    <p className="text-xs text-muted-foreground">Page {events.current_page} of {events.last_page} ({events.total} total)</p>
                    <div className="flex gap-1">
                        {events.links.map((link, i) => (
                            <Button key={i} variant={link.active ? 'default' : 'outline'} size="sm" className="h-7 min-w-[28px] px-2 text-xs" disabled={!link.url}
                                onClick={() => link.url && router.get(link.url, {}, { preserveState: true })}
                                dangerouslySetInnerHTML={{ __html: link.label }} />
                        ))}
                    </div>
                </div>
            ) : null}

            {ctx ? <ShiftContextMenu ctx={ctx} onClose={() => setCtx(null)} /> : null}
        </HealthClinicalShell>
    );
}

function FilterSelect({ label, value, onChange, placeholder, options }: { label: string; value?: string; onChange: (v: string) => void; placeholder: string; options: SelectOption[] }) {
    return (
        <div>
            <Label className="text-xs">{label}</Label>
            <Select value={value || ALL_SENTINEL} onValueChange={(v) => onChange(v === ALL_SENTINEL ? '' : v)}>
                <SelectTrigger className="h-8 text-xs">
                    <SelectValue placeholder={placeholder} />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value={ALL_SENTINEL}>{placeholder}</SelectItem>
                    {options.map((o) => (
                        <SelectItem key={o.value} value={o.value}>{o.label}</SelectItem>
                    ))}
                </SelectContent>
            </Select>
        </div>
    );
}
