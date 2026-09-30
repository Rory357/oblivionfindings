/* Reports & audit › Audit trail (Main, Q7). One append-only medication event
 * log, hash-chained per house: the screen and the export are the same
 * dataset, filtered and paged on the server (50 a page). Unrecorded doses are
 * first-class over the whole period, each actionable. Exports made are events
 * too, with their purpose (Q8). P11’s alert log stays in Settings (a link). */
import { PersonDisc } from '@/components/lists/entity-cells';
import { compactMenu, type MenuItem } from '@/components/lists/entity-menu';
import { EntityTable } from '@/components/lists/entity-table';
import { ListCaption } from '@/components/lists/list-caption';
import { PageHeaderMeterBig, PageHeaderMeterBlock, PageHeaderMeterCaption } from '@/components/page/page-header';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { StatusBadge } from '@/components/ui/status-badge';
import { type ReactNode } from 'react';
import { AlertOctagon, ArrowUpRight, Bell, ClipboardList, CloudOff, Download, FileText, History, KeyRound, LockKeyhole, Pill, Settings2, ShieldCheck, type LucideIcon } from 'lucide-react';
import { HOUSES, PEOPLE, orderOf } from '../data';
import { GAP_STATUS, PAGE, cdView, chainHead, eventAt, eventTitle, eventsFor, housesOf, labelIso, redactedEvent, slotsIn, time12, type EventKind, type LogEvent } from '../model';
import { Notice, StateLine, Wrap } from '../ui';
import { useCtx } from './hub';
import type { Built, Ctx } from './hub';

export type AuditSub = 'events' | 'gaps' | 'exports';
export const AUDIT_SUBS: { key: AuditSub; label: string; icon: LucideIcon }[] = [
    { key: 'events', label: 'Events', icon: History },
    { key: 'gaps', label: 'Unrecorded doses', icon: AlertOctagon },
    { key: 'exports', label: 'Exports made', icon: Download },
];
const KIND_ICON: Record<EventKind, LucideIcon> = { dose: Pill, controlled: LockKeyhole, order: ClipboardList, error: AlertOctagon, access: KeyRound, downtime: CloudOff, settings: Settings2, export: Download };

function Meter({ label, value, caption, tone, onClick, aria }: { label: string; value: ReactNode; caption: ReactNode; tone?: 'critical' | 'warning' | 'brand'; onClick?: () => void; aria: string }) {
    return (
        <PageHeaderMeterBlock label={label} tone={tone ?? 'brand'} onClick={onClick} ariaLabel={aria}>
            <PageHeaderMeterBig>{value}</PageHeaderMeterBig>
            <PageHeaderMeterCaption>{caption}</PageHeaderMeterCaption>
        </PageHeaderMeterBlock>
    );
}

export function audit(c: Ctx, ctxMenu: ReturnType<typeof useCtx>, sub: AuditSub, kind: string, person: string | null, page: number): Built {
    const houses = housesOf(c.p);
    const events = eventsFor(c.rt, c.period, c.pids, houses, kind, person);
    const gaps = slotsIn(c.period.from, c.period.to, c.pids).counted.filter((x) => x.outcome === 'notRecorded' && (!person || x.pid === person)).sort((a, b) => `${b.day}${b.time}`.localeCompare(`${a.day}${a.time}`));
    const exportsMade = eventsFor(c.rt, c.period, c.pids, houses, 'export', null);
    const heads = houses.map((h) => chainHead(h));
    const meters = (
        <>
            <Meter label="Events" value={c.dash ?? events.length.toLocaleString('en-NZ')} caption={c.dash ? '—' : c.period.text} aria={`${events.length} events`} onClick={() => c.go('/emar/reports', { view: 'audit', sub: undefined, pg: undefined, open: undefined })} />
            <Meter label="Unrecorded doses" value={c.dash ?? gaps.length} tone={!c.dash && gaps.length ? 'critical' : 'brand'} caption={c.dash ? '—' : !gaps.length ? 'None' : gaps.some((g) => !GAP_STATUS[`${g.orderId}|${g.day}|${g.time}`]) ? `${gaps.filter((g) => !GAP_STATUS[`${g.orderId}|${g.day}|${g.time}`]).length} not followed up yet` : 'Each one followed up'} aria={`${gaps.length} unrecorded doses`} onClick={() => c.go('/emar/reports', { view: 'audit', sub: 'gaps', pg: undefined, open: undefined })} />
            <Meter label="Exports made" value={c.dash ?? exportsMade.length} caption={c.dash ? '—' : 'Each with its purpose'} aria={`${exportsMade.length} exports made`} onClick={() => c.go('/emar/reports', { view: 'audit', sub: 'exports', pg: undefined, open: undefined })} />
            <Meter label="The chain" value={c.dash ?? 'Intact'} caption={c.dash ? '—' : 'Checked 9:12 am'} aria="The event chain is intact — verify it" onClick={() => c.toast('P09’s “Verify the chain” — outside this preview.')} />
        </>
    );
    let body: ReactNode;
    if (sub === 'gaps') body = <Gaps c={c} rows={gaps} ctxMenu={ctxMenu} />;
    else if (sub === 'exports') body = <EventList c={c} rows={exportsMade} page={1} pageSize={exportsMade.length || 1} ctxMenu={ctxMenu} title="Exports made" caption={`${exportsMade.length} shown · newest first · with who, what and why`} empty="No exports made in this period" emptyText="Every export and print is recorded here with its purpose." hrefPage={() => ''} />;
    else
        body = (
            <EventList
                c={c}
                rows={events}
                page={page}
                pageSize={PAGE}
                ctxMenu={ctxMenu}
                title="Events"
                caption=""
                empty="No events match"
                emptyText="Change the period, the kind or the person to see more."
                hrefPage={(n) => c.href({ pg: n === 1 ? undefined : String(n) })}
            />
        );
    return {
        meters,
        body: (
            <>
                {c.scenario === 'logdown' ? (
                    <Notice tone="critical" title="The event log can’t be written right now">
                        Medication changes are refused with “Couldn’t save — try again” until it’s back — nothing is saved without its event. Doses recorded offline on a device stay queued and are retried. The events already in the log are all here.
                    </Notice>
                ) : null}
                {body}
                <Card className="flex-row flex-wrap items-center justify-between gap-3 p-4">
                    <span className="flex items-start gap-3">
                        <Bell className="mt-0.5 size-4 text-muted-foreground" aria-hidden="true" />
                        <span>
                            <span className="block text-sm font-semibold">Alert log</span>
                            <span className="block text-caption">Which alerts went to whom, and when — kept in Settings › Alerts & access.</span>
                        </span>
                    </span>
                    <Button variant="outline" size="sm" onClick={() => c.go('/emar/settings', { view: 'alerts', sec: 'log', open: undefined, sub: undefined, pg: undefined })}>
                        Open the alert log <ArrowUpRight className="size-4" />
                    </Button>
                </Card>
            </>
        ),
    };
}

/* ───────────── the event list (server-paged) ───────────── */
function EventList({ c, rows, page, pageSize, ctxMenu, title, caption, empty, emptyText, hrefPage }: { c: Ctx; rows: LogEvent[]; page: number; pageSize: number; ctxMenu: ReturnType<typeof useCtx>; title: string; caption: string; empty: string; emptyText: string; hrefPage: (n: number) => string }) {
    const pages = Math.max(1, Math.ceil(rows.length / pageSize));
    const pg = Math.min(Math.max(1, page), pages);
    const shown = rows.slice((pg - 1) * pageSize, pg * pageSize);
    const from = rows.length ? (pg - 1) * pageSize + 1 : 0;
    const to = Math.min(pg * pageSize, rows.length);
    const menu = (e: LogEvent): MenuItem[] =>
        compactMenu([
            { label: 'Open the event', icon: History, onClick: () => c.open(`event:${e.id}`) },
            !!e.pid && (e.kind === 'dose' || (e.kind === 'controlled' && !!e.orderId)) && { label: `Open ${PEOPLE[e.pid].pref}’s MAR at this dose`, icon: FileText, onClick: () => c.toast(`Opens ${PEOPLE[e.pid!].pref}’s MAR on ${labelIso(e.day)} (P02) — outside this preview.`) },
        ]);
    /* Laravel’s paginator sends a windowed list with “…” gaps: the first and last pages, and the pages either side of this one. */
    const shownPages = Array.from({ length: pages }, (_, i) => i + 1).filter((n) => n === 1 || n === pages || Math.abs(n - pg) <= 1 || (pg <= 3 && n <= 4) || (pg >= pages - 2 && n >= pages - 3));
    const numbered = shownPages.flatMap((n, i) => [...(i && n - shownPages[i - 1] > 1 ? [{ url: null, label: '...', active: false }] : []), { url: hrefPage(n), label: String(n), active: n === pg }]);
    const links = pages > 1 ? [{ url: pg > 1 ? hrefPage(pg - 1) : null, label: '&laquo; Previous', active: false }, ...numbered, { url: pg < pages ? hrefPage(pg + 1) : null, label: 'Next &raquo;', active: false }] : [];
    return (
        <section className="flex flex-col gap-2.5" aria-label={title}>
            <ListCaption title={title} caption={caption || `${rows.length ? `${from.toLocaleString('en-NZ')}–${to.toLocaleString('en-NZ')} of ${rows.length.toLocaleString('en-NZ')}` : 'None'} · newest first · ${pageSize} a page`} />
            {shown.length ? (
                <EntityTable<LogEvent>
                    rows={shown}
                    rowKey={(e) => e.id}
                    rowHeight="content"
                    minWidth={1000}
                    identityLabel="Event"
                    identityWidth="2fr"
                    identity={(e) => ({ icon: redactedEvent(c.p, e) ? LockKeyhole : KIND_ICON[e.kind], name: eventTitle(c.p, e), subline: <Wrap>{redactedEvent(c.p, e) ? 'Details need controlled-medicine access' : e.detail}</Wrap> })}
                    columns={[
                        { key: 'when', label: 'When (NZ)', width: '1fr', cell: (e) => <span className="text-[12.5px]">{eventAt(e)}</span> },
                        { key: 'who', label: 'By', width: '0.9fr', cell: (e) => <span className="text-[12.5px]">{e.by}</span> },
                        {
                            key: 'person',
                            label: 'Person',
                            width: '0.9fr',
                            cell: (e) =>
                                e.pid ? (
                                    <span className="flex items-center gap-2 text-[12.5px]">
                                        <PersonDisc name={PEOPLE[e.pid].legal} size={22} />
                                        {PEOPLE[e.pid].pref}
                                    </span>
                                ) : (
                                    <span className="text-[12.5px] text-muted-foreground">{HOUSES[e.house]}</span>
                                ),
                        },
                        {
                            key: 'chain',
                            label: 'In the chain',
                            width: '0.9fr',
                            cell: (e) => (
                                <span className="flex flex-col items-start gap-0.5">
                                    <span className="text-[12.5px] tabular-nums">#{e.seq.toLocaleString('en-NZ')}</span>
                                    <StateLine icon={ShieldCheck}>Linked</StateLine>
                                </span>
                            ),
                        },
                    ]}
                    actionsFor={menu}
                    onOpen={(e) => c.open(`event:${e.id}`)}
                    onRowContextMenu={(ev, e) => ctxMenu.openAt(ev, eventTitle(c.p, e), menu(e))}
                />
            ) : (
                <Card className="p-2">
                    <EmptyState variant="compact" icon={History} title={empty} description={emptyText} />
                </Card>
            )}
            {links.length ? <LaravelPagination links={links} lastPage={pages} /> : null}
            {caption ? null : <p className="text-caption">Filtered and paged on the server. The export is this same list.</p>}
            {ctxMenu.node}
        </section>
    );
}

/* ───────────── unrecorded doses (gaps) — the whole period, each actionable ───────────── */
type Gap = ReturnType<typeof slotsIn>['counted'][number];
function Gaps({ c, rows, ctxMenu }: { c: Ctx; rows: Gap[]; ctxMenu: ReturnType<typeof useCtx> }) {
    const hide = (g: Gap) => g.cd && !cdView(c.p);
    const status = (g: Gap) => GAP_STATUS[`${g.orderId}|${g.day}|${g.time}`];
    const menu = (g: Gap): MenuItem[] =>
        compactMenu([
            { label: `Open ${PEOPLE[g.pid].pref}’s MAR at this dose`, icon: FileText, onClick: () => c.toast(`Opens ${PEOPLE[g.pid].pref}’s MAR on ${labelIso(g.day)} at ${time12(g.time)} (P02) — outside this preview.`) },
            { label: 'Report a medication error', icon: AlertOctagon, onClick: () => c.toast('Opens P08b’s Report a medication error, filled in with this dose — outside this preview.') },
            { label: 'Open the follow-up', icon: ClipboardList, onClick: () => c.toast('Opens the follow-up in Safety & oversight › Follow-ups (P08a) — outside this preview.') },
        ]);
    return (
        <section className="flex flex-col gap-2.5" aria-label="Unrecorded doses">
            <ListCaption title="Unrecorded doses" caption={`${rows.length} shown · ${c.period.text} · newest first`} />
            {rows.length ? (
                <EntityTable<Gap>
                    rows={rows}
                    rowKey={(g) => `${g.orderId}${g.day}${g.time}`}
                    rowHeight="content"
                    minWidth={960}
                    identityLabel="Person"
                    identityWidth="1.4fr"
                    identity={(g) => ({ mark: <PersonDisc name={PEOPLE[g.pid].legal} size={30} />, name: PEOPLE[g.pid].legal, subline: HOUSES[PEOPLE[g.pid].house] })}
                    columns={[
                        { key: 'med', label: 'Medicine', width: '1.2fr', cell: (g) => <span className="flex items-center gap-1.5 text-[12.5px]">{hide(g) ? <LockKeyhole className="size-3.5" aria-hidden="true" /> : null}{hide(g) ? 'Controlled medicine' : `${orderOf(g.orderId).med} ${orderOf(g.orderId).strength}`}</span> },
                        { key: 'due', label: 'Was due', width: '1fr', cell: (g) => <span className="text-[12.5px]">{labelIso(g.day)}, {time12(g.time)}</span> },
                        {
                            key: 'st',
                            label: 'What was done',
                            width: '1.8fr',
                            cell: (g) =>
                                status(g) ? (
                                    <span className="flex flex-col items-start gap-1 py-0.5">
                                        <StatusBadge variant={status(g).includes('open') ? 'warning' : 'neutral'} className="rounded-[8px]">
                                            {status(g).includes('open') ? 'Follow-up open' : 'Followed up'}
                                        </StatusBadge>
                                        <StateLine>{status(g)}</StateLine>
                                    </span>
                                ) : (
                                    <StatusBadge variant="critical" className="rounded-[8px]">
                                        Not followed up yet
                                    </StatusBadge>
                                ),
                        },
                    ]}
                    actionsFor={menu}
                    onOpen={(g) => c.toast(`Opens ${PEOPLE[g.pid].pref}’s MAR on ${labelIso(g.day)} at ${time12(g.time)} (P02) — outside this preview.`)}
                    onRowContextMenu={(e, g) => ctxMenu.openAt(e, `${PEOPLE[g.pid].pref} · ${labelIso(g.day)}`, menu(g))}
                />
            ) : (
                <Card className="p-2">
                    <EmptyState variant="compact" icon={AlertOctagon} title="No unrecorded doses in this period" description="Every dose whose window ended has an outcome recorded." />
                </Card>
            )}
            <p className="text-caption">A dose whose window ended with nothing recorded — over the whole period, however long.</p>
            {ctxMenu.node}
        </section>
    );
}
