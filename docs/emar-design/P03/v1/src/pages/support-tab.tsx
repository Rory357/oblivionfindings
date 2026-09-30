/* The person record’s Support plan (plan §7.3: P03 owns it; P02 v1 drew By
 * medicine and Assessment pending decision D6). Four sub-views:
 *   By medicine — the support for each medicine, what staff do and how the dose
 *     is recorded (P00/P01 wording), who decided and when; change it here.
 *   Assessment — the current assessment (today’s scores and checks, in plain
 *     words), its result as “most independence allowed”, and earlier ones.
 *   Agreement — who agreed, how, and what each side does (Q3).
 *   Changes — every support change, request and reassessment, never deleted. */
import { compactMenu, EntityContextMenu, type MenuItem } from '@/components/lists/entity-menu';
import { EntityTable } from '@/components/lists/entity-table';
import { ListCaption } from '@/components/lists/list-caption';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { SkeletonTable } from '@/components/ui/skeleton-table';
import { StatusBadge } from '@/components/ui/status-badge';
import { ArrowUpRight, Check, ClipboardList, Eye, FileSignature, FileText, History, LockKeyhole, MessageSquareText, Pill, RefreshCw, Users, X } from 'lucide-react';
import { useState, type MouseEvent, type ReactNode } from 'react';
import { CHECKS, PEOPLE, SCORES, SCORE_WORDS, STORAGE, SUPPORT, earlierAssessments, medsOf, type Medicine, type PersonId } from '../data';
import { useOpen } from '../host';
import { agreementOf, assessmentOf, canAssess, canRecordConsent, capOf, cdView, changesOf, concealed, consentOf, statusOf, supportOf, triggersOf, whoAssesses } from '../model';
import { useStore } from '../store';
import { CONCEALED, ConcealedIdentity, DesignNote, FactStrip, Notice, PlanBadge, SectionCard, StateLine, SupportChip, Wrap } from '../ui';

function useCtx() {
    const [ctx, setCtx] = useState<{ x: number; y: number; title: string; items: MenuItem[] } | null>(null);
    const openAt = (e: MouseEvent, title: string, items: MenuItem[]) => {
        e.preventDefault();
        const b = (e.currentTarget as HTMLElement).getBoundingClientRect();
        const kb = e.clientX === 0 && e.clientY === 0;
        setCtx({ x: kb ? b.left + 12 : e.clientX, y: kb ? b.top + 12 : e.clientY, title, items });
    };
    return { openAt, node: ctx ? <EntityContextMenu x={ctx.x} y={ctx.y} icon={Pill} title={ctx.title} items={ctx.items} onClose={() => setCtx(null)} /> : null };
}

export function SupportTab({ pid, view }: { pid: PersonId; view: string }) {
    const s = useStore();
    if (s.route.scenario === 'loading')
        return (
            <Card className="p-4" aria-busy="true" aria-label="Loading the support plan">
                <SkeletonTable rows={5} columns={5} />
            </Card>
        );
    return (
        <>
            {view === 'changes' ? null : <PlanNotices pid={pid} agreementOnly={view === 'agreement'} />}
            {view === 'assessment' ? <AssessmentView pid={pid} /> : view === 'agreement' ? <AgreementView pid={pid} /> : view === 'changes' ? <ChangesView pid={pid} /> : <ByMedicine pid={pid} />}
        </>
    );
}

/* ───────────── what needs doing (shown on every sub-view) ───────────── */
function PlanNotices({ pid, agreementOnly = false }: { pid: PersonId; agreementOnly?: boolean }) {
    const s = useStore();
    const open = useOpen();
    const p = s.route.persona;
    const pp = PEOPLE[pid];
    const st = statusOf(pid, s.rt, p);
    const a = assessmentOf(pid, s.rt);
    const trig = triggersOf(pid, s.rt);
    const asks = consentOf(pid, s.rt);
    const reassess = canAssess(p) ? (
        <Button size="sm" data-return="notice-reassess" onClick={() => open(`assess:${pid}`)}>
            <RefreshCw className="size-4" /> {a ? 'Reassess' : 'Start an assessment'}
        </Button>
    ) : undefined;
    const out: ReactNode[] = [];
    if (agreementOnly) {
        /* only the agreement notice below */
    } else if (st.state === 'reassess')
        out.push(
            <Notice key="r" tone="critical" title={`Reassess ${pp.pref}’s support — by ${trig[0].due}`} actions={reassess}>
                {trig.filter((t) => t.kind !== 'asked').map((t) => `${t.at}: ${t.detail}`).join(' ')} {trig[0].owner} has the follow-up. Support stays as it is until the reassessment.{canAssess(p) ? '' : ` Reassessments are done by ${whoAssesses}.`}
            </Notice>,
        );
    else if (st.state === 'overdue')
        out.push(
            <Notice key="o" tone="warning" title={`Support plan review date passed on ${a!.reassessBy}`} actions={reassess}>
                Support stays as it is until the reassessment — staff keep following it. Ask the house lead to review it with {pp.pref}.
            </Notice>,
        );
    else if (st.state === 'none')
        out.push(
            <Notice key="n" tone="warning" title={`No self-administration assessment for ${pp.pref}`} actions={reassess}>
                Until one is done, staff give every medicine (Administer).{canAssess(p) ? '' : ` Assessments are done by ${whoAssesses}.`}
            </Notice>,
        );
    const less = agreementOnly ? [] : asks.filter((c) => c.direction === 'less');
    if (less.length)
        out.push(
            <Notice key="c" tone="info" icon={MessageSquareText} title={`${pp.pref} asked staff to do more`}>
                {less.map((c) => `${c.at}: ${c.said} ${c.effect} (recorded by ${c.recordedBy}).`).join(' ')}
            </Notice>,
        );
    const more = agreementOnly ? [] : asks.filter((c) => c.direction === 'more');
    if (more.length)
        out.push(
            <Notice key="m" tone="info" icon={MessageSquareText} title={`${pp.pref} asked to do more themselves`}>
                {more.map((c) => `${c.at}: ${c.said} ${c.effect} (recorded by ${c.recordedBy}).`).join(' ')}
            </Notice>,
        );
    if (st.agreementMissing)
        out.push(
            <Notice key="a" tone="warning" icon={FileSignature} title="Agreement needed" actions={canAssess(p) ? <Button size="sm" variant="outline" onClick={() => open(`agreement:${pid}`)}>Record the agreement</Button> : undefined}>
                {pp.pref} keeps or takes a medicine themselves, so record who agreed and what each side does.
            </Notice>,
        );
    return out.length ? <>{out}</> : null;
}

/* ───────────── by medicine ───────────── */
function ByMedicine({ pid }: { pid: PersonId }) {
    const s = useStore();
    const open = useOpen();
    const ctx = useCtx();
    const p = s.route.persona;
    const a = assessmentOf(pid, s.rt);
    const sup = s.route.q.get('sup');
    const meds = medsOf(pid).filter((m) => !sup || supportOf(m, s.rt) === sup);
    const hidden = meds.filter((m) => concealed(m, p)).length;
    const cap = capOf(pid, s.rt);
    const decided = (m: Medicine) => {
        const c = consentOf(pid, s.rt).find((x) => x.med === m.key && x.direction === 'less');
        if (c) return `${c.at.replace(/^\w+ /, '')} · at ${PEOPLE[pid].pref}’s request (${c.recordedBy})`;
        const ev = s.rt.events.find((e) => e.pid === pid && e.what.includes(m.name));
        if (ev) return `28 September 2026 · ${ev.who}`;
        if (supportOf(m, s.rt) === null) return m.newOrder ? `New order ${m.newOrder} — not set yet` : 'Not set yet';
        return a ? `${a.assessed} · ${a.by}` : 'No assessment — staff give it';
    };
    const menu = (m: Medicine): MenuItem[] =>
        concealed(m, p)
            ? [{ label: 'Why can’t I see this?', icon: Eye, onClick: () => s.toast('info', `${CONCEALED.subline}. ${CONCEALED.ask}`) }]
            : compactMenu([
                  canAssess(p) && { label: supportOf(m, s.rt) === null ? 'Set support' : 'Give more staff support', icon: Users, onClick: () => open(`support:${m.key}`) },
                  canRecordConsent(p) && !!a && { label: 'Record a change the person asked for', icon: MessageSquareText, onClick: () => open(`consent:${pid}:${m.key}`) },
                  { separator: true },
                  { label: 'Open the medicine', icon: Pill, onClick: () => s.toast('info', 'The medicine detail is P02’s approved dialog — outside this preview.') },
              ]);
    return (
        <section aria-label="Support by medicine" className="flex flex-col gap-2.5">
            <ListCaption
                title="Support by medicine"
                caption={
                    <>
                        {meds.length} of {meds.length} shown{hidden ? ` · ${hidden} controlled — details hidden (needs controlled-medicine access)` : ''}
                        {a ? ` · most independence allowed: ${SUPPORT[cap].label}` : ' · no assessment yet'}
                    </>
                }
            />
            {meds.length ? (
                <EntityTable<Medicine>
                    rows={meds}
                    rowKey={(m) => m.key}
                    identityLabel="Medicine"
                    identityWidth="1.6fr"
                    rowHeight="content"
                    minWidth={960}
                    identity={(m) => (concealed(m, p) ? { icon: LockKeyhole, name: CONCEALED.name, subline: <Wrap>{CONCEALED.subline}</Wrap> } : { icon: Pill, name: m.name, subline: <Wrap>{m.strength} · {m.prn ? 'as needed' : m.when}{m.covert ? ' · covert plan' : ''}</Wrap> })}
                    columns={[
                        { key: 'sup', label: 'Support', width: '0.9fr', cell: (m) => (concealed(m, p) ? <span className="text-muted-foreground">—</span> : <SupportChip support={supportOf(m, s.rt)} />) },
                        { key: 'means', label: 'What staff do', width: '1.3fr', cell: (m) => (concealed(m, p) ? <span className="text-muted-foreground">—</span> : <span className="text-[12.5px]">{supportOf(m, s.rt) ? SUPPORT[supportOf(m, s.rt)!].desc : 'Staff give it until support is set'}</span>) },
                        { key: 'rec', label: 'How the dose is recorded', width: '1.3fr', cell: (m) => (concealed(m, p) ? <span className="text-muted-foreground">—</span> : <span className="text-[12.5px]">{SUPPORT[supportOf(m, s.rt) ?? 'administer'].recorded}</span>) },
                        { key: 'from', label: 'Decided', width: '1.3fr', cell: (m) => (concealed(m, p) ? <span className="text-muted-foreground">—</span> : <span className="text-[12.5px]">{decided(m)}</span>) },
                    ]}
                    actionsFor={menu}
                    onOpen={(m) => (concealed(m, p) ? s.toast('info', `${CONCEALED.subline}. ${CONCEALED.ask}`) : canAssess(p) ? open(`support:${m.key}`) : s.toast('info', 'The medicine detail is P02’s approved dialog — outside this preview.'))}
                    onRowContextMenu={(e, m) => ctx.openAt(e, concealed(m, p) ? CONCEALED.name : m.name, menu(m))}
                />
            ) : (
                <Card className="p-2">
                    <EmptyState variant="compact" icon={Users} title="No medicines with this support" description="Clear the Support filter in the header." />
                </Card>
            )}
            {ctx.node}
            <p className="text-caption">
                Support is set per medicine, at or below the most independence the assessment allows. Controlled medicines are Assist or Administer at most, so they stay in the controlled register. A new medicine is Administer until someone sets it.{canAssess(p) ? '' : ` Support is changed by ${whoAssesses}; you can record a change the person asks for.`}
            </p>
        </section>
    );
}

/* ───────────── assessment ───────────── */
function AssessmentView({ pid }: { pid: PersonId }) {
    const s = useStore();
    const open = useOpen();
    const p = s.route.persona;
    const a = assessmentOf(pid, s.rt);
    const pp = PEOPLE[pid];
    const ag = agreementOf(pid, s.rt);
    if (!a)
        return (
            <Card className="p-2">
                <EmptyState
                    icon={ClipboardList}
                    title={`No self-administration assessment for ${pp.pref}`}
                    description={`Until one is done, staff give every medicine (Administer).${canAssess(p) ? '' : ` Assessments are done by ${whoAssesses}.`}`}
                    action={canAssess(p) ? <Button data-return="start-assessment" onClick={() => open(`assess:${pid}`)}>Start an assessment</Button> : undefined}
                />
            </Card>
        );
    const total = Object.values(a.scores).reduce((n, v) => n + v, 0);
    const earlier = earlierAssessments(pid);
    return (
        <>
            <SectionCard
                eyebrow={`Self-administration assessment · ${a.assessed}`}
                title={<span className="flex flex-wrap items-center gap-2">Most independence allowed: <SupportChip support={capOf(pid, s.rt)} /></span>}
                icon={ClipboardList}
                right={
                    <>
                        <PlanBadge state={statusOf(pid, s.rt, p).state} />
                        {canAssess(p) ? (
                            <Button size="sm" data-return="assessment-reassess" onClick={() => open(`assess:${pid}`)}>
                                <RefreshCw className="size-4" /> Reassess
                            </Button>
                        ) : null}
                    </>
                }
            >
                <FactStrip
                    items={[
                        { label: 'Assessed', value: `${a.assessed} · ${a.by}` },
                        { label: 'Reassess by', value: `${a.reassessBy} (every ${a.intervalMonths} months)` },
                        { label: 'Agreement', value: ag ? `${ag.agreedBy} · ${ag.on}` : 'None recorded', onClick: () => s.set({ view: 'agreement' }), aria: 'View the agreement' },
                    ]}
                />
                <div className="grid gap-4 lg:grid-cols-2">
                    <div className="space-y-2">
                        <p className="text-[12px] font-semibold text-muted-foreground uppercase">What {pp.pref} can do · {total} of 25</p>
                        <ul className="divide-y rounded-lg border">
                            {SCORES.map((x) => (
                                <li key={x.key} className="flex items-center justify-between gap-3 px-3 py-2 text-[13px]">
                                    <span>
                                        <span className="block font-medium">{x.label}</span>
                                        <span className="block text-[11.5px] text-muted-foreground">{x.help}</span>
                                    </span>
                                    <span className="shrink-0 text-right">
                                        <span className="block font-semibold">{SCORE_WORDS[a.scores[x.key]]}</span>
                                        <span className="block text-[11.5px] text-muted-foreground">{a.scores[x.key]} of 5</span>
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </div>
                    <div className="space-y-2">
                        <p className="text-[12px] font-semibold text-muted-foreground uppercase">Everyday checks</p>
                        <ul className="divide-y rounded-lg border">
                            {CHECKS.map((x) => (
                                <li key={x.key} className="flex items-center justify-between gap-3 px-3 py-2 text-[13px]">
                                    <span>{x.label}</span>
                                    {a.checks[x.key] ? (
                                        <StatusBadge variant="success" className="rounded-[8px]"><Check className="size-3" aria-hidden="true" />Yes</StatusBadge>
                                    ) : (
                                        <StatusBadge variant="neutral" className="rounded-[8px]"><X className="size-3" aria-hidden="true" />No</StatusBadge>
                                    )}
                                </li>
                            ))}
                        </ul>
                        <p className="text-[13px]">
                            <span className="text-muted-foreground">Wants to manage any medicines · </span>
                            {a.wishes ? 'Yes' : 'No — prefers staff to give them'}
                        </p>
                        <p className="text-[13px]">
                            <span className="text-muted-foreground">Took part · </span>
                            {a.with.join(', ')}
                        </p>
                        <p className="text-[13px]">
                            <span className="text-muted-foreground">Storage · </span>
                            {a.storage}
                        </p>
                    </div>
                </div>
                {a.notes ? <p className="text-sm">{a.notes}</p> : null}
                <p className="text-caption">The result is worked out from the answers the same way as today. Each medicine is set at or below it, and support changes only through a reassessment or when {pp.pref} asks staff to do more.</p>
            </SectionCard>
            <section aria-label="Earlier assessments" className="flex flex-col gap-2.5">
                <ListCaption title="Earlier assessments" caption={`${earlier.length} shown · kept, never deleted`} />
                {earlier.length ? (
                    <EntityTable<(typeof earlier)[number]>
                        rows={earlier}
                        rowKey={(x) => x.id}
                        identityLabel="Assessed"
                        identityWidth="1.2fr"
                        identity={(x) => ({ icon: ClipboardList, name: x.assessed, subline: x.by })}
                        columns={[
                            { key: 'res', label: 'Most independence allowed', width: '1fr', cell: (x) => <SupportChip support={capOf(pid, { ...s.rt, assessments: { [pid]: x } })} /> },
                            { key: 'until', label: 'Replaced', width: '1fr', cell: () => <span className="text-[12.5px]">By the assessment of {a.assessed}</span> },
                        ]}
                        actionsFor={(x) => [{ label: 'View this assessment', icon: Eye, onClick: () => open(`assessment:${x.id}`) }]}
                        onOpen={(x) => open(`assessment:${x.id}`)}
                    />
                ) : (
                    <Card className="p-2">
                        <EmptyState variant="compact" icon={History} title="No earlier assessments" description="Earlier assessments appear here when this one is replaced." />
                    </Card>
                )}
            </section>
        </>
    );
}

/* ───────────── agreement ───────────── */
function AgreementView({ pid }: { pid: PersonId }) {
    const s = useStore();
    const open = useOpen();
    const p = s.route.persona;
    const pp = PEOPLE[pid];
    const ag = agreementOf(pid, s.rt);
    const st = statusOf(pid, s.rt, p);
    if (!ag)
        return (
            <Card className="p-2">
                <EmptyState
                    icon={FileSignature}
                    title={st.agreementMissing ? 'Agreement needed' : `No agreement needed for ${pp.pref}`}
                    description={st.agreementMissing ? `${pp.pref} keeps or takes a medicine themselves. Record who agreed, how, and what each side does.` : 'Staff give or help with every medicine, so there’s nothing for the person to agree to hold or take.'}
                    action={canAssess(p) && st.agreementMissing ? <Button data-return="record-agreement" onClick={() => open(`agreement:${pid}`)}>Record the agreement</Button> : undefined}
                />
            </Card>
        );
    const ordering = { person: `${pp.pref} orders their own`, service: 'The service orders', pharmacy: 'The pharmacy supplies automatically' }[ag.ordering];
    return (
        <SectionCard
            eyebrow={`Self-administration agreement · ${ag.on}`}
            title={`Agreed by ${ag.agreedBy}`}
            icon={FileSignature}
            right={canAssess(p) ? <Button size="sm" variant="outline" data-return="agreement-new" onClick={() => open(`agreement:${pid}`)}>Record a new agreement</Button> : undefined}
        >
            <FactStrip
                items={[
                    { label: 'Who agreed', value: ag.role === 'person' ? `${pp.pref} (the person)` : `${ag.agreedBy.replace(/ \(.*\)$/, '')} (${ag.role === 'guardian' ? 'welfare guardian' : 'EPOA'})` },
                    { label: 'How', value: ag.how === 'signed' ? 'Signed form, attached' : `Agreed verbally · witness ${ag.witness}` },
                    { label: 'Recorded by', value: ag.staff },
                ]}
            />
            <div className="grid gap-4 md:grid-cols-2">
                <div className="space-y-1">
                    <p className="text-[12px] font-semibold text-muted-foreground uppercase">What {pp.pref} does</p>
                    <p className="text-sm">{ag.personDoes}</p>
                </div>
                <div className="space-y-1">
                    <p className="text-[12px] font-semibold text-muted-foreground uppercase">What staff do</p>
                    <p className="text-sm">{ag.staffDo}</p>
                </div>
            </div>
            <p className="text-sm">
                <span className="text-muted-foreground">Ordering · </span>
                {ordering}
                <span className="text-muted-foreground"> · Storage · </span>
                {STORAGE.find((x) => (assessmentOf(pid, s.rt)?.storage ?? '').toLowerCase().includes(x.label.toLowerCase().slice(0, 12)))?.label ?? assessmentOf(pid, s.rt)?.storage}
            </p>
            {ag.file ? (
                <Button variant="link" className="h-auto justify-start p-0" onClick={() => s.toast('info', 'Opens the signed form in the file preview — outside this preview (synthetic).')}>
                    <FileText className="size-4" /> {ag.file} <ArrowUpRight className="size-3.5" />
                </Button>
            ) : null}
            <p className="text-caption">It carries over when support is reassessed, unless what’s agreed changes. Recording a new one keeps this one in Changes.</p>
        </SectionCard>
    );
}

/* ───────────── changes ───────────── */
function ChangesView({ pid }: { pid: PersonId }) {
    const s = useStore();
    const p = s.route.persona;
    const list = changesOf(pid, s.rt);
    const hide = (c: (typeof list)[number]) => !!c.cd && !cdView(p);
    return (
        <section aria-label="Support changes" className="flex flex-col gap-2.5">
            <ListCaption
                title="Support changes"
                caption={
                    <>
                        {list.length} shown{list.some(hide) ? ` · ${list.filter(hide).length} controlled — details hidden (needs controlled-medicine access)` : ''} · newest first · kept, never deleted
                    </>
                }
            />
            <EntityTable<(typeof list)[number]>
                rows={list}
                rowKey={(c) => c.id}
                rowHeight="content"
                minWidth={960}
                identityLabel="When"
                identityWidth="170px"
                identity={(c) => ({ icon: History, name: c.at.split(', ')[0], subline: c.at.split(', ')[1] ?? '' })}
                columns={[
                    { key: 'what', label: 'What changed', width: '2fr', cell: (c) => (hide(c) ? <ConcealedIdentity compact /> : <span className="text-[12.5px] font-semibold">{c.what}</span>) },
                    { key: 'ba', label: 'Before → after', width: '1.6fr', cell: (c) => (hide(c) ? <span className="text-muted-foreground">—</span> : <span className="text-[12.5px]">{c.before ? `${c.before} → ` : ''}{c.after}</span>) },
                    { key: 'by', label: 'By', width: '0.9fr', cell: (c) => <span className="text-[12.5px]">{c.who}</span> },
                ]}
                actionsFor={() => [{ label: 'See support by medicine', icon: Users, onClick: () => s.set({ view: undefined }) }]}
            />
            <DesignNote title="Design note — replaces today’s Activity tab">
                <p>Today’s register has an Activity tab that says “Entries are amendable but never deleted”, but a DELETE route exists and soft-deleted rows drop out of it (AUDIT 1.1). Here nothing is deleted: a reassessment replaces the assessment and keeps the old one.</p>
            </DesignNote>
        </section>
    );
}
