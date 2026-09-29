/* Clinical — INR, syringe driver, observations taken with doses. Absorbs
 * RecordInrDialog and SyringeDriverDialog (both broken today, AUDIT.md). INR:
 * every reading is shown, linked to the warfarin order when there is one
 * (Stephan, 30 Sep: "follow industry standard" — a test result is never hidden). */
import { EntityContextMenu, compactMenu, type MenuItem } from '@/components/lists/entity-menu';
import { EntityTable } from '@/components/lists/entity-table';
import { ListCaption } from '@/components/lists/list-caption';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { SkeletonTable } from '@/components/ui/skeleton-table';
import { StatusBadge } from '@/components/ui/status-badge';
import { Activity, AlertTriangle, Ban, CheckCircle2, ClipboardCheck, Info, Link2, LockKeyhole, Plus, Stethoscope, Syringe, ArrowUpRight } from 'lucide-react';
import { useState, type MouseEvent } from 'react';
import { DRIVER, FACTS, OBSERVATIONS, medsOf, type DriverCheck, type InrReading, type PersonId } from '../data';
import { PEOPLE } from '../p01/data';
import { DesignNote, Notice, NotConfigured } from '../p01/ui';
import { useDlg, useP02 } from '../store';
import { CONCEALED, FactStrip, SectionCard } from '../ui';

function useCtx() {
    const [ctx, setCtx] = useState<{ x: number; y: number; title: string; items: MenuItem[] } | null>(null);
    const openAt = (e: MouseEvent, title: string, items: MenuItem[]) => {
        e.preventDefault();
        const b = (e.currentTarget as HTMLElement).getBoundingClientRect();
        const kb = e.clientX === 0 && e.clientY === 0;
        setCtx({ x: kb ? b.left + 12 : e.clientX, y: kb ? b.top + 12 : e.clientY, title, items });
    };
    return { openAt, node: ctx ? <EntityContextMenu x={ctx.x} y={ctx.y} icon={Stethoscope} title={ctx.title} items={ctx.items} onClose={() => setCtx(null)} /> : null };
}

export function inrStatus(r: InrReading): { label: string; variant: 'success' | 'warning' | 'critical' | 'neutral' } {
    if (!r.target) return { label: 'No target range recorded', variant: 'neutral' };
    if (r.value > r.target[1]) return { label: 'Above the target range', variant: 'critical' };
    if (r.value < r.target[0]) return { label: 'Below the target range', variant: 'warning' };
    return { label: 'In the target range', variant: 'success' };
}
export const targetText = (r: InrReading) => (r.target ? `${r.target[0].toFixed(1)}–${r.target[1].toFixed(1)}` : 'Not recorded');

export function ClinicalTab({ pid, view }: { pid: PersonId; view: string }) {
    const s = useP02();
    if (s.route.state === 'loading')
        return (
            <Card className="p-4">
                <SkeletonTable rows={5} columns={5} />
            </Card>
        );
    if (view === 'driver') return <Driver pid={pid} />;
    if (view === 'observations') return <Observations pid={pid} />;
    return <Inr pid={pid} />;
}

/* ───────────── INR ───────────── */
function Inr({ pid }: { pid: PersonId }) {
    const s = useP02();
    const dlg = useDlg();
    const ctx = useCtx();
    const warfarin = medsOf(pid).find((m) => m.inr && m.status !== 'stopped');
    const canManage = s.can('orders.manage');
    if (!warfarin)
        return (
            <Card className="p-2">
                <EmptyState icon={Activity} title={`${PEOPLE[pid].pref} has no medicine that needs INR tests`} description="INR results are recorded here when a medicine such as warfarin is on the chart. A result can still be recorded without one." action={canManage ? <Button variant="outline" onClick={() => dlg.open('inr:new')}>Record INR result</Button> : undefined} />
            </Card>
        );
    const readings = s.inr;
    const latest = readings.find((r) => !r.disabled);
    const stale = s.route.state === 'inrStale';
    const st = latest ? inrStatus(latest) : null;
    const menu = (r: InrReading): MenuItem[] =>
        compactMenu([
            { label: 'View result', icon: Info, onClick: () => dlg.open(`inr:${r.id}`) },
            canManage && !r.linked && !r.disabled && { label: 'Link to the warfarin order', icon: Link2, onClick: () => (s.linkInr(r.id), s.toast('success', `Linked to ${warfarin.name} ${warfarin.strength}.`)) },
            { separator: true },
            canManage && !r.disabled && { label: 'Mark as entered in error…', icon: Ban, danger: true, onClick: () => dlg.open(`inr-error:${r.id}`) },
        ]);
    return (
        <>
            {stale ? (
                <Notice tone="warning" icon={AlertTriangle} title={`INR test overdue — the next test was due ${latest?.next}`}>
                    No newer result has been recorded. Follow {PEOPLE[pid].pref}’s plan: contact the anticoagulation clinic or prescriber today, and record the result here when it comes. On-call contact: <NotConfigured />
                </Notice>
            ) : null}
            {latest ? (
                <div className="grid gap-5 xl:grid-cols-[1.6fr_1fr]">
                    <SectionCard
                        eyebrow="Latest INR"
                        icon={Activity}
                        title={
                            <span className="flex flex-wrap items-center gap-2">
                                {latest.value.toFixed(1)}
                                <StatusBadge variant={st!.variant} className="rounded-[8px]">
                                    {st!.label}
                                </StatusBadge>
                                {!latest.linked ? (
                                    <StatusBadge variant="warning" className="rounded-[8px]">
                                        Not linked to a medicine
                                    </StatusBadge>
                                ) : null}
                            </span>
                        }
                        right={canManage ? <Button onClick={() => dlg.open('inr:new')}><Plus className="size-4" /> Record INR result</Button> : null}
                    >
                        <p className="text-sm">
                            <span className="text-muted-foreground">Instruction · </span>
                            {latest.instruction} <span className="text-muted-foreground">({latest.source})</span>
                        </p>
                        <FactStrip
                            items={[
                                { label: 'Tested', value: latest.tested },
                                { label: 'Target (from the prescriber)', value: targetText(latest) },
                                { label: 'Next test', value: stale ? <span className="text-status-warning">Was due {latest.next}</span> : (latest.next ?? 'Not set') },
                            ]}
                        />
                        <p className="text-caption">
                            {latest.linked ? `Linked to ${warfarin.name} ${warfarin.strength} · ` : 'Not linked to a medicine · '}entered by {latest.by}
                        </p>
                    </SectionCard>
                    <SectionCard eyebrow="Linked order" title={`${warfarin.name} ${warfarin.strength}`} icon={Link2}>
                        <p className="text-sm">{warfarin.when} · {warfarin.amount}</p>
                        <p className="text-caption">{warfarin.order} · prescribed by {warfarin.prescriber}. The 5:00 pm dose follows the latest instruction above.</p>
                        {!canManage ? <p className="text-caption">INR results are recorded by house leads and clinical leads — ask Jordan Tipene.</p> : null}
                    </SectionCard>
                </div>
            ) : null}
            <section aria-label="INR results" className="flex flex-col gap-2.5">
                <ListCaption title="INR results" caption={`${readings.length} of ${readings.length} shown · newest first · last 3 months`} />
                <EntityTable<InrReading>
                    rows={readings}
                    rowKey={(r) => r.id}
                    identityLabel="Result"
                    identityWidth="1.2fr"
                    rowHeight="content"
                    minWidth={1080}
                    identity={(r) => ({ icon: Activity, name: `INR ${r.value.toFixed(1)}`, subline: `Tested ${r.tested}` })}
                    columns={[
                        { key: 'st', label: 'Against the target', width: '1.4fr', cell: (r) => (r.disabled ? <StatusBadge variant="neutral" className="rounded-[8px]">Entered in error</StatusBadge> : <StatusBadge variant={inrStatus(r).variant} className="rounded-[8px]">{inrStatus(r).label} · {targetText(r)}</StatusBadge>) },
                        { key: 'ins', label: 'Instruction', width: '1.4fr', cell: (r) => <span className="text-[12.5px]">{r.instruction}</span> },
                        { key: 'next', label: 'Next test', width: '0.9fr', cell: (r) => <span className="text-[12.5px]">{r.next ?? 'Not set'}</span> },
                        { key: 'link', label: 'Medicine', width: '1.1fr', cell: (r) => (r.linked ? <span className="text-[12.5px]">{warfarin.name} {warfarin.strength.replace(' tablet', '')}</span> : <StatusBadge variant="warning" className="rounded-[8px]">Not linked</StatusBadge>) },
                        { key: 'by', label: 'Entered by', width: '1fr', cell: (r) => <span className="text-[12.5px]">{r.by}</span> },
                    ]}
                    actionsFor={menu}
                    onOpen={(r) => dlg.open(`inr:${r.id}`)}
                    onRowContextMenu={(e, r) => ctx.openAt(e, `INR ${r.value.toFixed(1)} · ${r.tested}`, menu(r))}
                    mutedFor={(r) => !!r.disabled}
                />
                {ctx.node}
            </section>
            {s.route.state === 'inrUnlinked' ? (
                <DesignNote title="Design note — NF-23, an INR saved without a medicine">
                    <p>Today the MAR page’s Record INR never sends the medicine, and every INR list (this card, the Overview action list, the dashboard INR watch, reports) filters out results with no medicine — so this 2.4 would vanish. Here it shows, labelled “Not linked”, and a lead can link it. Stephan (30 Sep): “follow industry standard” → every result is shown; Record INR pre-selects the warfarin order. Confirm at approval.</p>
                </DesignNote>
            ) : (
                <DesignNote title="Design note — INR">
                    <p>Target and instruction come from the prescriber or anticoagulation clinic with each result — nothing is calculated. “No target recorded” is shown as that, never as “below range” (today’s mislabel). Results are never deleted; a wrong one is marked “entered in error” with a reason.</p>
                </DesignNote>
            )}
        </>
    );
}

/* ───────────── Syringe driver ───────────── */
function Driver({ pid }: { pid: PersonId }) {
    const s = useP02();
    const dlg = useDlg();
    const ctx = useCtx();
    const canManage = s.can('orders.manage');
    if (pid !== 'grace')
        return (
            <Card className="p-2">
                <EmptyState icon={Syringe} title="No syringe driver running" description={`A syringe driver is started here from ${PEOPLE[pid].pref}’s orders when the district nursing team or prescriber sets one up.`} action={canManage ? <Button variant="outline" onClick={() => dlg.open('driver:new')}>Start a syringe driver</Button> : undefined} />
            </Card>
        );
    if (!s.cdView)
        return (
            <Card className="gap-2 p-5">
                <p className="flex items-center gap-2 text-section-title">
                    <LockKeyhole className="size-4" aria-hidden="true" /> A syringe driver is running
                </p>
                <p className="text-subtle">Its details include a controlled medicine. {CONCEALED.subline}. {CONCEALED.ask}</p>
            </Card>
        );
    const checks: DriverCheck[] = [...s.driverChecks, ...DRIVER.checks];
    const finished = s.driverFinished;
    const menu = (c: DriverCheck): MenuItem[] => [{ label: 'View check', icon: Info, onClick: () => s.toast('info', `${c.at} · ${c.by} · ${c.running ? 'running' : 'not running'} · ${c.site} · ${c.remaining} left${c.note ? ` · ${c.note}` : ''}`) }];
    return (
        <>
            <SectionCard
                eyebrow="Syringe driver"
                icon={Syringe}
                title={finished ? `Finished ${finished}` : `Running since ${DRIVER.started}`}
                right={
                    !finished && canManage ? (
                        <>
                            <Button variant="outline" onClick={() => dlg.open('finish')}>
                                Finish the driver
                            </Button>
                            <Button onClick={() => dlg.open('check')}>
                                <ClipboardCheck className="size-4" /> Record a check
                            </Button>
                        </>
                    ) : null
                }
            >
                <FactStrip
                    items={[
                        { label: 'Rate', value: DRIVER.rate },
                        { label: 'Runs for', value: DRIVER.duration },
                        { label: 'Next check', value: finished ? '—' : `By ${checks[0].at === '9:12 am' ? '1:12 pm' : '12:05 pm'} (every 4 hours)` },
                    ]}
                />
                <ul className="divide-y rounded-lg border text-sm">
                    {DRIVER.contents.map((c) => (
                        <li key={c.med} className="flex flex-wrap items-center justify-between gap-2 px-3 py-2">
                            <span>
                                <strong>{c.med}</strong> · {c.dose}
                            </span>
                            {c.cd ? <StatusBadge variant="neutral" size="sm" className="rounded-[8px]">Controlled</StatusBadge> : null}
                        </li>
                    ))}
                </ul>
                <p className="text-caption">
                    Site: {DRIVER.site} · started by {DRIVER.by} · witnessed by {DRIVER.witness} · {DRIVER.plan}.
                </p>
                {!canManage && !finished ? <p className="text-caption">Checks are recorded by house leads and clinical leads today — ask Jordan Tipene.</p> : null}
            </SectionCard>
            <section aria-label="Driver checks" className="flex flex-col gap-2.5">
                <ListCaption title="Checks" caption={`${checks.length} of ${checks.length} shown · today`} />
                <EntityTable<DriverCheck>
                    rows={checks}
                    rowKey={(c) => c.at}
                    identityLabel="Check"
                    identityWidth="1.1fr"
                    rowHeight="content"
                    minWidth={900}
                    identity={(c) => ({ icon: c.running ? CheckCircle2 : AlertTriangle, name: c.at, subline: c.by })}
                    columns={[
                        { key: 'run', label: 'Running', width: '0.9fr', cell: (c) => (c.running ? <StatusBadge variant="success" className="rounded-[8px]">Running</StatusBadge> : <StatusBadge variant="critical" className="rounded-[8px]">Not running</StatusBadge>) },
                        { key: 'site', label: 'Site', width: '1.4fr', cell: (c) => <span className="text-[12.5px]">{c.site}</span> },
                        { key: 'left', label: 'Left in the syringe', width: '1fr', cell: (c) => <span className="text-[12.5px]">{c.remaining}</span> },
                        { key: 'note', label: 'Note', width: '1.3fr', cell: (c) => <span className="text-[12.5px]">{c.note ?? '—'}</span> },
                    ]}
                    actionsFor={menu}
                    onOpen={(c) => menu(c)[0].onClick?.()}
                    onRowContextMenu={(e, c) => ctx.openAt(e, `Check ${c.at}`, menu(c))}
                />
                {ctx.node}
            </section>
            <DesignNote title="Design note — syringe driver">
                <p>Today’s Start driver form never sends which order each medicine comes from, so the server always refuses it, and there is no way to record a check or finish a driver on the page. Here contents are picked from the person’s orders, checks are listed, and a driver can only finish after at least one check (today’s server rule). A driver containing a controlled medicine is hidden whole from people without controlled-medicine access (today’s rule).</p>
            </DesignNote>
        </>
    );
}

/* ───────────── Observations taken with doses ───────────── */
function Observations({ pid }: { pid: PersonId }) {
    const s = useP02();
    const dlg = useDlg();
    const ctx = useCtx();
    const rows = pid === 'aroha' ? OBSERVATIONS : [];
    const menu = (o: (typeof OBSERVATIONS)[number]): MenuItem[] => [{ label: 'View the dose record', icon: Info, onClick: () => dlg.open(`dose:${o.id}`) }];
    return (
        <>
            <Notice tone="neutral" icon={Info} title="Readings taken with a dose">
                These are saved with the dose record when a medication rule asks for a reading. Other readings (weight, blood pressure checks, NEWS) are in Health monitoring on the{' '}
                <Button variant="link" className="h-auto p-0" onClick={() => s.go(`/operations/clients/${FACTS[pid].clientId}`, { tab: 'health_monitoring' })}>
                    client profile <ArrowUpRight className="size-4" aria-hidden="true" />
                </Button>
                .
            </Notice>
            <section aria-label="Observations" className="flex flex-col gap-2.5">
                <ListCaption title="Observations" caption={`${rows.length} of ${rows.length} shown · last 7 days`} />
                {rows.length ? (
                    <EntityTable
                        rows={rows}
                        rowKey={(o) => o.id}
                        identityLabel="Reading"
                        identityWidth="1.4fr"
                        rowHeight="content"
                        minWidth={900}
                        identity={(o) => ({ icon: Stethoscope, name: `${o.reading.label} ${o.reading.value}`, subline: `With ${o.med}` })}
                        columns={[
                            { key: 'when', label: 'When', width: '0.9fr', cell: (o) => <span className="text-[12.5px]">{o.day} · {o.at}</span> },
                            { key: 'by', label: 'Taken by', width: '1fr', cell: (o) => <span className="text-[12.5px]">{o.by}</span> },
                            { key: 'why', label: 'Why it was asked for', width: '2fr', cell: (o) => <span className="text-[12.5px]">{o.rule}</span> },
                        ]}
                        actionsFor={menu}
                        onOpen={(o) => dlg.open(`dose:${o.id}`)}
                        onRowContextMenu={(e, o) => ctx.openAt(e, o.reading.label, menu(o))}
                    />
                ) : (
                    <Card className="p-2">
                        <EmptyState variant="compact" icon={Stethoscope} title="No readings taken with doses" description="None of this person’s medicines has a rule that asks for a reading." />
                    </Card>
                )}
                {ctx.node}
            </section>
            <DesignNote title="Design note — observations">
                <p>No range is shown or judged here — the person’s plan says what to do with a reading. Whether readings taken with a dose should also appear in Health monitoring is an open question (README Q6).</p>
            </DesignNote>
        </>
    );
}
