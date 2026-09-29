/* The other recording entry points, as reference frames around real
 * components. Each keeps its owner's design; P01 changes only how a dose is
 * recorded there — always the shared recording dialog (one contract).
 *   MAR chart (P02 owns the page): the REAL MarGrid; its cell menu offers the
 *     one-click "Mark given" only for simple, non-controlled doses.
 *   Client profile › Medical (Clients owns it): Record dose → the same steps.
 *   Fleet transport (Fleet owns it): the carried dose's Record → the same steps, parity. */
import { EntityContextMenu, compactMenu, type MenuItem } from '@/components/lists/entity-menu';
import { EntityTable } from '@/components/lists/entity-table';
import { ListCaption } from '@/components/lists/list-caption';
import MarGrid, { type MarGridMed } from '@/components/emar/mar/mar-grid';
import { TierTwoTabs, type GroupedProfileNavTab } from '@/components/page/grouped-profile-nav';
import {
    PageHeader,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderStatusChip,
} from '@/components/page/page-header';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import type { DoseStatus, ScheduleRow } from '@/pages/meds/today/types';
import {
    Activity,
    Car,
    ClipboardCheck,
    ClipboardList,
    FileText,
    HeartPulse,
    History,
    Home,
    Pill,
    Printer,
    ShieldAlert,
    Stethoscope,
    Users,
    Zap,
} from 'lucide-react';
import { Link } from '@inertiajs/react';
import { useState, type MouseEvent } from 'react';
import { requirementsFor, type DoseState } from '../contract';
import { PEOPLE, doseById, type Dose } from '../data';
import { DoseActionCell, DoseStateCell, MedicineCell, useDoseMenu, useOpen, useRowContext } from '../doses';
import { useQuickGive } from '../record-dialog';
import { Shell } from '../shell';
import { hrefFor, useCounts, useStore } from '../store';
import { AllergyNotice, DesignNote, PersonMark } from '../ui';

const toGrid: Record<DoseState, DoseStatus> = {
    notdue: 'upcoming',
    due: 'due',
    late: 'overdue',
    given: 'given',
    prompted: 'given',
    assisted: 'given',
    reoffered: 'given',
    selfmanaged: 'given',
    refused: 'refused',
    withheld: 'withheld',
    away: 'withheld',
    sending: 'due',
    queued: 'due',
    rejected: 'due',
    uncertain: 'due',
};
const hh = (slot: string) => ({ '8:00 am': '08:00', '9:00 am': '09:00', '12:00 pm': '12:00' })[slot] ?? '08:00';

function tabsRender(onTab: (k: string) => void) {
    return (tab: GroupedProfileNavTab, className: string, inner: React.ReactNode, a: Record<string, unknown>) => (
        <Button variant="ghost" key={tab.key} className={className} {...a} onClick={() => onTab(tab.key)}>
            {inner}
        </Button>
    );
}

/* ───────────── MAR chart (/emar/mar?client_id=…) ───────────── */
export function MarPage() {
    const s = useStore();
    const open = useOpen();
    const quick = useQuickGive();
    const doseMenu = useDoseMenu();
    const pid = s.route.q.get('client') ?? 'aroha';
    const person = PEOPLE[pid] ?? PEOPLE.aroha;
    const doses = s.visibleDoses().filter((d) => d.pid === person.id);
    const [menu, setMenu] = useState<{ x: number; y: number; d: Dose } | null>(null);
    const c = useCounts();
    if (!PEOPLE[pid] || pid === 'ben')
        return (
            <Shell crumbs={[{ title: 'Home', href: '/dashboard' }, { title: 'Medication', href: '/meds/today' }, { title: 'MAR charts' }]}>
                <Card className="p-8 text-center">
                    <p className="text-section-title">We can’t show this record</p>
                    <p className="text-subtle mt-1">It may not exist, or it may not be available to you. Check the link, or go back.</p>
                    <div className="mt-4">
                        <Button variant="outline" onClick={() => s.go('/meds/today')}>
                            Back to Meds today
                        </Button>
                    </div>
                </Card>
                <DesignNote>A record outside your access and a record that doesn’t exist look the same (404, no existence leak).</DesignNote>
            </Shell>
        );
    const meds: MarGridMed[] = [...new Map(doses.map((d) => [d.med, d])).values()].map((d, i) => ({
        id: i + 1,
        name: `${d.med} ${d.strength}`,
        dosage: `${d.amount.n} ${d.amount.n <= 1 ? d.amount.unit : d.amount.plural}`,
        route: d.route,
        frequency: doses.filter((x) => x.med === d.med).length > 1 ? 'Twice a day' : 'Once a day',
        instructions: d.instructions,
        controlled_drug: !!d.cd,
        high_risk: false,
        witness_required: !!d.cd,
        is_inr: false,
        requires_observation: !!d.rules?.includes('mr1'),
        dose_times: doses.filter((x) => x.med === d.med).map((x) => hh(x.slot)),
    }));
    const medId = (d: Dose) => meds.find((m) => m.name === `${d.med} ${d.strength}`)!.id;
    const schedule: ScheduleRow[] = doses.map((d) => ({
        key: d.id,
        client_id: person.clientId,
        client_name: person.legal,
        medication_id: medId(d),
        medication_name: d.med,
        dose: `${d.amount.n} ${d.amount.unit}`,
        route: d.route,
        is_controlled: !!d.cd,
        requires_witness: !!d.cd,
        scheduled_for: `2026-09-28T${hh(d.slot)}:00+13:00`,
        time: hh(d.slot),
        round_label: d.slot,
        status: toGrid[s.stateOf(d)],
        recorded: s.recordOf(d) ? { id: 1, status: 'given', administered_at: null, time: s.recordOf(d)!.at, by: s.recordOf(d)!.by, witness: null, reason: null, reason_label: null, notes: null } : null,
        mar_url: '#',
    }));
    const byKey = (row: ScheduleRow) => doses.find((d) => d.id === row.key)!;
    const menuFor = (d: Dose): MenuItem[] => {
        const req = requirementsFor(d, s.ctx);
        const st = s.stateOf(d);
        const simple = st === 'due' && req.notSimple.length === 0;
        const base = doseMenu(d, 'mar');
        const extra: (MenuItem | false)[] = [
            simple && { label: 'Mark given (one click, as ordered)', icon: Zap, onClick: () => quick(d) },
            !simple && (st === 'due' || st === 'late') && { label: 'Why no one-click “Mark given”?', icon: FileText, onClick: () => s.toast('info', `“Mark given” isn’t offered for this dose: ${req.notSimple.join('; ')}. Use “Record dose” — the same safety checks as Meds today.`) },
        ];
        return compactMenu([...extra, ...base]);
    };
    const mine = c.st.filter((x) => x.d.pid === person.id);
    return (
        <Shell crumbs={[{ title: 'Home', href: '/dashboard' }, { title: 'Medication', href: '/meds/today' }, { title: 'MAR charts', href: '/emar/mar' }, { title: person.legal }]}>
            <PageHeader
                variant="profile"
                backHref="/meds/today"
                mark={<span className="eh-mark-ring overflow-hidden p-0"><PersonMark pid={person.id} size={44} /></span>}
                title={person.legal}
                titleChip={<PageHeaderStatusChip variant="neutral">{person.house}</PageHeaderStatusChip>}
                subline={<>Medication record · Monday 28 September 2026 · times in NZDT<br />Preferred name {person.pref} · NHI {person.nhi} (test)</>}
                actions={<PageHeaderGlassButton icon={Printer} onClick={() => s.toast('info', 'Prints the existing MAR PDF — outside this preview.')}>Print MAR</PageHeaderGlassButton>}
                meters={
                    <>
                        <PageHeaderMeterBlock label="Due now" href={hrefFor('/meds/today', { state: 'open' }, s.route)} ariaLabel={`View doses due now in Meds today`}>
                            <PageHeaderMeterBig>{mine.filter((x) => x.st === 'due').length}</PageHeaderMeterBig>
                            <PageHeaderMeterCaption>In today’s window</PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                        <PageHeaderMeterBlock label="Late" tone={mine.some((x) => x.st === 'late') ? 'warning' : 'brand'} href={hrefFor('/meds/today', { state: 'open' }, s.route)} ariaLabel="View late doses in Meds today">
                            <PageHeaderMeterBig>{mine.filter((x) => x.st === 'late').length}</PageHeaderMeterBig>
                            <PageHeaderMeterCaption>Outside the window</PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                        <PageHeaderMeterBlock label="Recorded today" href={hrefFor('/meds/today', { view: 'activity' }, s.route)} ariaLabel="View today’s activity">
                            <PageHeaderMeterBig>{mine.filter((x) => !['due', 'late', 'notdue', 'selfmanaged'].includes(x.st)).length} of {mine.filter((x) => x.st !== 'notdue').length}</PageHeaderMeterBig>
                            <PageHeaderMeterCaption>Due so far today</PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                        <PageHeaderMeterBlock label="Allergies" tone={person.allergy.status === 'recorded' ? 'critical' : 'warning'} href={hrefFor('/operations/clients/201', {}, s.route)} ariaLabel="View allergies on the client profile">
                            <PageHeaderMeterBig>{person.allergy.status === 'recorded' ? 'Recorded' : person.allergy.status === 'nkda' ? 'None known' : person.allergy.status === 'none' ? 'Not recorded' : 'Unavailable'}</PageHeaderMeterBig>
                            <PageHeaderMeterCaption>{person.allergy.status === 'recorded' ? 'Listed on the record' : 'Check the health profile'}</PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                    </>
                }
            />
            <TierTwoTabs
                tabs={[
                    { key: 'chart', label: 'Chart', icon: ClipboardList },
                    { key: 'medicines', label: 'Medicines', icon: Pill },
                    { key: 'support', label: 'Support plan', icon: Users },
                    { key: 'allergies', label: 'Allergies & alerts', icon: ShieldAlert },
                    { key: 'clinical', label: 'Clinical', icon: Stethoscope },
                    { key: 'history', label: 'History', icon: History },
                ]}
                activeTab="chart"
                onTab={(k) => k !== 'chart' && s.toast('info', 'The person medication record is designed in P02 — outside P01.')}
                ariaLabel="Medication record sections"
                testIdPrefix="p01-mar"
                renderLink={tabsRender((k) => k !== 'chart' && s.toast('info', 'The person medication record is designed in P02 — outside P01.'))}
            />
            <DesignNote title="Reference frame — the person medication record is designed in P02">
                <p>The chart below is the app’s real MAR grid (its cell labels are today’s; P02 restyles them to the P00 vocabulary). P01 designs only what recording from it does: clicking a due cell opens the shared recording steps, and right-clicking offers the one-click <strong>Mark given</strong> only for a simple dose — due now, not controlled, no witness, no rule readings or second person, support “Administer”, a fixed amount, not covert, allergies known with no match, the pack unchanged and your competency current. Everything else says why and uses Record dose.</p>
            </DesignNote>
            <MarGrid
                meds={meds}
                schedule={schedule}
                canRecord
                canRecordControlled
                onRecord={(row) => {
                    const d = byKey(row);
                    const st = s.stateOf(d);
                    const req = requirementsFor(d, s.ctx);
                    if ((st === 'due' || st === 'late') && (req.blockAll || req.blockGiven)) return open(`why:${d.id}`);
                    if (st === 'due' || st === 'late') return open(`record:${d.id}`, { from: 'mar' });
                    return open(`detail:${d.id}`);
                }}
                onContext={(e: MouseEvent, row) => {
                    e.preventDefault();
                    const kb = e.clientX === 0 && e.clientY === 0;
                    const b = (e.currentTarget as HTMLElement).getBoundingClientRect();
                    setMenu({ x: kb ? b.left + 12 : e.clientX, y: kb ? b.top + 12 : e.clientY, d: byKey(row) });
                }}
            />
            {menu ? <EntityContextMenu x={menu.x} y={menu.y} icon={Pill} title={`${PEOPLE[menu.d.pid].pref} · ${menu.d.med} · ${menu.d.slot}`} items={menuFor(menu.d)} onClose={() => setMenu(null)} /> : null}
            <p className="text-caption">Right-click a dose (or focus it and press Shift+F10) for its actions. “Mark given” records the ordered amount at 9:12 am and is checked by the server like any other record; if anything changed, it says “Not recorded” and asks for the full record.</p>
        </Shell>
    );
}

/* ───────────── client profile › Medical (Record dose) ───────────── */
export function ClientProfilePage() {
    const s = useStore();
    const open = useOpen();
    const person = PEOPLE.aroha;
    const doses = s.visibleDoses().filter((d) => d.pid === 'aroha');
    const due = doses.filter((d) => ['due', 'late'].includes(s.stateOf(d)));
    const soon = (k: string) => s.toast('info', `The client profile’s ${k} tab is unchanged — outside P01.`);
    return (
        <Shell crumbs={[{ title: 'Home', href: '/dashboard' }, { title: 'Clients', href: '/operations/clients' }, { title: person.legal }]}>
            <PageHeader
                variant="profile"
                backHref="/operations/clients"
                mark={<span className="eh-mark-ring overflow-hidden p-0"><PersonMark pid="aroha" size={44} /></span>}
                title={person.legal}
                titleChip={<PageHeaderStatusChip variant="success">Active</PageHeaderStatusChip>}
                subline={<>14 Kōwhai Street, Te Aro, Wellington<br />Client · Supported living · Kōwhai House</>}
                meters={
                    <>
                        <PageHeaderMeterBlock label="Next shift" href={hrefFor('/my-calendar', {}, s.route)} ariaLabel="View the next shift in My Calendar">
                            <PageHeaderMeterBig>3:00 pm</PageHeaderMeterBig>
                            <PageHeaderMeterCaption>Jordan Tipene</PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                        <PageHeaderMeterBlock label="Safety" tone="critical" onClick={() => document.getElementById('p01-mar-card')?.focus()} ariaLabel="View recorded allergies">
                            <PageHeaderMeterBig>2</PageHeaderMeterBig>
                            <PageHeaderMeterCaption>Allergies recorded</PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                        <PageHeaderMeterBlock label="Medications" value={`${due.length} due`} href={hrefFor('/emar/mar', { client: 'aroha' }, s.route)} ariaLabel="Open Aroha’s medication record">
                            <PageHeaderMeterBig>{doses.length}</PageHeaderMeterBig>
                            <PageHeaderMeterCaption>Doses on today’s chart</PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                        <PageHeaderMeterBlock label="Due now" tone={due.length ? 'warning' : 'brand'} onClick={() => document.getElementById('p01-mar-card')?.focus()} ariaLabel="View Aroha’s doses due now">
                            <PageHeaderMeterBig>{due.length}</PageHeaderMeterBig>
                            <PageHeaderMeterCaption>See the MAR card</PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                    </>
                }
            />
            <TierTwoTabs
                tabs={[
                    { key: 'overview', label: 'Overview', icon: Home },
                    { key: 'care', label: 'Care', icon: HeartPulse },
                    { key: 'medical', label: 'Medical', icon: Stethoscope },
                    { key: 'activity', label: 'Activity', icon: Activity },
                    { key: 'documents', label: 'Documents', icon: FileText },
                ]}
                activeTab="medical"
                onTab={(k) => k !== 'medical' && soon(k)}
                ariaLabel="Profile sections"
                testIdPrefix="p01-client"
                renderLink={tabsRender((k) => k !== 'medical' && soon(k))}
            />
            <DesignNote title="Reference frame — the client profile keeps its design">
                <p>Only “Record dose” changes: it opens the shared recording steps (the same dialog as Meds today), locked to Aroha. The profile’s own recording dialog (emar-dialog) is retired, so the profile no longer records with its own reason list, its own time field, no scheduled time or without the amount.</p>
            </DesignNote>
            <Card id="p01-mar-card" tabIndex={-1} className="gap-4 p-5 outline-none focus-visible:ring-2 focus-visible:ring-ring">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <h2 className="text-section-title">MAR · today</h2>
                    <div className="flex gap-2">
                        <Button variant="outline" onClick={() => s.go('/emar/mar', { client: 'aroha' })}>
                            <ClipboardList className="size-4" /> Open medication record
                        </Button>
                        <Button onClick={() => open('dose-pick:aroha')}>
                            <Pill className="size-4" /> Record dose
                        </Button>
                    </div>
                </div>
                <AllergyNotice pid="aroha" compact />
                <ListCaption title="Due or late now" caption={`${due.length} shown`} />
                <ul className="divide-y rounded-lg border">
                    {due.map((d) => (
                        <li key={d.id} className="flex flex-wrap items-center justify-between gap-3 px-3 py-2.5 text-sm">
                            <span>
                                <strong>{d.slot}</strong> · {d.med} {d.strength}
                            </span>
                            <DoseStateCell d={d} />
                        </li>
                    ))}
                </ul>
            </Card>
        </Shell>
    );
}

/* ───────────── Fleet transport — Record (parity; was “Administer”) ───────────── */
export function TransportPage() {
    const s = useStore();
    const menu = useDoseMenu();
    const ctx = useRowContext();
    const d = doseById('r2');
    const open = useOpen();
    const linkCls =
        'inline-flex h-[30px] items-center gap-1.5 rounded-lg border border-primary-foreground/25 bg-primary-foreground/10 px-2.5 text-[12px] font-semibold text-primary-foreground transition-colors hover:bg-primary-foreground/20';
    return (
        <Shell crumbs={[{ title: 'Home', href: '/dashboard' }, { title: 'Fleet & Assets', href: '/fleet-assets' }, { title: 'Transport Logs', href: '/fleet-assets/transports' }, { title: 'Transport #12' }]}>
            {/* Fleet's own header, copied from origin/main transports/show.tsx (cbd9b3ccf, 75d5f46b8). */}
            <PageHeader
                wrapTitle
                variant="profile"
                icon={Car}
                title="Transport #12"
                backHref="/fleet-assets/transports"
                titleChip={<PageHeaderStatusChip variant="info">in progress</PageHeaderStatusChip>}
                subline="Tama Walker · appointment"
                actions={
                    <>
                        <Link href={hrefFor('/fleet-assets/transports/12', {}, s.route)} className={linkCls}>
                            <Pill className="h-3.5 w-3.5" />
                            Medication Transit
                        </Link>
                        <Link href={hrefFor('/fleet-assets/transports/12/pre-check', {}, s.route)} className={linkCls}>
                            <ClipboardCheck className="h-3.5 w-3.5" />
                            Pre-Transport Check
                        </Link>
                    </>
                }
            />
            <DesignNote title="Reference frame — the transport record is Fleet’s">
                <p>The header is Fleet’s own, copied from the migrated transport page on main (PageHeader, profile variant). P01 changes only how a carried dose is recorded: the row’s action opens the same recording steps as Meds today, with the transport locked as context and the pack check kept (today’s scan check). It records against the scheduled 8:00 am dose, so there is one record wherever it’s made — once it has an outcome here, Meds today shows it too, and vice versa. Outcomes are no longer “given” only: a refusal or withhold can be recorded on the trip, with the time and amount given. The button says “Record”, like every other entry point (Fleet may keep “Administer” — README Q9).</p>
            </DesignNote>
            <section id="p01-carried" tabIndex={-1} className="flex flex-col gap-2.5 rounded-[14px] outline-none focus-visible:ring-2 focus-visible:ring-ring" aria-label="Medicines carried">
                <ListCaption
                    title="Medicines carried"
                    caption="1 of 1 shown · Kōwhai van · left 8:15 am · driver Priya Shah"
                    right={
                        <>
                            <Button variant="outline" size="sm" onClick={() => s.go('/emar/mar', { client: 'tama' })}>
                                <ClipboardList className="size-4" /> Tama’s medication record
                            </Button>
                            <Button variant="outline" size="sm" onClick={() => s.go('/meds/today')}>
                                <Pill className="size-4" /> Meds today
                            </Button>
                        </>
                    }
                />
                <EntityTable<Dose>
                    rows={[d]}
                    rowKey={(x) => x.id}
                    rowHeight="content"
                    minWidth={900}
                    identityLabel="Person"
                    identity={(x) => ({ mark: <PersonMark pid={x.pid} size={30} />, name: PEOPLE[x.pid].pref, subline: PEOPLE[x.pid].surname })}
                    columns={[
                        { key: 'm', label: 'Medicine', width: '1.6fr', cell: (x) => <MedicineCell d={x} /> },
                        { key: 'p', label: 'Packed', width: '1fr', cell: () => <span className="text-[12.5px]">8:05 am by Priya Shah</span> },
                        { key: 's', label: 'State', width: '1.8fr', cell: (x) => <DoseStateCell d={x} /> },
                        { key: 'a', label: '', width: '160px', align: 'right', cell: (x) => <DoseActionCell d={x} from="transport" /> },
                    ]}
                    actionsFor={(x) => menu(x, 'transport')}
                    onOpen={(x) => (['due', 'late'].includes(s.stateOf(x)) ? open(`record:${x.id}`, { from: 'transport' }) : open(`detail:${x.id}`))}
                    onRowContextMenu={(e, x) => ctx.openAt(e, `${PEOPLE[x.pid].pref} · ${x.med}`, menu(x, 'transport'))}
                />
                <p className="text-caption">Controlled medicines carried on a transport keep their witness step, the same as at the house.</p>
            </section>
            {ctx.node}
        </Shell>
    );
}

/* Fleet's pre-transport check is unchanged — the header link lands on a boundary note. */
export function TransportPreCheckPage() {
    return (
        <Shell crumbs={[{ title: 'Home', href: '/dashboard' }, { title: 'Fleet & Assets', href: '/fleet-assets' }, { title: 'Transport Logs', href: '/fleet-assets/transports' }, { title: 'Transport #12', href: '/fleet-assets/transports/12' }, { title: 'Pre-transport check' }]}>
            <Card className="gap-2 p-6">
                <h1 className="text-section-title">The pre-transport check is Fleet’s and unchanged</h1>
                <p className="text-subtle">Outside P01. The app keeps today’s screen.</p>
            </Card>
        </Shell>
    );
}
