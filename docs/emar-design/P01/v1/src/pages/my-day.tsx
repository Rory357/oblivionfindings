/* My Day — the real screen: the app's own MyDayHeader and WorkSchedule list,
 * with synthetic props. P01 designs the Medicines card (same counts as Meds
 * today, Open meds, the worker's own follow-ups, no separate "mark as given"
 * path) and the rostered medication tasks in the day list (one task per dose
 * time per house for everyone rostered; it completes by itself). The rest of
 * My Day is unchanged. NF-14: the dormant /my-day/medications routes retire. */
import { CounterPill } from '@/components/lists/entity-cells';
import { type MenuItem } from '@/components/lists/entity-menu';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { StatusBadge } from '@/components/ui/status-badge';
import { MyDayHeader, type MyDayView, type WorkFilter } from '@/pages/my-day/components/my-day-header';
import { WorkSchedule, type CalendarWorkEntry } from '@/pages/sites/calendar/work-schedule';
import { ArrowRight, ClipboardList, Flag, HelpCircle, Lock, Pill, UserRound, Users } from 'lucide-react';
import { useState } from 'react';
import { NOW_UTC } from '../clock';
import { requirementsFor } from '../contract';
import { PEOPLE, PERSONAS, allDoseById, type Dose } from '../data';
import { useOpen } from '../doses';
import { Shell } from '../shell';
import { useCounts, useStore } from '../store';
import { MedTaskBadge, useMedTasks } from '../tasks-model';
import { DesignNote, DoseBadge, Notice } from '../ui';

const at = (hhmm: string) => new Date(`2026-09-28T${hhmm}:00+13:00`).getTime();

export function MyDayPage() {
    const s = useStore();
    const c = useCounts();
    const tasks = useMedTasks();
    const [view, setView] = useState<MyDayView>('today');
    const [filter, setFilter] = useState<WorkFilter>('all');
    const [person, setPerson] = useState<'all' | number>('all');
    const [search, setSearch] = useState('');
    const scn = s.route.scenario;
    const persona = PERSONAS[s.route.persona];
    const residents = ['aroha', 'tama', 'mele', 'grace', 'sam'].map((id, i) => ({ id: PEOPLE[id].clientId, first_name: PEOPLE[id].pref, name: PEOPLE[id].pref, initials: PEOPLE[id].initials, hue: [260, 190, 140, 40, 330][i], photo_url: null }));
    const notClocked = scn === 'notClockedIn' && !s.clockedIn;

    const medEntries: CalendarWorkEntry[] = tasks.map((t) => {
        const actions: MenuItem[] = [
            { label: 'Open in Meds today', icon: Pill, onClick: () => s.go('/meds/today') },
            { label: 'Why is this on my list?', icon: HelpCircle, onClick: () => s.toast('info', 'You’re rostered on a shift covering Kōwhai House from 7:00 am to 3:00 pm, and these doses fall inside it. Everyone rostered sees it until every dose has an outcome; a lead can assign it to one person.') },
            ...(s.route.persona === 'lead' ? [{ label: 'Assign to one person', icon: UserRound, onClick: () => s.toast('info', 'Assigned to one person: the others on shift stop seeing it; overdue alerts still reach the house lead.') }] : []),
        ];
        return {
            key: `med-${t.slot}`,
            at: at(t.slot === '8:00 am' ? '08:00' : t.slot === '9:00 am' ? '09:00' : '12:00'),
            title: `Medicines due ${t.slot} — Kōwhai House`,
            person: t.people.join(', '),
            icon: Pill,
            source: 'medication',
            meta: `${t.total} ${t.total === 1 ? 'dose' : 'doses'} · ${t.done} of ${t.total} recorded${t.blocked ? ` · ${t.blocked} ${t.blocked === 1 ? 'needs' : 'need'} help` : ''} · completes by itself`,
            status: <MedTaskBadge t={t} />,
            openLabel: 'Open meds',
            onOpen: () => s.go('/meds/today'),
            actions,
        };
    });
    const other: CalendarWorkEntry[] = [
        { key: 't1', at: at('10:30'), title: 'Walk to the library', person: 'Tama', icon: ClipboardList, source: 'checklist', meta: 'Support plan activity', status: <StatusBadge variant="neutral">To do</StatusBadge>, openLabel: 'Open task', onOpen: () => s.toast('info', 'Support tasks are unchanged — outside P01.') },
        { key: 't2', at: at('13:30'), title: 'Grocery shop with Mele', person: 'Mele', icon: ClipboardList, source: 'checklist', meta: 'Shift task', status: <StatusBadge variant="neutral">To do</StatusBadge>, openLabel: 'Open task', onOpen: () => s.toast('info', 'Support tasks are unchanged — outside P01.') },
    ];
    const entries = (filter === 'meds' ? medEntries : filter === 'tasks' ? other : filter === 'attention' ? medEntries.filter((e) => /late|help/.test(e.meta ?? '')) : [...medEntries, ...other]).sort((a, b) => (a.at ?? 0) - (b.at ?? 0));

    return (
        <Shell crumbs={[{ title: 'Home', href: '/dashboard' }, { title: 'My Day' }]}>
            <MyDayHeader
                unavailable={scn === 'unavailable' ? ['Medications'] : undefined}
                dateLabel="Monday 28 September 2026"
                siteName="Kōwhai House"
                shiftLabel="7:00 am – 3:00 pm"
                clockedIn={!notClocked}
                hasShift
                onBreak={false}
                residents={residents}
                person={person}
                onPerson={setPerson}
                search={search}
                onSearch={setSearch}
                workFilter={filter}
                onWorkFilter={setFilter}
                view={view}
                onView={setView}
                taskTotal={2}
                taskDone={0}
                medTotal={c.denom}
                medRecorded={c.recordedN}
                attention={c.late.length + c.needsHelp.length + s.followUps.filter((f) => f.state === 'overdue').length}
                unreadHandover={false}
                canAdd
                onAdd={() => s.toast('info', 'Adding a task is unchanged — outside P01.')}
            />
            <div className="grid gap-5 xl:grid-cols-[minmax(0,1fr)_minmax(0,1.4fr)]">
                <MedicinesCard />
                <div className="min-w-0">
                    <WorkSchedule entries={entries} anytime={[]} now={NOW_UTC} startsAt={at('07:00')} endsAt={at('15:00')} caption={`${persona.name} · Kōwhai House · 7:00 am–3:00 pm`} />
                </div>
            </div>
            <DesignNote title="Design note — My Day">
                <p>My Day keeps its approved design; the header and the day list above are the real components. P01 adds the Medicines card and the rostered medication tasks (a task per dose time per house for everyone rostered on a covering shift — it can’t be ticked off by hand and completes when every dose has an outcome).</p>
                <p>NF-14: the dormant one-tap routes (/my-day/medications/…/administer, /refuse, /snooze) and the unmounted stream menu are retired, so My Day has no separate “mark as given” path. Recording always opens the shared recording steps.</p>
            </DesignNote>
        </Shell>
    );
}

/* ───────────── the Medicines card ───────────── */
export function MedicinesCard() {
    const s = useStore();
    const c = useCounts();
    const open = useOpen();
    const scn = s.route.scenario;
    const me = PERSONAS[s.route.persona].name;
    const mine = s.followUps.filter((f) => f.owner === me && f.state !== 'done');
    const confirmItems = Object.entries(s.records).filter(([, r]) => r.second?.forgot && !r.second.answer && r.second.name === me);
    const head = (
        <div className="flex flex-wrap items-baseline justify-between gap-2">
            <h2 className="text-section-title flex items-center gap-2">
                <Pill className="size-4 text-primary" /> Medicines
            </h2>
            <span className="text-caption">Kōwhai House · your shift 7:00 am–3:00 pm · same schedule as Meds today</span>
        </div>
    );
    if (scn === 'loading')
        return (
            <Card className="gap-3 p-5" aria-busy="true" aria-label="Medicines">
                {head}
                <Skeleton className="h-6 w-3/4" />
                <Skeleton className="h-16 w-full" />
            </Card>
        );
    if (scn === 'unavailable')
        return (
            <Card className="gap-3 p-5" aria-label="Medicines">
                {head}
                <Notice tone="critical" title="Couldn’t load medicines">
                    Some doses may already be recorded. Don’t give anything from memory — open Meds today or use the printed MAR.
                </Notice>
                <div>
                    <Button className="frontline-tap" onClick={() => s.go('/meds/today')}>
                        <Pill className="size-4" /> Open meds
                    </Button>
                </div>
            </Card>
        );
    const attention = c.st.filter((x) => x.st === 'late' || x.st === 'due').slice(0, 4);
    const link = (state?: string) => () => s.go('/meds/today', { state });
    return (
        <Card className="gap-4 p-5" aria-label="Medicines">
            {head}
            {scn === 'empty' ? (
                <p className="text-sm">Nothing left to record on your shift.</p>
            ) : (
                <div className="flex flex-wrap gap-2" role="group" aria-label="Medicine counts — the same as Meds today">
                    <CountChip label="Due now" n={c.due.length} onClick={link('open')} />
                    <CountChip label="Late" n={c.late.length} tone={c.late.length ? 'warning' : undefined} onClick={link('open')} />
                    <CountChip label="Needs help" n={c.needsHelp.length} tone={c.needsHelp.length ? 'critical' : undefined} onClick={link('help')} icon={Lock} />
                    <CountChip label="Recorded" n={`${c.recordedN} of ${c.denom}`} onClick={() => s.go('/meds/today', { view: 'activity' })} />
                </div>
            )}
            {attention.length ? (
                <div>
                    <p className="text-[10px] font-bold tracking-[0.08em] text-muted-foreground uppercase">Due or late now</p>
                    <ul className="mt-1 divide-y">
                        {attention.map(({ d, st }) => (
                            <li key={d.id} className="flex items-center justify-between gap-3 py-2">
                                <span className="min-w-0">
                                    <span className="block truncate text-[13px] font-semibold">
                                        {PEOPLE[d.pid].pref} · {d.med}
                                    </span>
                                    <span className="block text-caption">{d.slot}{blockedNote(d, s.ctx)}</span>
                                </span>
                                <DoseBadge state={st} size="sm" />
                            </li>
                        ))}
                    </ul>
                </div>
            ) : null}
            {confirmItems.length ? (
                <div className="space-y-2">
                    <p className="text-[10px] font-bold tracking-[0.08em] text-muted-foreground uppercase">Needs your answer</p>
                    {confirmItems.map(([id, r]) => (
                        <div key={id} className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-status-warning/40 bg-status-warning-bg p-3 text-sm">
                            <span>
                                <strong>Were you there?</strong> {r.by} named you as the second person for {PEOPLE[doseOf(id).pid].pref}’s {doseOf(id).med.toLowerCase()} · answer by 9:42 am
                            </span>
                            <Button size="sm" onClick={() => open(`confirm:${id}`)}>
                                <Users className="size-4" /> Answer
                            </Button>
                        </div>
                    ))}
                </div>
            ) : null}
            <div>
                <p className="text-[10px] font-bold tracking-[0.08em] text-muted-foreground uppercase">Your follow-ups</p>
                {mine.length ? (
                    <ul className="mt-1 divide-y">
                        {mine.map((f) => (
                            <li key={f.id} className="flex items-center justify-between gap-3 py-2">
                                <span className="min-w-0">
                                    <span className="block truncate text-[13px] font-semibold">{f.title}</span>
                                    <span className="block text-caption">{f.line}</span>
                                </span>
                                <StatusBadge variant={f.state === 'overdue' ? 'critical' : 'info'} size="sm">
                                    {f.state === 'overdue' ? 'Overdue' : f.due}
                                </StatusBadge>
                            </li>
                        ))}
                    </ul>
                ) : (
                    <p className="text-caption">None open.</p>
                )}
            </div>
            <div className="flex flex-wrap gap-2">
                <Button className="frontline-tap" onClick={() => s.go('/meds/today')}>
                    <Pill className="size-4" /> Open meds <ArrowRight className="size-4" />
                </Button>
                <Button variant="outline" className="frontline-tap" onClick={() => s.go('/meds/today', { view: 'followups' })}>
                    <Flag className="size-4" /> Open follow-ups
                </Button>
            </div>
        </Card>
    );
}
function blockedNote(d: Dose, ctx: Parameters<typeof requirementsFor>[1]) {
    const r = requirementsFor(d, ctx);
    return r.blockAll || r.blockGiven ? ' · needs help' : r.allergy.match ? ' · possible allergy match' : '';
}
const doseOf = (id: string) => allDoseById(id);
function CountChip({ label, n, tone, onClick, icon: Icon }: { label: string; n: number | string; tone?: 'warning' | 'critical'; onClick: () => void; icon?: typeof Lock }) {
    return (
        <Button type="button" variant="outline" className="frontline-tap" onClick={onClick} aria-label={`${label}: ${n} — open in Meds today`}>
            {Icon ? <Icon className="size-3.5" /> : null}
            {label}
            <CounterPill tone={tone ?? 'neutral'}>{n}</CounterPill>
        </Button>
    );
}
