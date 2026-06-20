/* eslint-disable no-restricted-syntax -- the hero filter-bar uses styled native <select>
 * and <input> controls on the dark primary gradient (matching the H&S dashboard/analytics
 * heroes); every colour is a semantic design token. */
import { Button } from '@/components/ui/button';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import {
    FirstAidDetailDialog,
    type FirstAidActionKey,
    type FirstAidDetail,
    type FirstAidSectionKey,
} from '@/components/health-safety/first-aid-detail-dialog';
import { FirstAidReportDialog } from '@/components/health-safety/first-aid-report-dialog';
import {
    EntityFilter,
    ShiftContextMenu,
    TabStrip,
    type RosterTabItem,
    type ShiftCtxItem,
    type ShiftCtxState,
} from '@/components/rostering';
import AppLayout from '@/layouts/app-layout';
import {
    HeroCluster,
    HeroClusterTile,
    HeroComplianceBadges,
    HeroMedallion,
    HeroSegmented,
    HeroShell,
    HeroStatusPill,
    fmt,
    type HeroComplianceBadge,
} from '@/pages/health-safety/components/hs-hero-kit';
import {
    FlagBadge,
    RegisterTableHeader,
    TONE_BG,
    TONE_DOT,
    entityTone,
    initials,
} from '@/pages/health-safety/components/register-row-kit';
import { WorkflowRibbon } from '@/pages/health-safety/components/workflow-ribbon';
import {
    injuryLabel,
    injuryTone,
    outcomeLabel,
    outcomeTone,
    personTypeLabel,
    OUTCOMES,
    PERSON_TYPES,
} from '@/pages/health-safety/first-aid/options';
import { Head, router } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    Ambulance,
    BarChart3,
    CheckCircle2,
    ClipboardList,
    FileText,
    HeartPulse,
    LayoutList,
    Link2,
    MousePointer2,
    Paperclip,
    Pencil,
    Plus,
    Search,
    ShieldCheck,
    Trash2,
    X,
} from 'lucide-react';
import { useState, type MouseEvent as ReactMouseEvent } from 'react';

type Opt = { id: number; name: string };
type IncidentOpt = { id: number; reference: string; label: string };

type FirstAidRow = {
    id: number;
    reference: string;
    treatment_date: string | null;
    treated_person_name: string;
    treated_person_type: string;
    injury_illness_type: string;
    body_part: string | null;
    treatment_given: string | null;
    treatment_outcome: string;
    ambulance_called: boolean;
    first_aider_name: string | null;
    site_name: string | null;
    incident_reported: boolean;
    related_incident_id: number | null;
    attachments_count: number;
    open_followups_count: number;
};

type Filters = {
    site_id: number | null;
    treated_person_type: string | null;
    treatment_outcome: string | null;
    first_aider_id: number | null;
    period: string;
    q: string | null;
};

type Hero = {
    live: { treated: number; ambulance: number; hospital: number; linked: number };
    attention: { reportable_unlinked: number; followups_open: number; no_aider: number; today: number };
    badges: { first_aiders: number; worksafe_awaiting: number; acc45_month: number };
};

type Paginated<T> = {
    data: T[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
    current_page: number;
    last_page: number;
};

type Props = {
    records: Paginated<FirstAidRow>;
    tab: string;
    tabCounts: Record<string, number>;
    hero: Hero;
    filters: Filters;
    sites: Opt[];
    firstAiders: Opt[];
    clients: Opt[];
    incidents: IncidentOpt[];
    can: { view: boolean; create: boolean; manage: boolean };
    detail: FirstAidDetail | null;
    report?: boolean;
};

const BASE = '/health-safety/first-aid';

const PERIODS = [
    { key: 'week', label: 'This week' },
    { key: '30d', label: '30 days' },
    { key: 'quarter', label: 'Quarter' },
    { key: 'all', label: 'All' },
];

function whenCompact(iso: string | null): string {
    if (!iso) return '—';
    const date = new Date(iso);
    if (Number.isNaN(date.getTime())) return '—';
    const now = new Date();
    const pad = (n: number) => String(n).padStart(2, '0');
    const hm = `${pad(date.getHours())}:${pad(date.getMinutes())}`;
    if (date.toDateString() === now.toDateString()) return `Today ${hm}`;
    const yesterday = new Date(now.getTime() - 86400000);
    if (date.toDateString() === yesterday.toDateString()) return `Yesterday ${hm}`;
    const days = Math.floor((now.getTime() - date.getTime()) / 86400000);
    if (days >= 0 && days < 7) return `${days} day${days === 1 ? '' : 's'} ago`;
    return date.toLocaleDateString('en-NZ', { day: 'numeric', month: 'short', year: 'numeric' });
}

export default function FirstAidIndex({
    records,
    tab,
    tabCounts,
    hero,
    filters,
    sites,
    firstAiders,
    clients,
    incidents,
    can,
    detail,
    report,
}: Props) {
    const [ctx, setCtx] = useState<ShiftCtxState | null>(null);
    const [reportOpen, setReportOpen] = useState(() => !!report);
    const [pendingSection, setPendingSection] = useState<FirstAidSectionKey>('overview');
    const [pendingAction, setPendingAction] = useState<FirstAidActionKey | null>(null);

    const hasFilters =
        !!filters.site_id ||
        !!filters.treated_person_type ||
        !!filters.treatment_outcome ||
        !!filters.first_aider_id ||
        !!filters.q ||
        (filters.period !== '30d' && !!filters.period);

    type QueryValue = string | number | undefined;
    const params = (extra: Record<string, QueryValue> = {}): Record<string, QueryValue> => ({
        site_id: filters.site_id ?? undefined,
        treated_person_type: filters.treated_person_type ?? undefined,
        treatment_outcome: filters.treatment_outcome ?? undefined,
        first_aider_id: filters.first_aider_id ?? undefined,
        period: filters.period ?? undefined,
        q: filters.q ?? undefined,
        tab: tab !== 'all' ? tab : undefined,
        ...extra,
    });

    const go = (extra: Record<string, QueryValue>) =>
        router.get(BASE, params(extra), { preserveState: true, preserveScroll: true, replace: true });

    const setTab = (id: string) =>
        router.get(BASE, params({ tab: id === 'all' ? undefined : id }), { preserveState: true, preserveScroll: true });

    const clearFilters = () =>
        router.get(BASE, tab !== 'all' ? { tab } : {}, { preserveState: true, preserveScroll: true, replace: true });

    const openRecord = (id: number, opts?: { section?: FirstAidSectionKey; action?: FirstAidActionKey }) => {
        setPendingSection(opts?.section ?? 'overview');
        setPendingAction(opts?.action ?? null);
        router.get(BASE, params({ record: id }), { preserveState: true, preserveScroll: true, only: ['detail'] });
    };
    const closeDetail = () =>
        router.get(BASE, params(), { preserveState: true, preserveScroll: true, only: ['detail'] });

    const TABS: RosterTabItem[] = [
        { id: 'all', label: 'All', icon: LayoutList, tone: 'primary', badge: tabCounts.all || undefined },
        { id: 'ambulance', label: 'Ambulance called', icon: Ambulance, tone: 'critical', badge: tabCounts.ambulance || undefined },
        { id: 'linked', label: 'Linked to incident', icon: Link2, tone: 'success', badge: tabCounts.linked || undefined },
        { id: 'reportable', label: 'Reportable · unlinked', icon: AlertTriangle, tone: 'warning', badge: tabCounts.reportable || undefined },
        { id: 'followup', label: 'Open follow-ups', icon: ClipboardList, tone: 'info', badge: tabCounts.followup || undefined },
    ];

    const badges: HeroComplianceBadge[] = [
        { icon: HeartPulse, tone: 'success', label: `First aiders · ${hero.badges.first_aiders} on the roll` },
        {
            icon: hero.badges.worksafe_awaiting > 0 ? AlertTriangle : CheckCircle2,
            tone: hero.badges.worksafe_awaiting > 0 ? 'warning' : 'success',
            label: `WorkSafe-notifiable · ${hero.badges.worksafe_awaiting} awaiting`,
        },
        { icon: FileText, tone: 'success', label: `ACC45 · ${hero.badges.acc45_month} this month` },
        { icon: ShieldCheck, tone: 'success', label: 'Ngā Paerewa NZS 8134:2021 · Certified' },
    ];

    const tableTitle =
        TABS.find((t) => t.id === tab)?.label === 'All' ? 'All treatments' : (TABS.find((t) => t.id === tab)?.label ?? 'All treatments');

    const openRowCtx = (e: ReactMouseEvent, r: FirstAidRow) => {
        e.preventDefault();
        const items: ShiftCtxItem[] = [
            { icon: <HeartPulse className="h-3.5 w-3.5" />, label: 'View record', sub: r.reference, tone: 'primary', onClick: () => openRecord(r.id) },
        ];
        if (can.manage) {
            items.push(
                { icon: <Pencil className="h-3.5 w-3.5" />, label: 'Edit treatment', onClick: () => openRecord(r.id, { action: 'edit' }) },
                {
                    icon: <Link2 className="h-3.5 w-3.5" />,
                    label: r.incident_reported ? 'View incident link' : 'Link to incident',
                    onClick: () => openRecord(r.id, { section: 'incident', action: r.incident_reported ? undefined : 'link_incident' }),
                },
                { icon: <Plus className="h-3.5 w-3.5" />, label: 'Add follow-up', onClick: () => openRecord(r.id, { action: 'add_followup' }) },
                { icon: <Paperclip className="h-3.5 w-3.5" />, label: 'Add evidence', onClick: () => openRecord(r.id, { section: 'evidence' }) },
                { sep: true },
                { icon: <Trash2 className="h-3.5 w-3.5" />, label: 'Archive record', tone: 'critical', onClick: () => openRecord(r.id, { action: 'delete' }) },
            );
        }
        setCtx({ x: e.clientX, y: e.clientY, tag: 'FIRST AID', meta: `${r.reference} · ${r.treated_person_name}`, items });
    };

    const openHeroCtx = (e: ReactMouseEvent) => {
        e.preventDefault();
        const items: ShiftCtxItem[] = [];
        if (can.create) items.push({ icon: <Plus className="h-3.5 w-3.5" />, label: 'Record first aid', tone: 'primary', onClick: () => setReportOpen(true) });
        items.push(
            { icon: <HeartPulse className="h-3.5 w-3.5" />, label: 'Go to H&S events', onClick: () => router.visit('/health-safety/events') },
            { icon: <BarChart3 className="h-3.5 w-3.5" />, label: 'Go to analytics', onClick: () => router.visit('/health-safety/analytics') },
        );
        setCtx({ x: e.clientX, y: e.clientY, tag: 'FIRST AID', meta: 'Register actions', items });
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Health & Safety', href: '/health-safety' },
                { title: 'First aid', href: BASE },
            ]}
        >
            <Head title="First aid" />

            <div className="flex flex-col gap-6 p-6">
                {/* ── Hero ── */}
                <div onContextMenu={openHeroCtx}>
                    <HeroShell
                        footer={
                            <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
                                <HeroSegmented
                                    label="Period"
                                    variant="pill"
                                    ariaLabel="Date range"
                                    items={PERIODS}
                                    value={filters.period}
                                    onChange={(v) => go({ period: v })}
                                />
                                {sites.length > 0 ? (
                                    <EntityFilter
                                        label="Site"
                                        allLabel="All sites"
                                        items={sites}
                                        value={filters.site_id}
                                        onChange={(id) => go({ site_id: id ?? undefined })}
                                        onDark
                                    />
                                ) : null}
                                <HeroSelect
                                    label="Person"
                                    value={filters.treated_person_type ?? ''}
                                    onChange={(v) => go({ treated_person_type: v || undefined })}
                                    options={[{ value: '', label: 'All' }, ...PERSON_TYPES]}
                                />
                                <HeroSelect
                                    label="Outcome"
                                    value={filters.treatment_outcome ?? ''}
                                    onChange={(v) => go({ treatment_outcome: v || undefined })}
                                    options={[{ value: '', label: 'All outcomes' }, ...OUTCOMES]}
                                />
                                <div className="relative ml-auto">
                                    <Search className="pointer-events-none absolute top-1/2 left-2.5 h-3.5 w-3.5 -translate-y-1/2 text-primary-foreground/60" />
                                    <input
                                        type="search"
                                        placeholder="Search treatments…"
                                        defaultValue={filters.q ?? ''}
                                        onKeyDown={(e) => {
                                            if (e.key === 'Enter') go({ q: (e.target as HTMLInputElement).value || undefined });
                                        }}
                                        className="w-48 rounded-lg border border-primary-foreground/20 bg-primary-foreground/10 py-1.5 pr-2.5 pl-8 text-xs text-primary-foreground placeholder:text-primary-foreground/50 focus-visible:ring-2 focus-visible:ring-primary-foreground/40 focus-visible:outline-none"
                                    />
                                </div>
                                {hasFilters ? (
                                    <button
                                        type="button"
                                        onClick={clearFilters}
                                        className="inline-flex items-center gap-1 rounded-md px-2 py-1 text-[11px] font-medium text-primary-foreground/70 transition-colors hover:text-primary-foreground"
                                    >
                                        <X className="h-3 w-3" /> Clear
                                    </button>
                                ) : null}
                            </div>
                        }
                    >
                        <WorkflowRibbon current="report" />

                        <div className="flex flex-wrap items-start justify-between gap-4">
                            <div className="flex items-start gap-4">
                                <HeroMedallion icon={HeartPulse} />
                                <div className="flex flex-col gap-1.5">
                                    <HeroStatusPill>First-aid register · synced just now</HeroStatusPill>
                                    <h1 className="text-2xl font-bold tracking-tight text-primary-foreground md:text-[28px]">First Aid Register</h1>
                                    <p className="max-w-xl text-sm text-primary-foreground/70">
                                        Every first-aid treatment — recorded, triaged and linked to its incident where escalation is
                                        needed. Right-click any row for the full treatment lifecycle.
                                    </p>
                                </div>
                            </div>
                            {can.create ? (
                                <Button
                                    onClick={() => setReportOpen(true)}
                                    className="border border-primary-foreground/25 bg-primary-foreground text-primary hover:bg-primary-foreground/90"
                                >
                                    <Plus className="mr-1.5 h-4 w-4" /> Record first aid
                                </Button>
                            ) : null}
                        </div>

                        <div className="grid gap-3 lg:grid-cols-2">
                            <HeroCluster title="Live · last 30 days" icon={Activity}>
                                <HeroClusterTile href={`${BASE}?period=30d`} label="Treated" value={fmt(hero.live.treated)} caption="treatments" tone="neutral" />
                                <HeroClusterTile href={`${BASE}?tab=ambulance`} label="Ambulance" value={fmt(hero.live.ambulance)} caption="111 called" tone="critical" />
                                <HeroClusterTile href={`${BASE}?treatment_outcome=sent_to_hospital`} label="To hospital" value={fmt(hero.live.hospital)} caption="referred on" tone="critical" />
                                <HeroClusterTile href={`${BASE}?tab=linked`} label="Linked" value={fmt(hero.live.linked)} caption="to incidents" tone="success" />
                            </HeroCluster>
                            <HeroCluster title="Needs attention" icon={AlertTriangle}>
                                <HeroClusterTile href={`${BASE}?tab=reportable`} label="Reportable" value={fmt(hero.attention.reportable_unlinked)} caption="unlinked" tone="critical" />
                                <HeroClusterTile href={`${BASE}?tab=followup`} label="Follow-up" value={fmt(hero.attention.followups_open)} caption="open" tone="warning" />
                                <HeroClusterTile label="No aider" value={fmt(hero.attention.no_aider)} caption="legacy rows" tone="warning" />
                                <HeroClusterTile label="Today" value={fmt(hero.attention.today)} caption="new treatments" tone="success" />
                            </HeroCluster>
                        </div>

                        <HeroComplianceBadges items={badges} />
                    </HeroShell>
                </div>

                {/* ── Tabs ── */}
                <TabStrip value={tab} items={TABS} onChange={setTab} ariaLabel="First aid views" />

                {/* ── Table ── */}
                <section className="overflow-hidden rounded-2xl border border-border bg-card shadow-sm">
                    <RegisterTableHeader
                        icon={HeartPulse}
                        title={tableTitle}
                        subtitle={`${records.data.length} shown`}
                        hint="Right-click a row for treatment actions"
                        hintIcon={MousePointer2}
                    />
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[1040px] text-sm">
                            <thead className="bg-muted/70 text-left text-[11px] font-bold tracking-wide text-muted-foreground uppercase">
                                <tr>
                                    <th className="px-4 py-3 font-bold">When</th>
                                    <th className="px-4 py-3 font-bold">Person treated</th>
                                    <th className="px-4 py-3 font-bold">Injury / illness</th>
                                    <th className="px-4 py-3 font-bold">Treatment given</th>
                                    <th className="px-4 py-3 font-bold">Outcome</th>
                                    <th className="px-4 py-3 font-bold">First-aider</th>
                                    <th className="px-4 py-3 font-bold">Incident</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-border">
                                {records.data.map((r) => {
                                    const oTone = outcomeTone(r.treatment_outcome);
                                    const iTone = injuryTone(r.injury_illness_type);
                                    return (
                                        <tr
                                            key={r.id}
                                            onClick={() => openRecord(r.id)}
                                            onContextMenu={(e) => openRowCtx(e, r)}
                                            tabIndex={0}
                                            aria-label={`Open record ${r.reference}`}
                                            onKeyDown={(e) => {
                                                if (e.key === 'Enter' || e.key === ' ') {
                                                    e.preventDefault();
                                                    openRecord(r.id);
                                                }
                                            }}
                                            className="cursor-pointer transition-colors hover:bg-muted/45 focus-visible:bg-muted/45 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring"
                                        >
                                            <td className="px-4 py-3 align-top whitespace-nowrap">
                                                <div className="text-[12.5px] font-bold">{whenCompact(r.treatment_date)}</div>
                                                <div className="mt-0.5 text-[11px] font-semibold text-muted-foreground">{r.reference}</div>
                                            </td>
                                            <td className="px-4 py-3 align-top">
                                                <div className="flex items-center gap-2.5">
                                                    <span className={`grid h-8 w-8 shrink-0 place-items-center rounded-full text-[11px] font-bold ${entityTone(r.id)}`}>
                                                        {initials(r.treated_person_name)}
                                                    </span>
                                                    <span className="min-w-0">
                                                        <span className="block truncate text-[12.5px] font-bold">{r.treated_person_name || '—'}</span>
                                                        <span className="mt-0.5 inline-flex items-center rounded-md border border-border px-1.5 text-[10px] font-semibold text-muted-foreground">
                                                            {personTypeLabel(r.treated_person_type)}
                                                        </span>
                                                    </span>
                                                </div>
                                            </td>
                                            <td className="px-4 py-3 align-top">
                                                <div className="flex items-start gap-2">
                                                    <span className={`mt-1.5 h-2 w-2 shrink-0 rounded-full ${TONE_DOT[iTone]}`} />
                                                    <span className="min-w-0">
                                                        <span className="block text-[12.5px] font-semibold">{injuryLabel(r.injury_illness_type)}</span>
                                                        {r.body_part ? <span className="mt-0.5 block text-[11px] text-muted-foreground">{r.body_part}</span> : null}
                                                    </span>
                                                </div>
                                            </td>
                                            <td className="max-w-[230px] px-4 py-3 align-top">
                                                <span className="block truncate text-foreground">{r.treatment_given || '—'}</span>
                                                {r.attachments_count > 0 || r.open_followups_count > 0 ? (
                                                    <span className="mt-1 flex items-center gap-2 text-[10.5px] font-medium text-muted-foreground">
                                                        {r.attachments_count > 0 ? (
                                                            <span className="inline-flex items-center gap-0.5">
                                                                <Paperclip className="h-3 w-3" />
                                                                {r.attachments_count}
                                                            </span>
                                                        ) : null}
                                                        {r.open_followups_count > 0 ? (
                                                            <span className="inline-flex items-center gap-0.5 text-status-warning">
                                                                <ClipboardList className="h-3 w-3" />
                                                                {r.open_followups_count}
                                                            </span>
                                                        ) : null}
                                                    </span>
                                                ) : null}
                                            </td>
                                            <td className="px-4 py-3 align-top">
                                                <div className="flex items-center gap-1.5">
                                                    <span className={`inline-flex items-center rounded-md px-2 py-1 text-[11px] font-bold ${TONE_BG[oTone]}`}>
                                                        {outcomeLabel(r.treatment_outcome)}
                                                    </span>
                                                    {r.ambulance_called ? (
                                                        <span
                                                            title="Ambulance called"
                                                            className="grid h-5 w-5 place-items-center rounded bg-status-critical-bg text-status-critical"
                                                        >
                                                            <Ambulance className="h-3 w-3" />
                                                        </span>
                                                    ) : null}
                                                </div>
                                            </td>
                                            <td className="px-4 py-3 align-top">
                                                <span className="text-[12.5px]">{r.first_aider_name ?? <span className="text-muted-foreground">—</span>}</span>
                                            </td>
                                            <td className="px-4 py-3 align-top">
                                                {r.related_incident_id ? (
                                                    <FlagBadge icon={Link2} tone="success" title="Linked to incident">
                                                        Linked
                                                    </FlagBadge>
                                                ) : r.incident_reported || r.ambulance_called || r.treatment_outcome === 'sent_to_hospital' ? (
                                                    <FlagBadge icon={AlertTriangle} tone="critical" title="Reportable — not yet linked to an incident">
                                                        Reportable
                                                    </FlagBadge>
                                                ) : (
                                                    <span className="text-muted-foreground">—</span>
                                                )}
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                        {records.data.length === 0 ? (
                            <div className="flex flex-col items-center justify-center gap-2 px-6 py-16 text-center">
                                <HeartPulse className="h-10 w-10 text-muted-foreground/40" />
                                <p className="font-semibold text-muted-foreground">No treatments here</p>
                                <p className="text-sm text-muted-foreground/70">Nothing matches this tab and filters.</p>
                            </div>
                        ) : null}
                    </div>
                </section>

                {records.last_page > 1 ? <LaravelPagination links={records.links} lastPage={records.last_page} /> : null}
            </div>

            {ctx ? <ShiftContextMenu ctx={ctx} onClose={() => setCtx(null)} /> : null}

            {reportOpen && can.create ? (
                <FirstAidReportDialog
                    open
                    onClose={() => setReportOpen(false)}
                    sites={sites}
                    firstAiders={firstAiders}
                    clients={clients}
                    incidents={incidents}
                    onOpenRecord={(id) => {
                        setReportOpen(false);
                        openRecord(id);
                    }}
                />
            ) : null}

            {detail ? (
                <FirstAidDetailDialog
                    key={detail.id}
                    detail={detail}
                    open
                    onClose={closeDetail}
                    initialSection={pendingSection}
                    initialAction={pendingAction}
                    sites={sites}
                    firstAiders={firstAiders}
                    clients={clients}
                    incidents={incidents}
                />
            ) : null}
        </AppLayout>
    );
}

/* Dark-hero native select — matches the search input chrome on the primary gradient. */
function HeroSelect({
    label,
    value,
    onChange,
    options,
}: {
    label: string;
    value: string;
    onChange: (v: string) => void;
    options: { value: string; label: string }[];
}) {
    return (
        <label className="inline-flex items-center gap-1.5">
            <span className="text-[11px] font-semibold tracking-wide text-primary-foreground/60 uppercase">{label}</span>
            <select
                value={value}
                onChange={(e) => onChange(e.target.value)}
                className="rounded-lg border border-primary-foreground/20 bg-primary-foreground/10 px-2.5 py-1.5 text-xs font-medium text-primary-foreground focus-visible:ring-2 focus-visible:ring-primary-foreground/40 focus-visible:outline-none"
            >
                {options.map((o) => (
                    <option key={o.value} value={o.value} className="text-foreground">
                        {o.label}
                    </option>
                ))}
            </select>
        </label>
    );
}
