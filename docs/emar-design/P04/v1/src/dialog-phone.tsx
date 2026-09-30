/* Countersign a prescriber’s phone instruction — P08a v1’s approved dialog
 * (docs/emar-design/P08a/v1/src/dialogs.tsx, CountersignDialog), copied view
 * for view so P04’s To check can open it. The dose-only instruction is
 * recorded while giving a dose (P01 v2); P00 v5 sets “by the end of the next
 * day”. Only the save wiring is P04’s. */
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Check, MessageSquareWarning } from 'lucide-react';
import { useState } from 'react';
import { NOW_LABEL } from './clock';
import { PEOPLE, PERSONAS, PHONE_INSTRUCTION } from './data';
import { Modal } from './modal';
import { useStore } from './store';
import { KV, TilePicker } from './ui';

export function PhoneCountersignDialog({ onClose, returnFocus }: { onClose: () => void; returnFocus?: () => HTMLElement | null }) {
    const s = useStore();
    const me = PERSONAS[s.route.persona];
    const [choice, setChoice] = useState<string | null>(null);
    const [note, setNote] = useState('');
    const [err, setErr] = useState('');
    return (
        <Modal
            width={720}
            title="Countersign a phone instruction"
            description={`${PEOPLE[PHONE_INSTRUCTION.pid].pref} · Insulin glargine 9:00 am · Dr Lena Chen by phone 8:55 am: 8 units today (ordered 10)`}
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
                    <Button
                        onClick={() => {
                            if (!choice) return setErr('Choose countersign or query.');
                            if (choice === 'query' && !note.trim()) return setErr('Say what you’re querying.');
                            if (s.route.scenario === 'offline') return setErr('You’re offline, so this can’t be saved yet. Nothing is lost — keep this open and save when you reconnect.');
                            const what = choice === 'sign' ? `Countersigned${note.trim() ? ` — ${note.trim()}` : ''}` : `Queried with the prescriber — ${note.trim()}`;
                            s.update((rt) => ({
                                ...rt,
                                phoneDone: what,
                                events: [{ id: `ev-${Date.now()}`, at: `Mon 28 Sep, ${NOW_LABEL}`, pid: PHONE_INSTRUCTION.pid, what: `Phone instruction ${choice === 'sign' ? 'countersigned' : 'queried with the prescriber'} — ${PHONE_INSTRUCTION.med}`, who: me.name }, ...rt.events],
                            }));
                            s.toast('success', choice === 'sign' ? 'Countersigned. The follow-up is closed.' : 'Query saved. The follow-up stays open until the prescriber answers.');
                            onClose();
                        }}
                    >
                        {choice === 'query' ? 'Save query' : 'Countersign'}
                    </Button>
                </>
            }
        >
            <KV
                rows={[
                    ['Prescriber', 'Dr Lena Chen, by phone at 8:55 am'],
                    ['What they said', 'Give 8 units of insulin glargine today instead of 10, for this dose only'],
                    ['Read back', 'Read back and confirmed by Priya Shah'],
                    ['Recorded', 'Priya Shah at 9:05 am · the 9:00 am dose was given as 8 units'],
                    ['Countersign by', 'The end of tomorrow (the organisation setting: by the end of the next day)'],
                ]}
            />
            <div className="space-y-2">
                <Label id="cs-l">
                    Your decision <span className="text-status-critical">*</span>
                </Label>
                <TilePicker
                    labelledBy="cs-l"
                    value={choice}
                    invalid={!!err && !choice}
                    onChange={(k) => (setChoice(k), setErr(''))}
                    tiles={[
                        { key: 'sign', label: 'Countersign', description: 'It matches what the prescriber asked for', icon: Check },
                        { key: 'query', label: 'Query it with the prescriber', description: 'Something doesn’t look right', icon: MessageSquareWarning },
                    ]}
                />
            </div>
            <div className="space-y-1.5">
                <Label htmlFor="cs-note">Note {choice === 'query' ? <span className="text-status-critical">*</span> : <span className="text-subtle">(optional)</span>}</Label>
                <Textarea id="cs-note" rows={2} value={note} onChange={(e) => (setNote(e.target.value), setErr(''))} placeholder="e.g. Confirmed with Dr Chen’s nurse at 10:20 am." />
                <InputError message={err} />
            </div>
            <p className="text-caption">A countersign isn’t a new order. If the change should last, the prescriber updates the order.</p>
        </Modal>
    );
}
