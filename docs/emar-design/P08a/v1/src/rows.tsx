/* The follow-up list, shared by Meds today › Follow-ups, Safety & oversight ›
 * Follow-ups and All Tasks: the real EntityTable (identity first, kebab last),
 * one MenuItem[] feeding the ⋯ menu AND the right-click / keyboard menu, row
 * click opens the follow-up. */
import { PersonDisc } from '@/components/lists/entity-cells';
import { EntityContextMenu, compactMenu, type MenuItem } from '@/components/lists/entity-menu';
import { EntityTable } from '@/components/lists/entity-table';
import { Button } from '@/components/ui/button';
import { ClipboardList, Eye, Flag, Repeat, UserCheck } from 'lucide-react';
import { useState, type MouseEvent } from 'react';
import { HOUSES, PEOPLE, PERSONAS, TYPE_LABEL } from './data';
import { primaryAction, ownerLines, stateLines } from './dialogs';
import { useOpen } from './host';
import { canAct, dueLabel, isOpen } from './model';
import { useStore, type Row } from './store';
import { FuBadge, PersonMark, StateLine, TypeChip } from './ui';

export function useRowContext() {
    const [ctx, setCtx] = useState<{ x: number; y: number; title: string; items: MenuItem[] } | null>(null);
    const openAt = (e: MouseEvent, title: string, items: MenuItem[]) => {
        if (e.target instanceof Element && e.target.closest('a[href]')) return;
        e.preventDefault();
        const b = (e.currentTarget as HTMLElement).getBoundingClientRect();
        const kb = e.clientX === 0 && e.clientY === 0;
        setCtx({ x: kb ? b.left + 12 : e.clientX, y: kb ? b.top + 12 : e.clientY, title, items });
    };
    const node = ctx ? <EntityContextMenu x={ctx.x} y={ctx.y} icon={Flag} title={ctx.title} items={ctx.items} onClose={() => setCtx(null)} /> : null;
    return { openAt, node };
}

export function FollowUpTable({ rows, showHouse, keyPrefix }: { rows: Row[]; showHouse?: boolean; keyPrefix: string }) {
    const s = useStore();
    const open = useOpen();
    const ctx = useRowContext();
    const p = s.route.persona;
    const menu = (r: Row): MenuItem[] => {
        const act = canAct(r.f, p);
        const openNow = isOpen(r.state) && r.state !== 'queued';
        const prim = primaryAction(r.f);
        return compactMenu([
            openNow && act.close && prim && { label: prim.label, icon: ClipboardList, onClick: () => open(prim.spec) },
            { label: 'View details and history', icon: Eye, onClick: () => open(`fu:${r.f.id}`) },
            openNow && act.reassign && r.f.type !== 'confirm' && { label: 'Reassign', icon: UserCheck, onClick: () => open(`reassign:${r.f.id}`) },
            r.f.prn && { label: 'View the as-needed dose', icon: Repeat, onClick: () => open(`prn:${r.f.id}`) },
            { separator: true },
            !!r.f.pid && { label: `Open ${PEOPLE[r.f.pid].pref}’s medication record`, icon: ClipboardList, onClick: () => s.toast('info', 'The person’s medication record is P02’s approved design — outside this preview.') },
        ]);
    };
    const action = (r: Row) => {
        const act = canAct(r.f, p);
        const openNow = isOpen(r.state) && r.state !== 'queued';
        const prim = primaryAction(r.f);
        const stop = (fn: () => void) => (e: MouseEvent) => (e.stopPropagation(), fn());
        if (openNow && act.close && prim)
            return (
                <Button data-return={`${keyPrefix}-${r.f.id}`} size="sm" variant={r.state === 'overdue' ? 'default' : 'outline'} className="frontline-tap" onClick={stop(() => open(prim.spec))}>
                    {prim.label}
                </Button>
            );
        return (
            <Button data-return={`${keyPrefix}-${r.f.id}`} size="sm" variant="ghost" className="frontline-tap" onClick={stop(() => open(`fu:${r.f.id}`))}>
                View
            </Button>
        );
    };
    return (
        <>
            <EntityTable<Row>
                rows={rows}
                rowKey={(r) => r.f.id}
                rowHeight="content"
                minWidth={960}
                identityLabel="Person"
                identityWidth="160px"
                identity={(r) => (r.f.pid ? { mark: <PersonMark pid={r.f.pid} />, name: PEOPLE[r.f.pid].pref, subline: showHouse ? HOUSES[r.f.house] : PEOPLE[r.f.pid].surname } : { icon: Repeat, name: HOUSES[r.f.house], subline: r.f.type === 'handover' ? 'Shift handover' : 'Several doses' })}
                columns={[
                    {
                        key: 'what',
                        label: 'Follow-up',
                        width: '2fr',
                        cell: (r) => (
                            <span className="flex min-w-0 flex-col gap-1 py-0.5">
                                <span className="text-[13px] font-semibold break-words">{r.f.title}</span>
                                <span className="flex flex-wrap items-center gap-1.5">
                                    <TypeChip cd={r.f.cd}>{TYPE_LABEL[r.f.type]}</TypeChip>
                                    <span className="text-[12px] text-muted-foreground">{r.f.source}</span>
                                </span>
                            </span>
                        ),
                    },
                    {
                        key: 'owner',
                        label: 'Owner',
                        width: '1.2fr',
                        cell: (r) => (
                            <span className="flex flex-col gap-1 py-0.5 text-[12.5px]">
                                <span className="flex items-center gap-2">
                                    {r.owner ? <PersonDisc name={r.owner} size={22} /> : null}
                                    {r.owner ?? <span className="font-semibold text-status-warning">Set when the handover is acknowledged</span>}
                                </span>
                                {ownerLines(r).map((l) => (
                                    <StateLine key={l}>{l}</StateLine>
                                ))}
                            </span>
                        ),
                    },
                    { key: 'due', label: 'Due', width: '0.85fr', cell: (r) => <span className="text-[12.5px] font-semibold">{dueLabel(r.f)}</span> },
                    {
                        key: 'state',
                        label: 'State',
                        width: '1.5fr',
                        cell: (r) => (
                            <span className="flex flex-col items-start gap-1 py-0.5">
                                <FuBadge state={r.state} />
                                {stateLines(r, s.route.scenario, PERSONAS[p].name).map((l) => (
                                    <StateLine key={l.text} tone={l.tone}>
                                        {l.text}
                                    </StateLine>
                                ))}
                            </span>
                        ),
                    },
                    { key: 'act', label: '', width: '170px', align: 'right', cell: (r) => action(r) },
                ]}
                actionsFor={menu}
                onOpen={(r) => open(`fu:${r.f.id}`)}
                onRowContextMenu={(e, r) => ctx.openAt(e, r.f.title, menu(r))}
                mutedFor={(r) => !isOpen(r.state)}
            />
            {ctx.node}
        </>
    );
}
