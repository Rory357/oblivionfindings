/* Medication › Safety & oversight › Downtime & paper records — /emar/downtime
 * (Main, Q11; the BUILD needs Stephan’s OK — README). When the system or the
 * internet is down, staff record on the downtime pack. Afterwards a lead
 * records the downtime (when, why, the paper sheets), and every dose on the
 * paper is entered: the time on the paper is the clinical time, the entry
 * time is kept beside it — nothing is ever silently backdated. The giver
 * enters their own; a lead entering for someone asks them to confirm; a
 * witnessed dose waits for its witness. */
import { PersonDisc } from '@/components/lists/entity-cells';
import { compactMenu, type MenuItem } from '@/components/lists/entity-menu';
import { EntityTable } from '@/components/lists/entity-table';
import { ListCaption } from '@/components/lists/list-caption';
import { PageHeader, PageHeaderFilterButton, PageHeaderGlassButton, PageHeaderMeterBig, PageHeaderMeterBlock, PageHeaderMeterCaption, PageHeaderPrimaryButton, PageHeaderStatusChip } from '@/components/page/page-header';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';
import { FileDropzone } from '@/components/ui/file-dropzone';
import { SkeletonTable } from '@/components/ui/skeleton-table';
import { StatusBadge } from '@/components/ui/status-badge';
import { ArrowLeft, CheckCheck, Clock3, CloudOff, FileImage, FileText, LockKeyhole, PenLine, Pill, Plus, Printer } from 'lucide-react';
import { HOUSES, PEOPLE, PERSONAS, has, orderOf, type Downtime, type PaperItem, type PersonaId } from '../data';
import { useOpen } from '../host';
import { ITEM_LABEL, allDowntimes, atLong, atText, canPack, canSeeDowntime, cdView, housesOf, itemState, labelIso, time12, toMin, NOW, type ItemState } from '../model';
import { Shell } from '../shell';
import { hrefFor, useStore } from '../store';
import { DesignNote, KV, Notice, StateLine, Wrap } from '../ui';
import { NotAvailablePage } from './access';
import { useCtx } from './hub';

const STATE_TONE: Record<ItemState, 'warning' | 'info' | 'neutral'> = { toEnter: 'warning', confirm: 'info', witness: 'warning', done: 'neutral' };
export const canEnterItem = (p: PersonaId, it: PaperItem) => PERSONAS[p].name === it.paper.by || has(p, 'errors.manage');
export const dtWhen = (d: Downtime) => `${labelIso(d.start.day)}, ${time12(d.start.hm)}–${time12(d.end.hm)}`;

export function DowntimePage() {
    const s = useStore();
    const r = s.route;
    const p = r.persona;
    const me = PERSONAS[p];
    const open = useOpen();
    const ctx = useCtx(FileText);
    const scn = r.scenario;
    if (!canSeeDowntime(p)) return <NotAvailablePage area="Safety & oversight" />;
    const all = allDowntimes(s.rt, scn).filter((d) => housesOf(p).includes(d.house));
    const dt = all.find((d) => d.id === r.q.get('dt'));
    const items = all.flatMap((d) => d.items);
    const toEnter = items.filter((i) => itemState(s.rt, i.id) === 'toEnter');
    const waiting = items.filter((i) => ['confirm', 'witness'].includes(itemState(s.rt, i.id)));
    const loading = scn === 'loading';
    const failed = scn === 'unavailable';
    const dash = loading ? '—' : failed ? 'Unavailable' : null;
    const header = (
        <PageHeader
            icon={CloudOff}
            title="Downtime & paper records"
            titleChip={<PageHeaderStatusChip variant="neutral">{me.houses.length > 1 ? '2 houses' : HOUSES[me.houses[0]]}</PageHeaderStatusChip>}
            subline={dt ? `${dt.id} · ${HOUSES[dt.house]} · ${dtWhen(dt)} · NZ time` : 'When the system is down, record on paper — then enter it here · NZ time'}
            actions={
                <>
                    {canPack(p) ? (
                        <PageHeaderGlassButton icon={Printer} onClick={() => open('export:pack')}>
                            Downtime pack
                        </PageHeaderGlassButton>
                    ) : null}
                    {has(p, 'errors.manage') ? (
                        <PageHeaderPrimaryButton icon={Plus} onClick={() => open('declare')}>
                            Record a downtime
                        </PageHeaderPrimaryButton>
                    ) : null}
                </>
            }
            meters={
                <>
                    <PageHeaderMeterBlock label="To enter" tone={toEnter.length ? 'warning' : 'brand'} ariaLabel={`${toEnter.length} paper records to enter`}>
                        <PageHeaderMeterBig>{dash ?? toEnter.length}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>{dash ? '—' : toEnter.length ? 'From the paper sheets' : 'Nothing waiting'}</PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock label="To confirm" ariaLabel={`${waiting.length} waiting to confirm`}>
                        <PageHeaderMeterBig>{dash ?? waiting.length}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>{dash ? '—' : 'By the giver or witness'}</PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock label="Downtimes" ariaLabel={`${all.length} downtimes recorded`}>
                        <PageHeaderMeterBig>{dash ?? all.length}</PageHeaderMeterBig>
                        <PageHeaderMeterCaption>{dash ? '—' : 'This month'}</PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            filters={
                <PageHeaderFilterButton icon={Clock3} onClick={() => s.toast('success', 'Up to date · as at 9:12 am · Pacific/Auckland')}>
                    As at 9:12 am · NZDT
                </PageHeaderFilterButton>
            }
        />
    );
    let body;
    if (loading)
        body = (
            <Card className="p-4" aria-busy="true" aria-label="Loading downtimes">
                <SkeletonTable rows={4} columns={5} />
            </Card>
        );
    else if (failed)
        body = (
            <Card className="p-2">
                <ErrorState title="We couldn’t load the downtimes" message="Nothing is shown rather than a list that might be wrong. Try again in a moment." onRetry={() => s.setViewer({ scenario: 'normal' })} />
            </Card>
        );
    else if (dt) body = <Period d={dt} />;
    else {
        const menu = (d: Downtime): MenuItem[] => compactMenu([{ label: 'Open it', icon: FileText, onClick: () => s.set({ dt: d.id, open: undefined }) }]);
        body = (
            <section className="flex flex-col gap-2.5" aria-label="Downtimes">
                <ListCaption title="Downtimes" caption={all.length ? `${all.length} · newest first` : 'None'} />
                {all.length ? (
                    <EntityTable<Downtime>
                        rows={[...all].sort((a, b) => toMin(b.start) - toMin(a.start))}
                        rowKey={(d) => d.id}
                        rowHeight="content"
                        minWidth={960}
                        identityLabel="Downtime"
                        identityWidth="1.3fr"
                        identity={(d) => ({ icon: CloudOff, name: `${d.id} · ${HOUSES[d.house]}`, subline: dtWhen(d) })}
                        columns={[
                            { key: 'why', label: 'Why', width: '1.6fr', cell: (d) => <span className="py-1 text-[12.5px]"><Wrap>{d.why}</Wrap></span> },
                            { key: 'by', label: 'Recorded by', width: '1fr', cell: (d) => <span className="flex flex-col py-1 text-[12.5px]"><span>{d.declared.by}</span><span className="text-[11.5px] text-muted-foreground">{atText(d.declared.at)}</span></span> },
                            {
                                key: 'prog',
                                label: 'Paper records',
                                width: '1.4fr',
                                cell: (d) => {
                                    const n = d.items.filter((i) => itemState(s.rt, i.id) === 'toEnter').length;
                                    const closed = s.rt.closedDowntimes[d.id];
                                    return (
                                        <span className="flex flex-col items-start gap-1 py-1">
                                            <StatusBadge variant={closed ? 'neutral' : n ? 'warning' : 'info'} className="rounded-[8px]">
                                                {closed ? 'Finished' : n ? `${n} of ${d.items.length} to enter` : 'All entered'}
                                            </StatusBadge>
                                            {n && toMin(d.end) < toMin(NOW) - 1440 ? <StateLine tone="warning">{`Waiting since ${labelIso(d.end.day)}`}</StateLine> : null}
                                        </span>
                                    );
                                },
                            },
                        ]}
                        actionsFor={menu}
                        onOpen={(d) => s.set({ dt: d.id, open: undefined })}
                        onRowContextMenu={(e, d) => ctx.openAt(e, `${d.id} · ${HOUSES[d.house]}`, menu(d))}
                    />
                ) : (
                    <Card className="p-2">
                        <EmptyState variant="compact" icon={CloudOff} title={`No downtime recorded at ${me.houses.length > 1 ? 'your houses' : HOUSES[me.houses[0]]}`} description="When the system or the internet is down, record on the downtime pack. Afterwards, record the downtime here and enter each paper record." />
                    </Card>
                )}
                {ctx.node}
            </section>
        );
    }
    return (
        <Shell crumbs={[{ title: 'Home', href: '/dashboard' }, { title: 'Medication' }, { title: 'Safety & oversight' }, { title: 'Downtime & paper records', href: hrefFor('/emar/downtime', {}, r) }, ...(dt ? [{ title: dt.id }] : [])]}>
            {header}
            {body}
            <DesignNote title="Design note — paper reconciliation (Main, Q11). BUILD: scope needs Stephan’s OK">
                <p>Today there is no downtime mode, no “entered from paper” flag and no way to reconcile paper records: a late entry is a free-text reason at most, and the offline queue keeps its device time only in the audit log. Here a downtime is recorded once, every dose on the paper is entered with both times shown, the giver confirms an entry made for them, a witnessed dose waits for its witness, and controlled entries go into the register in time order.</p>
            </DesignNote>
        </Shell>
    );
}

function Period({ d }: { d: Downtime }) {
    const s = useStore();
    const p = s.route.persona;
    const open = useOpen();
    const ctx = useCtx(Pill);
    const closed = s.rt.closedDowntimes[d.id];
    const left = d.items.filter((i) => itemState(s.rt, i.id) === 'toEnter');
    const waiting = d.items.filter((i) => ['confirm', 'witness'].includes(itemState(s.rt, i.id)));
    const menu = (it: PaperItem): MenuItem[] =>
        compactMenu([
            itemState(s.rt, it.id) === 'toEnter' && canEnterItem(p, it) && { label: PERSONAS[p].name === it.paper.by ? 'Enter it' : `Enter it for ${it.paper.by}`, icon: PenLine, onClick: () => open(`paper:${it.id}`) },
            { label: `Open ${PEOPLE[it.pid].pref}’s MAR on ${labelIso(d.start.day)}`, icon: FileText, onClick: () => s.toast('info', `Opens ${PEOPLE[it.pid].pref}’s MAR on ${labelIso(d.start.day)} (P02) — outside this preview.`) },
        ]);
    const hideCd = (it: PaperItem) => orderOf(it.orderId).cd && !cdView(p);
    return (
        <>
            <p>
                <Button variant="link" className="h-auto p-0" onClick={() => s.set({ dt: undefined, open: undefined })}>
                    <ArrowLeft className="size-4" /> All downtimes
                </Button>
            </p>
            {!closed && left.length && toMin(d.end) < toMin(NOW) - 1440 ? (
                <Notice tone="warning" icon={Clock3} title={`Waiting since ${labelIso(d.end.day)} — enter the paper records as soon as you can`}>
                    Paper records are due by the end of the next shift. Until they’re entered, the doses show as not recorded in reports and the audit trail.
                </Notice>
            ) : null}
            {closed ? <Notice tone="neutral" icon={CheckCheck} title={`Finished by ${closed.by}, ${atText(closed.at)}`}>Every paper record is entered. The paper sheets stay attached.</Notice> : null}
            <div className="grid items-start gap-5 lg:grid-cols-[1fr_1fr]">
                <Card className="gap-3 p-4">
                    <h2 className="text-sm font-semibold">The downtime</h2>
                    <KV
                        rows={[
                            ['House', HOUSES[d.house]],
                            ['When', `${atLong(d.start)} – ${time12(d.end.hm)}`],
                            ['Why', d.why],
                            ['Recorded by', `${d.declared.by}, ${atText(d.declared.at)}`],
                        ]}
                    />
                </Card>
                <Card className="gap-3 p-4">
                    <h2 className="text-sm font-semibold" id="dt-files-l">
                        The paper sheets
                    </h2>
                    <ul className="space-y-1.5">
                        {d.files.map((f) => (
                            <li key={f} className="flex items-center gap-2 text-[13px]">
                                <FileImage className="size-4 text-muted-foreground" aria-hidden="true" /> {f}
                            </li>
                        ))}
                    </ul>
                    {!closed && has(p, 'errors.manage') ? <FileDropzone aria-labelledby="dt-files-l" title="Add a photo or scan of the paper" hint="Images or PDF" accept="image/*,application/pdf" onFiles={(fs) => s.toast('success', `${fs.length} file${fs.length === 1 ? '' : 's'} attached to ${d.id}. (Nothing uploads in the preview.)`)} /> : null}
                </Card>
            </div>
            <section className="flex flex-col gap-2.5" aria-label="Paper records">
                <ListCaption
                    title="Paper records"
                    caption={`${d.items.length} · ${left.length ? `${left.length} to enter` : 'all entered'}`}
                    right={
                        !closed && has(p, 'errors.manage') ? (
                            <Button size="sm" variant="outline" onClick={() => open(`paperadd:${d.id}`)} data-return="paperadd">
                                <Plus className="size-4" /> Add a dose from the paper
                            </Button>
                        ) : undefined
                    }
                />
                <EntityTable<PaperItem>
                    rows={d.items}
                    rowKey={(it) => it.id}
                    rowHeight="content"
                    minWidth={1040}
                    identityLabel="Person"
                    identityWidth="1.1fr"
                    identity={(it) => ({ mark: <PersonDisc name={PEOPLE[it.pid].legal} size={30} />, name: PEOPLE[it.pid].legal, subline: <Wrap>{it.source === 'slot' ? `Due ${time12(it.due!)} — from the schedule` : 'As needed — listed from the paper'}</Wrap> })}
                    columns={[
                        { key: 'med', label: 'Medicine', width: '1.1fr', cell: (it) => <span className="flex items-center gap-1.5 py-1 text-[12.5px]">{hideCd(it) ? <><LockKeyhole className="size-3.5" aria-hidden="true" />Controlled medicine</> : `${orderOf(it.orderId).med} ${orderOf(it.orderId).strength}`}</span> },
                        { key: 'paper', label: 'On the paper', width: '1.5fr', cell: (it) => <span className="py-1 text-[12.5px]"><span className="block">{it.paper.by === '—' ? 'Enter what the paper says' : `${it.paper.outcome === 'given' ? 'Given' : it.paper.outcome === 'refused' ? 'Refused' : 'Withheld'} ${time12(it.paper.hm)} by ${it.paper.by}`}</span>{it.paper.note && !hideCd(it) ? <Wrap><span className="text-[11.5px] text-muted-foreground">“{it.paper.note}”</span></Wrap> : null}</span> },
                        {
                            key: 'st',
                            label: 'In the record',
                            width: '1.7fr',
                            cell: (it) => {
                                const st = itemState(s.rt, it.id);
                                const e = s.rt.paper[it.id];
                                return (
                                    <span className="flex flex-col items-start gap-1 py-1">
                                        <StatusBadge variant={STATE_TONE[st]} className="rounded-[8px]">
                                            {st === 'confirm' ? `Waiting for ${e.givenBy.split(' ')[0]} to confirm` : ITEM_LABEL[st]}
                                        </StatusBadge>
                                        {e ? <StateLine>{`${e.outcome === 'given' ? 'Given' : e.outcome === 'refused' ? 'Refused' : 'Withheld'} ${time12(e.hm)} ${labelIso(d.start.day)} by ${e.givenBy} (paper) — entered ${time12(e.at.hm)} ${labelIso(e.at.day)} by ${e.by}`}</StateLine> : null}
                                    </span>
                                );
                            },
                        },
                        {
                            key: 'act',
                            label: '',
                            width: '150px',
                            align: 'right',
                            cell: (it) =>
                                itemState(s.rt, it.id) === 'toEnter' && canEnterItem(p, it) && !closed ? (
                                    <Button size="sm" variant="outline" data-return={`paper-${it.id}`} onClick={(e) => (e.stopPropagation(), open(`paper:${it.id}`))}>
                                        {PERSONAS[p].name === it.paper.by ? 'Enter it' : 'Enter for them'}
                                    </Button>
                                ) : null,
                        },
                    ]}
                    actionsFor={menu}
                    onOpen={(it) => (itemState(s.rt, it.id) === 'toEnter' && canEnterItem(p, it) && !closed ? open(`paper:${it.id}`) : s.toast('info', `Opens ${PEOPLE[it.pid].pref}’s MAR on ${labelIso(d.start.day)} (P02) — outside this preview.`))}
                    onRowContextMenu={(e, it) => ctx.openAt(e, `${PEOPLE[it.pid].pref} · ${orderOf(it.orderId).med}`, menu(it))}
                />
                <p className="text-caption">What the paper says is shown as the starting point — it’s never entered for you. The time on the paper is the time given; the time it was entered is kept beside it.</p>
                {ctx.node}
            </section>
            {!closed && has(p, 'errors.manage') ? (
                <Card className="flex-row flex-wrap items-center justify-between gap-3 p-4">
                    <span>
                        <span className="block text-sm font-semibold">Finish this downtime</span>
                        <span className="text-caption block">{left.length ? `${left.length} paper ${left.length === 1 ? 'record is' : 'records are'} still to enter.` : waiting.length ? `Everything is entered. ${waiting.length} ${waiting.length === 1 ? 'is' : 'are'} still waiting to be confirmed — that carries on in Follow-ups.` : 'Everything is entered and confirmed.'}</span>
                    </span>
                    <Button size="sm" disabled={!!left.length} onClick={() => open(`finish:${d.id}`)}>
                        <CheckCheck className="size-4" /> Finish it
                    </Button>
                </Card>
            ) : null}
        </>
    );
}
