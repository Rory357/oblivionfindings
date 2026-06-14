/* eslint-disable no-restricted-syntax -- the register/near-limit/trends surfaces are
   custom-layout bordered panels (not Card/Button); all colours are semantic tokens. */
import { PageHero, type PageHeroStat } from '@/components/page';
import { EntityFilter, TabStrip, type RosterTabItem } from '@/components/rostering';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { PrnEffectDialog } from '@/pages/meds/today/components/prn-effect-dialog';
import { PrnWizard } from '@/pages/meds/today/components/prn-wizard';
import type { ClientInfo, PrnFollowUp, PrnMedication } from '@/pages/meds/today/types';
import { Head, router } from '@inertiajs/react';
import { AlertTriangle, BarChart3, Clock, Pill, Plus, Search, TrendingUp } from 'lucide-react';
import { useMemo, useState } from 'react';

type PrnAdministration = {
    id: number;
    client_id: number;
    client_name: string;
    client_medication_id: number;
    medication_name: string | null;
    controlled_drug: boolean;
    dose_given: string | null;
    reason: string | null;
    indication: string | null;
    status: string;
    administered_at: string | null;
    given_time: string | null;
    given_date: string | null;
    given_by: string | null;
    effectiveness: string | null;
    effectiveness_label: string | null;
};

type Props = {
    administrations: PrnAdministration[];
    pending_reviews: PrnFollowUp[];
    prn_medications: PrnMedication[];
    clients: ClientInfo[];
    witnesses: { id: number; name: string }[];
    board_user: { name: string; role_label: string | null };
    date: string;
    sites: { id: number; name: string }[];
    active_site: { id: number; name: string } | null;
    site_brand_colour: string | null;
};

type Modal = { type: 'record' } | { type: 'effect'; followUp: PrnFollowUp } | null;

function hue(id: number): number {
    return Math.round((id * 137.508) % 360);
}
function initials(name: string): string {
    return name.split(/\s+/).filter(Boolean).slice(0, 2).map((p) => p[0]!.toUpperCase()).join('');
}
function Avatar({ id, name }: { id: number; name: string }) {
    return (
        <span className="flex h-6 w-6 items-center justify-center rounded-full text-[10px] font-bold text-primary-foreground" style={{ backgroundColor: `oklch(0.62 0.16 ${hue(id)})` }}>
            {initials(name)}
        </span>
    );
}
function effTone(eff: string | null): string {
    if (eff === 'effective') return 'bg-status-success-bg text-status-success';
    if (eff === 'partially_effective') return 'bg-status-warning-bg text-status-warning';
    if (eff === 'not_effective') return 'bg-status-critical-bg text-status-critical';
    return 'bg-status-info-bg text-status-info'; // review due
}

const STATUS_FILTERS = [
    { id: 'all', label: 'All' },
    { id: 'review_due', label: 'Review due' },
    { id: 'effective', label: 'Effective' },
    { id: 'partially_effective', label: 'Partial' },
    { id: 'not_effective', label: 'Not effective' },
];

export default function PrnRecords(props: Props) {
    const { administrations, pending_reviews: reviews, prn_medications: prnMeds, clients, witnesses, board_user: signer, date, sites, active_site: activeSite, site_brand_colour: brandColour } = props;

    const [activeTab, setActiveTab] = useState('register');
    const [search, setSearch] = useState('');
    const [statusFilter, setStatusFilter] = useState('all');
    const [siteFilter, setSiteFilter] = useState<number | null>(activeSite?.id ?? null);
    const [modal, setModal] = useState<Modal>(null);

    const clientsMap = useMemo(() => new Map(clients.map((c) => [c.id, c])), [clients]);
    const nearLimit = useMemo(() => prnMeds.filter((m) => m.near_limit || m.over_limit), [prnMeds]);
    const medCountById = useMemo(() => new Map(prnMeds.map((m) => [m.id, m])), [prnMeds]);

    const register = useMemo(() => {
        const q = search.toLowerCase();
        return administrations.filter((a) => {
            if (statusFilter === 'review_due' && a.effectiveness) return false;
            if (['effective', 'partially_effective', 'not_effective'].includes(statusFilter) && a.effectiveness !== statusFilter) return false;
            if (q && !`${a.medication_name ?? ''} ${a.client_name}`.toLowerCase().includes(q)) return false;
            return true;
        });
    }, [administrations, statusFilter, search]);

    const TABS: RosterTabItem[] = [
        { id: 'register', label: 'Register', icon: BarChart3, tone: 'primary', badge: administrations.length || undefined },
        { id: 'reviews', label: 'Reviews due', icon: Clock, tone: 'warning', badge: reviews.length || undefined },
        { id: 'near', label: 'Near limit', icon: AlertTriangle, tone: 'critical', badge: nearLimit.length || undefined },
        { id: 'trends', label: 'Trends', icon: TrendingUp, tone: 'info' },
    ];

    const heroStats: PageHeroStat[] = [
        { label: 'Given', value: administrations.filter((a) => a.status === 'given').length, sub: 'last 30d' },
        { label: 'Reviews', value: reviews.length, sub: 'due now', tone: reviews.length > 0 ? 'warning' : 'neutral' },
        { label: 'Near limit', value: nearLimit.length, tone: nearLimit.length > 0 ? 'critical' : 'neutral' },
    ];

    const trends = useMemo(() => {
        const byMed = new Map<string, number>();
        for (const a of administrations) byMed.set(a.medication_name ?? '—', (byMed.get(a.medication_name ?? '—') ?? 0) + 1);
        const ranked = [...byMed.entries()].sort((x, y) => y[1] - x[1]).slice(0, 8);
        const max = ranked[0]?.[1] ?? 1;
        const reviewed = administrations.filter((a) => a.effectiveness);
        const effPct = reviewed.length ? Math.round((reviewed.filter((a) => a.effectiveness === 'effective').length / reviewed.length) * 100) : 0;
        return { ranked, max, total: administrations.length, effPct };
    }, [administrations]);

    return (
        <AppLayout breadcrumbs={[{ title: 'eMAR', href: '/emar' }, { title: 'PRN Records', href: '/emar/prn' }]}>
            <Head title="PRN Records" />
            <div className="flex flex-col gap-6 p-6">
                <PageHero
                    variant="hero"
                    category="ops"
                    brandColour={brandColour}
                    icon={Pill}
                    title={
                        <span>
                            <span className="flex items-center gap-2 text-[10.5px] font-semibold uppercase tracking-wide text-primary-foreground/80">
                                <span aria-hidden className="relative inline-flex h-2 w-2">
                                    <span className="absolute inset-0 animate-ping rounded-full bg-status-success/70" />
                                    <span className="relative inline-flex h-2 w-2 rounded-full bg-status-success" />
                                </span>
                                As-needed medication register
                            </span>
                            <span className="mt-1 block text-[26px] font-bold leading-tight">
                                PRN records for{' '}
                                <span className="border-b-2 border-primary-foreground/40">{activeSite?.name ?? 'your services'}</span>
                            </span>
                        </span>
                    }
                    description="Record as-needed doses, track daily limits and complete effectiveness reviews — every action stays on this page."
                    stats={heroStats}
                    actions={
                        <Button className="bg-primary-foreground text-primary hover:bg-primary-foreground/90" onClick={() => setModal({ type: 'record' })}>
                            <Plus className="h-4 w-4" />
                            Record PRN dose
                        </Button>
                    }
                    footer={
                        sites.length > 0 ? (
                            <div className="flex items-center justify-end py-3">
                                <EntityFilter label="Site" allLabel="All sites" items={sites} value={siteFilter} onChange={(id) => { setSiteFilter(id); router.get('/emar/prn', id ? { site_id: id } : {}, { preserveState: true, preserveScroll: true }); }} onDark />
                            </div>
                        ) : undefined
                    }
                />

                <TabStrip value={activeTab} onChange={setActiveTab} items={TABS} ariaLabel="PRN views" />

                {activeTab === 'register' && (
                    <div className="overflow-hidden rounded-2xl border bg-card shadow-sm">
                        <div className="flex flex-wrap items-center gap-2.5 border-b p-3.5">
                            <div className="relative">
                                <Search className="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                                <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search client or medication…" className="w-64 pl-8" />
                            </div>
                            <div className="flex flex-wrap gap-1">
                                {STATUS_FILTERS.map((f) => (
                                    <Button key={f.id} size="sm" variant={statusFilter === f.id ? 'secondary' : 'ghost'} onClick={() => setStatusFilter(f.id)}>{f.label}</Button>
                                ))}
                            </div>
                            <span className="ml-auto text-xs text-muted-foreground">{register.length} of {administrations.length}</span>
                        </div>
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[840px] text-sm">
                                <thead>
                                    <tr className="bg-muted text-left text-[11px] uppercase tracking-wide text-muted-foreground">
                                        <th className="px-4 py-2.5">Date / time</th>
                                        <th className="px-4 py-2.5">Client</th>
                                        <th className="px-4 py-2.5">Medication</th>
                                        <th className="px-4 py-2.5">Indication</th>
                                        <th className="px-4 py-2.5">Today</th>
                                        <th className="px-4 py-2.5">Effectiveness</th>
                                        <th className="px-4 py-2.5">Given by</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {register.length === 0 ? (
                                        <tr><td colSpan={7} className="px-4 py-12 text-center text-muted-foreground">No PRN doses match these filters.</td></tr>
                                    ) : register.map((a) => {
                                        const med = medCountById.get(a.client_medication_id);
                                        return (
                                            <tr key={a.id} className="border-b last:border-b-0">
                                                <td className="px-4 py-3"><div className="font-medium">{a.given_time}</div><div className="text-xs text-muted-foreground">{a.given_date}</div></td>
                                                <td className="px-4 py-3"><span className="flex items-center gap-2"><Avatar id={a.client_id} name={a.client_name} />{a.client_name}</span></td>
                                                <td className="px-4 py-3"><div className="flex items-center gap-1.5 font-medium">{a.medication_name}{a.controlled_drug && <span className="rounded bg-status-critical-bg px-1 py-0.5 text-[9px] font-bold text-status-critical">CD</span>}</div>{a.dose_given && <div className="text-xs text-muted-foreground">{a.dose_given}</div>}</td>
                                                <td className="px-4 py-3 text-muted-foreground">{a.reason ?? a.indication ?? '—'}</td>
                                                <td className="px-4 py-3">{med && med.max_per_day ? <span className={`rounded-full px-2 py-0.5 text-[11px] font-semibold ${med.over_limit ? 'bg-status-critical-bg text-status-critical' : med.near_limit ? 'bg-status-warning-bg text-status-warning' : 'bg-muted text-muted-foreground'}`}>{med.given_last_24h} of {med.max_per_day}</span> : <span className="text-muted-foreground">—</span>}</td>
                                                <td className="px-4 py-3"><span className={`rounded-full px-2 py-0.5 text-[11px] font-semibold ${effTone(a.effectiveness)}`}>{a.effectiveness_label ?? 'Review due'}</span></td>
                                                <td className="px-4 py-3 text-muted-foreground">{a.given_by ?? '—'}</td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                    </div>
                )}

                {activeTab === 'reviews' && (
                    <div className="overflow-hidden rounded-2xl border bg-card shadow-sm">
                        <div className="flex items-center gap-2 border-b bg-status-warning-bg/40 px-5 py-3 text-sm font-medium text-status-warning"><Clock className="h-4 w-4" />Effectiveness reviews due ({reviews.length})</div>
                        {reviews.length === 0 ? <div className="px-5 py-10 text-center text-sm text-muted-foreground">No effectiveness reviews due.</div> : (
                            <ul className="divide-y">
                                {reviews.map((r) => (
                                    <li key={r.administration_id} className="flex items-center justify-between px-5 py-3">
                                        <span className="flex items-center gap-2 text-sm"><Avatar id={r.client_id} name={clientsMap.get(r.client_id)?.name ?? '?'} /><span className="font-medium">{clientsMap.get(r.client_id)?.name ?? 'Resident'}</span> · {r.medication_name} <span className="text-muted-foreground">given {r.given_time}</span></span>
                                        <Button size="sm" onClick={() => setModal({ type: 'effect', followUp: r })}>Record effectiveness</Button>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                )}

                {activeTab === 'near' && (
                    nearLimit.length === 0 ? <div className="rounded-2xl border bg-card px-5 py-12 text-center text-sm text-muted-foreground">No PRN medications approaching their daily limit.</div> : (
                        <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
                            {nearLimit.map((m) => {
                                const pct = m.max_per_day ? Math.min(100, Math.round((m.given_last_24h / m.max_per_day) * 100)) : 0;
                                return (
                                    <div key={m.id} className="flex flex-col gap-2 rounded-2xl border bg-card p-4 shadow-sm">
                                        <div className="flex items-center justify-between">
                                            <span className="font-semibold">{m.name}</span>
                                            <span className={`rounded-full px-2 py-0.5 text-[11px] font-semibold ${m.over_limit ? 'bg-status-critical-bg text-status-critical' : 'bg-status-warning-bg text-status-warning'}`}>{m.over_limit ? 'At limit' : `${m.remaining_today ?? 0} left`}</span>
                                        </div>
                                        <div className="text-xs text-muted-foreground">{m.client_name}</div>
                                        <div className="text-xs">{m.given_last_24h} of {m.max_per_day} doses · {m.remaining_today ?? 0} remaining</div>
                                        <div className="h-1.5 overflow-hidden rounded-full bg-muted"><div className={`h-full rounded-full ${m.over_limit ? 'bg-status-critical' : 'bg-status-warning'}`} style={{ width: `${pct}%` }} /></div>
                                        <div className="text-[11px] text-muted-foreground">{m.last_given_label ? `Last given ${m.last_given_label}` : 'None today'}{m.min_hours_between ? ` · min ${m.min_hours_between}h between` : ''}</div>
                                    </div>
                                );
                            })}
                        </div>
                    )
                )}

                {activeTab === 'trends' && (
                    <div className="grid grid-cols-1 gap-4 lg:grid-cols-[2fr_1fr]">
                        <div className="rounded-2xl border bg-card p-5 shadow-sm">
                            <div className="mb-3 text-[15px] font-bold">Most-used PRN medications</div>
                            {trends.ranked.length === 0 ? <p className="text-sm text-muted-foreground">No PRN doses recorded.</p> : (
                                <ul className="flex flex-col gap-2.5">
                                    {trends.ranked.map(([name, count]) => (
                                        <li key={name} className="flex items-center gap-3 text-sm">
                                            <span className="w-40 shrink-0 truncate">{name}</span>
                                            <span className="h-2.5 flex-1 overflow-hidden rounded-full bg-muted"><span className="block h-full rounded-full bg-primary" style={{ width: `${(count / trends.max) * 100}%` }} /></span>
                                            <span className="w-8 text-right text-muted-foreground">{count}</span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </div>
                        <div className="flex flex-col gap-4">
                            <div className="rounded-2xl border bg-card p-4 shadow-sm"><div className="text-xs uppercase tracking-wide text-muted-foreground">Total PRN doses</div><div className="text-2xl font-bold">{trends.total}</div></div>
                            <div className="rounded-2xl border bg-card p-4 shadow-sm"><div className="text-xs uppercase tracking-wide text-muted-foreground">Reviewed effective</div><div className="text-2xl font-bold text-status-success">{trends.effPct}%</div></div>
                            <div className="rounded-2xl border bg-card p-4 shadow-sm"><div className="text-xs uppercase tracking-wide text-muted-foreground">Near limit now</div><div className="text-2xl font-bold text-status-critical">{nearLimit.length}</div></div>
                        </div>
                    </div>
                )}
            </div>

            {modal?.type === 'record' && (
                <PrnWizard medications={prnMeds} clients={clientsMap} date={date} witnesses={witnesses} signedAs={{ name: signer.name, role_label: signer.role_label }} onClose={() => setModal(null)} />
            )}
            {modal?.type === 'effect' && (
                <PrnEffectDialog followUp={modal.followUp} client={clientsMap.get(modal.followUp.client_id)} onClose={() => setModal(null)} />
            )}
        </AppLayout>
    );
}
