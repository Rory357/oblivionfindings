/* “Did it help?” — the as-needed effect check. One dialog replaces today’s
 * worker PrnEffectDialog (Meds today) and the lead PrnEffectivenessDialog
 * (PRN records); both post to /meds/today/prn/effect. Adds “Couldn’t check”
 * with a reason and a “check again at” time up to the end of the shift (Main,
 * Q2), and the escalation fields today stores but never acts on. Real Dialog
 * (Fleet Modal layout), TilePicker, Select, Textarea, Switch, DateTimeField. */
import { DateTimeField } from '@/components/fleet-assets/maintenance/date-time-field';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { CircleSlash, Moon, ThumbsUp, TrendingUp } from 'lucide-react';
import { useState } from 'react';
import { NOW_LABEL, NOW_LOCAL, NOW_MIN, localToMinutes, labelOf } from './clock';
import { PEOPLE, PERSONAS } from './data';
import { finish, Modal, NotFoundDialog } from './modal';
import { SHIFT_END_MIN, canAct, endOfShiftError, overdueBy } from './model';
import { useStore } from './store';
import { KV, Notice, NotConfigured, TilePicker } from './ui';

const OUTCOMES = [
    { key: 'helped', label: 'Helped', description: 'The symptom has eased', icon: ThumbsUp },
    { key: 'partly', label: 'Helped a little', description: 'Some relief', icon: TrendingUp },
    { key: 'none', label: 'Didn’t help', description: 'No change, or worse', icon: CircleSlash },
    { key: 'couldnt', label: 'Couldn’t check', description: 'Asleep, out, or didn’t want to talk', icon: Moon },
];
const COULDNT = ['Asleep', 'Out of the house', 'Didn’t want to talk about it', 'Other'];
const TOLD = ['Jordan Tipene (house lead)', 'The on-call contact', 'The prescriber or GP', 'Someone else'];

export function EffectCheckDialog({ id, onClose, returnFocus }: { id: string; onClose: () => void; returnFocus?: () => HTMLElement | null }) {
    const s = useStore();
    const row = s.row(id);
    const [outcome, setOutcome] = useState<string | null>(null);
    const [noticed, setNoticed] = useState('');
    const [escalate, setEscalate] = useState(false);
    const [told, setTold] = useState('');
    const [done, setDone] = useState('');
    const [reason, setReason] = useState('');
    const [again, setAgain] = useState(`${NOW_LOCAL.slice(0, 11)}10:15`);
    const [e, setE] = useState<Record<string, string>>({});
    if (!row || row.f.type !== 'effect' || !row.f.prn) return <NotFoundDialog onClose={onClose} />;
    const { f } = row;
    const prn = f.prn!;
    const p = PEOPLE[f.pid!];
    const act = canAct(f, s.route.persona);
    const needsEsc = escalate || outcome === 'none';
    const save = () => {
        const x: Record<string, string> = {};
        if (!outcome) x.outcome = `Choose how ${p.pref} is now.`;
        if (outcome === 'couldnt') {
            if (!reason) x.reason = 'Choose why you couldn’t check.';
            const t = localToMinutes(again);
            if (t == null) x.again = 'Enter a time.';
            else if (t <= NOW_MIN) x.again = `Choose a time after now (${NOW_LABEL}).`;
            else if (t > SHIFT_END_MIN) x.again = endOfShiftError;
        } else if (outcome && needsEsc) {
            if (!told) x.told = 'Choose who you told.';
            if (!done.trim()) x.done = 'Say what was done.';
        }
        setE(x);
        if (Object.keys(x).length) {
            const k = Object.keys(x)[0];
            window.setTimeout(() => document.getElementById(k === 'outcome' ? 'ec-outcome' : `ec-${k}`)?.focus(), 50);
            return;
        }
        if (outcome === 'couldnt') {
            const t = localToMinutes(again)!;
            s.update(f.id, { couldnt: { at: NOW_LABEL, by: PERSONAS[s.route.persona].name, reason, again: labelOf(t), againMin: t } });
            s.toast('info', `Saved — couldn’t check (${reason.toLowerCase()}). Check again at ${labelOf(t)}; if it isn’t done by 3:00 pm it carries over to the next shift.`);
        } else finish(s, f, `${OUTCOMES.find((o) => o.key === outcome)!.label}${noticed.trim() ? ` — ${noticed.trim()}` : ''}${needsEsc ? ` · told ${told}` : ''}`);
        onClose();
    };
    return (
        <Modal
            width={720}
            title="Did it help?"
            description={`${p.pref} · ${prn.med} · ${prn.amount} for ${prn.reason.toLowerCase()} · given ${prn.given} by ${prn.by}`}
            onClose={onClose}
            onCloseAutoFocus={(ev) => {
                const el = returnFocus?.();
                if (el) {
                    ev.preventDefault();
                    el.focus();
                }
            }}
            footer={
                <>
                    <Button variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    {act.close ? <Button onClick={save}>{outcome === 'couldnt' ? 'Save — check again later' : 'Record effect'}</Button> : null}
                </>
            }
        >
            {row.state === 'overdue' ? (
                <Notice tone="warning" title={`Overdue — the check was due at ${f.due}${f.carried ? ` · carried over from ${f.carried.from}` : ''}`}>
                    Record what you see now. It’s {overdueBy(f.dueMin)} after the check time, and that’s kept with the record.
                </Notice>
            ) : null}
            {!act.close ? <Notice tone="neutral" title="You can see this, but not record it">{act.why}</Notice> : null}
            <KV
                rows={[
                    ['Given', `${prn.given} by ${prn.by} · ${prn.amount} · for ${prn.reason.toLowerCase()}`],
                    ['Check was due', `${f.due}${f.couldnt ? ` (moved — couldn’t check at ${f.couldnt.at}: ${f.couldnt.reason.toLowerCase()})` : ''}`],
                    ['Owner', row.owner ?? 'Set when the handover is acknowledged'],
                ]}
            />
            <div className="space-y-2" data-field="ec-outcome">
                <Label id="ec-outcome-l">
                    How is {p.pref} now? <span className="text-status-critical">*</span>
                </Label>
                <div id="ec-outcome" tabIndex={-1}>
                    <TilePicker labelledBy="ec-outcome-l" value={outcome} invalid={!!e.outcome} onChange={(k) => (setOutcome(k), setE({}), k === 'none' && setEscalate(true))} tiles={OUTCOMES} />
                </div>
                <InputError message={e.outcome} />
            </div>
            {outcome === 'couldnt' ? (
                <div className="grid gap-3 sm:grid-cols-2">
                    <div className="space-y-1.5">
                        <Label htmlFor="ec-reason">
                            Why couldn’t you check? <span className="text-status-critical">*</span>
                        </Label>
                        <Select value={reason || undefined} onValueChange={(v) => (setReason(v), setE({}))}>
                            <SelectTrigger id="ec-reason" className="w-full" aria-invalid={!!e.reason}>
                                <SelectValue placeholder="Choose" />
                            </SelectTrigger>
                            <SelectContent>
                                {COULDNT.map((x) => (
                                    <SelectItem key={x} value={x}>
                                        {x}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={e.reason} />
                    </div>
                    <div className="sm:col-span-2" data-field="ec-again">
                        <DateTimeField id="ec-again" label="Check again at" value={again} onChange={(v) => (setAgain(v), setE({}))} error={e.again} hint="Up to the end of your shift (3:00 pm). If it’s still not done, it carries over to the next shift — it never closes by itself." />
                    </div>
                </div>
            ) : outcome ? (
                <>
                    {outcome === 'none' ? (
                        <Notice tone="warning" title="It didn’t help">
                            Don’t give another dose unless the prescription allows it. Tell Jordan Tipene (house lead) or the on-call contact. On-call contact: <NotConfigured />
                        </Notice>
                    ) : null}
                    <div className="space-y-1.5">
                        <Label htmlFor="ec-noticed">
                            What did you notice? <span className="text-subtle">(optional)</span>
                        </Label>
                        <Textarea id="ec-noticed" rows={2} value={noticed} placeholder={outcome === 'none' ? `e.g. ${p.pref} still has the ${prn.reason.toLowerCase()} and is lying down.` : `e.g. ${p.pref} said it had eased and went back to what they were doing.`} onChange={(ev) => setNoticed(ev.target.value)} />
                    </div>
                    <div className="flex items-start justify-between gap-4 rounded-xl border p-3">
                        <div>
                            <Label htmlFor="ec-escalate" className="text-sm font-medium">
                                Someone else needs to know
                            </Label>
                            <p className="text-caption">The house lead, the on-call contact, or the prescriber.</p>
                        </div>
                        <div className="flex items-center gap-2">
                            <span className="text-caption">{needsEsc ? 'On' : 'Off'}</span>
                            <Switch id="ec-escalate" checked={needsEsc} onCheckedChange={(v) => setEscalate(v)} disabled={outcome === 'none'} />
                        </div>
                    </div>
                    {needsEsc ? (
                        <div className="grid gap-3 sm:grid-cols-2">
                            <div className="space-y-1.5">
                                <Label htmlFor="ec-told">
                                    Who did you tell? <span className="text-status-critical">*</span>
                                </Label>
                                <Select value={told || undefined} onValueChange={(v) => (setTold(v), setE({}))}>
                                    <SelectTrigger id="ec-told" className="w-full" aria-invalid={!!e.told}>
                                        <SelectValue placeholder="Choose" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {TOLD.map((x) => (
                                            <SelectItem key={x} value={x}>
                                                {x}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <InputError message={e.told} />
                            </div>
                            <div className="space-y-1.5">
                                <Label htmlFor="ec-done">
                                    What was done? <span className="text-status-critical">*</span>
                                </Label>
                                <Textarea id="ec-done" rows={2} value={done} aria-invalid={!!e.done} placeholder="e.g. Rang Jordan at 9:15 am — they’ll call the GP." onChange={(ev) => (setDone(ev.target.value), setE({}))} />
                                <InputError message={e.done} />
                            </div>
                        </div>
                    ) : null}
                    <p className="text-caption">Recorded at the time you save ({NOW_LABEL} NZDT). The PRN record and {p.pref}’s chart show it.</p>
                </>
            ) : null}
        </Modal>
    );
}
