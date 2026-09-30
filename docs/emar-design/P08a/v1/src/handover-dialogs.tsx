/* The shift handover’s medication lens (plan §2.4). Today the handover’s
 * medication part is free-text lines (“Follow-up items”, “Medications due”) with
 * no owner or due time, plus a count panel; acknowledging is “I’ve read this
 * handover” (handover-detail-dialog.tsx). P08a keeps the handover and its
 * acknowledgement, and replaces the medication part with live items: follow-ups
 * carried over (Main, Q1: the incoming worker owns them on acknowledgement),
 * doses with no outcome, refusals, the controlled-drug count (P07a) and supply
 * (P06). Acknowledging closes no medication work and never blocks recording (Q5).
 * Real WizardShell (detail sections with headerLabel; the outgoing draft as
 * steps), ReviewCard/ReviewRow, Textarea, StatusBadge. */
import { EntityChip } from '@/components/lists/entity-cells';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import { ReviewCard, ReviewRow, WizardShell, WizardStepPane } from '@/components/wizard/shell';
import { ArrowUpRight, Check, ChevronLeft, ChevronRight, ClipboardList, FileText, History, Lock, Package, Pill, Repeat, ShieldCheck, Users } from 'lucide-react';
import { useState, type ReactNode } from 'react';
import { NOW_LABEL } from './clock';
import { FOLLOW_UPS, HOUSES, PEOPLE, PERSONAS, TYPE_LABEL, hoById, type Handover } from './data';
import { NotFoundDialog } from './modal';
import { dueLabel, isOpen } from './model';
import { useStore, type Row } from './store';
import { DesignNote, FuBadge, Notice } from './ui';

function LensSection({ icon: Icon, title, caption, children }: { icon: typeof Pill; title: string; caption?: string; children: ReactNode }) {
    return (
        <section aria-label={title} className="rounded-xl border bg-card">
            <header className="flex flex-wrap items-baseline justify-between gap-2 border-b px-3 py-2">
                <span className="flex items-center gap-2 text-sm font-semibold">
                    <Icon className="size-4 text-muted-foreground" aria-hidden="true" /> {title}
                </span>
                {caption ? <span className="text-caption">{caption}</span> : null}
            </header>
            <div className="px-3 py-2 text-sm">{children}</div>
        </section>
    );
}
function FuLine({ row, onOpen, ownerNote }: { row: Row; onOpen: (id: string) => void; ownerNote?: string }) {
    const f = row.f;
    return (
        <li className="flex flex-wrap items-center justify-between gap-3 py-2">
            <span className="min-w-0">
                <span className="block font-medium">
                    {f.title}
                    {f.pid && !f.title.includes(PEOPLE[f.pid].pref) ? ` · ${PEOPLE[f.pid].pref}` : ''}
                </span>
                <span className="block text-caption">
                    {TYPE_LABEL[f.type]} · {dueLabel(f)} · owner {row.owner ?? ownerNote ?? 'not set'}
                </span>
            </span>
            <span className="flex items-center gap-2">
                <FuBadge state={row.state} />
                <Button size="sm" variant="ghost" className="frontline-tap" onClick={() => onOpen(f.id)}>
                    Open
                </Button>
            </span>
        </li>
    );
}

/** The medication lens for one handover (incoming view). */
export function MedicationLens({ h, onOpen, incoming }: { h: Handover; onOpen: (id: string) => void; incoming: string }) {
    const s = useStore();
    const p = PERSONAS[s.route.persona];
    const rows = s.rows();
    const carried = rows.filter((r) => h.carried.includes(r.f.id));
    const leadOpen = rows.filter((r) => r.f.house === h.house && !h.carried.includes(r.f.id) && isOpen(r.state) && ['disputed', 'partial', 'unconfirmed', 'override', 'countersign'].includes(r.f.type) && r.f.id !== 'fu-partial' && r.f.id !== 'fu-phone' && r.f.id !== 'fu-override');
    const cdView = p.perms.includes('cd.view');
    return (
        <div className="space-y-3">
            <LensSection icon={Repeat} title="Follow-ups carried over" caption={carried.length ? `${carried.length} from ${h.label.split(' →')[0].toLowerCase()} shift` : 'None'}>
                {carried.length ? (
                    <>
                        <ul className="divide-y">
                            {carried.map((r) => (
                                <FuLine key={r.f.id} row={r} onOpen={onOpen} ownerNote={incoming === p.name ? 'you, once you acknowledge' : `${incoming}, when the handover is acknowledged`} />
                            ))}
                        </ul>
                        <p className="text-caption">Live items, not notes. Everyone rostered sees them until they’re done; the incoming worker becomes the owner on acknowledgement, or the house lead assigns them.</p>
                    </>
                ) : (
                    <p className="text-muted-foreground">Nothing was left open at the shift change.</p>
                )}
            </LensSection>
            {leadOpen.length ? (
                <LensSection icon={Users} title="Still with the house lead" caption={`${leadOpen.length} open`}>
                    <ul className="divide-y">
                        {leadOpen.map((r) => (
                            <FuLine key={r.f.id} row={r} onOpen={onOpen} />
                        ))}
                    </ul>
                </LensSection>
            ) : null}
            <LensSection icon={Pill} title="Doses with no outcome at the shift change" caption={h.noOutcome.length ? `${h.noOutcome.length}` : 'None'}>
                <p className="text-muted-foreground">{h.noOutcome.length ? h.noOutcome.join(' · ') : `Every dose due before ${h.change.split(' ')[0]} ${h.change.split(' ')[1]} has an outcome.`}</p>
            </LensSection>
            {cdView ? (
                <LensSection icon={ShieldCheck} title="Controlled-drug count at the shift change">
                    <span className="flex flex-wrap items-center gap-2">
                        <StatusBadge variant="success" size="sm">
                            <Check className="size-3" /> Counted
                        </StatusBadge>
                        {h.cdCount}
                        <Button variant="link" size="sm" className="h-auto p-0" onClick={() => s.toast('info', 'Opens Meds today › Controlled checks — P07a’s approved view, outside this preview.')}>
                            Controlled checks <ArrowUpRight className="size-3.5" />
                        </Button>
                    </span>
                </LensSection>
            ) : null}
            <LensSection icon={Package} title="Supply" caption={h.supply ? '1 note' : 'Nothing low'}>
                {h.supply ? (
                    <span className="flex flex-wrap items-center gap-2">
                        {h.supply}
                        <Button variant="link" size="sm" className="h-auto p-0" onClick={() => s.toast('info', 'Opens Meds today › Stock alerts — P06’s design, outside this preview.')}>
                            Stock alerts <ArrowUpRight className="size-3.5" />
                        </Button>
                    </span>
                ) : (
                    <span className="text-muted-foreground">No medicine below its reorder level.</span>
                )}
            </LensSection>
            <p className="text-caption">Acknowledging records that you’ve read this handover. It closes no medication work — follow-ups stay open until they’re done — and it never stops anyone recording.</p>
        </div>
    );
}

/* ───────────── the incoming worker’s handover (detail, sections) ───────────── */
export function HandoverDetailDialog({ id, onClose, onOpenFollowUp }: { id: string; onClose: () => void; onOpenFollowUp: (id: string) => void }) {
    const s = useStore();
    const h = hoById(id);
    const [sec, setSec] = useState(1);
    const me = PERSONAS[s.route.persona];
    if (!h || !me.houses.includes(h.house)) return <NotFoundDialog onClose={onClose} />;
    const ackPending = s.route.scenario === 'ackPending' && h.id === 'h-kow-am';
    const ack = s.acked[h.id] ?? (ackPending ? null : h.ack);
    const canAck = !ack && me.name === h.to;
    const carriedN = h.carried.length;
    const SECS = [
        { key: 'notes', label: 'Shift notes', blurb: `${h.from} → ${h.to}`, icon: FileText },
        { key: 'meds', label: 'Medication', blurb: 'Live items, not notes', icon: Pill },
        { key: 'history', label: 'History', blurb: 'Submitted and acknowledged', icon: History },
    ];
    const body: ReactNode[] = [
        <div key="n" className="space-y-3">
            <div className="flex flex-wrap gap-1.5">
                <EntityChip icon={Repeat}>{h.label}</EntityChip>
                <EntityChip>{HOUSES[h.house]}</EntityChip>
                <EntityChip>{h.change}</EntityChip>
            </div>
            <p className="text-sm">{h.notes}</p>
            <DesignNote title="Reference frame">Shift notes, mood and the other steps are the handover’s own (Operations › Handovers, unchanged by P08a). P08a designs the medication section.</DesignNote>
        </div>,
        <MedicationLens key="m" h={h} onOpen={onOpenFollowUp} incoming={h.to} />,
        <div key="h" className="space-y-2 text-sm">
            <p>
                Submitted {h.submitted} by {h.from} to {h.to}.
            </p>
            <p>{ack ? `Acknowledged ${ack.at} by ${ack.by}.` : `Not acknowledged yet — ${h.to} is the only person who can acknowledge it. The house lead gets a heads-up 1 hour into the shift.`}</p>
        </div>,
    ];
    return (
        <WizardShell
            open
            onClose={onClose}
            title={`Handover — ${h.label}, ${HOUSES[h.house]}`}
            description="Shift notes, medication and history."
            railIcon={Repeat}
            railTitle={`Handover · ${h.label}`}
            railSub={`${HOUSES[h.house]} · ${h.change}`}
            steps={SECS}
            stepIndex={sec}
            onStepClick={setSec}
            headerLabel={SECS[sec].label}
            sequential={false}
            pct={null}
            railExtra={
                <div className="space-y-2 text-[11.5px] text-muted-foreground">
                    <p>
                        <span className="font-semibold text-foreground">Acknowledged</span>
                        <br />
                        {ack ? `${ack.at} · ${ack.by}` : 'Not yet'}
                    </p>
                    <p>
                        <span className="font-semibold text-foreground">Carried over</span>
                        <br />
                        {carriedN ? `${carriedN} follow-up${carriedN === 1 ? '' : 's'}` : 'Nothing'}
                    </p>
                </div>
            }
            footerStart={
                <Button type="button" variant="outline" onClick={onClose}>
                    Close
                </Button>
            }
            footerEnd={
                canAck ? (
                    <Button
                        type="button"
                        onClick={() => {
                            s.acknowledge(h.id, me.name);
                            const names = FOLLOW_UPS.filter((f) => h.carried.includes(f.id)).map((f) => `${PEOPLE[f.pid!].pref}’s ${f.prn?.med.split(' ')[0].toLowerCase() ?? ''} effect check`);
                            s.toast('success', `Handover acknowledged at ${NOW_LABEL}.${names.length ? ` You now own ${names.length} follow-up carried over from the night shift: ${names.join(', ')}.` : ''}`);
                            onClose();
                        }}
                    >
                        <Check className="size-4" /> I’ve read this handover
                    </Button>
                ) : (
                    <span className="flex items-center gap-2 text-caption">
                        {ack ? (
                            <>
                                <Check className="size-3.5 text-status-success" /> Acknowledged {ack.at} by {ack.by}
                            </>
                        ) : (
                            <>
                                <Lock className="size-3.5" /> Only {h.to} can acknowledge it
                            </>
                        )}
                    </span>
                )
            }
            maxWidth="min(94vw, 1000px)"
            maxHeight="min(86vh, 780px)"
        >
            <WizardStepPane>
                <div className="space-y-4">{body[sec]}</div>
            </WizardStepPane>
        </WizardShell>
    );
}

/* ───────────── the outgoing worker’s handover (draft) — the medication step ───────────── */
export function HandoverDraftDialog({ onClose, onOpenFollowUp }: { onClose: () => void; onOpenFollowUp: (id: string) => void }) {
    const s = useStore();
    const me = PERSONAS[s.route.persona];
    const [step, setStep] = useState(2);
    const [notes, setNotes] = useState('Aroha asked for her losartan after breakfast. Grace refused her sertraline again — see the follow-up.');
    const [medNote, setMedNote] = useState('');
    if (!me.shift || !me.houses.includes('kowhai')) return <NotFoundDialog onClose={onClose} />;
    const open = s.rows().filter((r) => r.f.house === 'kowhai' && isOpen(r.state) && r.state !== 'waiting');
    const workerOpen = open.filter((r) => ['effect', 'reoffer'].includes(r.f.type));
    const leadOpen = open.filter((r) => !['effect', 'reoffer'].includes(r.f.type));
    const STEPS = [
        { key: 'people', label: 'Shift & people', blurb: 'From you to the afternoon', icon: Users },
        { key: 'notes', label: 'Notes', blurb: 'What the next shift should know', icon: FileText },
        { key: 'meds', label: 'Medication', blurb: 'Live items that carry over', icon: Pill },
        { key: 'review', label: 'Review & submit', blurb: 'Submit at the shift change', icon: ClipboardList },
    ];
    const body: ReactNode[] = [
        <div key="p" className="space-y-3 text-sm">
            <div className="flex items-start gap-3 rounded-xl border border-primary/40 bg-primary/10 p-3">
                <span className="mt-0.5 shrink-0 rounded-lg bg-background/60 p-1.5">
                    <Lock className="h-4 w-4 text-primary" />
                </span>
                <div>
                    <p className="font-medium">
                        {me.name} ({me.shift}) → Leilani Faleolo (3:00 pm–11:00 pm)
                    </p>
                    <p className="text-xs text-muted-foreground">Kōwhai House · from the roster. The incoming worker is the only person who can acknowledge.</p>
                </div>
            </div>
            <DesignNote title="Reference frame">The handover’s own steps (Operations › Handovers). P08a changes only the medication step.</DesignNote>
        </div>,
        <div key="n" className="space-y-1.5">
            <Label htmlFor="ho-notes">Notes for the next shift</Label>
            <Textarea id="ho-notes" rows={4} value={notes} onChange={(e) => setNotes(e.target.value)} />
        </div>,
        <div key="m" className="space-y-3">
            <Notice tone="info" title="Open follow-ups carry over by themselves">
                You don’t copy them into the handover. Anything still open at 3:00 pm moves to the afternoon shift; Leilani becomes the owner when they acknowledge. Doses and follow-ups you finish before then drop off this list.
            </Notice>
            <LensSection icon={Repeat} title="Yours — will carry over if still open at 3:00 pm" caption={`${workerOpen.length} open now`}>
                <ul className="divide-y">
                    {workerOpen.map((r) => (
                        <FuLine key={r.f.id} row={r} onOpen={onOpenFollowUp} />
                    ))}
                </ul>
            </LensSection>
            <LensSection icon={Users} title="With the house lead" caption={`${leadOpen.length} open`}>
                <ul className="divide-y">
                    {leadOpen.map((r) => (
                        <FuLine key={r.f.id} row={r} onOpen={onOpenFollowUp} />
                    ))}
                </ul>
            </LensSection>
            <LensSection icon={Pill} title="Doses still to record before 3:00 pm">
                <span className="text-muted-foreground">Shown live when you submit — the 12:00 pm round is the last one on your shift.</span>
            </LensSection>
            <LensSection icon={ShieldCheck} title="Controlled-drug count">
                <span className="flex flex-wrap items-center gap-2">
                    Due at the 3:00 pm shift change, counted with Leilani.
                    <Button variant="link" size="sm" className="h-auto p-0" onClick={() => s.toast('info', 'Opens Meds today › Controlled checks — P07a’s approved view, outside this preview.')}>
                        Controlled checks <ArrowUpRight className="size-3.5" />
                    </Button>
                </span>
            </LensSection>
            <div className="space-y-1.5">
                <Label htmlFor="ho-med">
                    Anything else about medication <span className="text-subtle">(optional)</span>
                </Label>
                <Textarea id="ho-med" rows={2} value={medNote} placeholder="e.g. Tama’s new levetiracetam dose starts tonight." onChange={(e) => setMedNote(e.target.value)} />
                <p className="text-caption">For context only. Anything that needs doing is a follow-up, with an owner and a due time.</p>
            </div>
        </div>,
        <div key="r" className="grid gap-3 sm:grid-cols-2">
            <ReviewCard icon={Users} title="Shift & people" onEdit={() => setStep(0)}>
                <ReviewRow label="From" value={`${me.name} · ${me.shift}`} />
                <ReviewRow label="To" value="Leilani Faleolo · 3:00 pm–11:00 pm" />
            </ReviewCard>
            <ReviewCard icon={Pill} title="Medication" onEdit={() => setStep(2)}>
                <ReviewRow label="Carries over if still open" value={`${workerOpen.length} of yours · ${leadOpen.length} with the house lead`} />
                <ReviewRow label="Controlled-drug count" value="At 3:00 pm with Leilani" />
                <ReviewRow label="Note" value={medNote || '—'} />
            </ReviewCard>
        </div>,
    ];
    return (
        <WizardShell
            open
            onClose={onClose}
            title="Hand over your shift — Kōwhai House"
            description="Shift and people, notes, medication, then review."
            railIcon={Repeat}
            railTitle="Hand over your shift"
            railSub="Kōwhai House · 3:00 pm"
            steps={STEPS}
            stepIndex={step}
            onStepClick={setStep}
            pct={Math.round(((step + 1) / STEPS.length) * 100)}
            pctLabel="Draft"
            footerStart={
                step > 0 ? (
                    <Button type="button" variant="ghost" onClick={() => setStep(step - 1)}>
                        <ChevronLeft className="size-4" /> Back
                    </Button>
                ) : (
                    <Button type="button" variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                )
            }
            footerEnd={
                step < 3 ? (
                    <Button type="button" onClick={() => setStep(step + 1)}>
                        Continue <ChevronRight className="size-4" />
                    </Button>
                ) : (
                    <span className="flex flex-wrap items-center gap-2">
                        <span className="text-caption">Submit at the shift change — saved as a draft until then.</span>
                        <Button
                            type="button"
                            onClick={() => {
                                s.toast('success', 'Draft saved at 9:12 am. Submit it when you hand over at 3:00 pm.');
                                onClose();
                            }}
                        >
                            Save draft
                        </Button>
                    </span>
                )
            }
            maxWidth="min(94vw, 1000px)"
            maxHeight="min(86vh, 780px)"
        >
            <WizardStepPane>
                <div className="space-y-4">{body[step]}</div>
            </WizardStepPane>
        </WizardShell>
    );
}
