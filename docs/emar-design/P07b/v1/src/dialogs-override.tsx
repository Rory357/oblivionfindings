/* One witness override (Main, Q7): request → decision → doses given → the
 * house lead’s witnessed count and sign-off (P07a Q4). The count and sign-off
 * is P07a’s approved dialog (Controlled checks); this view links to it. */
import { Button } from '@/components/ui/button';
import { StatusBadge } from '@/components/ui/status-badge';
import { PEOPLE, PERSONAS, HOUSES } from './data';
import { Modal } from './modal';
import { allOverrides, medOf, overrideState } from './model';
import { useStore } from './store';

export function OverrideDialog({ id, onClose }: { id: string; onClose: () => void }) {
    const s = useStore();
    const p = s.route.persona;
    const o = allOverrides(s.rt).find((x) => x.id === id)!;
    const m = medOf(o.medId);
    const st = overrideState(o);
    const steps: { title: string; body: string; done: boolean }[] = [
        { title: 'Asked for', body: `${o.requestedAt} by ${o.requestedBy}: “${o.why}”`, done: true },
        { title: 'Decision', body: o.decision.state === 'approved' ? `Granted by ${o.decision.by}, ${o.decision.at} — until ${o.decision.until}` : o.decision.state === 'declined' ? `Declined by ${o.decision.by}, ${o.decision.at}: “${o.decision.note}”` : 'Waiting for a manager', done: o.decision.state !== 'waiting' },
        { title: 'Doses given under it', body: o.doses.length ? o.doses.map((d) => `${d.at} by ${d.by}, with no second person`).join('; ') : o.decision.state === 'declined' ? 'None — the dose waited for a witness' : 'None yet', done: o.doses.length > 0 || o.decision.state === 'declined' },
        ...(o.followUp ? [{ title: 'Witnessed count and sign-off', body: o.followUp.done ? `${o.followUp.done.by}, ${o.followUp.done.at}, witnessed by ${o.followUp.done.witness}: “${o.followUp.done.note}”` : `${o.followUp.owner} — due ${o.followUp.due}${o.followUp.overdue ? ' · overdue' : ''}`, done: !!o.followUp.done }] : []),
    ];
    const owner = o.followUp && !o.followUp.done && PERSONAS[p].name === o.followUp.owner;
    return (
        <Modal
            width={720}
            title={`${o.id} — ${m.med}, ${PEOPLE[m.pid].pref}`}
            description={`${HOUSES[o.house]} · witness override`}
            onClose={onClose}
            footer={
                <>
                    <Button variant="outline" onClick={onClose}>
                        Close
                    </Button>
                    {owner ? <Button onClick={() => s.toast('info', 'The witnessed count and sign-off is P07a’s approved dialog (Controlled checks › Count and sign off) — outside this preview.')}>Count and sign off</Button> : null}
                </>
            }
        >
            <div className="flex flex-wrap items-center gap-2">
                <StatusBadge variant={st.variant} className="rounded-[8px] font-semibold">
                    {st.label}
                </StatusBadge>
            </div>
            <ol className="space-y-3" aria-label="What happened">
                {steps.map((x, n) => (
                    <li key={x.title} className="flex gap-3 text-sm">
                        <span className={`grid size-6 shrink-0 place-items-center rounded-full border text-[11px] font-semibold ${x.done ? 'border-primary bg-primary/10 text-primary' : 'text-muted-foreground'}`} aria-hidden="true">
                            {n + 1}
                        </span>
                        <span>
                            <span className="block font-semibold">{x.title}</span>
                            <span className="block text-muted-foreground">{x.body}</span>
                        </span>
                    </li>
                ))}
            </ol>
            {o.followUp?.overdue && !o.followUp.done ? <p className="text-sm">The sign-off is late. {o.followUp.owner} has a follow-up; the provider manager is told.</p> : null}
        </Modal>
    );
}
