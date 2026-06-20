import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import {
    HeroShell,
    HeroStatusPill,
    HeroMedallion,
    HeroCluster,
    HeroClusterTile,
    HeroSegmented,
    fmt,
} from '@/pages/health-safety/components/hs-hero-kit';
import { WorkflowRibbon } from '@/pages/health-safety/components/workflow-ribbon';
import {
    RegisterTableHeader,
    FlagBadge,
    TONE_BG,
    TONE_DOT,
    titleCase,
    initials,
    entityTone,
    type Tone,
} from '@/pages/health-safety/components/register-row-kit';
import {
    EntityFilter,
    ShiftContextMenu,
    TabStrip,
    type RosterTabItem,
    type ShiftCtxItem,
    type ShiftCtxState,
} from '@/components/rostering';
import { RepresentativeDetailDialog } from '@/components/worker-participation/representative-detail-dialog';
import { MeetingDetailDialog } from '@/components/worker-participation/meeting-detail-dialog';
import { ConsultationDetailDialog } from '@/components/worker-participation/consultation-detail-dialog';
import { AddRepresentativeWizard } from '@/components/worker-participation/add-representative-wizard';
import { ScheduleMeetingWizard } from '@/components/worker-participation/schedule-meeting-wizard';
import { NewConsultationWizard } from '@/components/worker-participation/new-consultation-wizard';
import {
    CONSULT_STATUS,
    CONSULT_ORDER,
    MEETING_STATUS,
    REP_STATUS,
    fmtDate,
    type ConsultationRow,
    type MeetingRow,
    type RepRow,
    type WpDetailAction,
} from '@/components/worker-participation/shared';
import { Head, router } from '@inertiajs/react';
import { useState, type ComponentType, type MouseEvent as ReactMouseEvent, type ReactNode } from 'react';
import {
    Calendar, CheckCircle2, ClipboardCheck, Copy, Download, Eye, FileCheck2, FileText,
    GraduationCap, MapPin, MessageSquare, MousePointer2, Pencil, Plus, Search, ShieldCheck,
    Slash, Upload, UserCog, UserPlus, Users, Wrench, X, XCircle,
} from 'lucide-react';

/* ------------------------------------------------------------------ */
/*  Types (match WorkerParticipationController@index)                  */
/* ------------------------------------------------------------------ */

type Paginated<T> = { data: T[]; links: { url: string | null; label: string; active: boolean }[]; last_page: number };
type Filters = { tab: string; site_id: number | null; status: string | null; period: string; q: string | null };

type Props = {
    filters: Filters;
    tab: 'representatives' | 'meetings' | 'consultations';
    tabCounts: Record<string, number>;
    rows: Paginated<RepRow | MeetingRow | ConsultationRow>;
    hero: {
        clusters: {
            participation: { active_reps: number; sites_without_rep: number; committees: number; meetings_quarter: number };
            consultation: { open: number; awaiting_feedback: number; overdue_actions: number; minutes_outstanding: number };
        };
        badges: { reps_coverage_pct: number; sites_total: number; sites_covered: number; minutes_overdue: number; consultations_awaiting: number; training_below_minimum: number };
    };
    detail: { kind: 'representative' | 'meeting' | 'consultation'; data: any } | null;
    can: { manage: boolean };
    sites: { id: number; name: string }[];
    staff: { id: number; name: string }[];
    committees: { id: number; name: string; site_id: number | null; meeting_frequency: string; meetings_count: number }[];
};

const BASE = '/health-safety/worker-participation';

/* ------------------------------------------------------------------ */
/*  Page                                                               */
/* ------------------------------------------------------------------ */

export default function WorkerParticipationIndex({ filters, tab, tabCounts, rows, hero, detail, can, sites, staff, committees }: Props) {
    const [ctx, setCtx] = useState<ShiftCtxState | null>(null);
    const [wizard, setWizard] = useState<'rep' | 'meeting' | 'consultation' | null>(null);
    const [newOpen, setNewOpen] = useState(false);
    // Tracks which sub-action a detail dialog should open straight onto (right-click → action).
    const [pendingAction, setPendingAction] = useState<WpDetailAction | null>(null);

    const go = (next: Partial<Filters>) =>
        router.get(BASE, { ...filters, ...next }, { preserveState: true, preserveScroll: true, replace: true });
    const setTab = (id: string) => router.get(BASE, { ...filters, tab: id, status: null }, { preserveState: true, preserveScroll: true });
    const openDetail = (param: 'representative' | 'meeting' | 'consultation', id: number, action: WpDetailAction | null = null) => {
        setPendingAction(action);
        router.get(BASE, { ...filters, [param]: id }, { preserveState: true, preserveScroll: true, only: ['detail'] });
    };
    const closeDetail = () => {
        setPendingAction(null);
        router.get(BASE, { ...filters }, { preserveState: true, preserveScroll: true, only: ['detail'] });
    };
    const clearFilters = () => router.get(BASE, { tab }, { preserveState: true, preserveScroll: true, replace: true });
    const hasFilters = !!(filters.site_id || filters.status || filters.q || (filters.period && filters.period !== 'quarter'));

    const TABS: RosterTabItem[] = [
        { id: 'representatives', label: 'Representatives', icon: Users, tone: 'info', badge: tabCounts.representatives || undefined },
        { id: 'meetings', label: 'Committee meetings', icon: Calendar, tone: 'info', badge: tabCounts.meetings || undefined },
        { id: 'consultations', label: 'Consultations', icon: MessageSquare, tone: 'warning', badge: tabCounts.consultations || undefined },
    ];

    const PERIOD = [{ key: 'week', label: 'This week' }, { key: '30d', label: '30 days' }, { key: 'quarter', label: 'Quarter' }, { key: 'year', label: 'Year' }];
    const STATUS_OPTS: Record<string, [string, string][]> = {
        representatives: [['active', 'Active'], ['inactive', 'Inactive'], ['resigned', 'Resigned']],
        meetings: [['scheduled', 'Scheduled'], ['completed', 'Completed'], ['cancelled', 'Cancelled']],
        consultations: [['open', 'Open'], ['feedback_received', 'Feedback received'], ['actioned', 'Actioned'], ['closed', 'Closed']],
    };

    /* ---- shared actionsFor (powers right-click AND the row menu) ---- */
    const actionsFor = (kind: string, row: any): ShiftCtxItem[] => {
        if (kind === 'representative') {
            const active = row.status === 'active';
            const items: ShiftCtxItem[] = [
                { icon: <Eye className="h-3.5 w-3.5" />, label: 'View representative', sub: row.user?.name, tone: 'primary', onClick: () => openDetail('representative', row.id) },
            ];
            if (can.manage) {
                items.push(
                    { icon: <Pencil className="h-3.5 w-3.5" />, label: 'Edit details', onClick: () => openDetail('representative', row.id, 'edit') },
                    { icon: <GraduationCap className="h-3.5 w-3.5" />, label: 'Record training days', onClick: () => openDetail('representative', row.id, 'training') },
                    { sep: true },
                    active
                        ? { icon: <Slash className="h-3.5 w-3.5" />, label: 'Mark stood-down', tone: 'critical', onClick: () => router.put(`${BASE}/representatives/${row.id}`, { status: 'inactive' }, { preserveScroll: true }) }
                        : { icon: <CheckCircle2 className="h-3.5 w-3.5" />, label: 'Mark active', tone: 'primary', onClick: () => router.put(`${BASE}/representatives/${row.id}`, { status: 'active' }, { preserveScroll: true }) },
                );
            }
            items.push({ sep: true }, { icon: <Copy className="h-3.5 w-3.5" />, label: 'Copy link', onClick: () => navigator.clipboard?.writeText(`${location.origin}${BASE}?tab=representatives&representative=${row.id}`) });
            return items;
        }
        if (kind === 'meeting') {
            const sched = row.status === 'scheduled';
            const items: ShiftCtxItem[] = [
                { icon: <Eye className="h-3.5 w-3.5" />, label: 'View meeting', sub: row.committee?.name, tone: 'primary', onClick: () => openDetail('meeting', row.id) },
            ];
            if (can.manage && row.status !== 'cancelled') {
                items.push({ icon: <Pencil className="h-3.5 w-3.5" />, label: 'Edit meeting', onClick: () => openDetail('meeting', row.id, 'edit') });
            }
            if (can.manage && sched) {
                items.push(
                    { icon: <UserPlus className="h-3.5 w-3.5" />, label: 'Add attendees', onClick: () => openDetail('meeting', row.id, 'attendees') },
                    { icon: <ClipboardCheck className="h-3.5 w-3.5" />, label: 'Complete meeting', sub: 'Minutes + action items', onClick: () => openDetail('meeting', row.id, 'complete') },
                    { icon: <XCircle className="h-3.5 w-3.5" />, label: 'Cancel meeting', tone: 'critical', onClick: () => router.put(`${BASE}/meetings/${row.id}/cancel`, {}, { preserveScroll: true }) },
                );
            }
            items.push({ sep: true });
            if (row.minutes_document_path) {
                items.push({ icon: <Download className="h-3.5 w-3.5" />, label: 'Download minutes', onClick: () => window.open(`${BASE}/meetings/${row.id}/minutes/download`) });
            } else if (can.manage) {
                items.push({ icon: <Upload className="h-3.5 w-3.5" />, label: 'Upload minutes', onClick: () => openDetail('meeting', row.id, 'minutes') });
            }
            return items;
        }
        // consultation
        const closed = row.status === 'closed';
        const items: ShiftCtxItem[] = [
            { icon: <Eye className="h-3.5 w-3.5" />, label: 'View consultation', sub: row.title, tone: 'primary', onClick: () => openDetail('consultation', row.id) },
        ];
        if (can.manage && !closed) {
            items.push(
                { icon: <Pencil className="h-3.5 w-3.5" />, label: 'Edit details', onClick: () => openDetail('consultation', row.id, 'edit') },
                { icon: <MessageSquare className="h-3.5 w-3.5" />, label: 'Record feedback', onClick: () => openDetail('consultation', row.id, 'feedback') },
                { icon: <FileCheck2 className="h-3.5 w-3.5" />, label: 'Record outcome', onClick: () => openDetail('consultation', row.id, 'outcome') },
            );
        }
        if (can.manage) {
            items.push({ sep: true }, { icon: <Upload className="h-3.5 w-3.5" />, label: 'Upload document', onClick: () => openDetail('consultation', row.id, 'upload') });
            if (!closed) {
                items.push({ icon: <XCircle className="h-3.5 w-3.5" />, label: 'Close consultation', tone: 'critical', onClick: () => openDetail('consultation', row.id, 'close') });
            }
        }
        return items;
    };

    const openRowCtx = (e: ReactMouseEvent, kind: string, row: any, tag: string, meta: string) => {
        e.preventDefault();
        setCtx({ x: e.clientX, y: e.clientY, tag, meta, items: actionsFor(kind, row) });
    };

    const openHeroCtx = (e: ReactMouseEvent) => {
        if (!can.manage) return;
        e.preventDefault();
        setCtx({ x: e.clientX, y: e.clientY, tag: 'NEW', meta: 'Quick actions', items: [
            { icon: <UserPlus className="h-3.5 w-3.5" />, label: 'Add representative', tone: 'primary', onClick: () => setWizard('rep') },
            { icon: <Calendar className="h-3.5 w-3.5" />, label: 'Schedule meeting', onClick: () => setWizard('meeting') },
            { icon: <MessageSquare className="h-3.5 w-3.5" />, label: 'New consultation', onClick: () => setWizard('consultation') },
            { sep: true },
            { icon: <Download className="h-3.5 w-3.5" />, label: 'Export board report', onClick: () => window.open(`${BASE}/export${filters.site_id ? `?site_id=${filters.site_id}` : ''}`) },
        ] });
    };

    const c = hero.clusters;
    const b = hero.badges;
    const coverageTone: Tone = b.reps_coverage_pct >= 100 ? 'success' : b.reps_coverage_pct >= 60 ? 'warning' : 'critical';

    return (
        <AppLayout breadcrumbs={[{ title: 'Health & Safety', href: '/health-safety' }, { title: 'Worker Participation', href: BASE }]}>
            <Head title="Worker Participation" />
            <div className="flex flex-col gap-6 p-6">
                <HeroShell
                    footer={
                        <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
                            <HeroSegmented label="Period" variant="pill" ariaLabel="Period" items={PERIOD} value={filters.period} onChange={(k) => go({ period: k })} />
                            {sites.length ? <EntityFilter label="Site" allLabel="All sites" items={sites} value={filters.site_id} onChange={(id) => go({ site_id: id })} onDark /> : null}
                            <HeroSegmented label="Status" variant="pill" ariaLabel="Status"
                                items={[{ key: 'all', label: 'All' }, ...STATUS_OPTS[tab].map(([k, l]) => ({ key: k, label: l }))]}
                                value={filters.status ?? 'all'} onChange={(k) => go({ status: k === 'all' ? null : k })} />
                            <div className="relative ml-auto">
                                <Search className="pointer-events-none absolute top-1/2 left-2.5 h-3.5 w-3.5 -translate-y-1/2 text-primary-foreground/60" />
                                <input type="search" placeholder="Search register…" defaultValue={filters.q ?? ''}
                                    onKeyDown={(e) => { if (e.key === 'Enter') go({ q: (e.target as HTMLInputElement).value || null }); }}
                                    className="w-48 rounded-lg border border-primary-foreground/20 bg-primary-foreground/10 py-1.5 pr-2.5 pl-8 text-xs text-primary-foreground placeholder:text-primary-foreground/50 focus-visible:ring-2 focus-visible:ring-primary-foreground/40 focus-visible:outline-none" />
                            </div>
                            {hasFilters ? (
                                // eslint-disable-next-line no-restricted-syntax -- onDark clear affordance on the hero footer
                                <button type="button" onClick={clearFilters} className="inline-flex items-center gap-1 rounded-md px-2 py-1 text-[11px] font-medium text-primary-foreground/70 transition-colors hover:text-primary-foreground">
                                    <X className="h-3 w-3" /> Clear
                                </button>
                            ) : null}
                        </div>
                    }
                >
                    <div onContextMenu={openHeroCtx}>
                        <WorkflowRibbon current="report" />
                        <div className="mt-4 flex flex-wrap items-start justify-between gap-4">
                            <div className="flex items-start gap-4">
                                <HeroMedallion icon={Users} />
                                <div className="flex flex-col gap-1.5">
                                    <HeroStatusPill>Worker participation · HSWA 2015</HeroStatusPill>
                                    <h1 className="text-2xl font-bold tracking-tight text-primary-foreground md:text-[28px]">Worker Participation</h1>
                                    <p className="max-w-xl text-sm text-primary-foreground/70">Elect and support H&amp;S representatives, run committee meetings, and consult kaimahi on work that affects them — the HSWA 2015 participation duties, in one register.</p>
                                </div>
                            </div>
                            {can.manage ? (
                                <Popover open={newOpen} onOpenChange={setNewOpen}>
                                    <PopoverTrigger asChild>
                                        <Button size="sm" className="bg-primary-foreground text-primary hover:bg-primary-foreground/90"><Plus className="mr-1.5 h-4 w-4" /> New</Button>
                                    </PopoverTrigger>
                                    <PopoverContent align="end" className="w-64 p-1.5">
                                        {([['rep', UserPlus, 'Add representative', 'Elect or appoint a rep'], ['meeting', Calendar, 'Schedule meeting', 'Committee meeting'], ['consultation', MessageSquare, 'New consultation', 'Consult kaimahi on a change']] as const).map(([k, Icon, t, s]) => (
                                            // eslint-disable-next-line no-restricted-syntax -- two-line menu item (icon + title + subtitle) in the New popover
                                            <button key={k} type="button" onClick={() => { setNewOpen(false); setWizard(k as 'rep' | 'meeting' | 'consultation'); }} className="flex w-full items-start gap-2.5 rounded-md p-2.5 text-left transition-colors hover:bg-muted">
                                                <Icon className="mt-0.5 h-4 w-4 shrink-0 text-primary" />
                                                <span><span className="block text-sm font-medium">{t}</span><span className="block text-xs text-muted-foreground">{s}</span></span>
                                            </button>
                                        ))}
                                    </PopoverContent>
                                </Popover>
                            ) : null}
                        </div>

                        <div className="mt-4 grid gap-3 lg:grid-cols-2">
                            <HeroCluster title="Representatives & committees" icon={Users}>
                                <HeroClusterTile href={`${BASE}?tab=representatives&status=active`} label="Active reps" value={fmt(c.participation.active_reps)} caption="currently serving" tone="success" />
                                <HeroClusterTile href={`${BASE}?tab=representatives`} label="Sites w/o rep" value={fmt(c.participation.sites_without_rep)} caption="coverage gap" tone={c.participation.sites_without_rep > 0 ? 'warning' : 'success'} />
                                <HeroClusterTile href={`${BASE}?tab=meetings`} label="Committees" value={fmt(c.participation.committees)} caption="across all sites" tone="neutral" />
                                <HeroClusterTile href={`${BASE}?tab=meetings`} label="Meetings · qtr" value={fmt(c.participation.meetings_quarter)} caption="this quarter" tone="neutral" />
                            </HeroCluster>
                            <HeroCluster title="Consultation & actions" icon={MessageSquare}>
                                <HeroClusterTile href={`${BASE}?tab=consultations&status=open`} label="Open consults" value={fmt(c.consultation.open)} caption="not yet closed" tone="warning" />
                                <HeroClusterTile href={`${BASE}?tab=consultations`} label="Awaiting feedback" value={fmt(c.consultation.awaiting_feedback)} caption="kaimahi to respond" tone="warning" />
                                <HeroClusterTile href={`${BASE}?tab=meetings`} label="Overdue actions" value={fmt(c.consultation.overdue_actions)} caption="from meetings" tone={c.consultation.overdue_actions > 0 ? 'critical' : 'success'} />
                                <HeroClusterTile href={`${BASE}?tab=meetings`} label="Minutes out" value={fmt(c.consultation.minutes_outstanding)} caption="to be filed" tone={c.consultation.minutes_outstanding > 0 ? 'warning' : 'success'} />
                            </HeroCluster>
                        </div>

                        {/* WP-specific NZ compliance chip row (HeroComplianceBadges is H&S-dashboard-specific). */}
                        <div className="mt-4 flex flex-wrap items-center gap-2">
                            <HeroChip icon={ShieldCheck} tone={coverageTone} label={`Reps coverage · ${b.reps_coverage_pct}% (${b.sites_covered}/${b.sites_total} sites)`} />
                            <HeroChip icon={FileText} tone={b.minutes_overdue > 0 ? 'warning' : 'success'} label={b.minutes_overdue > 0 ? `Minutes · ${b.minutes_overdue} outstanding` : 'Minutes · all filed'} />
                            <HeroChip icon={MessageSquare} tone={b.consultations_awaiting > 0 ? 'warning' : 'success'} label={b.consultations_awaiting > 0 ? `Consultations · ${b.consultations_awaiting} awaiting feedback` : 'Consultations · up to date'} />
                            <HeroChip icon={GraduationCap} tone={b.training_below_minimum > 0 ? 'warning' : 'success'} label={b.training_below_minimum > 0 ? `Rep training · ${b.training_below_minimum} below 2-day min` : 'Rep training · minimum met'} />
                        </div>
                    </div>
                </HeroShell>

                <TabStrip value={tab} onChange={setTab} items={TABS} ariaLabel="Participation views" />

                <Card>
                    <CardContent className="p-0">
                        <RegisterTableHeader
                            icon={tab === 'representatives' ? Users : tab === 'meetings' ? Calendar : MessageSquare}
                            title={tab === 'representatives' ? 'H&S Representatives' : tab === 'meetings' ? 'Committee Meetings' : 'Worker Consultations'}
                            subtitle={tab === 'representatives' ? 'elected & appointed reps' : tab === 'meetings' ? 'scheduled & completed' : 'feedback → outcome → close'}
                            hint="Right-click a row for the full list of actions" hintIcon={MousePointer2}
                        />
                        {tab === 'representatives' && <RepTable rows={rows.data as RepRow[]} onOpen={(id) => openDetail('representative', id)} onCtx={openRowCtx} />}
                        {tab === 'meetings' && <MeetingTable rows={rows.data as MeetingRow[]} onOpen={(id) => openDetail('meeting', id)} onCtx={openRowCtx} />}
                        {tab === 'consultations' && <ConsultationTable rows={rows.data as ConsultationRow[]} onOpen={(id) => openDetail('consultation', id)} onCtx={openRowCtx} />}
                    </CardContent>
                </Card>

                {rows.last_page > 1 ? <LaravelPagination links={rows.links} /> : null}
            </div>

            {ctx ? <ShiftContextMenu ctx={ctx} onClose={() => setCtx(null)} /> : null}

            {detail?.kind === 'representative' ? <RepresentativeDetailDialog key={detail.data.id} detail={detail.data} open onClose={closeDetail} staff={staff} sites={sites} can={can} initialAction={pendingAction} /> : null}
            {detail?.kind === 'meeting' ? <MeetingDetailDialog key={detail.data.id} detail={detail.data} open onClose={closeDetail} staff={staff} can={can} initialAction={pendingAction} /> : null}
            {detail?.kind === 'consultation' ? <ConsultationDetailDialog key={detail.data.id} detail={detail.data} open onClose={closeDetail} sites={sites} staff={staff} can={can} initialAction={pendingAction} /> : null}

            {wizard === 'rep' ? <AddRepresentativeWizard open sites={sites} staff={staff} onClose={() => setWizard(null)} /> : null}
            {wizard === 'meeting' ? <ScheduleMeetingWizard open committees={committees} sites={sites} staff={staff} onClose={() => setWizard(null)} /> : null}
            {wizard === 'consultation' ? <NewConsultationWizard open sites={sites} staff={staff} onClose={() => setWizard(null)} /> : null}
        </AppLayout>
    );
}

/* ------------------------------------------------------------------ */
/*  Hero chip (WP-specific compliance row)                             */
/* ------------------------------------------------------------------ */

function HeroChip({ icon: Icon, tone, label }: { icon: ComponentType<{ className?: string }>; tone: Tone; label: string }) {
    return (
        // eslint-disable-next-line no-restricted-syntax -- onDark hero compliance chip
        <span className="inline-flex items-center gap-1.5 rounded-full border border-primary-foreground/20 bg-primary-foreground/10 px-2.5 py-1 text-[11px] font-medium text-primary-foreground">
            <Icon className="h-3.5 w-3.5 text-primary-foreground/80" />
            {label}
            <span className={`h-1.5 w-1.5 rounded-full ${TONE_DOT[tone]}`} />
        </span>
    );
}

/* ------------------------------------------------------------------ */
/*  Tables                                                             */
/* ------------------------------------------------------------------ */

function Row({ children, onClick, onContextMenu }: { children: ReactNode; onClick: () => void; onContextMenu: (e: ReactMouseEvent) => void }) {
    return (
        <tr tabIndex={0} onClick={onClick} onContextMenu={onContextMenu}
            onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); onClick(); } }}
            className="cursor-pointer transition-colors hover:bg-muted/45 focus:bg-muted/45 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/40">
            {children}
        </tr>
    );
}

const TH = ({ children }: { children: ReactNode }) => <th className="px-4 py-2.5">{children}</th>;
const HeadRow = ({ cols }: { cols: string[] }) => (
    <thead><tr className="border-b text-left text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">{cols.map((c) => <TH key={c}>{c}</TH>)}</tr></thead>
);
const Pill = ({ tone, children }: { tone: Tone; children: ReactNode }) => (
    <span className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-[11px] font-medium ${TONE_BG[tone]}`}><span className={`h-1.5 w-1.5 rounded-full ${TONE_DOT[tone]}`} />{children}</span>
);

function RepTable({ rows, onOpen, onCtx }: { rows: RepRow[]; onOpen: (id: number) => void; onCtx: (e: ReactMouseEvent, kind: string, row: RepRow, tag: string, meta: string) => void }) {
    if (!rows.length) return <Empty icon={Users} label="No representatives" />;
    return (
        <div className="overflow-x-auto"><table className="w-full text-sm">
            <HeadRow cols={['Representative', 'Site', 'Work group', 'Election', 'Training', 'Status']} />
            <tbody className="divide-y">
                {rows.map((r) => {
                    const name = r.user?.name ?? '—';
                    const below = (r.training_days_completed ?? 0) < 2;
                    return (
                        <Row key={r.id} onClick={() => onOpen(r.id)} onContextMenu={(e) => onCtx(e, 'representative', r, r.status.toUpperCase(), `${name} · ${r.site?.name ?? ''}`)}>
                            <td className="px-4 py-3"><div className="flex items-center gap-2.5"><span className={`flex h-8 w-8 items-center justify-center rounded-full text-[11px] font-semibold ${entityTone(r.id)}`}>{initials(name)}</span><span className="font-medium">{name}</span></div></td>
                            <td className="px-4 py-3"><span className="inline-flex items-center gap-1.5 text-muted-foreground"><MapPin className="h-3.5 w-3.5" />{r.site?.name ?? '—'}</span></td>
                            <td className="px-4 py-3 text-muted-foreground">{r.work_group ?? '—'}</td>
                            <td className="px-4 py-3"><span className="font-medium">{titleCase(r.election_method ?? '—')}</span><span className="block text-xs text-muted-foreground">{fmtDate(r.elected_at)}</span></td>
                            <td className="px-4 py-3"><FlagBadge icon={GraduationCap} tone={below ? 'warning' : 'neutral'} title="HSWA paid training">{r.training_days_completed} {r.training_days_completed === 1 ? 'day' : 'days'}</FlagBadge></td>
                            <td className="px-4 py-3"><Pill tone={REP_STATUS[r.status] ?? 'neutral'}>{titleCase(r.status)}</Pill></td>
                        </Row>
                    );
                })}
            </tbody>
        </table></div>
    );
}

function MeetingTable({ rows, onOpen, onCtx }: { rows: MeetingRow[]; onOpen: (id: number) => void; onCtx: (e: ReactMouseEvent, kind: string, row: MeetingRow, tag: string, meta: string) => void }) {
    if (!rows.length) return <Empty icon={Calendar} label="No meetings" />;
    return (
        <div className="overflow-x-auto"><table className="w-full text-sm">
            <HeadRow cols={['Committee', 'Date', 'Status', 'Attendees', 'Flags']} />
            <tbody className="divide-y">
                {rows.map((m) => (
                    <Row key={m.id} onClick={() => onOpen(m.id)} onContextMenu={(e) => onCtx(e, 'meeting', m, m.status.toUpperCase(), m.committee?.name ?? '')}>
                        <td className="px-4 py-3"><div className="flex items-center gap-2.5"><span className="grid h-8 w-8 place-items-center rounded-lg bg-primary/10 text-primary"><Users className="h-4 w-4" /></span><span className="font-medium">{m.committee?.name ?? '—'}</span></div></td>
                        <td className="px-4 py-3"><span className="inline-flex items-center gap-1.5 text-muted-foreground"><Calendar className="h-3.5 w-3.5" />{fmtDate(m.scheduled_at)}</span></td>
                        <td className="px-4 py-3"><Pill tone={MEETING_STATUS[m.status] ?? 'neutral'}>{titleCase(m.status)}</Pill></td>
                        <td className="px-4 py-3"><span className="inline-flex items-center gap-1.5 text-muted-foreground"><UserCog className="h-3.5 w-3.5" />{m.attendees_count > 0 ? `${m.attendees_count} confirmed` : '—'}</span></td>
                        <td className="px-4 py-3"><div className="flex flex-wrap gap-1.5">
                            {m.actions_due_count > 0 ? <FlagBadge icon={Wrench} tone="critical" title="Action items due">{m.actions_due_count} due</FlagBadge> : null}
                            {m.status === 'completed' && !m.minutes_document_path ? <FlagBadge icon={FileText} tone="warning" title="Minutes outstanding">No minutes</FlagBadge> : null}
                            {m.minutes_document_path ? <FlagBadge icon={FileCheck2} tone="success" title="Minutes filed">Minutes</FlagBadge> : null}
                        </div></td>
                    </Row>
                ))}
            </tbody>
        </table></div>
    );
}

function ConsultationTable({ rows, onOpen, onCtx }: { rows: ConsultationRow[]; onOpen: (id: number) => void; onCtx: (e: ReactMouseEvent, kind: string, row: ConsultationRow, tag: string, meta: string) => void }) {
    if (!rows.length) return <Empty icon={MessageSquare} label="No consultations" />;
    return (
        <div className="overflow-x-auto"><table className="w-full text-sm">
            <HeadRow cols={['Topic', 'Type', 'Status', 'Progress', 'Docs']} />
            <tbody className="divide-y">
                {rows.map((c) => {
                    const st = CONSULT_STATUS[c.status] ?? CONSULT_STATUS.open;
                    const ord = CONSULT_ORDER[c.status] ?? 1;
                    return (
                        <Row key={c.id} onClick={() => onOpen(c.id)} onContextMenu={(e) => onCtx(e, 'consultation', c, c.status.toUpperCase(), c.title)}>
                            <td className="px-4 py-3"><div className="flex items-center gap-2.5"><span className={`h-1.5 w-1.5 rounded-full ${TONE_DOT[st.tone]}`} /><span><span className="block max-w-sm truncate font-medium">{c.title}</span><span className="block text-xs text-muted-foreground">{c.site?.name} · {fmtDate(c.consultation_date)}</span></span></div></td>
                            <td className="px-4 py-3"><span className={`inline-flex rounded-md px-2 py-0.5 text-[11px] font-medium ${TONE_BG.neutral}`}>{titleCase(c.consultation_type)}</span></td>
                            <td className="px-4 py-3"><span className={`inline-flex rounded-full px-2.5 py-0.5 text-[11px] font-medium ${TONE_BG[st.tone]}`}>{st.label}</span></td>
                            <td className="px-4 py-3"><div className="flex items-center gap-1">{[1, 2, 3, 4].map((i) => <span key={i} className={`h-1.5 w-6 rounded ${i <= ord ? TONE_DOT[st.tone] : 'bg-muted'}`} />)}</div></td>
                            <td className="px-4 py-3"><div className="flex gap-2 text-muted-foreground"><FileText className={`h-4 w-4 ${c.document_path ? 'text-primary' : 'opacity-30'}`} /><FileCheck2 className={`h-4 w-4 ${c.outcome_document_path ? 'text-status-success' : 'opacity-30'}`} /></div></td>
                        </Row>
                    );
                })}
            </tbody>
        </table></div>
    );
}

function Empty({ icon: Icon, label }: { icon: ComponentType<{ className?: string }>; label: string }) {
    return <div className="px-4 py-16 text-center"><Icon className="mx-auto mb-3 h-10 w-10 text-muted-foreground/40" /><p className="font-medium text-muted-foreground">{label}</p><p className="mt-1 text-sm text-muted-foreground/70">Nothing matches this tab and filters.</p></div>;
}
