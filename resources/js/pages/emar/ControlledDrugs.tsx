/* eslint-disable no-restricted-syntax -- CD register tab tables/cards are custom-layout
   bordered surfaces (not Card/Button); all colours are semantic tokens. */
import { statusTone, type CdDiscrepancy, type CdEntry, type CdLossReport, type CdMedication, type ClientOption, type StaffOption } from '@/components/emar/controlled/types';
import { PageHero, type PageHeroStat } from '@/components/page';
import { EntityFilter, TabStrip, type RosterTabItem } from '@/components/rostering';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { BalanceCheckDialog, CdPill, LossActionDialog, RecordCdEntryDialog, RecordDestructionDialog, ReportLossDialog, ResolveDiscrepancyDialog } from '@/pages/emar/_cd-dialogs';
import { Head, router } from '@inertiajs/react';
import { Activity, AlertTriangle, ClipboardCheck, FileWarning, Lock, Package, Plus, ShieldCheck, Trash2, TrendingUp } from 'lucide-react';
import { useMemo, useState } from 'react';

type CdDestructionRow = { id: number; client_name: string; medication_name: string | null; quantity: number | string | null; unit: string | null; reason: string | null; destroyed_at: string | null; destroyed_by_name: string | null; witness_name: string | null };

type Props = {
    medications: CdMedication[];
    recentEntries: CdEntry[];
    discrepancies: CdDiscrepancy[];
    destructions: CdDestructionRow[];
    lossReports: CdLossReport[];
    staff: StaffOption[];
    clients: ClientOption[];
    sites: { id: number; name: string }[];
    active_site: { id: number; name: string } | null;
    site_brand_colour: string | null;
};

type Modal =
    | { type: 'entry' | 'balance' | 'loss' | 'destruction' }
    | { type: 'balanceMed'; medId: number }
    | { type: 'resolveDisc'; disc: CdDiscrepancy }
    | { type: 'lossAction'; report: CdLossReport; action: 'investigate' | 'resolve' }
    | null;

function daysSince(iso: string | null): number | null {
    if (!iso) return null;
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return null;
    return Math.floor((Date.now() - d.getTime()) / (1000 * 60 * 60 * 24));
}

export default function ControlledDrugs(props: Props) {
    const { medications, recentEntries, discrepancies, destructions, lossReports, staff, sites, active_site: activeSite, site_brand_colour: brandColour } = props;

    const [activeTab, setActiveTab] = useState('register');
    const [siteFilter, setSiteFilter] = useState<number | null>(activeSite?.id ?? null);
    const [modal, setModal] = useState<Modal>(null);

    // Last balance check per medication (from the append-only register).
    const reconciliation = useMemo(() => {
        return medications.map((m) => {
            const last = recentEntries.find((e) => e.entry_type === 'balance_check' && e.medication_name === m.name && e.client_id === m.client_id);
            const days = daysSince(last?.recorded_at ?? null);
            return { med: m, lastAt: last?.recorded_at ?? null, days, overdue: days === null || days >= 7 };
        });
    }, [medications, recentEntries]);

    const openLosses = lossReports.filter((l) => ['reported', 'investigating'].includes(l.investigation_status));
    const overdueChecks = reconciliation.filter((r) => r.overdue).length;

    const TABS: RosterTabItem[] = [
        { id: 'register', label: 'Register', icon: Lock, tone: 'primary', badge: medications.length || undefined },
        { id: 'recent', label: 'Recent Entries', icon: Package, tone: 'info', badge: recentEntries.length || undefined },
        { id: 'reconciliation', label: 'Reconciliation', icon: ShieldCheck, tone: 'success', badge: overdueChecks || undefined },
        { id: 'discrepancies', label: 'Discrepancies', icon: AlertTriangle, tone: 'critical', badge: discrepancies.length || undefined },
        { id: 'destructions', label: 'Destructions', icon: Trash2, tone: 'warning', badge: destructions.length || undefined },
        { id: 'loss', label: 'Loss Reports', icon: FileWarning, tone: 'critical', badge: openLosses.length || undefined },
        { id: 'audit', label: 'Audit Trail', icon: Activity, tone: 'primary' },
    ];

    const heroStats: PageHeroStat[] = [
        { label: 'Active CDs', value: medications.length },
        { label: 'Open discrepancies', value: discrepancies.length, tone: discrepancies.length > 0 ? 'critical' : 'neutral' },
        { label: 'Overdue checks', value: overdueChecks, tone: overdueChecks > 0 ? 'warning' : 'neutral' },
        { label: 'Loss investigations', value: openLosses.length, tone: openLosses.length > 0 ? 'critical' : 'neutral' },
    ];

    return (
        <AppLayout breadcrumbs={[{ title: 'eMAR', href: '/emar' }, { title: 'Controlled Drugs', href: '/emar/controlled' }]}>
            <Head title="Controlled Drug Register" />
            <div className="flex flex-col gap-6 p-6">
                <PageHero
                    variant="hero"
                    category="ops"
                    brandColour={brandColour}
                    icon={Lock}
                    title={
                        <span>
                            <span className="flex items-center gap-2 text-[10.5px] font-semibold uppercase tracking-wide text-primary-foreground/80">
                                <span aria-hidden className="relative inline-flex h-2 w-2">
                                    <span className="absolute inset-0 animate-ping rounded-full bg-status-success/70" />
                                    <span className="relative inline-flex h-2 w-2 rounded-full bg-status-success" />
                                </span>
                                Controlled drug register · synced
                            </span>
                            <span className="mt-1 block text-[26px] font-bold leading-tight">
                                CD register for{' '}
                                <span className="border-b-2 border-primary-foreground/40">{activeSite?.name ?? 'your services'}</span>
                            </span>
                        </span>
                    }
                    description="Running balances, two-person witness, reconciliation, discrepancies, destructions and loss investigations — append-only and audit-ready."
                    stats={heroStats}
                    actions={
                        <>
                            <Button className="bg-primary-foreground text-primary hover:bg-primary-foreground/90" onClick={() => setModal({ type: 'entry' })}>
                                <Plus className="h-4 w-4" />
                                Record CD entry
                            </Button>
                            <Button variant="outline" className="border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground hover:bg-primary-foreground/20" onClick={() => setModal({ type: 'balance' })}>
                                <ClipboardCheck className="h-4 w-4" />
                                Balance check
                            </Button>
                            <Button variant="outline" className="border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground hover:bg-primary-foreground/20" onClick={() => setModal({ type: 'loss' })}>
                                <FileWarning className="h-4 w-4" />
                                Report loss
                            </Button>
                        </>
                    }
                    footer={
                        sites.length > 0 ? (
                            <div className="flex items-center justify-end py-3">
                                <EntityFilter label="Site" allLabel="All sites" items={sites} value={siteFilter} onChange={(id) => { setSiteFilter(id); router.get('/emar/controlled', id ? { site_id: id } : {}, { preserveState: true, preserveScroll: true }); }} onDark />
                            </div>
                        ) : undefined
                    }
                />

                {discrepancies.length > 0 && (
                    <div className="flex items-center justify-between gap-3 rounded-xl border border-status-critical/30 bg-status-critical-bg/60 px-4 py-3">
                        <span className="flex items-center gap-2 text-sm font-medium text-status-critical">
                            <AlertTriangle className="h-4 w-4" />
                            {discrepancies.length} open controlled-drug discrepanc{discrepancies.length === 1 ? 'y' : 'ies'} — investigate and resolve.
                        </span>
                        <Button size="sm" variant="outline" onClick={() => setActiveTab('discrepancies')}>Review</Button>
                    </div>
                )}

                <TabStrip value={activeTab} onChange={setActiveTab} items={TABS} ariaLabel="Controlled drug views" />

                {activeTab === 'register' && (
                    <TableCard head={['Client', 'Medication', 'On hand', 'Last checked', '']} empty={medications.length === 0 ? 'No active controlled drugs.' : null}>
                        {medications.map((m) => {
                            const rec = reconciliation.find((r) => r.med.id === m.id);
                            return (
                                <tr key={m.id} className="border-b last:border-b-0">
                                    <td className="px-4 py-3">{m.client_name}</td>
                                    <td className="px-4 py-3 font-medium">{m.name}</td>
                                    <td className="px-4 py-3 tabular-nums">{m.stock ? `${m.stock.on_hand ?? '—'} ${m.stock.unit ?? ''}` : '—'}</td>
                                    <td className="px-4 py-3">{rec?.overdue ? <span className="text-status-warning">{rec.days === null ? 'Never' : `${rec.days}d ago`}</span> : <span className="text-muted-foreground">{rec?.days}d ago</span>}</td>
                                    <td className="px-4 py-3 text-right"><Button size="sm" variant="outline" onClick={() => setModal({ type: 'balanceMed', medId: m.id })}><ClipboardCheck className="h-3.5 w-3.5" />Check balance</Button></td>
                                </tr>
                            );
                        })}
                    </TableCard>
                )}

                {activeTab === 'recent' && (
                    <TableCard head={['Date', 'Client', 'Medication', 'Type', 'Qty', 'Balance', 'Recorded by', 'Witness']} empty={recentEntries.length === 0 ? 'No register entries yet.' : null}>
                        {recentEntries.map((e) => (
                            <tr key={e.id} className="border-b last:border-b-0">
                                <td className="px-4 py-3 text-muted-foreground">{e.recorded_at ? new Date(e.recorded_at).toLocaleString('en-NZ', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : '—'}</td>
                                <td className="px-4 py-3">{e.client_name}</td>
                                <td className="px-4 py-3 font-medium">{e.medication_name}</td>
                                <td className="px-4 py-3 capitalize text-muted-foreground">{e.entry_type.replace('_', ' ')}</td>
                                <td className="px-4 py-3 tabular-nums">{e.quantity} {e.unit}</td>
                                <td className="px-4 py-3 tabular-nums text-muted-foreground">{e.on_hand_before ?? '—'} → {e.on_hand_after ?? '—'}</td>
                                <td className="px-4 py-3 text-muted-foreground">{e.recorded_by_name ?? '—'}</td>
                                <td className="px-4 py-3 text-muted-foreground">{e.witnessed_by_name ?? '—'}</td>
                            </tr>
                        ))}
                    </TableCard>
                )}

                {activeTab === 'reconciliation' && (
                    <TableCard head={['Client', 'Medication', 'On hand', 'Last balance check', 'Status']} empty={reconciliation.length === 0 ? 'No controlled drugs to reconcile.' : null}>
                        {reconciliation.map((r) => (
                            <tr key={r.med.id} className="border-b last:border-b-0">
                                <td className="px-4 py-3">{r.med.client_name}</td>
                                <td className="px-4 py-3 font-medium">{r.med.name}</td>
                                <td className="px-4 py-3 tabular-nums">{r.med.stock ? `${r.med.stock.on_hand ?? '—'} ${r.med.stock.unit ?? ''}` : '—'}</td>
                                <td className="px-4 py-3 text-muted-foreground">{r.lastAt ? new Date(r.lastAt).toLocaleDateString('en-NZ') : 'Never'}</td>
                                <td className="px-4 py-3">{r.overdue ? <CdPill label="Overdue" tone="bg-status-warning-bg text-status-warning" /> : <CdPill label="Current" tone="bg-status-success-bg text-status-success" />}</td>
                            </tr>
                        ))}
                    </TableCard>
                )}

                {activeTab === 'discrepancies' && (
                    discrepancies.length === 0 ? <EmptyCard text="No open discrepancies." /> : (
                        <div className="flex flex-col gap-3">
                            {discrepancies.map((d) => (
                                <div key={d.id} className="flex flex-wrap items-center justify-between gap-3 rounded-2xl border bg-card p-4 shadow-sm">
                                    <div>
                                        <div className="font-medium">{d.medication?.name ?? 'CD'} <span className="text-sm text-muted-foreground">· {d.client ? `${d.client.first_name} ${d.client.last_name}` : ''}</span></div>
                                        <div className="text-xs text-muted-foreground">Difference {d.difference} · {d.reason} · reported {d.reported_at ? new Date(d.reported_at).toLocaleDateString('en-NZ') : ''}</div>
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <CdPill label={d.status} tone={statusTone(d.status)} />
                                        <Button size="sm" onClick={() => setModal({ type: 'resolveDisc', disc: d })}>Resolve</Button>
                                    </div>
                                </div>
                            ))}
                        </div>
                    )
                )}

                {activeTab === 'destructions' && (
                    <div className="flex flex-col gap-3">
                        <div className="flex justify-end">
                            <Button size="sm" onClick={() => setModal({ type: 'destruction' })}><Trash2 className="h-4 w-4" />Record destruction</Button>
                        </div>
                        <TableCard head={['Date', 'Client', 'Medication', 'Qty', 'Reason', 'Destroyed by', 'Witness']} empty={destructions.length === 0 ? 'No CD destructions recorded.' : null}>
                            {destructions.map((d) => (
                                <tr key={d.id} className="border-b last:border-b-0">
                                    <td className="px-4 py-3 text-muted-foreground">{d.destroyed_at ? new Date(d.destroyed_at).toLocaleDateString('en-NZ') : '—'}</td>
                                    <td className="px-4 py-3">{d.client_name}</td>
                                    <td className="px-4 py-3 font-medium">{d.medication_name}</td>
                                    <td className="px-4 py-3 tabular-nums">{d.quantity} {d.unit}</td>
                                    <td className="px-4 py-3 text-muted-foreground">{d.reason}</td>
                                    <td className="px-4 py-3 text-muted-foreground">{d.destroyed_by_name ?? '—'}</td>
                                    <td className="px-4 py-3 text-muted-foreground">{d.witness_name ?? '—'}</td>
                                </tr>
                            ))}
                        </TableCard>
                    </div>
                )}

                {activeTab === 'loss' && (
                    lossReports.length === 0 ? <EmptyCard text="No loss reports." /> : (
                        <div className="flex flex-col gap-3">
                            {lossReports.map((l) => (
                                <div key={l.id} className="flex flex-wrap items-center justify-between gap-3 rounded-2xl border bg-card p-4 shadow-sm">
                                    <div>
                                        <div className="font-medium">{l.medication_name} <span className="text-sm text-muted-foreground">· {l.quantity_lost} {l.unit} lost</span></div>
                                        <div className="text-xs text-muted-foreground">{l.circumstances}{l.reported_to_police ? ` · Police ${l.police_reference ?? 'ref'}` : ''}</div>
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <CdPill label={l.investigation_status} tone={statusTone(l.investigation_status)} />
                                        {l.investigation_status === 'reported' && <Button size="sm" variant="outline" onClick={() => setModal({ type: 'lossAction', report: l, action: 'investigate' })}>Investigate</Button>}
                                        {l.investigation_status !== 'resolved' && <Button size="sm" onClick={() => setModal({ type: 'lossAction', report: l, action: 'resolve' })}>Resolve</Button>}
                                    </div>
                                </div>
                            ))}
                        </div>
                    )
                )}

                {activeTab === 'audit' && (
                    <TableCard head={['When', 'Medication', 'Movement', 'Balance', 'By · witness']} empty={recentEntries.length === 0 ? 'No audit entries.' : null}>
                        {recentEntries.map((e) => (
                            <tr key={e.id} className="border-b last:border-b-0">
                                <td className="px-4 py-3 text-muted-foreground">{e.recorded_at ? new Date(e.recorded_at).toLocaleString('en-NZ', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : '—'}</td>
                                <td className="px-4 py-3 font-medium">{e.medication_name}</td>
                                <td className="px-4 py-3 capitalize text-muted-foreground">{e.entry_type.replace('_', ' ')} {e.quantity}</td>
                                <td className="px-4 py-3 tabular-nums text-muted-foreground">{e.on_hand_after ?? '—'}</td>
                                <td className="px-4 py-3 text-muted-foreground">{e.recorded_by_name ?? '—'}{e.witnessed_by_name ? ` · ${e.witnessed_by_name}` : ''}</td>
                            </tr>
                        ))}
                    </TableCard>
                )}
            </div>

            {modal?.type === 'entry' && <RecordCdEntryDialog medications={medications} staff={staff} onClose={() => setModal(null)} />}
            {modal?.type === 'balance' && <BalanceCheckDialog medications={medications} staff={staff} onClose={() => setModal(null)} />}
            {modal?.type === 'balanceMed' && <BalanceCheckDialog medications={medications} staff={staff} presetMedId={modal.medId} onClose={() => setModal(null)} />}
            {modal?.type === 'loss' && <ReportLossDialog medications={medications} onClose={() => setModal(null)} />}
            {modal?.type === 'destruction' && <RecordDestructionDialog medications={medications} staff={staff} onClose={() => setModal(null)} />}
            {modal?.type === 'resolveDisc' && <ResolveDiscrepancyDialog discrepancy={modal.disc} onClose={() => setModal(null)} />}
            {modal?.type === 'lossAction' && <LossActionDialog report={modal.report} action={modal.action} onClose={() => setModal(null)} />}
        </AppLayout>
    );
}

function TableCard({ head, empty, children }: { head: string[]; empty: string | null; children: React.ReactNode }) {
    return (
        <div className="overflow-hidden rounded-2xl border bg-card shadow-sm">
            {empty ? (
                <div className="px-5 py-12 text-center text-sm text-muted-foreground">{empty}</div>
            ) : (
                <div className="overflow-x-auto">
                    <table className="w-full min-w-[720px] text-sm">
                        <thead>
                            <tr className="bg-muted text-left text-[11px] uppercase tracking-wide text-muted-foreground">
                                {head.map((h, i) => (
                                    <th key={i} className="px-4 py-2.5">{h}</th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>{children}</tbody>
                    </table>
                </div>
            )}
        </div>
    );
}

function EmptyCard({ text }: { text: string }) {
    return <div className="rounded-2xl border bg-card px-5 py-12 text-center text-sm text-muted-foreground">{text}</div>;
}
