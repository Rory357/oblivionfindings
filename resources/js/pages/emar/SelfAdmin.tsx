/* eslint-disable no-restricted-syntax -- register tables, reassess/agreement/per-med cards, the
   activity feed and hero search are custom-layout bordered surfaces / chip buttons (not Card/Button);
   all colours are semantic tokens. */
import { PageHero, type PageHeroStat } from '@/components/page';
import { EntityFilter, TabStrip, type RosterTabItem } from '@/components/rostering';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import {
    AssessmentWizardDialog,
    categoryMeta,
    MedScopeDialog,
    scopeMeta,
    SignAgreementDialog,
    ViewSelfAdminDialog,
    type ClientOpt,
    type SelfAdminRow,
} from '@/pages/emar/_self-admin-dialogs';
import { Head, router } from '@inertiajs/react';
import { AlarmClock, ClipboardCheck, Eye, FileSignature, FileText, History, ListChecks, Pill, Plus, RotateCcw, Search } from 'lucide-react';
import { useMemo, useState } from 'react';

type ActivityItem = { actor: string; text: string; subject: string; at: string | null; icon: string };
type Kpis = { self_managing: number; supervised: number; administered: number; due_now: number; independent: number; independent_pct: number; unsigned: number; total: number };

type Props = {
    assessments: SelfAdminRow[];
    activity: ActivityItem[];
    kpis: Kpis;
    clients: ClientOpt[];
    staff: { id: number; name: string }[];
    sites: { id: number; name: string }[];
    active_site: { id: number; name: string } | null;
    site_brand_colour: string | null;
};

type Modal =
    | { type: 'new' }
    | { type: 'reassess'; assessment: SelfAdminRow }
    | { type: 'view'; assessment: SelfAdminRow }
    | { type: 'agreement'; assessment: SelfAdminRow }
    | { type: 'scope'; assessment: SelfAdminRow }
    | null;

const initials = (n: string) => n.split(' ').filter(Boolean).slice(0, 2).map((p) => p[0]).join('').toUpperCase() || '?';
const fmtDate = (iso: string | null) => (iso ? new Date(iso).toLocaleDateString('en-NZ', { day: 'numeric', month: 'short', year: 'numeric' }) : '—');
const relative = (iso: string | null) => { if (!iso) return ''; const d = Math.floor((Date.now() - new Date(iso).getTime()) / 86400000); return d <= 0 ? 'today' : d === 1 ? 'yesterday' : `${d}d ago`; };

export default function SelfAdmin({ assessments, activity, kpis, clients, sites, active_site: activeSite, site_brand_colour: brandColour }: Props) {
    const [activeTab, setActiveTab] = useState('assessments');
    const [search, setSearch] = useState('');
    const [siteFilter, setSiteFilter] = useState<number | null>(activeSite?.id ?? null);
    const [modal, setModal] = useState<Modal>(null);

    const visible = useMemo(() => {
        const q = search.trim().toLowerCase();
        return assessments.filter((a) => !q || `${a.client_name} ${a.nhi ?? ''} ${a.assessor_name ?? ''}`.toLowerCase().includes(q));
    }, [assessments, search]);

    const dueList = visible.filter((a) => a.reassessment_due);
    const agreements = visible.filter((a) => a.outcome === 'independent' || a.outcome === 'prompted');
    const selfManaging = visible.filter((a) => a.outcome !== 'administered' && a.client_medications.length > 0);

    const onSite = (id: number | null) => { setSiteFilter(id); router.get('/emar/self-admin', id ? { site_id: id } : {}, { preserveState: true, preserveScroll: true }); };

    const TABS: RosterTabItem[] = [
        { id: 'assessments', label: 'Assessments', icon: ListChecks, tone: 'primary', badge: assessments.length || undefined },
        { id: 'reassess', label: 'Reassessments due', icon: AlarmClock, tone: 'warning', badge: dueList.length || undefined },
        { id: 'agreements', label: 'Agreements', icon: FileSignature, tone: 'info', badge: kpis.unsigned || undefined },
        { id: 'permed', label: 'Per-medication scope', icon: Pill, tone: 'primary' },
        { id: 'activity', label: 'Activity', icon: History, tone: 'primary' },
    ];

    const heroStats: PageHeroStat[] = [
        { label: 'Self-managing', value: kpis.self_managing },
        { label: 'Supervised', value: kpis.supervised },
        { label: 'Due now', value: kpis.due_now, tone: kpis.due_now > 0 ? 'critical' : 'neutral' },
        { label: 'Independent', value: `${kpis.independent_pct}%` },
    ];

    return (
        <AppLayout breadcrumbs={[{ title: 'eMAR', href: '/emar' }, { title: 'Self-Administration', href: '/emar/self-admin' }]}>
            <Head title="eMAR - Self-Administration" />
            <div className="flex flex-col gap-6 p-6">
                <PageHero
                    variant="hero"
                    category="ops"
                    brandColour={brandColour}
                    icon={ClipboardCheck}
                    title={
                        <span>
                            <span className="flex items-center gap-2 text-[10.5px] font-semibold uppercase tracking-wide text-primary-foreground/80">
                                <span aria-hidden className="relative inline-flex h-2 w-2">
                                    <span className="absolute inset-0 animate-ping rounded-full bg-status-success/70" />
                                    <span className="relative inline-flex h-2 w-2 rounded-full bg-status-success" />
                                </span>
                                Self-administration oversight · live
                            </span>
                            <span className="mt-1 block text-[26px] font-bold leading-tight">
                                Self-administration across{' '}
                                <span className="border-b-2 border-primary-foreground/40">{activeSite?.name ?? `${kpis.total} clients`}</span>
                            </span>
                        </span>
                    }
                    description="Independence first; staff step in only where the risk assessment says so. Consent-first, NZ MOH medicines-management categories."
                    stats={heroStats}
                    actions={
                        <Button className="bg-primary-foreground text-primary hover:bg-primary-foreground/90" onClick={() => setModal({ type: 'new' })}>
                            <Plus className="h-4 w-4" />
                            New assessment
                        </Button>
                    }
                    footer={
                        <div className="flex flex-col gap-3 py-3 lg:flex-row lg:items-center lg:justify-between">
                            <div className="flex items-center gap-2 rounded-full bg-primary-foreground px-3 py-1.5">
                                <Search className="h-3.5 w-3.5 text-muted-foreground" />
                                <input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search client or NHI…" className="w-56 bg-transparent text-sm text-foreground outline-none placeholder:text-muted-foreground" />
                            </div>
                            {sites.length > 0 && <EntityFilter label="Site" allLabel="All sites" items={sites} value={siteFilter} onChange={onSite} onDark />}
                        </div>
                    }
                />

                {kpis.due_now > 0 && (
                    <div className="flex items-center justify-between gap-3 rounded-xl border border-status-warning/30 bg-status-warning-bg/60 px-4 py-3">
                        <span className="text-sm font-medium text-status-warning">{kpis.due_now} self-administration assessment{kpis.due_now === 1 ? '' : 's'} due for reassessment.</span>
                        <Button size="sm" variant="outline" onClick={() => setActiveTab('reassess')}>Review</Button>
                    </div>
                )}

                <TabStrip value={activeTab} onChange={setActiveTab} items={TABS} ariaLabel="Self-administration views" />

                {activeTab === 'assessments' && (
                    <div className="overflow-hidden rounded-2xl border bg-card shadow-sm">
                        {visible.length === 0 ? <div className="px-5 py-12 text-center text-sm text-muted-foreground">No assessments match the current filters.</div> : (
                            <div className="overflow-x-auto">
                                <table className="w-full min-w-[920px] text-sm">
                                    <thead>
                                        <tr className="bg-muted/50 text-left text-[11px] uppercase tracking-wide text-muted-foreground">
                                            <th className="px-4 py-2.5">Client</th><th className="px-4 py-2.5">Category</th><th className="px-4 py-2.5">Capacity</th><th className="px-4 py-2.5">Capability</th><th className="px-4 py-2.5">Consent</th><th className="px-4 py-2.5">Reassessment</th><th className="px-4 py-2.5">Assessor</th><th className="px-4 py-2.5 text-right">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {visible.map((a) => {
                                            const cat = categoryMeta(a.outcome);
                                            const capPct = (a.total_score / 25) * 100;
                                            const capCount = [a.can_identify_medications, a.can_read_labels, a.can_open_packaging, a.can_manage_timing, a.can_store_safely, a.willing_to_self_admin].filter(Boolean).length;
                                            return (
                                                <tr key={a.id} className="cursor-pointer border-b last:border-b-0 hover:bg-muted/30" onClick={() => setModal({ type: 'view', assessment: a })}>
                                                    <td className="px-4 py-3"><div className="flex items-center gap-3"><span className="flex h-9 w-9 items-center justify-center rounded-full bg-primary/10 text-xs font-bold text-primary">{initials(a.client_name)}</span><div><div className="font-medium">{a.client_name}</div><div className="text-xs text-muted-foreground">{a.nhi ? `NHI ${a.nhi} · ` : ''}{a.site_name ?? ''}</div></div></div></td>
                                                    <td className="px-4 py-3"><span className={`rounded-full px-2 py-0.5 text-[11px] font-semibold ${cat.cls}`}>{cat.label}</span></td>
                                                    <td className="px-4 py-3"><div className="flex items-center gap-2"><div className="h-1.5 w-16 overflow-hidden rounded-full bg-muted"><div className={`h-full rounded-full ${capPct >= 80 ? 'bg-status-success' : capPct >= 55 ? 'bg-status-warning' : 'bg-status-critical'}`} style={{ width: `${capPct}%` }} /></div><span className="tabular-nums text-xs">{a.total_score}/25</span></div></td>
                                                    <td className="px-4 py-3"><span className={`text-xs font-semibold ${capCount >= 5 ? 'text-status-success' : capCount >= 3 ? 'text-status-warning' : 'text-status-critical'}`}>{capCount}/6</span></td>
                                                    <td className="px-4 py-3 text-xs">{a.wishes_to_self_administer ? <span className="text-status-success">Wishes to self-admin</span> : <span className="text-muted-foreground">Declined</span>}</td>
                                                    <td className="px-4 py-3 text-muted-foreground">{fmtDate(a.reassessment_date)}{a.reassessment_due && <span className="ml-1 rounded-full bg-status-critical-bg px-1.5 py-0.5 text-[10px] font-semibold text-status-critical">Due</span>}</td>
                                                    <td className="px-4 py-3 text-muted-foreground">{a.assessor_name ?? '—'}</td>
                                                    <td className="px-4 py-3" onClick={(e) => e.stopPropagation()}>
                                                        <div className="flex items-center justify-end gap-1">
                                                            <Button size="sm" variant="ghost" onClick={() => setModal({ type: 'view', assessment: a })} title="View"><Eye className="h-3.5 w-3.5" /></Button>
                                                            <Button size="sm" variant="ghost" onClick={() => setModal({ type: 'reassess', assessment: a })} title="Reassess"><RotateCcw className="h-3.5 w-3.5" /></Button>
                                                        </div>
                                                    </td>
                                                </tr>
                                            );
                                        })}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </div>
                )}

                {activeTab === 'reassess' && (
                    dueList.length === 0 ? <Empty text="No reassessments due." /> : (
                        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                            {dueList.map((a) => {
                                const cat = categoryMeta(a.outcome);
                                return (
                                    <div key={a.id} className="rounded-2xl border-l-4 border-l-status-critical border bg-card p-4 shadow-sm">
                                        <div className="flex items-center gap-3"><span className="flex h-9 w-9 items-center justify-center rounded-full bg-primary/10 text-xs font-bold text-primary">{initials(a.client_name)}</span><div><div className="font-medium">{a.client_name}</div><span className={`rounded-full px-2 py-0.5 text-[10px] font-semibold ${cat.cls}`}>{cat.label}</span></div></div>
                                        <div className="mt-3 text-xs text-muted-foreground">Reassessment was due {fmtDate(a.reassessment_date)}.{a.reassessment_trigger ? ` Trigger: ${a.reassessment_trigger}.` : ''}</div>
                                        <div className="mt-3 flex gap-2"><Button size="sm" onClick={() => setModal({ type: 'reassess', assessment: a })}>Start reassessment</Button><Button size="sm" variant="outline" onClick={() => setModal({ type: 'view', assessment: a })}>View</Button></div>
                                    </div>
                                );
                            })}
                        </div>
                    )
                )}

                {activeTab === 'agreements' && (
                    agreements.length === 0 ? <Empty text="No self-managing clients yet." /> : (
                        <div className="overflow-hidden rounded-2xl border bg-card shadow-sm">
                            {agreements.map((a) => {
                                const signed = !!a.agreement_signed_at;
                                return (
                                    <div key={a.id} className="flex items-center justify-between gap-3 border-b px-4 py-3 last:border-b-0">
                                        <div className="flex items-center gap-3"><span className="flex h-9 w-9 items-center justify-center rounded-full bg-primary/10 text-xs font-bold text-primary">{initials(a.client_name)}</span><div><div className="text-sm font-medium">{a.client_name}</div><div className="text-xs text-muted-foreground">{categoryMeta(a.outcome).label}{a.ordering_responsibility ? ` · ordering: ${a.ordering_responsibility}` : ''}</div></div></div>
                                        <div className="flex items-center gap-2">
                                            {signed ? <span className="rounded-full bg-status-success-bg px-2 py-0.5 text-[11px] font-semibold text-status-success">Signed</span> : <span className="rounded-full bg-status-critical-bg px-2 py-0.5 text-[11px] font-semibold text-status-critical">Awaiting signature</span>}
                                            {signed ? <Button size="sm" variant="outline" onClick={() => setModal({ type: 'view', assessment: a })}>View</Button> : <Button size="sm" onClick={() => setModal({ type: 'agreement', assessment: a })}>Sign agreement</Button>}
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    )
                )}

                {activeTab === 'permed' && (
                    selfManaging.length === 0 ? <Empty text="No self-managing clients with active medications." /> : (
                        <div className="flex flex-col gap-4">
                            {selfManaging.map((a) => (
                                <div key={a.id} className="overflow-hidden rounded-2xl border bg-card shadow-sm">
                                    <div className="flex items-center justify-between gap-3 border-b bg-muted/40 px-4 py-3">
                                        <div className="flex items-center gap-3"><span className="flex h-9 w-9 items-center justify-center rounded-full bg-primary/10 text-xs font-bold text-primary">{initials(a.client_name)}</span><div><div className="text-sm font-semibold">{a.client_name}</div><span className={`rounded-full px-2 py-0.5 text-[10px] font-semibold ${categoryMeta(a.outcome).cls}`}>{categoryMeta(a.outcome).label}</span></div></div>
                                        <Button size="sm" variant="outline" onClick={() => setModal({ type: 'scope', assessment: a })}><Pill className="h-3.5 w-3.5" />Set scope</Button>
                                    </div>
                                    <div className="flex flex-col">
                                        {a.client_medications.map((m) => { const sm = scopeMeta(m.scope); return (
                                            <div key={m.id} className="flex items-center justify-between gap-3 border-b px-4 py-2.5 last:border-b-0">
                                                <div className="flex items-center gap-2"><Pill className="h-4 w-4 text-muted-foreground" /><span className="text-sm font-medium">{m.name}</span>{m.controlled && <span className="rounded-full bg-status-critical-bg px-1.5 py-0.5 text-[10px] font-semibold text-status-critical">CD</span>}{m.dosage && <span className="text-xs text-muted-foreground">{m.dosage}</span>}</div>
                                                <span className={`rounded-full px-2 py-0.5 text-[11px] font-semibold ${sm.cls}`}>{sm.label}</span>
                                            </div>
                                        ); })}
                                    </div>
                                </div>
                            ))}
                        </div>
                    )
                )}

                {activeTab === 'activity' && (
                    <div className="overflow-hidden rounded-2xl border bg-card shadow-sm">
                        <div className="border-b bg-muted/40 px-4 py-3 text-xs text-muted-foreground">Entries are amendable but never deleted.</div>
                        {activity.length === 0 ? <Empty text="No activity yet." /> : (
                            <div className="flex flex-col">
                                {activity.map((e, i) => (
                                    <div key={i} className="flex items-center gap-3 border-b px-4 py-3 last:border-b-0">
                                        <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-accent text-primary">{e.icon === 'file' ? <FileText className="h-4 w-4" /> : <ClipboardCheck className="h-4 w-4" />}</span>
                                        <div className="flex-1 text-sm"><span className="font-medium">{e.actor}</span> {e.text} <span className="font-medium">{e.subject}</span></div>
                                        <span className="text-xs text-muted-foreground">{relative(e.at)}</span>
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>
                )}
            </div>

            {modal?.type === 'new' && <AssessmentWizardDialog clients={clients} mode="new" onClose={() => setModal(null)} />}
            {modal?.type === 'reassess' && <AssessmentWizardDialog clients={clients} mode="reassess" assessment={modal.assessment} onClose={() => setModal(null)} />}
            {modal?.type === 'view' && <ViewSelfAdminDialog assessment={modal.assessment} onClose={() => setModal(null)} />}
            {modal?.type === 'agreement' && <SignAgreementDialog assessment={modal.assessment} onClose={() => setModal(null)} />}
            {modal?.type === 'scope' && <MedScopeDialog assessment={modal.assessment} onClose={() => setModal(null)} />}
        </AppLayout>
    );
}

function Empty({ text }: { text: string }) {
    return <div className="rounded-2xl border border-dashed bg-card px-5 py-12 text-center text-sm text-muted-foreground">{text}</div>;
}
