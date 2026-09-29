/* P11 v4 — Stephan’s additions (29 Sep 2026): the alert log, who can’t be reached, reviewing the defaults in one go,
 * putting an earlier value back from Change history, and cellphone consent for on-call (Q10). Real components only. */
import { ConfirmDialog } from '@/components/confirm-dialog';
import { DiscardDraftDialog } from '@/components/governance/DiscardDraftDialog';
import { Button } from '@/components/ui/button';
import { InfoCard, StepHead } from '@/components/wizard/primitives';
import { ReviewCard, ReviewRow, WizardShell, WizardStepPane, WizardSuccessPane } from '@/components/wizard/shell';
import { Modal, Notice } from '@/pages/fleet-assets/settings/_ui';
import { ArrowUpRight, Bell, CalendarX, Check, ChevronLeft, ChevronRight, History, Info, Mail, Phone, Pill, Repeat, Smartphone, UserX, Users } from 'lucide-react';
import { useState } from 'react';
import { ALERTS, ALERT_LOG, EMPLOYED, HOUSES, HOUSE_KEYS, ONCALL_ROSTER, phoneOf, type Employed, type Hist } from './data';
import { GROUPS, VIEW_LABEL, alertById, allHistory, decisionRegistry, has, keepDefault, loosens, myHouses, readOnlyAudit, useStore, type GroupKey, type Model, type ViewKey } from './model';
import { settingsHref, useNav } from './nav';
import { OnOff } from './ui';

const Missing = ({ what }: { what: string }) => {
    const { close } = useNav();
    return <Modal title="We can’t show this record" description="It may not exist, or it may not be available to you." onClose={close}><p className="text-subtle">Choose the {what} from the list.</p></Modal>;
};

/* ── Put an earlier value back (Change history). It goes into the draft; nothing changes until it’s reviewed and saved. ── */
export function canRestore(m: Model, h: Hist): boolean {
    if (!h.g || !h.k || h.fromV === undefined) return false;
    const G = GROUPS[h.g as GroupKey];
    if (!G || !G.keys.includes(h.k) || !G.can(m.persona) || readOnlyAudit(m.persona) || m.demo.settings === 'offline') return false;
    return JSON.stringify((m.draft[h.g as GroupKey] as Record<string, unknown>)[h.k]) !== JSON.stringify(h.fromV);
}
export function RestoreValue({ id }: { id: string }) {
    const { m, set, flash } = useStore();
    const { close, go } = useNav();
    const h = allHistory(m).find((x) => x.id === id);
    if (!h || !canRestore(m, h)) return <Missing what="change" />;
    const g = h.g as GroupKey, k = h.k!, G = GROUPS[g];
    const now = G.fmt(k, (m.saved[g] as Record<string, unknown>)[k]), back = G.fmt(k, h.fromV);
    const weaker = loosens(g, k, (m.saved[g] as Record<string, unknown>)[k], h.fromV);
    const where = VIEW_LABEL[G.view].toLowerCase();
    return (
        <ConfirmDialog open onClose={close} variant={weaker ? 'destructive' : 'default'} title="Put the earlier value back in your draft?" confirmText="Put back in draft"
            description={<>
                <p><b className="text-foreground">{G.label(k)}</b></p>
                <p className="mt-1">Now: {now}</p>
                <p>Earlier: <b className="text-foreground">{back}</b> — before {h.who}’s change on {h.when}.</p>
                {weaker ? <p className="mt-2 text-status-critical"><b>This loosens a check.</b> Staff will be checked less than they are now.</p> : null}
                {h.note ? <p className="mt-2 text-status-critical">This undoes a recorded decision: {h.note}.</p> : null}
                <p className="mt-2">Nothing changes until you review and save {where}. Saving records it in the change history.</p>
            </>}
            onConfirm={() => {
                set((d) => { (d.draft[g] as Record<string, unknown>)[k] = JSON.parse(JSON.stringify(h.fromV)); });
                const href = settingsHref(G.view, G.sec(k)), msg = `Earlier value put back in your draft. Review and save ${where} to apply it.`;
                // Moving to the setting’s tab clears page messages, so say it once we’re there.
                if (location.hash === href) flash(msg); else window.addEventListener('hashchange', () => setTimeout(() => flash(msg), 0), { once: true });
                go(href);
            }} />
    );
}

/* ── Review the defaults in one go: every “Default — not yet reviewed” the person can change, one view per step. ── */
const VIEW_ICON: Record<string, typeof Bell> = { rules: Pill, rounds: Repeat, staff: Users, alerts: Bell };
type Item = { id: string; view: ViewKey; sec: string; label: string; today: string; g?: GroupKey; k?: string; keepable: boolean };
export function reviewItems(m: Model): Item[] {
    const p = m.persona, out: Item[] = [];
    const ok = (g?: GroupKey) => !!g && GROUPS[g].can(p) && !readOnlyAudit(p) && m.demo.settings !== 'offline';
    decisionRegistry(m).forEach((r) => {
        if (r.g === 'ea') { if (!out.some((i) => i.g === 'ea')) out.push({ id: 'ea', view: 'alerts', sec: 'emergency', label: 'Emergency access policy', today: `Today: ${GROUPS.ea.fmt('def', m.saved.ea.def)} per grant, longest ${GROUPS.ea.fmt('max', m.saved.ea.max)}`, g: 'ea', k: 'def', keepable: ok('ea') }); return; }
        out.push({ id: `${r.g ?? 'nc'}.${r.k ?? r.label}`, view: r.view, sec: r.sec, label: r.label, today: r.until, g: r.g, k: r.k, keepable: r.state === 'default' && ok(r.g) });
    });
    return out;
}
export function ReviewDefaults() {
    const { m, set } = useStore();
    const { close, go } = useNav();
    const [items] = useState(() => reviewItems(m));
    const views = (['rules', 'rounds', 'staff', 'alerts'] as ViewKey[]).filter((v) => items.some((i) => i.view === v));
    const steps = [...views.map((v) => ({ key: v, label: VIEW_LABEL[v], blurb: `${items.filter((i) => i.view === v).length} to decide`, icon: VIEW_ICON[v] })), { key: 'review', label: 'Review', blurb: 'Keep the ones you chose', icon: Check }];
    const [step, setStep] = useState(0), [keep, setKeep] = useState<Record<string, boolean>>({}), [guard, setGuard] = useState<null | string>(null), [done, setDone] = useState<null | { kept: number; left: number }>(null);
    const kept = items.filter((i) => keep[i.id]);
    // The emergency access policy is one row here but several settings in Still to decide.
    const total = decisionRegistry(m).length, weight = (i: Item) => (i.g === 'ea' ? decisionRegistry(m).filter((r) => r.g === 'ea').length : 1), left = total - kept.reduce((n, i) => n + weight(i), 0);
    if (!items.some((i) => i.keepable)) return <Missing what="setting" />;
    const leave = (href: string | null) => (kept.length && !done ? setGuard(href ?? '') : href ? go(href) : close());
    const apply = () => { set((d) => { kept.forEach((i) => keepDefault(d, i.g!, i.k!)); }); setDone({ kept: kept.length, left }); };
    const last = steps.length - 1;
    const v = views[step];
    const list = items.filter((i) => i.view === v);
    return (
        <>
            <WizardShell open onClose={() => leave(null)} title="Review the defaults" description="Keep today’s value for each setting you’re happy with. The rest stay “not yet reviewed”." railIcon={Check} railTitle="Review the defaults" railSub={`${total} still to decide`}
                steps={steps} stepIndex={step} onStepClick={setStep} pct={Math.round((kept.length / Math.max(1, items.filter((i) => i.keepable).length)) * 100)}
                success={done ? <WizardSuccessPane title={`${done.kept} ${done.kept === 1 ? 'value' : 'values'} kept`} blurb={<>They now show as reviewed by you, and each is in the change history. {done.left} still to decide — they carry on behaving as today until someone chooses.</>} actions={<Button onClick={close} autoFocus>Done</Button>} /> : undefined}
                footerStart={<div className="flex gap-2"><Button variant="outline" onClick={() => leave(null)}>Cancel</Button>{step > 0 ? <Button variant="outline" onClick={() => setStep(step - 1)}><ChevronLeft />Back</Button> : null}</div>}
                footerEnd={step < last ? <Button onClick={() => setStep(step + 1)}>Continue<ChevronRight /></Button> : <Button onClick={apply} disabled={!kept.length}><Check />Keep {kept.length} {kept.length === 1 ? 'value' : 'values'}</Button>}>
                <WizardStepPane key={step}>
                    {step < last ? (
                        <div className="space-y-4">
                            <StepHead icon={VIEW_ICON[v]} title={VIEW_LABEL[v]} blurb="Turn on Keep for each value you’re happy with. To change one instead, open its tab." />
                            {list.some((i) => i.keepable) ? <div className="flex justify-end"><Button variant="outline" size="sm" onClick={() => setKeep({ ...keep, ...Object.fromEntries(list.filter((i) => i.keepable).map((i) => [i.id, true])) })}>Keep all on this step</Button></div> : null}
                            <div className="divide-y divide-border rounded-xl border">
                                {list.map((i) => (
                                    <div key={i.id} className="flex items-center justify-between gap-4 p-3">
                                        <div className="min-w-0"><label htmlFor={`rd-${i.id}`} className="text-[13px] font-semibold">{i.label}</label><p className="text-caption">{i.today}</p></div>
                                        {i.keepable ? <span className="inline-flex items-center gap-3"><span className="text-caption">Keep</span><OnOff id={`rd-${i.id}`} checked={!!keep[i.id]} label={`Keep today’s value: ${i.label}`} onChange={(x) => setKeep({ ...keep, [i.id]: x })} /></span>
                                            : <Button variant="link" onClick={() => leave(settingsHref(i.view, i.sec))}>{i.g ? 'Open its tab' : 'Set a value'} <ArrowUpRight className="size-4" /></Button>}
                                    </div>
                                ))}
                            </div>
                        </div>
                    ) : (
                        <div className="space-y-4">
                            <StepHead icon={Check} title="Review" blurb="Kept values show as reviewed by you. Each is recorded in the change history and the audit log." />
                            <div className="grid gap-4 sm:grid-cols-2">
                                {views.map((x, n) => { const kv = kept.filter((i) => i.view === x); return <ReviewCard key={x} icon={VIEW_ICON[x]} title={VIEW_LABEL[x]} onEdit={() => setStep(n)}>{kv.length ? kv.map((i) => <ReviewRow key={i.id} label={i.label} value="Keep" />) : <p className="text-caption">Nothing kept — these stay to decide.</p>}</ReviewCard>; })}
                            </div>
                            <InfoCard icon={Info}><b>{kept.length} kept · {left} still to decide after this.</b> Nothing about how doses are recorded changes.</InfoCard>
                        </div>
                    )}
                </WizardStepPane>
            </WizardShell>
            <DiscardDraftDialog open={guard !== null} mode="edit" description="The values you chose to keep haven’t been applied. Leaving now loses them." onKeepEditing={() => setGuard(null)} onDiscard={() => { const to = guard; setGuard(null); if (to) go(to); else close(); }} />
        </>
    );
}

/* ── Alert log: one alert, what happened and when ── */
export function AlertLogView({ id }: { id: string }) {
    const { m } = useStore();
    const { close, go } = useNav();
    const r = ALERT_LOG.find((x) => x.id === id && myHouses(m.persona).includes(x.house));
    if (!r) return <Missing what="alert" />;
    if (r.cd && !has(m.persona, 'cd.view')) return (
        <Modal title="Controlled-medicine alert" description={`${HOUSES[r.house]} · sent ${r.sent}`} onClose={close}>
            <Notice><span><b>Only people with controlled-medicine access can see this alert’s details</b> — what it was about, who was told and what happened. The house lead or Rangi Parata can tell you more.</span></Notice>
        </Modal>
    );
    const a = alertById(r.k), dl = m.saved.delivery, noFollow = !dl.realertEvery && !dl.escalateAfter;
    return (
        <Modal title={a.l} description={`${r.about} · ${HOUSES[r.house]} · sent ${r.sent}`} onClose={close}>
            <ReviewCard icon={History} title="What happened">
                {r.events.map((e, i) => <ReviewRow key={i} label={e.at} value={<span className="text-right"><b>{e.what}</b><span className="text-caption block">{e.who}</span></span>} />)}
                {r.status === 'open' ? <ReviewRow label="Now, 9:12 am" value={<b>Not attended — {r.waited}</b>} /> : null}
            </ReviewCard>
            {(r.status === 'open' || r.afterHours) && noFollow ? <Notice><span><b>Nobody else was told.</b> Re-alerting and escalation are off{r.afterHours ? ', and this was after hours' : ''}. <Button variant="link" onClick={() => go(settingsHref('alerts', 'delivery'))}>Review delivery & follow-up <ArrowUpRight className="size-4" /></Button></span></Notice> : null}
            <p className="text-caption">Synthetic example. The log keeps who was told, how, and who attended. It never changes the medication record.</p>
        </Modal>
    );
}

/* ── Who can’t be reached: contact gaps from staff records, push set-up and approved leave ── */
export type ReachIssue = { kind: 'email' | 'push' | 'phone' | 'leave'; text: string; fix: string; matters: string | null };
export type Reach = { e: Employed; issues: ReachIssue[] };
const types = (n: number) => `${n} alert ${n === 1 ? 'type' : 'types'}`;
export function reachRows(m: Model): Reach[] {
    const inGroups = (e: Employed, ch: 'email' | 'push') => ALERTS.filter((a) => { const x = m.draft.alerts[a.k]; return x[ch] && x.groups.some((g) => e.groups.includes(g)); }).length;
    const backupFor = (e: Employed) => HOUSE_KEYS.filter((h) => m.oncall[h]?.person === e.id);
    const rostered = (e: Employed) => HOUSE_KEYS.some((h) => ONCALL_ROSTER[h].some((n) => n.onCall === e.id || n.teamLead === e.id));
    return EMPLOYED.map((e) => {
        const issues: ReachIssue[] = [];
        if (!e.workEmail) { const n = inGroups(e, 'email'); issues.push({ kind: 'email', text: 'No work email', fix: 'An HR admin adds a work email in HR › People.', matters: n ? `Misses ${types(n)} sent by email` : null }); }
        if (!e.push) { const n = inGroups(e, 'push'); issues.push({ kind: 'push', text: 'Push not set up', fix: 'They turn on push for their phone or browser in their account › Notifications.', matters: n ? `Misses ${types(n)} sent by push` : null }); }
        if (!phoneOf(e).kind) issues.push({ kind: 'phone', text: e.cell ? 'Cellphone not shared' : 'No phone number', fix: e.cell ? 'They can agree to show their cellphone when on call, in their account — or an HR admin adds a work phone.' : 'An HR admin adds a work phone in HR › People.', matters: rostered(e) || backupFor(e).length ? 'Screens would show no number when they’re on call' : null });
        if (e.leave) { const h = backupFor(e); issues.push({ kind: 'leave', text: e.leave.text, fix: 'Choose another backup, or roster an on-call shift for those nights.', matters: h.length ? `On-call backup for ${h.map((x) => HOUSES[x]).join(' and ')} — nights they’re away show nobody` : null }); }
        return { e, issues };
    }).filter((r) => r.issues.length);
}
const REACH_ICON = { email: Mail, push: Smartphone, phone: Phone, leave: CalendarX };
export function ReachDetail({ id }: { id: string }) {
    const { m } = useStore();
    const { close, go } = useNav();
    const r = reachRows(m).find((x) => x.e.id === id);
    if (!r) return <Missing what="person" />;
    return (
        <Modal title={`Why ${r.e.name} can’t always be reached`} description={`${r.e.role} · ${r.e.houses}`} onClose={close}
            footer={<>{r.issues.some((i) => i.kind === 'leave' || i.kind === 'phone') ? <Button variant="outline" onClick={() => go(settingsHref('alerts', 'oncall'))}>On-call contacts <ArrowUpRight /></Button> : null}<Button onClick={close}>Close</Button></>}>
            {r.issues.map((i) => (
                <ReviewCard key={i.kind} icon={REACH_ICON[i.kind]} title={i.text}>
                    <ReviewRow label="Why it matters" value={i.matters ?? 'Not needed with today’s settings'} />
                    <ReviewRow label="Who fixes it" value={i.fix} />
                </ReviewCard>
            ))}
        </Modal>
    );
}

/* ── Q10: what staff see in their account before a personal cellphone is shown for on-call ── */
export function ConsentView() {
    const { close } = useNav();
    return (
        <Modal title="What staff see in their account" description="Account › Notifications — example for Priya Shah" onClose={close}>
            <div className="flex items-start justify-between gap-4 rounded-xl border p-3">
                <div><label htmlFor="cv-cell" className="text-[13px] font-semibold">Show my personal cellphone when I’m on call</label><p className="text-caption">Only used if you have no work phone. Staff at the houses you’re on call for see it on blocked screens and follow-ups. You can turn this off at any time.</p><p className="text-caption mt-1">You agreed on 12 Sep 2026.</p></div>
                <OnOff id="cv-cell" checked disabled label="Show my personal cellphone when I’m on call" onChange={() => undefined} />
            </div>
            <InfoCard icon={UserX}>If someone turns this off, they drop out of the on-call dropdown, and a house using them as backup shows them under “Who can’t be reached”.</InfoCard>
        </Modal>
    );
}
