/* History — every dose outcome (scheduled and as-needed), the correction chain
 * (absorbs CorrectionsReviewDialog) and all recorded changes (absorbs
 * MedicationEventDrawer, for people with audit access). */
import { EntityContextMenu, compactMenu, type MenuItem } from '@/components/lists/entity-menu';
import { EntityTable } from '@/components/lists/entity-table';
import { ListCaption } from '@/components/lists/list-caption';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { SkeletonTable } from '@/components/ui/skeleton-table';
import { StatusBadge } from '@/components/ui/status-badge';
import { ArrowRight, CalendarRange, FileText, Flag, History as HistoryIcon, Info, LockKeyhole, Pill, RefreshCw } from 'lucide-react';
import { useState, type MouseEvent } from 'react';
import { EVENTS, HOUSES, medByKey, recorderIn01, type Admin, type AuditEvent, type PersonId } from '../data';
import { OUTCOME_LABEL, useAdmins } from '../model';
import { DesignNote, DoseBadge } from '../p01/ui';
import { hrefFor, useDlg, useP02 } from '../store';
import { CONCEALED, ConcealedCaption, Wrap } from '../ui';

function useCtx() {
    const [ctx, setCtx] = useState<{ x: number; y: number; title: string; items: MenuItem[] } | null>(null);
    const openAt = (e: MouseEvent, title: string, items: MenuItem[]) => {
        e.preventDefault();
        const b = (e.currentTarget as HTMLElement).getBoundingClientRect();
        const kb = e.clientX === 0 && e.clientY === 0;
        setCtx({ x: kb ? b.left + 12 : e.clientX, y: kb ? b.top + 12 : e.clientY, title, items });
    };
    return { openAt, node: ctx ? <EntityContextMenu x={ctx.x} y={ctx.y} icon={HistoryIcon} title={ctx.title} items={ctx.items} onClose={() => setCtx(null)} /> : null };
}
const NOT_GIVEN = new Set(['refused', 'withheld', 'away']);
const concealedId = { icon: LockKeyhole, name: CONCEALED.name, subline: CONCEALED.subline };
export const changeText = (c: NonNullable<Admin['correction']>) => `${OUTCOME_LABEL[c.from.outcome]} ${c.from.at} → ${OUTCOME_LABEL[c.to.outcome]} ${c.to.at}`;

export function HistoryTab({ pid, view }: { pid: PersonId; view: string }) {
    const s = useP02();
    if (s.route.state === 'loading')
        return (
            <Card className="p-4">
                <SkeletonTable rows={8} columns={5} />
            </Card>
        );
    if (view === 'corrections') return <Corrections pid={pid} />;
    if (view === 'changes' && s.can('audit.view')) return <Changes pid={pid} />;
    return <Doses pid={pid} />;
}

function useDoseMenu(pid: PersonId) {
    const s = useP02();
    const dlg = useDlg();
    return (a: Admin): MenuItem[] => {
        const m = medByKey(a.med);
        if (m.cd && !s.cdView) return [{ label: 'Why is this hidden?', icon: Info, onClick: () => s.toast('info', `${CONCEALED.subline}. ${CONCEALED.ask}`) }];
        return compactMenu([
            { label: 'View dose details', icon: Info, onClick: () => dlg.open(`dose:${a.id}`) },
            s.can('correct') && !m.cd && a.correction?.status !== 'pending' && a.outcome !== 'selfmanaged' && { label: 'Request a correction', icon: RefreshCw, onClick: () => dlg.open(`correct:${a.id}`) },
            a.correction?.status === 'pending' && s.can('correct') && { label: 'Review the correction', icon: RefreshCw, onClick: () => dlg.open(`correction:${a.id}`) },
            { separator: true },
            s.can('audit.view') && { label: 'Open in All changes', icon: FileText, onClick: () => s.set({ view: 'changes', page: undefined }) },
            recorderIn01(s.route.persona) && { label: 'Report a medication error', icon: Flag, onClick: () => s.toast('info', 'Opens the existing Report a medication error dialog (redesigned in P08b) — outside this preview.') },
            { label: 'Show on the chart', icon: CalendarRange, onClick: () => s.set({ tab: undefined, view: undefined, mode: undefined, day: a.day === '2026-09-28' ? undefined : a.day, page: undefined }) },
        ]);
    };
}

/* ───────────── Doses ───────────── */
function Doses({ pid }: { pid: PersonId }) {
    const s = useP02();
    const dlg = useDlg();
    const ctx = useCtx();
    const menuFor = useDoseMenu(pid);
    const admins = useAdmins(pid);
    const med = s.route.q.get('med');
    const out = s.route.q.get('out');
    const filtered = admins.filter((a) => (!med || a.med === med) && (!out || (out === 'given' ? !NOT_GIVEN.has(a.outcome) : out === 'notgiven' ? NOT_GIVEN.has(a.outcome) : !!a.correction)));
    const hidden = s.cdView ? 0 : filtered.filter((a) => medByKey(a.med).cd).length;
    const houses = new Set(admins.map((a) => a.house));
    const per = 10;
    const pages = Math.max(1, Math.ceil(filtered.length / per));
    const page = Math.min(pages, Math.max(1, Number(s.route.q.get('page') ?? 1)));
    const rows = filtered.slice((page - 1) * per, page * per);
    const link = (n: number) => hrefFor(s.route.path, (() => { const q: Record<string, string | undefined> = {}; s.route.q.forEach((v, k) => (q[k] = v)); return { ...q, page: n === 1 ? undefined : String(n) }; })(), s.route).slice(1);
    const links = [
        { url: page > 1 ? link(page - 1) : null, label: '&laquo; Previous', active: false },
        ...Array.from({ length: pages }, (_, i) => ({ url: link(i + 1), label: String(i + 1), active: i + 1 === page })),
        { url: page < pages ? link(page + 1) : null, label: 'Next &raquo;', active: false },
    ];
    return (
        <section aria-label="Dose history" className="flex flex-col gap-2.5">
            <ListCaption
                title={med ? `Dose history · ${medByKey(med).name}` : 'Dose history'}
                caption={
                    <>
                        {rows.length} of {filtered.length} shown · page {page} of {pages} · last 7 days · times in NZDT{hidden ? ' · ' : ''}
                        <ConcealedCaption n={hidden} />
                    </>
                }
                right={med ? <Button size="sm" variant="ghost" onClick={() => s.set({ med: undefined, page: undefined })}>Show every medicine</Button> : null}
            />
            {rows.length ? (
                <EntityTable<Admin>
                    rows={rows}
                    rowKey={(a) => a.id}
                    identityLabel="Medicine"
                    identityWidth="1.5fr"
                    rowHeight="content"
                    minWidth={1100}
                    identity={(a) => {
                        const m = medByKey(a.med);
                        if (m.cd && !s.cdView) return concealedId;
                        return { icon: Pill, name: `${m.name} ${m.strength.replace(/ (tablet|capsule|pen)$/, '')}`, subline: a.prn ? 'As needed' : `${a.slot} dose` };
                    }}
                    columns={[
                        { key: 'when', label: 'When', width: '0.9fr', cell: (a) => <span className="text-[12.5px]">{a.dayLabel} · {medByKey(a.med).cd && !s.cdView ? '—' : a.at}</span> },
                        {
                            key: 'out',
                            label: 'Outcome',
                            width: '1.4fr',
                            cell: (a) =>
                                medByKey(a.med).cd && !s.cdView ? (
                                    <span className="text-muted-foreground">Hidden</span>
                                ) : (
                                    <span className="flex flex-wrap items-center gap-1.5">
                                        <DoseBadge state={a.outcome} size="sm" />
                                        {a.correction?.status === 'pending' ? <StatusBadge variant="warning" size="sm" className="rounded-[8px]">Correction waiting</StatusBadge> : a.correction?.status === 'approved' ? <StatusBadge variant="info" size="sm" className="rounded-[8px]">Corrected</StatusBadge> : null}
                                    </span>
                                ),
                        },
                        { key: 'by', label: 'By', width: '1.3fr', cell: (a) => (medByKey(a.med).cd && !s.cdView ? <span className="text-muted-foreground">—</span> : <span className="text-[12.5px]">{a.by}{a.second ? ` · ${a.second}` : ''}</span>) },
                        ...(houses.size > 1 ? [{ key: 'house', label: 'House', width: '0.9fr', cell: (a: Admin) => <span className="text-[12.5px]">{HOUSES[a.house]}</span> }] : []),
                        { key: 'note', label: 'Amount · note', width: '1.8fr', cell: (a) => (medByKey(a.med).cd && !s.cdView ? <span className="text-muted-foreground">—</span> : <span className="text-[12.5px]">{[a.amount, a.reading ? `${a.reading.label} ${a.reading.value}` : null, a.note].filter(Boolean).join(' · ') || '—'}</span>) },
                    ]}
                    actionsFor={menuFor}
                    onOpen={(a) => (medByKey(a.med).cd && !s.cdView ? menuFor(a)[0].onClick?.() : dlg.open(`dose:${a.id}`))}
                    onRowContextMenu={(e, a) => ctx.openAt(e, medByKey(a.med).cd && !s.cdView ? CONCEALED.name : `${medByKey(a.med).name} · ${a.dayLabel} ${a.at}`, menuFor(a))}
                />
            ) : (
                <Card className="p-2">
                    <EmptyState variant="compact" icon={HistoryIcon} title="No doses match these filters" description="Clear the Outcome filter in the header, or widen the range." />
                </Card>
            )}
            {pages > 1 ? <LaravelPagination links={links} lastPage={pages} /> : null}
            {ctx.node}
        </section>
    );
}

/* ───────────── Corrections (the chain) ───────────── */
function Corrections({ pid }: { pid: PersonId }) {
    const s = useP02();
    const dlg = useDlg();
    const ctx = useCtx();
    const admins = useAdmins(pid).filter((a) => a.correction);
    const hidden = s.cdView ? 0 : admins.filter((a) => medByKey(a.med).cd).length;
    const order = { pending: 0, approved: 1, rejected: 2 } as const;
    const rows = [...admins].sort((a, b) => order[a.correction!.status] - order[b.correction!.status]);
    const menu = (a: Admin): MenuItem[] => compactMenu([{ label: a.correction!.status === 'pending' && s.can('correct') ? 'Review the correction' : 'View the correction', icon: RefreshCw, onClick: () => dlg.open(`correction:${a.id}`) }, { label: 'View dose details', icon: Info, onClick: () => dlg.open(`dose:${a.id}`) }]);
    return (
        <section aria-label="Corrections" className="flex flex-col gap-2.5">
            <ListCaption
                title="Corrections"
                caption={
                    <>
                        {rows.length} of {rows.length} shown · waiting first{hidden ? ' · ' : ''}
                        <ConcealedCaption n={hidden} />
                    </>
                }
            />
            {rows.length ? (
                <EntityTable<Admin>
                    rows={rows}
                    rowKey={(a) => a.id}
                    identityLabel="Dose"
                    identityWidth="1.4fr"
                    rowHeight="content"
                    minWidth={1100}
                    identity={(a) => (medByKey(a.med).cd && !s.cdView ? concealedId : { icon: RefreshCw, name: `${medByKey(a.med).name} · ${a.dayLabel} ${a.slot ?? ''}`, subline: <Wrap>“{a.correction!.reason}”</Wrap> })}
                    columns={[
                        {
                            key: 'chg',
                            label: 'Change',
                            width: '1.8fr',
                            cell: (a) => (
                                <span className="flex flex-wrap items-center gap-1.5 text-[12.5px]">
                                    <DoseBadge state={a.correction!.from.outcome} size="sm" /> {a.correction!.from.at}
                                    <ArrowRight className="size-3.5 text-muted-foreground" aria-label="changed to" />
                                    <DoseBadge state={a.correction!.to.outcome} size="sm" /> {a.correction!.to.at}
                                </span>
                            ),
                        },
                        { key: 'req', label: 'Asked for', width: '1.1fr', cell: (a) => <span className="text-[12.5px]">{a.correction!.requestedBy} · {a.correction!.requestedAt}</span> },
                        {
                            key: 'st',
                            label: 'Status',
                            width: '1.2fr',
                            cell: (a) =>
                                a.correction!.status === 'pending' ? (
                                    <StatusBadge variant="warning" className="rounded-[8px]">Waiting for a second person</StatusBadge>
                                ) : a.correction!.status === 'approved' ? (
                                    <StatusBadge variant="success" className="rounded-[8px]">Approved · {a.correction!.decidedBy}</StatusBadge>
                                ) : (
                                    <StatusBadge variant="neutral" className="rounded-[8px]">Declined · {a.correction!.decidedBy}</StatusBadge>
                                ),
                        },
                        { key: 'act', label: '', width: '110px', align: 'right', cell: (a) => (a.correction!.status === 'pending' && s.can('correct') ? <Button size="sm" variant="outline" onClick={(e) => (e.stopPropagation(), dlg.open(`correction:${a.id}`))}>Review</Button> : null) },
                    ]}
                    actionsFor={menu}
                    onOpen={(a) => dlg.open(`correction:${a.id}`)}
                    onRowContextMenu={(e, a) => ctx.openAt(e, `Correction · ${medByKey(a.med).name}`, menu(a))}
                />
            ) : (
                <Card className="p-2">
                    <EmptyState variant="compact" icon={RefreshCw} title="No corrections" description="When a dose record is wrong, “Request a correction” on the dose keeps the original and asks a second person to approve the change." />
                </Card>
            )}
            {ctx.node}
            <DesignNote title="Design note — the correction chain">
                <p>A correction never overwrites: the original stays, the change waits for someone other than the person who asked (today’s two-person rule), and an approved change becomes what the chart shows, marked “Corrected”. Only one correction can wait at a time; after 30 minutes a reason is required (today’s server rules). Controlled-medicine records can’t be corrected here — they go through the controlled register.</p>
            </DesignNote>
        </section>
    );
}

/* ───────────── All changes (audit events) ───────────── */
function Changes({ pid }: { pid: PersonId }) {
    const s = useP02();
    const dlg = useDlg();
    const ctx = useCtx();
    const rows = pid === 'aroha' ? EVENTS : [];
    const hidden = s.cdView ? 0 : rows.filter((e) => e.cd).length;
    const menu = (e: AuditEvent): MenuItem[] => (e.cd && !s.cdView ? [{ label: 'Why is this hidden?', icon: Info, onClick: () => s.toast('info', `${CONCEALED.subline}. ${CONCEALED.ask}`) }] : [{ label: 'View the change', icon: Info, onClick: () => dlg.open(`event:${e.id}`) }]);
    return (
        <section aria-label="All changes" className="flex flex-col gap-2.5">
            <ListCaption
                title="All changes to this record"
                caption={
                    <>
                        {rows.length} of {rows.length} shown · newest first{hidden ? ' · ' : ''}
                        <ConcealedCaption n={hidden} />
                    </>
                }
                right={<Button size="sm" variant="outline" onClick={() => s.toast('info', 'Exports this person’s changes as CSV (existing audit export, P09) — outside this preview.')}>Export</Button>}
            />
            {rows.length ? (
                <EntityTable<AuditEvent>
                    rows={rows}
                    rowKey={(e) => e.id}
                    identityLabel="Change"
                    identityWidth="2.2fr"
                    rowHeight="content"
                    minWidth={1000}
                    identity={(e) => (e.cd && !s.cdView ? { icon: LockKeyhole, name: 'Change to a controlled medicine', subline: CONCEALED.subline } : { icon: HistoryIcon, name: e.what, subline: e.who })}
                    columns={[
                        { key: 'at', label: 'When', width: '1.1fr', cell: (e) => <span className="text-[12.5px]">{e.at}</span> },
                        { key: 'chk', label: 'Checked', width: '1.2fr', cell: (e) => (e.checked === 'unchanged' ? <StatusBadge variant="success" className="rounded-[8px]">Unchanged since saved</StatusBadge> : <StatusBadge variant="neutral" className="rounded-[8px]">Not checked yet</StatusBadge>) },
                    ]}
                    actionsFor={menu}
                    onOpen={(e) => menu(e)[0].onClick?.()}
                    onRowContextMenu={(ev, e) => ctx.openAt(ev, e.cd && !s.cdView ? 'Controlled medicine' : e.what, menu(e))}
                />
            ) : (
                <Card className="p-2">
                    <EmptyState variant="compact" icon={HistoryIcon} title="No changes in this range" />
                </Card>
            )}
            {ctx.node}
        </section>
    );
}
