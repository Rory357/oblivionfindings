/* eslint-disable no-restricted-syntax -- the tab cards/tables are custom-layout
   bordered surfaces (not Card/Button); all colours are semantic tokens. */
import {
    countersignHoursLeft,
    needsCountersign,
    orderStatusTone,
    type ClientOption,
    type CovertAuth,
    type MedOption,
    type PrescriptionOrder,
    type StaffOption,
} from '@/components/emar/prescriptions/types';
import { PageHero, type PageHeroStat } from '@/components/page';
import { EntityFilter, TabStrip, type RosterTabItem } from '@/components/rostering';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { CountersignDialog, CovertDialog, DispenseDialog, LinkMarDialog, NewOrderDialog } from '@/pages/emar/_prescription-dialogs';
import { Head, router } from '@inertiajs/react';
import { AlertTriangle, CalendarDays, FileText, LineChart, Link2, Package, PenTool, Pill, Plus, Search, ShieldCheck } from 'lucide-react';
import { useMemo, useState } from 'react';

type Modal =
    | { type: 'order' }
    | { type: 'covert' }
    | { type: 'countersign' | 'dispense' | 'link'; order: PrescriptionOrder }
    | null;

type Props = {
    orders: PrescriptionOrder[];
    covert: CovertAuth[];
    clients: ClientOption[];
    staff: StaffOption[];
    medications: MedOption[];
    sites: { id: number; name: string }[];
    active_site: { id: number; name: string } | null;
    site_brand_colour: string | null;
};

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
function Pill2({ label, tone }: { label: string; tone: string }) {
    return <span className={`rounded-full px-2 py-0.5 text-[11px] font-semibold capitalize ${tone}`}>{label}</span>;
}

export default function Prescriptions(props: Props) {
    const { orders, covert, clients, staff, medications, sites, active_site: activeSite, site_brand_colour: brandColour } = props;

    const [activeTab, setActiveTab] = useState('orders');
    const [search, setSearch] = useState('');
    const [statusFilter, setStatusFilter] = useState('all');
    const [siteFilter, setSiteFilter] = useState<number | null>(activeSite?.id ?? null);
    const [modal, setModal] = useState<Modal>(null);

    const counts = useMemo(() => {
        const awaiting = orders.filter(needsCountersign);
        return {
            awaiting: awaiting.length,
            overdue: awaiting.filter((o) => (countersignHoursLeft(o) ?? 1) < 0).length,
            active: orders.filter((o) => ['pending', 'confirmed'].includes(o.status)).length,
            toDispense: orders.filter((o) => o.status === 'confirmed').length,
            covert: covert.length,
        };
    }, [orders, covert]);

    const ordersFiltered = useMemo(() => {
        const q = search.toLowerCase();
        return orders.filter((o) => {
            if (statusFilter !== 'all' && o.status !== statusFilter) return false;
            if (q && !`${o.medication_name ?? ''} ${o.client_name} ${o.prescriber_name ?? ''}`.toLowerCase().includes(q)) return false;
            return true;
        });
    }, [orders, statusFilter, search]);

    const awaitingOrders = orders.filter(needsCountersign);
    const toDispense = orders.filter((o) => o.status === 'confirmed');
    const dispensed = orders.filter((o) => o.status === 'dispensed');

    const TABS: RosterTabItem[] = [
        { id: 'orders', label: 'Prescriber Orders', icon: FileText, tone: 'primary', badge: orders.length || undefined },
        { id: 'countersign', label: 'Awaiting Countersign', icon: PenTool, tone: 'warning', badge: counts.awaiting || undefined },
        { id: 'dispensing', label: 'Dispensing', icon: Package, tone: 'success', badge: toDispense.length || undefined },
        { id: 'covert', label: 'Covert', icon: ShieldCheck, tone: 'critical', badge: covert.length || undefined },
        { id: 'activity', label: 'Activity', icon: LineChart, tone: 'info' },
    ];

    const heroStats: PageHeroStat[] = [
        { label: 'Awaiting countersign', value: counts.awaiting, tone: counts.overdue > 0 ? 'critical' : counts.awaiting > 0 ? 'warning' : 'neutral' },
        { label: 'Active orders', value: counts.active },
        { label: 'To dispense', value: counts.toDispense },
        { label: 'Covert active', value: counts.covert, tone: counts.covert > 0 ? 'warning' : 'neutral' },
    ];

    const confirm = (o: PrescriptionOrder) => router.put(`/emar/prescriptions/${o.id}`, { status: 'confirmed' }, { preserveScroll: true });
    const cancel = (o: PrescriptionOrder) => router.delete(`/emar/prescriptions/${o.id}`, { preserveScroll: true });
    const revoke = (c: CovertAuth) => router.post(`/emar/prescriptions/covert/${c.id}/revoke`, {}, { preserveScroll: true });

    return (
        <AppLayout breadcrumbs={[{ title: 'eMAR', href: '/emar' }, { title: 'Prescriptions', href: '/emar/prescriptions' }]}>
            <Head title="Prescriptions & Orders" />
            <div className="flex flex-col gap-6 p-6">
                <PageHero
                    variant="hero"
                    category="ops"
                    brandColour={brandColour}
                    icon={FileText}
                    title={
                        <span>
                            <span className="flex items-center gap-2 text-[10.5px] font-semibold uppercase tracking-wide text-primary-foreground/80">
                                <span aria-hidden className="relative inline-flex h-2 w-2">
                                    <span className="absolute inset-0 animate-ping rounded-full bg-status-success/70" />
                                    <span className="relative inline-flex h-2 w-2 rounded-full bg-status-success" />
                                </span>
                                Prescriptions &amp; orders · live
                            </span>
                            <span className="mt-1 block text-[26px] font-bold leading-tight">
                                Prescriber orders for{' '}
                                <span className="border-b-2 border-primary-foreground/40">{activeSite?.name ?? 'your services'}</span>
                            </span>
                        </span>
                    }
                    description="Prescriber orders, verbal/telephone countersignatures, dispensing, and covert administration authorisations."
                    stats={heroStats}
                    actions={
                        <>
                            <Button className="bg-primary-foreground text-primary hover:bg-primary-foreground/90" onClick={() => setModal({ type: 'order' })}>
                                <Plus className="h-4 w-4" />
                                New prescriber order
                            </Button>
                            <Button variant="outline" className="border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground hover:bg-primary-foreground/20" onClick={() => setModal({ type: 'covert' })}>
                                <ShieldCheck className="h-4 w-4" />
                                New covert authorisation
                            </Button>
                        </>
                    }
                    footer={
                        sites.length > 0 ? (
                            <div className="flex items-center justify-end py-3">
                                <EntityFilter label="Site" allLabel="All sites" items={sites} value={siteFilter} onChange={(id) => { setSiteFilter(id); router.get('/emar/prescriptions', id ? { site_id: id } : {}, { preserveState: true, preserveScroll: true }); }} onDark />
                            </div>
                        ) : undefined
                    }
                />

                {counts.overdue > 0 && (
                    <div className="flex items-center justify-between gap-3 rounded-xl border border-status-critical/30 bg-status-critical-bg/60 px-4 py-3">
                        <span className="flex items-center gap-2 text-sm font-medium text-status-critical">
                            <AlertTriangle className="h-4 w-4" />
                            {counts.overdue} verbal/telephone order{counts.overdue === 1 ? ' is' : 's are'} overdue for prescriber countersignature.
                        </span>
                        <Button size="sm" variant="outline" onClick={() => setActiveTab('countersign')}>
                            Review queue
                        </Button>
                    </div>
                )}

                <TabStrip value={activeTab} onChange={setActiveTab} items={TABS} ariaLabel="Prescription views" />

                {activeTab === 'orders' && (
                    <div className="overflow-hidden rounded-2xl border bg-card shadow-sm">
                        <div className="flex flex-wrap items-center gap-2.5 border-b p-3.5">
                            <div className="relative">
                                <Search className="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                                <Input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search client, medication or prescriber…" className="w-72 pl-8" />
                            </div>
                            <Select value={statusFilter} onValueChange={setStatusFilter}>
                                <SelectTrigger className="h-9 w-40"><SelectValue /></SelectTrigger>
                                <SelectContent>
                                    {['all', 'pending', 'confirmed', 'dispensed', 'cancelled', 'expired'].map((s) => (
                                        <SelectItem key={s} value={s} className="capitalize">{s === 'all' ? 'All statuses' : s}</SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <span className="ml-auto text-xs text-muted-foreground">{ordersFiltered.length} of {orders.length}</span>
                        </div>
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[920px] text-sm">
                                <thead>
                                    <tr className="bg-muted text-left text-[11px] uppercase tracking-wide text-muted-foreground">
                                        <th className="px-4 py-2.5">Date</th>
                                        <th className="px-4 py-2.5">Resident</th>
                                        <th className="px-4 py-2.5">Medication</th>
                                        <th className="px-4 py-2.5">Type</th>
                                        <th className="px-4 py-2.5">Prescriber</th>
                                        <th className="px-4 py-2.5">Status</th>
                                        <th className="px-4 py-2.5">Countersign</th>
                                        <th className="px-4 py-2.5"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {ordersFiltered.length === 0 ? (
                                        <tr><td colSpan={8} className="px-4 py-12 text-center text-muted-foreground">No orders match these filters.</td></tr>
                                    ) : ordersFiltered.map((o) => {
                                        const hrs = countersignHoursLeft(o);
                                        return (
                                            <tr key={o.id} className="border-b last:border-b-0">
                                                <td className="px-4 py-3 text-muted-foreground">{o.order_date}</td>
                                                <td className="px-4 py-3"><span className="flex items-center gap-2"><Avatar id={o.client_id} name={o.client_name} />{o.client_name}</span></td>
                                                <td className="px-4 py-3"><div className="font-medium">{o.medication_name}</div><div className="text-xs text-muted-foreground">{[o.dose, o.route, o.frequency].filter(Boolean).join(' · ')}</div></td>
                                                <td className="px-4 py-3">{['verbal', 'telephone'].includes(o.order_type) ? <Pill2 label={o.order_type} tone="bg-status-warning-bg text-status-warning" /> : <span className="text-muted-foreground capitalize">{o.order_type}</span>}</td>
                                                <td className="px-4 py-3"><div>{o.prescriber_name}</div>{o.prescriber_registration && <div className="text-xs text-muted-foreground">{o.prescriber_registration}</div>}</td>
                                                <td className="px-4 py-3"><Pill2 label={o.status} tone={orderStatusTone(o.status)} /></td>
                                                <td className="px-4 py-3">
                                                    {!o.requires_countersign ? <span className="text-muted-foreground">—</span>
                                                        : o.countersigned_at ? <span className="text-status-success">✓ Signed</span>
                                                        : <Button size="sm" variant={hrs !== null && hrs < 0 ? 'destructive' : 'default'} onClick={() => setModal({ type: 'countersign', order: o })}>{hrs !== null && hrs < 0 ? 'Overdue — sign' : `Sign · ${hrs}h`}</Button>}
                                                </td>
                                                <td className="px-4 py-3 text-right">
                                                    <div className="flex items-center justify-end gap-1">
                                                        {o.status === 'pending' && !needsCountersign(o) && <Button size="sm" variant="ghost" onClick={() => confirm(o)}>Confirm</Button>}
                                                        {o.status === 'confirmed' && <Button size="sm" variant="ghost" onClick={() => setModal({ type: 'dispense', order: o })}><Package className="h-3.5 w-3.5" />Dispense</Button>}
                                                        {['pending', 'confirmed'].includes(o.status) && <Button size="sm" variant="ghost" onClick={() => setModal({ type: 'link', order: o })} aria-label="Link to MAR"><Link2 className="h-3.5 w-3.5" /></Button>}
                                                        {['pending', 'confirmed'].includes(o.status) && <Button size="sm" variant="ghost" className="text-status-critical" onClick={() => cancel(o)}>Cancel</Button>}
                                                    </div>
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                    </div>
                )}

                {activeTab === 'countersign' && (
                    <div className="flex flex-col gap-4">
                        <div className="flex items-center gap-2 rounded-xl border border-status-warning/30 bg-status-warning-bg/50 px-4 py-2.5 text-sm text-status-warning">
                            <AlertTriangle className="h-4 w-4" />
                            Verbal &amp; telephone orders must be countersigned by the prescriber within 24 hours.
                        </div>
                        {awaitingOrders.length === 0 ? (
                            <div className="rounded-2xl border bg-card px-5 py-12 text-center text-sm text-muted-foreground">Nothing awaiting countersignature.</div>
                        ) : (
                            <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                                {awaitingOrders.map((o) => {
                                    const hrs = countersignHoursLeft(o);
                                    const overdue = hrs !== null && hrs < 0;
                                    return (
                                        <div key={o.id} className="flex flex-col gap-3 rounded-2xl border bg-card p-4 shadow-sm">
                                            <div className="flex items-center justify-between">
                                                <span className="flex items-center gap-2 font-medium"><Avatar id={o.client_id} name={o.client_name} />{o.client_name}</span>
                                                <Pill2 label={o.order_type} tone="bg-status-warning-bg text-status-warning" />
                                            </div>
                                            <div className="text-sm"><span className="font-medium">{o.medication_name}</span> <span className="text-muted-foreground">{[o.dose, o.frequency].filter(Boolean).join(' · ')}</span></div>
                                            <div className="text-xs text-muted-foreground">Prescriber: {o.prescriber_name} · Taken {o.order_date} by {o.received_by_name ?? '—'}{o.read_back_confirmed ? ' · read-back ✓' : ''}</div>
                                            <div>
                                                <div className={`mb-1 text-[11px] font-medium ${overdue ? 'text-status-critical' : 'text-status-warning'}`}>{overdue ? `Overdue by ${Math.abs(hrs!)}h` : `${hrs}h remaining`}</div>
                                                <div className="h-1.5 overflow-hidden rounded-full bg-muted"><div className={`h-full rounded-full ${overdue ? 'bg-status-critical' : 'bg-status-warning'}`} style={{ width: `${overdue ? 100 : Math.max(5, 100 - ((hrs ?? 0) / 24) * 100)}%` }} /></div>
                                            </div>
                                            <Button className="mt-1" onClick={() => setModal({ type: 'countersign', order: o })}>Countersign now</Button>
                                        </div>
                                    );
                                })}
                            </div>
                        )}
                    </div>
                )}

                {activeTab === 'dispensing' && (
                    <div className="flex flex-col gap-4">
                        <div className="overflow-hidden rounded-2xl border bg-card shadow-sm">
                            <div className="border-b px-5 py-3.5 text-[15px] font-bold">Awaiting dispense</div>
                            {toDispense.length === 0 ? <div className="px-5 py-8 text-center text-sm text-muted-foreground">No confirmed orders awaiting dispense.</div> : (
                                <ul className="divide-y">
                                    {toDispense.map((o) => (
                                        <li key={o.id} className="flex items-center justify-between px-5 py-3">
                                            <span className="flex items-center gap-2 text-sm"><Avatar id={o.client_id} name={o.client_name} /><span className="font-medium">{o.client_name}</span> · {o.medication_name}</span>
                                            <Button size="sm" onClick={() => setModal({ type: 'dispense', order: o })}><Package className="h-4 w-4" />Record dispensing</Button>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </div>
                        {dispensed.length > 0 && (
                            <div className="overflow-hidden rounded-2xl border bg-card shadow-sm">
                                <div className="border-b px-5 py-3.5 text-[15px] font-bold">Recently dispensed</div>
                                <table className="w-full text-sm">
                                    <thead><tr className="border-b text-left text-[11px] uppercase tracking-wide text-muted-foreground"><th className="px-5 py-2">Resident · Medication</th><th className="px-5 py-2">Pharmacy</th><th className="px-5 py-2">Batch</th><th className="px-5 py-2">Dispensed by</th></tr></thead>
                                    <tbody>
                                        {dispensed.map((o) => (
                                            <tr key={o.id} className="border-b last:border-b-0">
                                                <td className="px-5 py-2.5"><span className="font-medium">{o.client_name}</span> · {o.medication_name}</td>
                                                <td className="px-5 py-2.5 text-muted-foreground">{o.pharmacy_name ?? '—'}</td>
                                                <td className="px-5 py-2.5 text-muted-foreground">{o.batch_number ?? '—'}</td>
                                                <td className="px-5 py-2.5 text-muted-foreground">{o.dispensed_by_name ?? '—'}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </div>
                )}

                {activeTab === 'covert' && (
                    <div className="flex flex-col gap-4">
                        <div className="flex items-start gap-2 rounded-xl border border-status-critical/30 bg-status-critical-bg/50 px-4 py-3 text-sm text-status-critical">
                            <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />
                            <span>Covert administration is a restrictive practice requiring capacity assessment, a best-interest MDT decision, pharmacist advice, and regular review. Medication must always be offered overtly first.</span>
                        </div>
                        {covert.length === 0 ? <div className="rounded-2xl border bg-card px-5 py-12 text-center text-sm text-muted-foreground">No active covert authorisations.</div> : (
                            <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                                {covert.map((c) => (
                                    <div key={c.id} className="flex flex-col gap-3 rounded-2xl border bg-card p-4 shadow-sm">
                                        <div className="flex items-center justify-between">
                                            <span className="flex items-center gap-2 font-medium"><Avatar id={c.client_id} name={c.client_name} />{c.client_name}</span>
                                            <Pill2 label="Active" tone="bg-status-critical-bg text-status-critical" />
                                        </div>
                                        <div className="text-sm font-medium">{c.medication_name}</div>
                                        {c.administration_method && <div className="rounded-lg border bg-background px-3 py-2 text-xs"><span className="font-medium">Method:</span> {c.administration_method}</div>}
                                        <div className="text-xs text-muted-foreground">Authorised by {c.authorised_by_name} · {c.authorised_date}</div>
                                        <div className={`text-xs ${c.review_overdue ? 'font-medium text-status-critical' : 'text-muted-foreground'}`}>Next review: {c.review_date}{c.review_overdue ? ' · Overdue' : ''}</div>
                                        <Button variant="outline" className="mt-1 text-status-critical" onClick={() => revoke(c)}>Revoke authorisation</Button>
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>
                )}

                {activeTab === 'activity' && (
                    <div className="overflow-hidden rounded-2xl border bg-card shadow-sm">
                        <div className="border-b px-5 py-3.5 text-[15px] font-bold">Order activity</div>
                        {orders.length === 0 ? <div className="px-5 py-10 text-center text-sm text-muted-foreground">No order activity yet.</div> : (
                            <ul className="divide-y">
                                {orders.slice(0, 40).map((o) => (
                                    <li key={o.id} className="flex items-center justify-between px-5 py-3 text-sm">
                                        <span className="flex items-center gap-2">
                                            <span className={`flex h-7 w-7 items-center justify-center rounded-full ${orderStatusTone(o.status)}`}><Pill className="h-3.5 w-3.5" /></span>
                                            <span><span className="font-medium capitalize">{o.status}</span> · {o.medication_name} <span className="text-muted-foreground">— {o.client_name}</span></span>
                                        </span>
                                        <span className="text-xs text-muted-foreground">{o.dispensed_at ? `dispensed` : o.countersigned_at ? 'signed' : o.order_date}</span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                )}
            </div>

            {modal?.type === 'order' && <NewOrderDialog clients={clients} staff={staff} onClose={() => setModal(null)} />}
            {modal?.type === 'covert' && <CovertDialog clients={clients} medications={medications} onClose={() => setModal(null)} />}
            {modal?.type === 'countersign' && <CountersignDialog order={modal.order} onClose={() => setModal(null)} />}
            {modal?.type === 'dispense' && <DispenseDialog order={modal.order} staff={staff} onClose={() => setModal(null)} />}
            {modal?.type === 'link' && <LinkMarDialog order={modal.order} medications={medications} onClose={() => setModal(null)} />}
        </AppLayout>
    );
}
