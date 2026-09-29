/* All Tasks — reference frame. All Tasks keeps its own page design (it is a
 * separate migration target); P01 adds a medication task provider. This frame
 * shows exactly what the provider emits, in the shared list contract, per
 * persona: rostered medication tasks, a witness-override request for the
 * manager, "were you there?" for a named colleague, and house-lead follow-ups
 * raised by the recording contract. */
import { EntityTable } from '@/components/lists/entity-table';
import { ListCaption } from '@/components/lists/list-caption';
import { Button } from '@/components/ui/button';
import { StatusBadge } from '@/components/ui/status-badge';
import { CheckCircle2, FileText, HelpCircle, ListTodo, Pill, ShieldCheck, UserRound, Users } from 'lucide-react';
import type { ReactNode } from 'react';
import { PEOPLE, PERSONAS, allDoseById, type Dose } from '../data';
import { useOpen, useRowContext } from '../doses';
import { Shell } from '../shell';
import { useStore } from '../store';
import { DesignNote } from '../ui';
import { MedTaskBadge, useMedTasks } from '../tasks-model';

interface Row {
    id: string;
    icon: typeof Pill;
    title: string;
    sub: string;
    module: string;
    due: string;
    who: string;
    state: ReactNode;
    open: () => void;
    openLabel: string;
    menu: { label: string; icon: typeof Pill; onClick: () => void }[];
}

export function TasksPage() {
    const s = useStore();
    const open = useOpen();
    const tasks = useMedTasks();
    const ctx = useRowContext();
    const p = s.route.persona;
    const me = PERSONAS[p].name;
    const rows: Row[] = [];

    if (p === 'pm' && s.route.scenario === 'cdNoWitness' && s.override === 'waiting')
        rows.push({
            id: 'ovr',
            icon: ShieldCheck,
            title: 'Witness override request — Grace · clonazepam',
            sub: 'From Priya Shah at 9:13 am · Kōwhai House · nobody else on shift can witness (roster attached)',
            module: 'Medication',
            due: 'Now — the 9:00 am dose is waiting',
            who: 'People who can grant witness overrides',
            state: <StatusBadge variant="critical">Waiting for you</StatusBadge>,
            open: () => open('ovr-approve'),
            openLabel: 'Review',
            menu: [
                { label: 'Review and approve', icon: CheckCircle2, onClick: () => open('ovr-approve') },
                { label: 'Decline', icon: UserRound, onClick: () => open('ovr-approve:decline') },
            ],
        });
    Object.entries(s.records)
        .filter(([, r]) => r.second?.forgot && !r.second.answer && r.second.name === me)
        .forEach(([id, r]) => {
            const d = allDoseById(id);
            rows.push({
                id: `c-${id}`,
                icon: Users,
                title: `Were you there? — ${PEOPLE[d.pid].pref} · ${d.med.toLowerCase()}`,
                sub: `${r.by} named you as the second person at ${r.at} because you’d forgotten your PIN`,
                module: 'Medication',
                due: 'Answer by 9:42 am',
                who: 'You',
                state: <StatusBadge variant="warning">Needs your answer</StatusBadge>,
                open: () => open(`confirm:${id}`),
                openLabel: 'Answer',
                menu: [{ label: 'Answer', icon: Users, onClick: () => open(`confirm:${id}`) }],
            });
        });
    if (p === 'lead' || p === 'pm') {
        Object.entries(s.records).forEach(([id, r]) => {
            const d: Dose = allDoseById(id);
            r.warn?.forEach((w, i) => {
                const kind = w.startsWith('Prescriber') ? 'rx' : w.startsWith('Different amount not confirmed') ? 'amt' : w.startsWith('No witness') ? 'ov' : w.startsWith('More than') ? 'err' : null;
                if (!kind) return;
                rows.push({
                    id: `l-${id}-${i}`,
                    icon: kind === 'rx' ? FileText : kind === 'ov' ? ShieldCheck : HelpCircle,
                    title: kind === 'rx' ? `Countersign a prescriber’s phone instruction — ${PEOPLE[d.pid].pref}` : kind === 'amt' ? `Check a partial dose not confirmed by a second person — ${PEOPLE[d.pid].pref}` : kind === 'ov' ? `Review a controlled dose given without a witness — ${PEOPLE[d.pid].pref}` : `Review medication error ME-2026-031 — ${PEOPLE[d.pid].pref}`,
                    sub: `${d.med} ${d.slot} · recorded by ${r.by} at ${r.at}`,
                    module: 'Medication',
                    due: kind === 'rx' ? 'By the end of the next day (organisation setting)' : 'By the end of the next shift',
                    who: 'Jordan Tipene (house lead)',
                    state: <StatusBadge variant="warning">{kind === 'rx' ? 'Waiting to countersign' : 'Needs review'}</StatusBadge>,
                    open: () => s.toast('info', kind === 'rx' ? 'Countersigning is designed in P04 (orders).' : 'Follow-up review is designed in P08a.'),
                    openLabel: kind === 'rx' ? 'Countersign' : 'Review',
                    menu: [{ label: 'Open the dose', icon: Pill, onClick: () => open(`detail:${id}`) }],
                });
            });
            if (r.second?.answer === 'no')
                rows.push({ id: `d-${id}`, icon: Users, title: `Check a dose co-signed without a PIN — ${PEOPLE[d.pid].pref}`, sub: `${d.med} · named second person ${r.second.name} answered “I wasn’t there”`, module: 'Medication', due: 'By the end of the next shift', who: 'Jordan Tipene (house lead)', state: <StatusBadge variant="critical">Disputed</StatusBadge>, open: () => open(`detail:${id}`), openLabel: 'Review', menu: [{ label: 'Open the dose', icon: Pill, onClick: () => open(`detail:${id}`) }] });
        });
    }
    (p === 'pm' ? [] : tasks).forEach((t) =>
        rows.push({
            id: `m-${t.slot}`,
            icon: Pill,
            title: `Medicines due ${t.slot} — Kōwhai House`,
            sub: `${t.total} ${t.total === 1 ? 'dose' : 'doses'} for ${t.people.join(', ')} · from your rostered shift 7:00 am–3:00 pm`,
            module: 'Medication',
            due: t.slot,
            who: 'Everyone rostered on a shift covering Kōwhai House',
            state: (
                <span className="flex flex-col items-start gap-1">
                    <MedTaskBadge t={t} />
                    <span className="text-[12px] text-muted-foreground">
                        {t.done} of {t.total} recorded{t.blocked ? ` · ${t.blocked} ${t.blocked === 1 ? 'needs' : 'need'} help` : ''} · {t.state === 'done' ? 'completed by itself' : 'completes by itself when every dose has an outcome'}
                    </span>
                </span>
            ),
            open: () => s.go('/meds/today'),
            openLabel: 'Open meds',
            menu: [
                { label: 'Open in Meds today', icon: Pill, onClick: () => s.go('/meds/today') },
                { label: 'Why is this on my list?', icon: HelpCircle, onClick: () => s.toast('info', 'You’re rostered on a shift covering Kōwhai House from 7:00 am to 3:00 pm, and these doses fall inside it. Everyone rostered sees it until every dose has an outcome; a lead can assign it to one person.') },
                ...(p === 'lead' ? [{ label: 'Assign to one person', icon: UserRound, onClick: () => s.toast('info', 'Assigned: the others on shift stop seeing it; overdue alerts still reach the house lead.') }] : []),
            ],
        }),
    );
    s.followUps
        .filter((f) => f.owner === PERSONAS[p].name)
        .forEach((f) => rows.push({ id: f.id, icon: ListTodo, title: f.title, sub: f.src, module: 'Medication · follow-up', due: f.due, who: f.owner, state: <StatusBadge variant={f.state === 'overdue' ? 'critical' : 'info'}>{f.state === 'overdue' ? 'Overdue' : 'Open'}</StatusBadge>, open: () => s.go('/meds/today', { view: 'followups' }), openLabel: 'Open', menu: [{ label: 'Open follow-ups', icon: ListTodo, onClick: () => s.go('/meds/today', { view: 'followups' }) }] }));

    return (
        <Shell crumbs={[{ title: 'Home', href: '/dashboard' }, { title: 'All Tasks' }]}>
            <DesignNote title="Reference frame — All Tasks keeps its own design">
                <p>Only the medication rows are new: a medication task provider, CD-concealed and scoped like the other providers. They’re shown here in the shared list contract with the exact text, owner, due time and actions the provider emits; All Tasks renders them in its existing list. Today (verified) All Tasks has no dose or round provider.</p>
            </DesignNote>
            <section className="flex flex-col gap-2.5" aria-label="Medication tasks">
                <ListCaption title={`Medication · for ${PERSONAS[p].name}`} caption={`${rows.length} of ${rows.length} shown`} />
                <EntityTable<Row>
                    rows={rows}
                    rowKey={(r) => r.id}
                    rowHeight="content"
                    minWidth={960}
                    identityLabel="Task"
                    identityWidth="2fr"
                    identity={(r) => ({ icon: r.icon, name: r.title, subline: r.sub })}
                    columns={[
                        { key: 'due', label: 'Due', width: '1fr', cell: (r) => <span className="text-[12.5px]">{r.due}</span> },
                        { key: 'who', label: 'Owner', width: '1.2fr', cell: (r) => <span className="text-[12.5px]">{r.who}</span> },
                        { key: 'st', label: 'State', width: '1.4fr', cell: (r) => r.state },
                        { key: 'act', label: '', width: '130px', align: 'right', cell: (r) => <Button size="sm" variant="outline" className="frontline-tap" onClick={(e) => (e.stopPropagation(), r.open())}>{r.openLabel}</Button> },
                    ]}
                    actionsFor={(r) => r.menu}
                    onOpen={(r) => r.open()}
                    onRowContextMenu={(e, r) => ctx.openAt(e, r.title, r.menu)}
                />
            </section>
            {ctx.node}
        </Shell>
    );
}
