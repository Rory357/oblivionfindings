/* Incidents › Awaiting review — a FRAME of the Incidents module’s queue
 * (deviation: Incidents isn’t an eMAR package). It shows only what P08b adds
 * there (Main, Q6): an incident from the medication side carries the neutral
 * title, never the medicine or the accounts, and shows “Ready to close —
 * medication error closed” once the medication side is closed, for people who
 * close incidents. Reviewing and closing uses the Incidents module’s own path
 * (review, then close with an outcome of up to 120 characters). */
import { PersonDisc } from '@/components/lists/entity-cells';
import { compactMenu } from '@/components/lists/entity-menu';
import { EntityTable } from '@/components/lists/entity-table';
import { ListCaption } from '@/components/lists/list-caption';
import { PageHeader, PageHeaderFilterButton, PageHeaderMeterBig, PageHeaderMeterBlock, PageHeaderMeterCaption, PageHeaderStatusChip } from '@/components/page/page-header';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { StatusBadge } from '@/components/ui/status-badge';
import { AlertOctagon, CheckCheck, Clock3, Lock, Siren } from 'lucide-react';
import { type MouseEvent } from 'react';
import { HOUSES, PEOPLE, type PersonId } from '../data';
import { useOpen } from '../host';
import { canCloseIncident, canSeeAll, housesOf, INC_STATUS, incidentsIn, incidentState, incidentTitle, isReady, WHO_CLOSES_INCIDENTS } from '../model';
import { Shell } from '../shell';
import { useStore } from '../store';
import { DesignNote, StateLine, Wrap } from '../ui';
import { useCtx } from './errors';

/** Two of the module’s other incidents, so the medication ones are seen among the rest (synthetic). */
type Row = { id: string; title: string; pid: PersonId; status: string; reported: string; med?: string };
const OTHERS: Row[] = [
    { id: 'INC-2235', title: 'Fall in the bathroom — no injury', pid: 'sam', status: 'Submitted — awaiting review', reported: 'Sun 27 Sep, 7:40 pm' },
    { id: 'INC-2232', title: 'Property damage — kitchen window', pid: 'ben', status: 'Submitted — awaiting review', reported: 'Sat 26 Sep, 3:15 pm' },
];

export function IncidentsFrame() {
    const s = useStore();
    const p = s.route.persona;
    const open = useOpen();
    const ctx = useCtx(Siren);
    const med = incidentsIn(s.rt, p).filter((i) => i.status !== 'closed');
    const rows: Row[] = [...med.map((i) => ({ id: i.id, title: incidentTitle(s.rt, i), pid: i.pid, status: INC_STATUS[i.status], reported: i.source === 'error' ? `From ${i.ref}` : `From ${i.ref} (controlled register)`, med: i.id })), ...OTHERS.filter((o) => housesOf(p).includes(PEOPLE[o.pid].house))];
    const incOf = (r: Row) => med.find((i) => i.id === r.med) ?? null;
    const ready = med.filter((i) => isReady(s.rt, i));
    const stop = (fn: () => void) => (e: MouseEvent) => (e.stopPropagation(), fn());
    const menu = (r: Row) => {
        const i = incOf(r);
        return compactMenu([
            { label: 'Open the incident', icon: Siren, onClick: () => s.toast('info', `${r.id} opens in the Incidents module — outside this preview.`) },
            !!i && canCloseIncident(p) && isReady(s.rt, i) && { label: 'Review and close', icon: CheckCheck, onClick: () => open(`incclose:${i.id}`) },
            !!i && i.source === 'error' && { separator: true },
            !!i && i.source === 'error' && { label: `Open ${i.ref} in Medication errors`, icon: AlertOctagon, onClick: () => s.go('/emar/errors', { open: `error:${i.ref}:incident`, tab: undefined }) },
        ]);
    };
    return (
        <Shell crumbs={[{ title: 'Home', href: '/dashboard' }, { title: 'Incidents', href: '/incidents' }, { title: 'Awaiting review' }]}>
            <PageHeader
                icon={Siren}
                title="Incidents"
                titleChip={<PageHeaderStatusChip variant="neutral">Frame</PageHeaderStatusChip>}
                subline="Awaiting review · the Incidents module’s queue, as P08b changes it"
                meters={
                    canSeeAll(p) ? (
                        <>
                            <PageHeaderMeterBlock label="Awaiting review" ariaLabel={`${rows.length} incidents awaiting review`}>
                                <PageHeaderMeterBig>{rows.length}</PageHeaderMeterBig>
                                <PageHeaderMeterCaption>{med.length} from the medication side</PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                            <PageHeaderMeterBlock label="Ready to close" tone={ready.length && canCloseIncident(p) ? 'warning' : 'brand'} ariaLabel={`${ready.length} incidents ready to close`}>
                                <PageHeaderMeterBig>{ready.length}</PageHeaderMeterBig>
                                <PageHeaderMeterCaption>{canCloseIncident(p) ? 'For you to close' : `For ${WHO_CLOSES_INCIDENTS}`}</PageHeaderMeterCaption>
                            </PageHeaderMeterBlock>
                        </>
                    ) : undefined
                }
                filters={
                    <PageHeaderFilterButton icon={Clock3} onClick={() => s.toast('success', 'Up to date · as at 9:12 am · Pacific/Auckland')}>
                        As at 9:12 am · Pacific/Auckland
                    </PageHeaderFilterButton>
                }
            />
            {!canSeeAll(p) ? (
                <Card className="items-center gap-3 p-10 text-center">
                    <Lock className="size-8 text-muted-foreground" aria-hidden="true" />
                    <h2 className="text-section-title">This queue is for people who review incidents</h2>
                    <p className="text-subtle">Incidents you reported are in the Incidents module, under your reports.</p>
                </Card>
            ) : (
                <section className="flex flex-col gap-2.5" aria-label="Awaiting review">
                    <ListCaption title="Awaiting review" caption={`${rows.length} shown · newest first`} />
                    {rows.length ? (
                        <EntityTable<Row>
                            rows={rows}
                            rowKey={(r) => r.id}
                            rowHeight="content"
                            minWidth={960}
                            identityLabel="Incident"
                            identityWidth="1.9fr"
                            identity={(r) => ({ icon: Siren, name: r.id, subline: <Wrap><span className="text-foreground">{r.title}</span> · {r.reported}</Wrap> })}
                            columns={[
                                {
                                    key: 'who',
                                    label: 'Person',
                                    width: '1.1fr',
                                    cell: (r) =>
                                        incOf(r) && incOf(r)!.source !== 'error' ? (
                                            <span className="text-[12.5px]">{HOUSES[PEOPLE[r.pid].house]}</span>
                                        ) : (
                                            <span className="flex items-center gap-2 text-[12.5px]">
                                                <PersonDisc name={PEOPLE[r.pid].legal} size={24} />
                                                {PEOPLE[r.pid].pref}
                                            </span>
                                        ),
                                },
                                { key: 'status', label: 'Status', width: '1fr', cell: (r) => <span className="text-[12.5px]">{r.status}</span> },
                                {
                                    key: 'med',
                                    label: 'Medication side',
                                    width: '1.6fr',
                                    cell: (r) => {
                                        const i = incOf(r);
                                        if (!i) return <span className="text-[12.5px] text-muted-foreground">—</span>;
                                        const st = incidentState(s.rt, i);
                                        return (
                                            <span className="flex flex-col items-start gap-1 py-0.5">
                                                <StatusBadge variant={st.variant} className="rounded-[8px]">
                                                    {st.label}
                                                </StatusBadge>
                                                {st.line ? <StateLine>{st.line}</StateLine> : null}
                                            </span>
                                        );
                                    },
                                },
                                {
                                    key: 'act',
                                    label: '',
                                    width: '150px',
                                    align: 'right',
                                    cell: (r) => {
                                        const i = incOf(r);
                                        return i && canCloseIncident(p) && isReady(s.rt, i) ? (
                                            <Button size="sm" data-return={`if-${i.id}`} onClick={stop(() => open(`incclose:${i.id}`))}>
                                                Review and close
                                            </Button>
                                        ) : null;
                                    },
                                },
                            ]}
                            actionsFor={menu}
                            onOpen={(r) => {
                                const i = incOf(r);
                                if (i && canCloseIncident(p) && isReady(s.rt, i)) open(`incclose:${i.id}`);
                                else s.toast('info', `${r.id} opens in the Incidents module — outside this preview.`);
                            }}
                            onRowContextMenu={(e, r) => ctx.openAt(e, r.id, menu(r))}
                        />
                    ) : (
                        <Card className="p-2">
                            <EmptyState variant="compact" icon={Siren} title="Nothing awaiting review" description="Submitted incidents appear here until they’re reviewed." />
                        </Card>
                    )}
                    {ctx.node}
                </section>
            )}
            <DesignNote title="Design note — a frame of the Incidents module (deviation, Main Q6)">
                <p>
                    Today an incident made from a medication error is titled “Medication Error: {'{type}'}”, copies the free text and shows the medicine’s name on its detail page to anyone who can see incidents; nothing on the medication side ever closes one (AUDIT 1, 3, 4). Here it carries the neutral title and closes through the module’s own review → close path and its check. Only the medication-side column is P08b’s; the rest of this page is the module as it is.
                </p>
            </DesignNote>
        </Shell>
    );
}
