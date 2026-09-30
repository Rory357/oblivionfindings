/* Reports & audit › Standard reports (Main, Q2–Q6, Q10). Eight fixed reports,
 * each with its own meters and one table, every number read from the one
 * dose-slot projection and defined once (model.ts DEFS, the contract page).
 * A zero denominator reads “Not applicable”, with why. Rows open the person’s
 * MAR for the period (P02); ⋯, right-click and the menu key give the same menu. */
import { PersonDisc } from '@/components/lists/entity-cells';
import { compactMenu, type MenuItem } from '@/components/lists/entity-menu';
import { EntityTable } from '@/components/lists/entity-table';
import { ListCaption } from '@/components/lists/list-caption';
import { PageHeaderMeterBig, PageHeaderMeterBlock, PageHeaderMeterCaption } from '@/components/page/page-header';
import { Card } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { StatusBadge } from '@/components/ui/status-badge';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { AlertOctagon, ClipboardList, Download, FileText, LockKeyhole, Package, Pill, Repeat, Stethoscope, UserCheck, UserRound, type LucideIcon } from 'lucide-react';
import { type MouseEvent, type ReactNode } from 'react';
import { HOUSES, HARM_LABEL, PEOPLE, TYPE_LABEL, UNIT_COST, orderOf, type MedError, type PersonId, type PersonaId, type StaffStatus } from '../data';
import {
    ROUND_LABEL,
    addDays,
    canAudit,
    canExportKind,
    cdIn,
    cdView,
    daysLeft,
    daysIn,
    daysUntil,
    errorsIn,
    labelIso,
    pct,
    prnIn,
    redactedErr,
    reviewsIn,
    roundsIn,
    slotsIn,
    staffIn,
    stockLines,
    stockOnly,
    totals,
    TODAY_ISO,
    type Period,
    type RoundName,
    type RoundResult,
    type Runtime,
    type Tone,
} from '../model';
import { StateLine, Wrap } from '../ui';
import { useCtx } from './hub';

export type ReportKey = 'doses' | 'rounds' | 'prn' | 'controlled' | 'errors' | 'reviews' | 'stock' | 'competency';
export const REPORTS: { key: ReportKey; label: string; icon: LucideIcon }[] = [
    { key: 'doses', label: 'Doses', icon: Pill },
    { key: 'rounds', label: 'Rounds', icon: Repeat },
    { key: 'prn', label: 'As needed', icon: ClipboardList },
    { key: 'controlled', label: 'Controlled medicines', icon: LockKeyhole },
    { key: 'errors', label: 'Medication errors', icon: AlertOctagon },
    { key: 'reviews', label: 'Reviews', icon: Stethoscope },
    { key: 'stock', label: 'Stock', icon: Package },
    { key: 'competency', label: 'Competency', icon: UserCheck },
];
export interface Ctx {
    p: PersonaId;
    period: Period;
    pids: PersonId[];
    rt: Runtime;
    dash: string | null;
    open: (spec: string) => void;
    toast: (msg: string) => void;
    go: (path: string, params?: Record<string, string | undefined>) => void;
    sac: boolean;
    scenario: string;
    /** The current route with these params changed, as a path the Inertia shim can visit. */
    href: (params: Record<string, string | undefined>) => string;
}
export interface Built {
    meters: ReactNode;
    body: ReactNode;
}

/* ───────────── shared pieces ───────────── */
const NA = 'Not applicable';
function Meter({ label, value, caption, tone, onClick, aria }: { label: string; value: ReactNode; caption: ReactNode; tone?: 'critical' | 'warning' | 'brand'; onClick?: () => void; aria: string }) {
    return (
        <PageHeaderMeterBlock label={label} tone={tone ?? 'brand'} onClick={onClick} ariaLabel={aria}>
            <PageHeaderMeterBig>{value}</PageHeaderMeterBig>
            <PageHeaderMeterCaption>{caption}</PageHeaderMeterCaption>
        </PageHeaderMeterBlock>
    );
}
/** A rate, or “Not applicable” with the reason (Q4). */
function Rate({ n, d, reason }: { n: number; d: number; reason: string }) {
    const v = pct(n, d);
    return v ? (
        <span className="text-[12.5px] font-semibold tabular-nums">{v}</span>
    ) : (
        <span className="flex flex-col items-start gap-0.5">
            <StatusBadge variant="neutral" className="rounded-[8px]">
                {NA}
            </StatusBadge>
            <StateLine>{reason}</StateLine>
        </span>
    );
}
const Num = ({ n, tone }: { n: number; tone?: 'critical' | 'warning' }) => <span className={`text-[12.5px] tabular-nums ${n && tone === 'critical' ? 'font-semibold text-status-critical' : n && tone === 'warning' ? 'font-semibold text-status-warning' : ''}`}>{n}</span>;
const stop = (fn: () => void) => (e: MouseEvent) => (e.stopPropagation(), fn());
function Section({ title, caption, children, label, note }: { title: string; caption: string; children: ReactNode; label?: string; note?: string }) {
    return (
        <section className="flex flex-col gap-2.5" aria-label={label ?? title}>
            <ListCaption title={title} caption={caption} />
            {children}
            {note ? <p className="text-caption">{note}</p> : null}
        </section>
    );
}
const emptyCard = (icon: LucideIcon, title: string, text: string) => (
    <Card className="p-2">
        <EmptyState variant="compact" icon={icon} title={title} description={text} />
    </Card>
);
/** Weeks (Monday–Sunday, NZ) that overlap the period. */
function weeksOf(p: Period) {
    const out: { from: string; to: string }[] = [];
    const first = daysIn(p.from, p.from)[0];
    const d0 = new Date(Date.UTC(+first.slice(0, 4), +first.slice(5, 7) - 1, +first.slice(8, 10)));
    let mon = addDays(first, -((d0.getUTCDay() + 6) % 7));
    while (mon <= p.to) {
        out.push({ from: mon < p.from ? p.from : mon, to: addDays(mon, 6) > p.to ? p.to : addDays(mon, 6) });
        mon = addDays(mon, 7);
    }
    return out;
}
const personMenu = (c: Ctx, pid: PersonId, extra: (MenuItem | false)[] = []): MenuItem[] =>
    compactMenu([
        { label: `Open ${PEOPLE[pid].pref}’s MAR for this period`, icon: FileText, onClick: () => c.toast(`Opens ${PEOPLE[pid].pref}’s MAR, ${c.period.text} (P02) — outside this preview.`) },
        ...extra,
        canExportKind(c.p, 'mar') && { label: `Make ${PEOPLE[pid].pref}’s MAR (PDF)`, icon: Download, onClick: () => c.open(`export:mar:${pid}`) },
    ]);

/* ═════════════ Doses (Q2, Q4, Q6) ═════════════ */
export function doses(c: Ctx, ctxMenu: ReturnType<typeof useCtx>): Built {
    const { counted, dueNow } = slotsIn(c.period.from, c.period.to, c.pids);
    const t = totals(counted);
    const rows = c.pids.map((pid) => ({ pid, t: totals(counted.filter((s) => s.pid === pid)) }));
    const reasonFor = (pid: PersonId) => (counted.some((s) => s.pid === pid) ? '' : PEOPLE[pid].id === 'hemi' ? 'Only as-needed medicines' : 'No dose has reached the end of its window yet');
    const gap = (pid: PersonId) => (canAudit(c.p) ? { label: 'Open their unrecorded doses', icon: AlertOctagon, onClick: () => c.go('/emar/reports', { view: 'audit', sub: 'gaps', person: pid, open: undefined }) } : false);
    const menu = (r: (typeof rows)[number]) => personMenu(c, r.pid, [r.t.notRecorded > 0 && gap(r.pid)]);
    const meters = (
        <>
            <Meter label="Given as due" value={c.dash ?? pct(t.given, t.due) ?? NA} caption={c.dash ? '—' : t.due ? `${t.given} of ${t.due} doses due` : 'No doses were due'} aria={`Given as due: ${pct(t.given, t.due) ?? NA}`} />
            <Meter label="Refused" value={c.dash ?? t.refused} caption={c.dash ? '—' : 'By the person'} aria={`${t.refused} doses refused`} />
            <Meter label="Withheld" value={c.dash ?? t.withheld} caption={c.dash ? '—' : 'For a clinical reason'} aria={`${t.withheld} doses withheld`} />
            <Meter label="Missed" value={c.dash ?? t.missed} tone={!c.dash && t.missed ? 'warning' : 'brand'} caption={c.dash ? '—' : 'Reported as an error'} aria={`${t.missed} doses missed`} />
            <Meter label="Not recorded" value={c.dash ?? t.notRecorded} tone={!c.dash && t.notRecorded ? 'critical' : 'brand'} caption={c.dash ? '—' : t.notRecorded ? 'On Unrecorded doses' : 'None'} onClick={canAudit(c.p) ? () => c.go('/emar/reports', { view: 'audit', sub: 'gaps', open: undefined }) : undefined} aria={`${t.notRecorded} doses not recorded`} />
        </>
    );
    const weeks = weeksOf(c.period).map((w) => ({ ...w, t: totals(counted.filter((s) => s.day >= w.from && s.day <= w.to)) }));
    const body = (
        <>
            <Section title="By person" caption={`${rows.length} shown · ${c.period.text}`} note={`Totals include controlled medicines, for everyone.${dueNow ? ` ${dueNow} ${dueNow === 1 ? 'dose is' : 'doses are'} inside ${dueNow === 1 ? 'its' : 'their'} 60-minute window now and ${dueNow === 1 ? 'isn’t' : 'aren’t'} counted yet.` : ''}`}>
                <EntityTable<(typeof rows)[number]>
                    rows={rows}
                    rowKey={(r) => r.pid}
                    rowHeight="content"
                    minWidth={980}
                    identityLabel="Person"
                    identityWidth="1.6fr"
                    identity={(r) => ({ mark: <PersonDisc name={PEOPLE[r.pid].legal} size={30} />, name: PEOPLE[r.pid].legal, subline: HOUSES[PEOPLE[r.pid].house] })}
                    columns={[
                        { key: 'due', label: 'Due', width: '0.6fr', align: 'right', cell: (r) => <Num n={r.t.due} /> },
                        { key: 'given', label: 'Given', width: '0.6fr', align: 'right', cell: (r) => <Num n={r.t.given} /> },
                        { key: 'refused', label: 'Refused', width: '0.7fr', align: 'right', cell: (r) => <Num n={r.t.refused} /> },
                        { key: 'withheld', label: 'Withheld', width: '0.7fr', align: 'right', cell: (r) => <Num n={r.t.withheld} /> },
                        { key: 'missed', label: 'Missed', width: '0.6fr', align: 'right', cell: (r) => <Num n={r.t.missed} tone="warning" /> },
                        { key: 'nr', label: 'Not recorded', width: '0.8fr', align: 'right', cell: (r) => <Num n={r.t.notRecorded} tone="critical" /> },
                        { key: 'rate', label: 'Given as due', width: '1.3fr', cell: (r) => <Rate n={r.t.given} d={r.t.due} reason={reasonFor(r.pid)} /> },
                    ]}
                    footerRows={[{ key: 'all', label: 'Everyone shown', tone: 'strong', cells: { due: t.due, given: t.given, refused: t.refused, withheld: t.withheld, missed: t.missed, nr: t.notRecorded, rate: pct(t.given, t.due) ?? NA } }]}
                    actionsFor={menu}
                    onOpen={(r) => c.toast(`Opens ${PEOPLE[r.pid].pref}’s MAR, ${c.period.text} (P02) — outside this preview.`)}
                    onRowContextMenu={(e, r) => ctxMenu.openAt(e, PEOPLE[r.pid].legal, menu(r))}
                />
            </Section>
            <Section title="Each week" caption="Monday to Sunday · by when each dose was due">
                <Card className="p-0">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Week</TableHead>
                                <TableHead className="text-right">Due</TableHead>
                                <TableHead className="text-right">Given</TableHead>
                                <TableHead className="text-right">Not recorded</TableHead>
                                <TableHead className="text-right">Given as due</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {weeks.map((w) => (
                                <TableRow key={w.from}>
                                    <TableCell>{w.from === w.to ? labelIso(w.from) : `${labelIso(w.from)} – ${labelIso(w.to)}`}</TableCell>
                                    <TableCell className="text-right tabular-nums">{w.t.due}</TableCell>
                                    <TableCell className="text-right tabular-nums">{w.t.given}</TableCell>
                                    <TableCell className="text-right tabular-nums">{w.t.notRecorded}</TableCell>
                                    <TableCell className="text-right tabular-nums">{pct(w.t.given, w.t.due) ?? NA}</TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </Card>
            </Section>
            {ctxMenu.node}
        </>
    );
    return { meters, body };
}

/* ═════════════ Rounds (Q3, NF-15) ═════════════ */
export function rounds(c: Ctx, ctxMenu: ReturnType<typeof useCtx>): Built {
    const { rows, notEnded } = roundsIn(c.period.from, c.period.to, c.pids);
    const n = (r: RoundResult, list = rows) => list.filter((x) => x.result === r).length;
    type G = { key: string; house: keyof typeof HOUSES; name: RoundName; list: typeof rows };
    const groups: G[] = [];
    for (const r of rows) {
        const key = `${r.house}|${r.name}`;
        const g = groups.find((x) => x.key === key);
        if (g) g.list.push(r);
        else groups.push({ key, house: r.house, name: r.name, list: [r] });
    }
    const order: RoundName[] = ['Morning', 'Midday', 'Afternoon', 'Evening'];
    groups.sort((a, b) => a.house.localeCompare(b.house) || order.indexOf(a.name) - order.indexOf(b.name));
    const problems = rows.filter((r) => r.result === 'notCompleted' || r.result === 'notStarted').sort((a, b) => b.day.localeCompare(a.day));
    const menu = (g: G) => compactMenu([canExportKind(c.p, 'round') && { label: `Make a round sheet — ${HOUSES[g.house]}`, icon: Download, onClick: () => c.open(`export:round:${g.house}`) }, canAudit(c.p) && { label: 'Open unrecorded doses', icon: AlertOctagon, onClick: () => c.go('/emar/reports', { view: 'audit', sub: 'gaps', open: undefined }) }, { label: 'Open the Doses report', icon: Pill, onClick: () => c.go('/emar/reports', { view: 'reports', sub: 'doses', open: undefined }) }]);
    const naReason = notEnded ? 'No round has ended yet' : 'No round in the period';
    const meters = (
        <>
            <Meter label="On time" value={c.dash ?? pct(n('onTime'), rows.length) ?? NA} caption={c.dash ? '—' : rows.length ? `${n('onTime')} of ${rows.length} rounds ended` : naReason} aria={`Rounds on time: ${pct(n('onTime'), rows.length) ?? NA}`} />
            <Meter label="Late" value={c.dash ?? n('late')} tone={!c.dash && n('late') ? 'warning' : 'brand'} caption={c.dash ? '—' : 'Recorded late'} aria={`${n('late')} rounds late`} />
            <Meter label="Not completed" value={c.dash ?? n('notCompleted')} tone={!c.dash && n('notCompleted') ? 'critical' : 'brand'} caption={c.dash ? '—' : 'A dose left unrecorded'} aria={`${n('notCompleted')} rounds not completed`} />
            <Meter label="Not started" value={c.dash ?? n('notStarted')} tone={!c.dash && n('notStarted') ? 'critical' : 'brand'} caption={c.dash ? '—' : 'No dose recorded'} aria={`${n('notStarted')} rounds not started`} />
        </>
    );
    const body = (
        <>
            <Section title="By round" caption={`${groups.length} shown · rounds whose window has ended`} note={`Worked out from each round’s doses, in NZ time.${notEnded ? ` ${notEnded} ${notEnded === 1 ? 'round is' : 'rounds are'} still open today and not counted yet — the morning round’s window ends at 10:00 am.` : ''}`}>
                {groups.length ? (
                    <EntityTable<G>
                        rows={groups}
                        rowKey={(g) => g.key}
                        rowHeight="content"
                        minWidth={940}
                        identityLabel="Round"
                        identityWidth="1.5fr"
                        identity={(g) => ({ icon: Repeat, name: `${g.name} round`, subline: HOUSES[g.house] })}
                        columns={[
                            { key: 'ended', label: 'Ended', width: '0.6fr', align: 'right', cell: (g) => <Num n={g.list.length} /> },
                            { key: 'on', label: 'On time', width: '0.7fr', align: 'right', cell: (g) => <Num n={n('onTime', g.list)} /> },
                            { key: 'late', label: 'Late', width: '0.6fr', align: 'right', cell: (g) => <Num n={n('late', g.list)} tone="warning" /> },
                            { key: 'nc', label: 'Not completed', width: '0.9fr', align: 'right', cell: (g) => <Num n={n('notCompleted', g.list)} tone="critical" /> },
                            { key: 'ns', label: 'Not started', width: '0.8fr', align: 'right', cell: (g) => <Num n={n('notStarted', g.list)} tone="critical" /> },
                            { key: 'rate', label: 'On time', width: '1fr', cell: (g) => <Rate n={n('onTime', g.list)} d={g.list.length} reason="No round ended" /> },
                        ]}
                        actionsFor={menu}
                        onOpen={(g) => (canExportKind(c.p, 'round') ? c.open(`export:round:${g.house}`) : c.toast(`${g.name} round, ${HOUSES[g.house]} — each day’s round sheet is in Print & exports.`))}
                        onRowContextMenu={(e, g) => ctxMenu.openAt(e, `${g.name} round · ${HOUSES[g.house]}`, menu(g))}
                    />
                ) : (
                    emptyCard(Repeat, NA, `${naReason}.`)
                )}
            </Section>
            <Section title="Rounds not completed or not started" caption={`${problems.length} shown · newest first`}>
                {problems.length ? (
                    <Card className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Day</TableHead>
                                    <TableHead>Round</TableHead>
                                    <TableHead>Result</TableHead>
                                    <TableHead className="text-right">Doses in it</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {problems.map((r) => (
                                    <TableRow key={`${r.house}${r.day}${r.name}`}>
                                        <TableCell>{labelIso(r.day)}</TableCell>
                                        <TableCell>
                                            {r.name} · {HOUSES[r.house]}
                                        </TableCell>
                                        <TableCell>
                                            <StatusBadge variant="critical" className="rounded-[8px]">
                                                {ROUND_LABEL[r.result]}
                                            </StatusBadge>
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">{r.slots}</TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </Card>
                ) : (
                    rows.length ? emptyCard(Repeat, 'Every round that ended was completed', 'A round is not completed when its window ends with a dose unrecorded.') : emptyCard(Repeat, NA, 'No round has ended yet in this period.')
                )}
            </Section>
            {ctxMenu.node}
        </>
    );
    return { meters, body };
}

/* ═════════════ As needed (PRN) ═════════════ */
export function prn(c: Ctx, ctxMenu: ReturnType<typeof useCtx>): Built {
    const list = prnIn(c.period, c.pids).sort((a, b) => b.day.localeCompare(a.day));
    const withEffect = list.filter((d) => d.effect).length;
    const people = new Set(list.map((d) => orderOf(d.orderId).pid)).size;
    type R = (typeof list)[number];
    const hide = (r: R) => !!orderOf(r.orderId).cd && !cdView(c.p);
    const menu = (r: R) => personMenu(c, orderOf(r.orderId).pid);
    const meters = (
        <>
            <Meter label="As-needed doses given" value={c.dash ?? list.length} caption={c.dash ? '—' : `${people} ${people === 1 ? 'person' : 'people'}`} aria={`${list.length} as-needed doses given`} />
            <Meter label="Effect recorded" value={c.dash ?? pct(withEffect, list.length) ?? NA} tone={!c.dash && list.length && withEffect < list.length ? 'warning' : 'brand'} caption={c.dash ? '—' : list.length ? `${withEffect} of ${list.length}` : 'No as-needed dose given'} aria={`Effect recorded: ${pct(withEffect, list.length) ?? NA}`} />
        </>
    );
    const body = (
        <>
            <Section title="As-needed doses" caption={`${list.length} shown · newest first · ${c.period.text}`}>
                {list.length ? (
                    <EntityTable<R>
                        rows={list}
                        rowKey={(r) => `${r.orderId}${r.day}`}
                        rowHeight="content"
                        minWidth={900}
                        identityLabel="Medicine"
                        identityWidth="1.5fr"
                        identity={(r) => ({ icon: hide(r) ? LockKeyhole : Pill, name: hide(r) ? 'Controlled medicine' : orderOf(r.orderId).med, subline: <Wrap>{PEOPLE[orderOf(r.orderId).pid].legal} · {HOUSES[PEOPLE[orderOf(r.orderId).pid].house]}</Wrap> })}
                        columns={[
                            { key: 'when', label: 'Given', width: '1fr', cell: (r) => <span className="text-[12.5px]">{labelIso(r.day)}, {r.at}</span> },
                            { key: 'by', label: 'By', width: '0.9fr', cell: (r) => <span className="text-[12.5px]">{r.by}</span> },
                            { key: 'effect', label: 'Effect', width: '1.4fr', cell: (r) => (r.effect ? <span className="text-[12.5px]">{hide(r) ? 'Recorded' : r.effect}</span> : <StatusBadge variant="warning" className="rounded-[8px]">Not recorded yet</StatusBadge>) },
                        ]}
                        actionsFor={menu}
                        onOpen={(r) => c.toast(`Opens ${PEOPLE[orderOf(r.orderId).pid].pref}’s MAR at this dose (P02) — outside this preview.`)}
                        onRowContextMenu={(e, r) => ctxMenu.openAt(e, `${hide(r) ? 'Controlled medicine' : orderOf(r.orderId).med} · ${PEOPLE[orderOf(r.orderId).pid].pref}`, menu(r))}
                    />
                ) : (
                    emptyCard(ClipboardList, 'No as-needed doses given', 'As-needed doses appear here with their effect once recorded.')
                )}
            </Section>
            {ctxMenu.node}
        </>
    );
    return { meters, body };
}

/* ═════════════ Controlled medicines (Q6 — needs controlled-medicine access) ═════════════ */
export function controlled(c: Ctx, ctxMenu: ReturnType<typeof useCtx>): Built {
    if (!cdView(c.p))
        return {
            meters: undefined,
            body: (
                <Card className="items-center gap-3 p-10 text-center">
                    <LockKeyhole className="size-8 text-muted-foreground" aria-hidden="true" />
                    <h2 className="text-section-title">This report needs controlled-medicine access</h2>
                    <p className="text-subtle">Controlled doses are included in every other report’s totals. The breakdown by medicine is for people with controlled-medicine access.</p>
                </Card>
            ),
        };
    const x = cdIn(c.period, c.pids);
    type R = (typeof x.byOrder)[number];
    const menu = (r: R) => compactMenu([{ label: `Open ${PEOPLE[r.order.pid].pref}’s MAR for this period`, icon: FileText, onClick: () => c.toast(`Opens ${PEOPLE[r.order.pid].pref}’s MAR (P02) — outside this preview.`) }, canExportKind(c.p, 'cdreg') && { label: 'Make its register (PDF)', icon: Download, onClick: () => c.open(`export:cdreg:${r.order.id}`) }]);
    const openD = x.discrepancies.filter((d) => d.state === 'open').length;
    const meters = (
        <>
            <Meter label="Controlled doses given" value={c.dash ?? x.given} caption={c.dash ? '—' : 'Scheduled and as needed'} aria={`${x.given} controlled doses given`} />
            <Meter label="Witnessed" value={c.dash ?? pct(x.witnessed, x.given) ?? NA} caption={c.dash ? '—' : x.given ? `${x.witnessed} of ${x.given}` : 'No controlled dose given'} aria={`Witnessed: ${pct(x.witnessed, x.given) ?? NA}`} />
            <Meter label="Counts done" value={c.dash ?? x.counts} caption={c.dash ? '—' : 'Twice a day'} aria={`${x.counts} counts done`} />
            <Meter label="Discrepancies" value={c.dash ?? x.discrepancies.length} tone={!c.dash && openD ? 'critical' : 'brand'} caption={c.dash ? '—' : openD ? `${openD} still open` : 'None open'} aria={`${x.discrepancies.length} discrepancies`} />
            <Meter label="Losses" value={c.dash ?? x.losses.length} tone={!c.dash && x.losses.length ? 'warning' : 'brand'} caption={c.dash ? '—' : `${x.destructions.length} destroyed`} aria={`${x.losses.length} losses`} />
        </>
    );
    const events = [...x.discrepancies.map((d) => ({ id: d.id, kind: 'Discrepancy', day: d.day, orderId: d.orderId, what: d.what, state: d.state === 'open' ? 'Open' : 'Closed' })), ...x.losses.map((d) => ({ id: d.id, kind: 'Loss', day: d.day, orderId: d.orderId, what: d.what, state: 'Waiting for a manager' })), ...x.destructions.map((d) => ({ id: d.id, kind: 'Destruction', day: d.day, orderId: d.orderId, what: d.what, state: 'Done' }))].sort((a, b) => b.day.localeCompare(a.day));
    const body = (
        <>
            <Section title="By medicine" caption={`${x.byOrder.length} shown · ${c.period.text}`}>
                <EntityTable<R>
                    rows={x.byOrder}
                    rowKey={(r) => r.order.id}
                    rowHeight="content"
                    minWidth={860}
                    identityLabel="Medicine"
                    identityWidth="1.6fr"
                    identity={(r) => ({ icon: LockKeyhole, name: r.order.med, subline: <Wrap>{r.order.strength} · {PEOPLE[r.order.pid].legal} · {HOUSES[PEOPLE[r.order.pid].house]}</Wrap> })}
                    columns={[
                        { key: 'given', label: 'Doses given', width: '0.8fr', align: 'right', cell: (r) => <Num n={r.given} /> },
                        { key: 'wit', label: 'Witnessed', width: '0.8fr', align: 'right', cell: (r) => <Rate n={r.given} d={r.given} reason="None given" /> },
                        { key: 'kind', label: 'Given', width: '0.8fr', cell: (r) => <span className="text-[12.5px]">{r.order.prn ? 'As needed' : 'Scheduled'}</span> },
                    ]}
                    actionsFor={menu}
                    onOpen={(r) => (canExportKind(c.p, 'cdreg') ? c.open(`export:cdreg:${r.order.id}`) : c.toast(`Opens the register for ${r.order.med} (P07b) — outside this preview.`))}
                    onRowContextMenu={(e, r) => ctxMenu.openAt(e, `${r.order.med} · ${PEOPLE[r.order.pid].pref}`, menu(r))}
                />
            </Section>
            <Section title="Discrepancies, losses and destructions" caption={`${events.length} shown · newest first`}>
                {events.length ? (
                    <Card className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>What</TableHead>
                                    <TableHead>Medicine</TableHead>
                                    <TableHead>Day</TableHead>
                                    <TableHead>Where it’s at</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {events.map((ev) => (
                                    <TableRow key={ev.id}>
                                        <TableCell>
                                            <span className="font-medium">
                                                {ev.kind} {ev.id}
                                            </span>
                                            <span className="block text-caption">{ev.what}</span>
                                        </TableCell>
                                        <TableCell>
                                            {orderOf(ev.orderId).med} · {PEOPLE[orderOf(ev.orderId).pid].pref}
                                        </TableCell>
                                        <TableCell>{labelIso(ev.day)}</TableCell>
                                        <TableCell>
                                            <StatusBadge variant={ev.state === 'Open' ? 'critical' : ev.state.startsWith('Waiting') ? 'warning' : 'neutral'} className="rounded-[8px]">
                                                {ev.state}
                                            </StatusBadge>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </Card>
                ) : (
                    emptyCard(LockKeyhole, 'No discrepancies, losses or destructions', 'They appear here from the controlled register (P07b).')
                )}
            </Section>
            {ctxMenu.node}
        </>
    );
    return { meters, body };
}

/* ═════════════ Medication errors (P08b’s numbers; Q10 governance counts; Q11 SAC) ═════════════ */
const harmTone = (e: MedError): { label: string; variant: Tone } =>
    e.reached === 'no' ? { label: 'Near miss', variant: 'info' } : e.harm === 'severe' || e.harm === 'death' ? { label: HARM_LABEL[e.harm], variant: 'critical' } : e.harm === 'moderate' || e.harm === 'minor' ? { label: HARM_LABEL[e.harm], variant: 'warning' } : { label: e.harm === 'unknown' ? 'Harm not known yet' : 'Reached · no harm', variant: 'neutral' };
const STAGE: Record<MedError['stage'], string> = { triage: 'To triage', investigating: 'Investigating', actions: 'Actions', closed: 'Closed' };
export function errors(c: Ctx, ctxMenu: ReturnType<typeof useCtx>): Built {
    const list = errorsIn(c.rt, c.period, c.pids).sort((a, b) => b.occurredIso.localeCompare(a.occurredIso));
    const reached = list.filter((e) => e.reached !== 'no');
    const harm = reached.filter((e) => ['minor', 'moderate', 'severe', 'death'].includes(e.harm));
    const near = list.filter((e) => e.reached === 'no');
    const closed = list.filter((e) => e.stage === 'closed');
    const menu = (e: MedError) => compactMenu([{ label: `Open ${e.id} in Medication errors`, icon: AlertOctagon, onClick: () => c.toast(`Opens ${e.id} in Safety & oversight › Medication errors (P08b) — outside this preview.`) }, { separator: true }, { label: `Open ${PEOPLE[e.pid].pref}’s MAR for this period`, icon: FileText, onClick: () => c.toast(`Opens ${PEOPLE[e.pid].pref}’s MAR (P02) — outside this preview.`) }]);
    const meters = (
        <>
            <Meter label="Reached the person" value={c.dash ?? reached.length} caption={c.dash ? '—' : `${harm.length} with harm`} aria={`${reached.length} errors reached the person`} tone={!c.dash && harm.some((e) => ['severe', 'death'].includes(e.harm)) ? 'critical' : !c.dash && harm.length ? 'warning' : 'brand'} />
            <Meter label="Near misses" value={c.dash ?? near.length} caption={c.dash ? '—' : 'Didn’t reach the person'} aria={`${near.length} near misses`} />
            <Meter label="Closed" value={c.dash ?? closed.length} caption={c.dash ? '—' : `${list.length - closed.length} still open`} aria={`${closed.length} closed`} />
        </>
    );
    const types = (Object.keys(TYPE_LABEL) as (keyof typeof TYPE_LABEL)[]).map((k) => ({ k, n: list.filter((e) => e.type === k).length })).filter((x) => x.n);
    const sacText = (e: MedError) => (e.reached === 'no' ? 'No SAC — near miss' : e.closed?.sac ? `SAC ${e.closed.sac}` : e.stage === 'closed' ? 'Not rated' : 'Rated at close');
    const body = (
        <>
            <Section title="Governance counts" caption={`${c.period.text} · by when it happened`} note="The governance report reads these same numbers.">
                <div className="grid gap-3 md:grid-cols-2">
                    <Card className="gap-1 p-4">
                        <p className="text-caption">Medication errors that reached the person</p>
                        <p className="text-2xl font-semibold tabular-nums">{reached.length}</p>
                        <p className="text-caption">
                            Target: <StatusBadge variant="neutral" size="sm">Not configured</StatusBadge> · set by the organisation in Governance
                        </p>
                    </Card>
                    <Card className="gap-1 p-4">
                        <p className="text-caption">Near misses reported</p>
                        <p className="text-2xl font-semibold tabular-nums">{near.length}</p>
                        <p className="text-caption">No target — shown next to errors that reached the person, not held to one</p>
                    </Card>
                </div>
            </Section>
            <Section title="Errors" caption={`${list.length} shown · newest first`} note={c.sac ? 'SAC is as confirmed by the person who closed the error.' : undefined}>
                {list.length ? (
                    <EntityTable<MedError>
                        rows={list}
                        rowKey={(e) => e.id}
                        rowHeight="content"
                        minWidth={c.sac ? 1040 : 940}
                        identityLabel="Person"
                        identityWidth="1.4fr"
                        identity={(e) => ({ mark: <PersonDisc name={PEOPLE[e.pid].legal} size={30} />, name: PEOPLE[e.pid].legal, subline: <Wrap>{e.id} · {HOUSES[PEOPLE[e.pid].house]}</Wrap> })}
                        columns={[
                            {
                                key: 'what',
                                label: 'What happened',
                                width: '1.6fr',
                                cell: (e) => (
                                    <span className="flex flex-col gap-0.5 py-0.5 text-[12.5px]">
                                        <span className="font-semibold">{TYPE_LABEL[e.type]}</span>
                                        <StateLine icon={redactedErr(c.p, e) ? LockKeyhole : undefined}>{redactedErr(c.p, e) ? 'Controlled medicine' : e.orderIds.length ? e.orderIds.map((o) => orderOf(o).med).join(' and ') : 'Not about one medicine'}</StateLine>
                                        <StateLine>{e.occurred}</StateLine>
                                    </span>
                                ),
                            },
                            {
                                key: 'harm',
                                label: 'Reach & harm',
                                width: '1.2fr',
                                cell: (e) => (
                                    <StatusBadge variant={harmTone(e).variant} className="rounded-[8px]">
                                        {harmTone(e).label}
                                    </StatusBadge>
                                ),
                            },
                            { key: 'stage', label: 'Stage', width: '0.8fr', cell: (e) => <span className="text-[12.5px]">{STAGE[e.stage]}</span> },
                            ...(c.sac ? [{ key: 'sac', label: 'SAC', width: '0.9fr', cell: (e: MedError) => <span className={`text-[12.5px] ${e.closed?.sac ? 'font-semibold' : 'text-muted-foreground'}`}>{sacText(e)}</span> }] : []),
                        ]}
                        actionsFor={menu}
                        onOpen={(e) => c.toast(`Opens ${e.id} in Safety & oversight › Medication errors (P08b) — outside this preview.`)}
                        onRowContextMenu={(ev, e) => ctxMenu.openAt(ev, `${e.id} · ${PEOPLE[e.pid].pref}`, menu(e))}
                    />
                ) : (
                    emptyCard(AlertOctagon, 'No medication errors in this period', 'Errors are counted by when they happened, in NZ time.')
                )}
            </Section>
            {types.length ? (
                <Section title="What went wrong" caption="one reason per report" note="Controlled-medicine errors are counted, not named.">
                    <Card className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>What went wrong</TableHead>
                                    <TableHead className="text-right">Reports</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {types.map((x) => (
                                    <TableRow key={x.k}>
                                        <TableCell>{TYPE_LABEL[x.k]}</TableCell>
                                        <TableCell className="text-right tabular-nums">{x.n}</TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </Card>
                </Section>
            ) : null}
            {ctxMenu.node}
        </>
    );
    return { meters, body };
}

/* ═════════════ Reviews (P05) ═════════════ */
export function reviews(c: Ctx, ctxMenu: ReturnType<typeof useCtx>): Built {
    const all = reviewsIn(c.pids);
    const due = all.filter((r) => r.dueIso >= c.period.from && r.dueIso <= c.period.to);
    const done = all.filter((r) => r.doneIso && r.doneIso >= c.period.from && r.doneIso <= c.period.to);
    const overdue = all.filter((r) => r.state === 'booked' && daysUntil(r.dueIso) < 0);
    const waiting = all.reduce((n, r) => n + r.changesWaiting, 0);
    const list = [...new Set([...due, ...done, ...overdue])].sort((a, b) => a.dueIso.localeCompare(b.dueIso));
    type R = (typeof list)[number];
    const menu = (r: R) => personMenu(c, r.pid, [{ label: `Open review ${r.id}`, icon: Stethoscope, onClick: () => c.toast(`Opens ${r.id} in Orders & reviews › Medication reviews (P05) — outside this preview.`) }]);
    const meters = (
        <>
            <Meter label="Reviews due" value={c.dash ?? due.length} caption={c.dash ? '—' : c.period.text} aria={`${due.length} reviews due`} />
            <Meter label="Done" value={c.dash ?? done.length} caption={c.dash ? '—' : 'Recorded in the period'} aria={`${done.length} reviews done`} />
            <Meter label="Overdue now" value={c.dash ?? overdue.length} tone={!c.dash && overdue.length ? 'critical' : 'brand'} caption={c.dash ? '—' : overdue.length ? PEOPLE[overdue[0].pid].pref : 'None overdue'} aria={`${overdue.length} reviews overdue`} />
            <Meter label="Changes waiting" value={c.dash ?? waiting} tone={!c.dash && waiting ? 'warning' : 'brand'} caption={c.dash ? '—' : 'For the prescriber'} aria={`${waiting} changes waiting`} />
        </>
    );
    const body = (
        <>
            <Section title="Reviews" caption={`${list.length} shown · due, done or overdue in ${c.period.text}`}>
                {list.length ? (
                    <EntityTable<R>
                        rows={list}
                        rowKey={(r) => r.id}
                        rowHeight="content"
                        minWidth={860}
                        identityLabel="Person"
                        identityWidth="1.5fr"
                        identity={(r) => ({ mark: <PersonDisc name={PEOPLE[r.pid].legal} size={30} />, name: PEOPLE[r.pid].legal, subline: <Wrap>{r.id} · {r.kind}</Wrap> })}
                        columns={[
                            { key: 'due', label: 'Due', width: '0.9fr', cell: (r) => <span className="text-[12.5px]">{r.due}</span> },
                            { key: 'state', label: 'Where it’s at', width: '1.2fr', cell: (r) => (r.state === 'recorded' ? <StatusBadge variant="neutral" className="rounded-[8px]">Recorded {r.doneIso ? labelIso(r.doneIso) : ''}</StatusBadge> : daysUntil(r.dueIso) < 0 ? <StatusBadge variant="critical" className="rounded-[8px]">Overdue</StatusBadge> : <StatusBadge variant="info" className="rounded-[8px]">Booked</StatusBadge>) },
                            { key: 'ch', label: 'Changes waiting', width: '0.9fr', align: 'right', cell: (r) => <Num n={r.changesWaiting} tone="warning" /> },
                        ]}
                        actionsFor={menu}
                        onOpen={(r) => c.toast(`Opens ${r.id} in Orders & reviews › Medication reviews (P05) — outside this preview.`)}
                        onRowContextMenu={(e, r) => ctxMenu.openAt(e, `${r.id} · ${PEOPLE[r.pid].pref}`, menu(r))}
                    />
                ) : (
                    emptyCard(Stethoscope, 'No reviews due or done in this period', 'Medication reviews come from Orders & reviews (P05).')
                )}
            </Section>
            {ctxMenu.node}
        </>
    );
    return { meters, body };
}

/* ═════════════ Stock (P06; finance sees this one only, with no people — Q5) ═════════════ */
export function stock(c: Ctx, ctxMenu: ReturnType<typeof useCtx>): Built {
    const fin = stockOnly(c.p);
    const lines = stockLines(c.p).filter((l) => fin || c.pids.includes(orderOf(l.orderId).pid));
    type L = (typeof lines)[number];
    const below = lines.filter((l) => l.onHand <= l.reorderAt);
    const out7 = lines.filter((l) => daysLeft(l) !== null && daysLeft(l)! <= 7);
    const exp = lines.filter((l) => l.expiresIso <= addDays(TODAY_ISO, 30));
    const value = lines.reduce((n, l) => n + l.onHand * (UNIT_COST[l.orderId] ?? 0), 0);
    const menu = (l: L): MenuItem[] => compactMenu([{ label: 'Open in Stock & controlled drugs', icon: Package, onClick: () => c.toast(`Opens ${orderOf(l.orderId).med} in Stock & controlled drugs (P06) — outside this preview.`) }, canExportKind(c.p, 'stock') && { label: 'Make the stock CSV', icon: Download, onClick: () => c.open('export:stock') }]);
    const meters = (
        <>
            <Meter label="Stock lines" value={c.dash ?? lines.length} caption={c.dash ? '—' : fin ? `Worth $${value.toFixed(2)} on hand` : 'As at 9:12 am'} aria={`${lines.length} stock lines`} />
            <Meter label="Below reorder level" value={c.dash ?? below.length} tone={!c.dash && below.length ? 'warning' : 'brand'} caption={c.dash ? '—' : 'Order soon'} aria={`${below.length} below reorder level`} />
            <Meter label="Running out in 7 days" value={c.dash ?? out7.length} tone={!c.dash && out7.length ? 'critical' : 'brand'} caption={c.dash ? '—' : 'At today’s use'} aria={`${out7.length} running out in 7 days`} />
            <Meter label="Expiring in 30 days" value={c.dash ?? exp.length} tone={!c.dash && exp.length ? 'warning' : 'brand'} caption={c.dash ? '—' : 'Check and replace'} aria={`${exp.length} expiring in 30 days`} />
        </>
    );
    const status = (l: L): { label: string; variant: Tone } => (daysLeft(l) !== null && daysLeft(l)! <= 7 ? { label: 'Running out', variant: 'critical' } : l.onHand <= l.reorderAt ? { label: 'Below reorder level', variant: 'warning' } : l.expiresIso <= addDays(TODAY_ISO, 30) ? { label: 'Expiring soon', variant: 'warning' } : { label: 'OK', variant: 'neutral' });
    const body = (
        <>
            <Section title="Stock lines" caption={`${lines.length} shown · as at 9:12 am`} note={`${fin ? 'No people — medicine and house only.' : 'For the people you can see.'}${cdView(c.p) ? '' : ' Controlled medicines need controlled-medicine access.'}`}>
                <EntityTable<L>
                    rows={lines}
                    rowKey={(l) => l.orderId}
                    rowHeight="content"
                    minWidth={940}
                    identityLabel="Medicine"
                    identityWidth="1.6fr"
                    identity={(l) => ({ icon: orderOf(l.orderId).cd ? LockKeyhole : Pill, name: orderOf(l.orderId).med, subline: <Wrap>{orderOf(l.orderId).strength} · {fin ? HOUSES[PEOPLE[orderOf(l.orderId).pid].house] : `${PEOPLE[orderOf(l.orderId).pid].legal} · ${HOUSES[PEOPLE[orderOf(l.orderId).pid].house]}`}</Wrap> })}
                    columns={[
                        { key: 'on', label: 'On hand', width: '0.9fr', align: 'right', cell: (l) => <span className="text-[12.5px] tabular-nums">{l.onHand} {l.unit}</span> },
                        { key: 'days', label: 'Days left', width: '1fr', cell: (l) => (daysLeft(l) === null ? <StateLine>Not applicable — as needed</StateLine> : <span className="text-[12.5px] tabular-nums">{daysLeft(l)}</span>) },
                        { key: 'exp', label: 'Expires', width: '0.9fr', cell: (l) => <span className="text-[12.5px]">{l.expires}</span> },
                        ...(fin ? [{ key: 'cost', label: 'Value on hand', width: '0.9fr', align: 'right' as const, cell: (l: L) => <span className="text-[12.5px] tabular-nums">${(l.onHand * (UNIT_COST[l.orderId] ?? 0)).toFixed(2)}</span> }] : []),
                        { key: 'st', label: 'Status', width: '1fr', cell: (l) => <StatusBadge variant={status(l).variant} className="rounded-[8px]">{status(l).label}</StatusBadge> },
                    ]}
                    actionsFor={menu}
                    onOpen={(l) => c.toast(`Opens ${orderOf(l.orderId).med} in Stock & controlled drugs (P06) — outside this preview.`)}
                    onRowContextMenu={(e, l) => ctxMenu.openAt(e, orderOf(l.orderId).med, menu(l))}
                />
            </Section>
            {ctxMenu.node}
        </>
    );
    return { meters, body };
}

/* ═════════════ Competency (P11 Staff eligibility) ═════════════ */
const STAFF_LABEL: Record<StaffStatus, { label: string; variant: Tone }> = { current: { label: 'Current', variant: 'success' }, renewal: { label: 'Renewal due', variant: 'warning' }, expired: { label: 'Expired', variant: 'critical' }, exempt: { label: 'Exemption', variant: 'info' } };
export function competency(c: Ctx, ctxMenu: ReturnType<typeof useCtx>): Built {
    const list = staffIn(c.p);
    type S = (typeof list)[number];
    const n = (st: StaffStatus) => list.filter((x) => x.status === st).length;
    const menu = (s: S) => compactMenu([{ label: 'Open in Staff eligibility', icon: UserRound, onClick: () => c.toast(`Opens ${s.name} in Safety & oversight › Staff eligibility (P11) — outside this preview.`) }]);
    const meters = (
        <>
            <Meter label="Give medicines" value={c.dash ?? list.length} caption={c.dash ? '—' : 'Staff at your houses'} aria={`${list.length} staff give medicines`} />
            <Meter label="Current" value={c.dash ?? n('current')} caption={c.dash ? '—' : 'As at today'} aria={`${n('current')} current`} />
            <Meter label="Renewal due" value={c.dash ?? n('renewal')} tone={!c.dash && n('renewal') ? 'warning' : 'brand'} caption={c.dash ? '—' : 'In the next 30 days'} aria={`${n('renewal')} renewal due`} />
            <Meter label="Expired" value={c.dash ?? n('expired')} tone={!c.dash && n('expired') ? 'critical' : 'brand'} caption={c.dash ? '—' : 'Can’t give medicines'} aria={`${n('expired')} expired`} />
            <Meter label="Exemptions" value={c.dash ?? n('exempt')} caption={c.dash ? '—' : 'Time-limited'} aria={`${n('exempt')} exemptions`} />
        </>
    );
    const body = (
        <>
            <Section title="Staff who give medicines" caption={`${list.length} shown · as at today`} note="Assessments and exemptions are in Safety & oversight › Staff eligibility.">
                <EntityTable<S>
                    rows={list}
                    rowKey={(s) => s.name}
                    rowHeight="content"
                    minWidth={780}
                    identityLabel="Staff member"
                    identityWidth="1.5fr"
                    identity={(s) => ({ mark: <PersonDisc name={s.name} size={30} />, name: s.name, subline: HOUSES[s.house] })}
                    columns={[
                        { key: 'st', label: 'Competency', width: '1fr', cell: (s) => <StatusBadge variant={STAFF_LABEL[s.status].variant} className="rounded-[8px]">{STAFF_LABEL[s.status].label}</StatusBadge> },
                        { key: 'until', label: 'Until', width: '1fr', cell: (s) => <span className="text-[12.5px]">{s.until}</span> },
                    ]}
                    actionsFor={menu}
                    onOpen={(s) => c.toast(`Opens ${s.name} in Safety & oversight › Staff eligibility (P11) — outside this preview.`)}
                    onRowContextMenu={(e, s) => ctxMenu.openAt(e, s.name, menu(s))}
                />
            </Section>
            {ctxMenu.node}
        </>
    );
    return { meters, body };
}

export const BUILDERS: Record<ReportKey, (c: Ctx, m: ReturnType<typeof useCtx>) => Built> = { doses, rounds, prn, controlled, errors, reviews, stock, competency };
