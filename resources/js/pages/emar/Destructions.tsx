/* eslint-disable no-restricted-syntax -- register tab tables/report cards are custom-layout
   bordered surfaces (not Card/Button); all colours are semantic tokens. */
import { type CdMedication, type StaffOption } from '@/components/emar/controlled/types';
import { PageHero, type PageHeroStat } from '@/components/page';
import { EntityFilter, TabStrip, type RosterTabItem } from '@/components/rostering';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { CdPill, RecordDestructionDialog, VoidDestructionDialog } from '@/pages/emar/_cd-dialogs';
import { Head, router } from '@inertiajs/react';
import { ClipboardCheck, Download, FileText, Lock, Package, Plus, Trash2 } from 'lucide-react';
import { useMemo, useState } from 'react';

type DestructionRow = {
    id: number;
    client_id: number | null;
    client_name: string;
    site_id: number | null;
    site_name: string | null;
    medication_name: string | null;
    form: string | null;
    strength: string | null;
    quantity: number | string | null;
    unit: string | null;
    batch_number: string | null;
    expiry_date: string | null;
    reason: string | null;
    reason_label: string | null;
    disposal_method: string | null;
    disposal_method_label: string | null;
    is_controlled_drug: boolean;
    controlled_drug_class: string | null;
    authorised_by_name: string | null;
    destroyed_at: string | null;
    destroyed_by_name: string | null;
    witness_1_name: string | null;
    witness_2_name: string | null;
    notes: string | null;
    voided_at: string | null;
    void_reason: string | null;
    voided_by_name: string | null;
    is_voided: boolean;
};

type Props = {
    destructions: DestructionRow[];
    medications: CdMedication[];
    staff: StaffOption[];
    clients: { id: number; first_name: string; last_name: string }[];
    sites: { id: number; name: string }[];
    active_site: { id: number; name: string } | null;
    site_brand_colour: string | null;
};

type Modal = { type: 'record' } | { type: 'void'; row: DestructionRow } | null;

const DAY = 1000 * 60 * 60 * 24;

function withinDays(iso: string | null, days: number): boolean {
    if (!iso) return false;
    const t = new Date(iso).getTime();
    return !Number.isNaN(t) && Date.now() - t <= days * DAY;
}

function relativeDays(iso: string | null): string {
    if (!iso) return 'Never';
    const t = new Date(iso).getTime();
    if (Number.isNaN(t)) return '—';
    const d = Math.floor((Date.now() - t) / DAY);
    return d <= 0 ? 'Today' : d === 1 ? 'Yesterday' : `${d}d ago`;
}

const fmtDate = (iso: string | null) => (iso ? new Date(iso).toLocaleDateString('en-NZ', { day: 'numeric', month: 'short', year: 'numeric' }) : '—');

function csvCell(value: unknown): string {
    const s = value === null || value === undefined ? '' : String(value);
    return /[",\n]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
}

function exportCsv(rows: DestructionRow[]) {
    const head = ['Date', 'Client', 'Site', 'Medication', 'Form', 'Strength', 'Quantity', 'Unit', 'Batch', 'Expiry', 'Reason', 'Method', 'Controlled drug', 'Destroyed by', 'Witness 1', 'Witness 2', 'Authorised by', 'Notes', 'Voided', 'Void reason'];
    const lines = rows.map((d) => [
        fmtDate(d.destroyed_at), d.client_name, d.site_name, d.medication_name, d.form, d.strength,
        d.quantity, d.unit, d.batch_number, d.expiry_date, d.reason_label ?? d.reason, d.disposal_method_label ?? d.disposal_method,
        d.is_controlled_drug ? `Yes${d.controlled_drug_class ? ` (Class ${d.controlled_drug_class})` : ''}` : 'No',
        d.destroyed_by_name, d.witness_1_name, d.witness_2_name, d.authorised_by_name, d.notes,
        d.is_voided ? 'Yes' : 'No', d.void_reason,
    ].map(csvCell).join(','));
    const blob = new Blob([[head.join(','), ...lines].join('\n')], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `destruction-register-${new Date().toISOString().slice(0, 10)}.csv`;
    a.click();
    URL.revokeObjectURL(url);
}

export default function Destructions({ destructions, medications, staff, sites, active_site: activeSite, site_brand_colour: brandColour }: Props) {
    const [activeTab, setActiveTab] = useState('log');
    const [siteFilter, setSiteFilter] = useState<number | null>(activeSite?.id ?? null);
    const [modal, setModal] = useState<Modal>(null);

    const live = useMemo(() => destructions.filter((d) => !d.is_voided), [destructions]);
    const controlled = useMemo(() => destructions.filter((d) => d.is_controlled_drug), [destructions]);

    const destroyed30 = live.filter((d) => withinDays(d.destroyed_at, 30)).length;
    const cd30 = controlled.filter((d) => !d.is_voided && withinDays(d.destroyed_at, 30)).length;
    const lastAt = live.map((d) => d.destroyed_at).filter(Boolean).sort().slice(-1)[0] ?? null;

    // Report aggregates (live records only).
    const byReason = useMemo(() => tally(live.map((d) => d.reason_label ?? d.reason ?? 'Unspecified')), [live]);
    const byMethod = useMemo(() => tally(live.map((d) => d.disposal_method_label ?? d.disposal_method ?? 'Unspecified')), [live]);

    const TABS: RosterTabItem[] = [
        { id: 'log', label: 'Destruction log', icon: ClipboardCheck, tone: 'primary', badge: live.length || undefined },
        { id: 'controlled', label: 'Controlled drugs', icon: Lock, tone: 'critical', badge: controlled.filter((d) => !d.is_voided).length || undefined },
        { id: 'reports', label: 'Reports & export', icon: FileText, tone: 'info' },
    ];

    const heroStats: PageHeroStat[] = [
        { label: 'Live records', value: live.length },
        { label: 'Destroyed (30d)', value: destroyed30 },
        { label: 'CD destructions (30d)', value: cd30, tone: cd30 > 0 ? 'warning' : 'neutral' },
        { label: 'Last destruction', value: relativeDays(lastAt) },
    ];

    return (
        <AppLayout breadcrumbs={[{ title: 'eMAR', href: '/emar' }, { title: 'Destructions', href: '/emar/destructions' }]}>
            <Head title="Medication Destruction Register" />
            <div className="flex flex-col gap-6 p-6">
                <PageHero
                    variant="hero"
                    category="ops"
                    brandColour={brandColour}
                    icon={Trash2}
                    title={
                        <span>
                            <span className="flex items-center gap-2 text-[10.5px] font-semibold uppercase tracking-wide text-primary-foreground/80">
                                <span aria-hidden className="relative inline-flex h-2 w-2">
                                    <span className="absolute inset-0 animate-ping rounded-full bg-status-success/70" />
                                    <span className="relative inline-flex h-2 w-2 rounded-full bg-status-success" />
                                </span>
                                Disposal register · immutable
                            </span>
                            <span className="mt-1 block text-[26px] font-bold leading-tight">
                                Medication disposal &amp; destruction for{' '}
                                <span className="border-b-2 border-primary-foreground/40">{activeSite?.name ?? 'your services'}</span>
                            </span>
                        </span>
                    }
                    description="Witnessed disposal of medication and controlled drugs — append-only and retained. Erroneous entries are voided, never deleted."
                    stats={heroStats}
                    actions={
                        <>
                            <Button className="bg-primary-foreground text-primary hover:bg-primary-foreground/90" onClick={() => setModal({ type: 'record' })}>
                                <Plus className="h-4 w-4" />
                                Record destruction
                            </Button>
                            <Button variant="outline" className="border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground hover:bg-primary-foreground/20" onClick={() => exportCsv(destructions)} disabled={destructions.length === 0}>
                                <Download className="h-4 w-4" />
                                Export register
                            </Button>
                        </>
                    }
                    footer={
                        sites.length > 0 ? (
                            <div className="flex items-center justify-end py-3">
                                <EntityFilter label="Site" allLabel="All sites" items={sites} value={siteFilter} onChange={(id) => { setSiteFilter(id); router.get('/emar/destructions', id ? { site_id: id } : {}, { preserveState: true, preserveScroll: true }); }} onDark />
                            </div>
                        ) : undefined
                    }
                />

                <TabStrip value={activeTab} onChange={setActiveTab} items={TABS} ariaLabel="Destruction register views" />

                {activeTab === 'log' && (
                    <TableCard head={['Date', 'Client', 'Medication', 'Qty', 'Reason', 'Method', 'Destroyed by', 'Witness', '']} empty={destructions.length === 0 ? 'No destruction records yet.' : null}>
                        {destructions.map((d) => (
                            <LogRow key={d.id} d={d} onVoid={() => setModal({ type: 'void', row: d })} />
                        ))}
                    </TableCard>
                )}

                {activeTab === 'controlled' && (
                    <TableCard head={['Date', 'Client', 'Controlled drug', 'Qty', 'Witness 1', 'Witness 2', 'Authorised by', '']} empty={controlled.length === 0 ? 'No controlled-drug destructions recorded.' : null}>
                        {controlled.map((d) => (
                            <tr key={d.id} className={cnRow(d)}>
                                <td className="px-4 py-3 text-muted-foreground">{fmtDate(d.destroyed_at)}</td>
                                <td className="px-4 py-3">{d.client_name}</td>
                                <td className="px-4 py-3 font-medium">
                                    {d.medication_name}
                                    {d.controlled_drug_class && <CdPill label={`Class ${d.controlled_drug_class}`} tone="ml-2 bg-status-critical-bg text-status-critical" />}
                                </td>
                                <td className="px-4 py-3 tabular-nums">{d.quantity} {d.unit}</td>
                                <td className="px-4 py-3 text-muted-foreground">{d.witness_1_name ?? '—'}</td>
                                <td className="px-4 py-3 text-muted-foreground">{d.witness_2_name ?? '—'}</td>
                                <td className="px-4 py-3 text-muted-foreground">{d.authorised_by_name ?? '—'}</td>
                                <td className="px-4 py-3 text-right">
                                    {d.is_voided ? <CdPill label="Voided" tone="bg-muted text-muted-foreground" /> : <Button size="sm" variant="ghost" onClick={() => setModal({ type: 'void', row: d })}>Void</Button>}
                                </td>
                            </tr>
                        ))}
                    </TableCard>
                )}

                {activeTab === 'reports' && (
                    <div className="flex flex-col gap-4">
                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            <StatCard label="Live records" value={live.length} hint="Excludes voided" />
                            <StatCard label="Controlled-drug disposals" value={controlled.filter((d) => !d.is_voided).length} hint="All time" />
                            <StatCard label="Destroyed (30 days)" value={destroyed30} hint="Rolling" />
                            <StatCard label="Voided records" value={destructions.length - live.length} hint="Retained, superseded" />
                        </div>
                        <div className="grid gap-4 lg:grid-cols-2">
                            <BreakdownCard title="By reason" rows={byReason} />
                            <BreakdownCard title="By disposal method" rows={byMethod} />
                        </div>
                        <div className="flex items-center justify-between gap-3 rounded-2xl border bg-card p-4 shadow-sm">
                            <div>
                                <div className="text-sm font-medium">Export disposal register</div>
                                <div className="text-xs text-muted-foreground">Download the full register (including voided records) as CSV for audit.</div>
                            </div>
                            <Button variant="outline" onClick={() => exportCsv(destructions)} disabled={destructions.length === 0}>
                                <Download className="h-4 w-4" />
                                Export CSV
                            </Button>
                        </div>
                    </div>
                )}
            </div>

            {modal?.type === 'record' && <RecordDestructionDialog medications={medications} staff={staff} sites={sites} defaultSiteId={siteFilter} onClose={() => setModal(null)} />}
            {modal?.type === 'void' && <VoidDestructionDialog destruction={modal.row} onClose={() => setModal(null)} />}
        </AppLayout>
    );
}

function LogRow({ d, onVoid }: { d: DestructionRow; onVoid: () => void }) {
    return (
        <tr className={cnRow(d)}>
            <td className="px-4 py-3 text-muted-foreground">{fmtDate(d.destroyed_at)}</td>
            <td className="px-4 py-3">{d.client_name}</td>
            <td className="px-4 py-3 font-medium">
                <span className={d.is_voided ? 'line-through' : ''}>{d.medication_name}</span>
                {d.is_controlled_drug && <CdPill label="CD" tone="ml-2 bg-status-critical-bg text-status-critical" />}
                {d.is_voided && <span className="ml-2 align-middle"><CdPill label="Voided" tone="bg-muted text-muted-foreground" /></span>}
                {d.is_voided && d.void_reason && <div className="mt-0.5 text-xs font-normal text-muted-foreground">Voided{d.voided_by_name ? ` by ${d.voided_by_name}` : ''}: {d.void_reason}</div>}
            </td>
            <td className="px-4 py-3 tabular-nums">{d.quantity} {d.unit}</td>
            <td className="px-4 py-3 text-muted-foreground">{d.reason_label ?? d.reason ?? '—'}</td>
            <td className="px-4 py-3 text-muted-foreground">{d.disposal_method_label ?? d.disposal_method ?? '—'}</td>
            <td className="px-4 py-3 text-muted-foreground">{d.destroyed_by_name ?? '—'}</td>
            <td className="px-4 py-3 text-muted-foreground">{[d.witness_1_name, d.witness_2_name].filter(Boolean).join(', ') || '—'}</td>
            <td className="px-4 py-3 text-right">
                {d.is_voided ? <span className="text-xs text-muted-foreground">{fmtDate(d.voided_at)}</span> : <Button size="sm" variant="ghost" onClick={onVoid}>Void</Button>}
            </td>
        </tr>
    );
}

const cnRow = (d: DestructionRow) => `border-b last:border-b-0${d.is_voided ? ' bg-muted/30 text-muted-foreground' : ''}`;

function tally(values: string[]): { label: string; count: number }[] {
    const map = new Map<string, number>();
    values.forEach((v) => map.set(v, (map.get(v) ?? 0) + 1));
    return [...map.entries()].map(([label, count]) => ({ label, count })).sort((a, b) => b.count - a.count);
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

function StatCard({ label, value, hint }: { label: string; value: number | string; hint?: string }) {
    return (
        <div className="rounded-2xl border bg-card p-4 shadow-sm">
            <div className="text-xs font-medium uppercase tracking-wide text-muted-foreground">{label}</div>
            <div className="mt-1 text-2xl font-bold tabular-nums">{value}</div>
            {hint && <div className="text-xs text-muted-foreground">{hint}</div>}
        </div>
    );
}

function BreakdownCard({ title, rows }: { title: string; rows: { label: string; count: number }[] }) {
    const max = Math.max(1, ...rows.map((r) => r.count));
    return (
        <div className="rounded-2xl border bg-card p-4 shadow-sm">
            <div className="mb-3 flex items-center gap-2 text-sm font-semibold"><Package className="h-4 w-4 text-muted-foreground" />{title}</div>
            {rows.length === 0 ? (
                <div className="py-6 text-center text-sm text-muted-foreground">No records.</div>
            ) : (
                <div className="flex flex-col gap-2">
                    {rows.map((r) => (
                        <div key={r.label} className="flex items-center gap-3">
                            <div className="w-40 shrink-0 truncate text-sm">{r.label}</div>
                            <div className="h-2 flex-1 overflow-hidden rounded-full bg-muted">
                                <div className="h-full rounded-full bg-primary" style={{ width: `${(r.count / max) * 100}%` }} />
                            </div>
                            <div className="w-8 text-right text-sm tabular-nums text-muted-foreground">{r.count}</div>
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
}
