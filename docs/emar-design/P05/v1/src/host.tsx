/* The URL-driven dialog host (P01 / P07a / P08a / P03 / P04 / P06 / P07b
 * pattern): every dialog is addressable with ?open=… so the review session can
 * deep-link each state, and closing returns focus to the control that opened
 * it. A review at a house outside yours looks the same as one that doesn’t
 * exist (404). A deep link to an action your role can’t take — or one with
 * nothing left to do — says why and who can. */
import { PEOPLE, PERSONAS, orderOf, type PersonId } from './data';
import { ApptDialog, CancelDialog, ChangeDialog, DecisionDialog, EnterDialog, IntervalDialog, MoveDialog, OutcomeDialog } from './dialogs-change';
import { BookDialog, RecordDialog, ReviewDialog } from './dialogs-review';
import { Modal, NotFoundDialog } from './modal';
import { canEnter, canManage, cdView, changeState, housesOf, inScope, lowerFirst, reviewOf, WHO_ENTERS, WHO_MANAGES } from './model';
import { HistDetail, ReviewSettings } from './pages/settings';
import { useStore } from './store';

let lastTrigger: HTMLElement | null = null;
export function useOpen() {
    const s = useStore();
    return (spec: string) => {
        lastTrigger = document.activeElement instanceof HTMLElement ? document.activeElement : null;
        s.set({ open: spec });
    };
}
export function useClose() {
    const s = useStore();
    return () => s.set({ open: undefined });
}
export function returnFocus() {
    return lastTrigger?.isConnected ? lastTrigger : null;
}

function CantDoThis({ onClose, what, who }: { onClose: () => void; what: string; who: string }) {
    const s = useStore();
    return (
        <Modal title={`You can’t ${what}`} description={`This is done by ${who}.`} onClose={onClose}>
            <p className="text-sm">{PERSONAS[s.route.persona].name} can see medication reviews for their people. Ask the house lead if something needs doing.</p>
        </Modal>
    );
}
function NothingToDo({ onClose, title, description = 'It may have been done already.', children }: { onClose: () => void; title: string; description?: string; children: string }) {
    return (
        <Modal title={title} description={description} onClose={onClose}>
            <p className="text-sm">{children}</p>
        </Modal>
    );
}

export function DialogHost() {
    const s = useStore();
    const close = useClose();
    const spec = s.route.q.get('open');
    if (!spec) return null;
    const [kind, a1, a2] = spec.split(':');
    const p = s.route.persona;
    const rf = () => returnFocus();
    const go = (x: string) => s.set({ open: x });
    const r = a1 ? reviewOf(s.rt, a1) : null;
    const seen = !!r && inScope(p, r);
    const item = r?.recorded && a2 ? r.recorded.items.find((i) => i.orderId === a2) : undefined;
    const hidden = !!a2 && !!item && !!orderOf(a2).cd && !cdView(p);
    switch (kind) {
        case 'review':
            if (!seen) return <NotFoundDialog onClose={close} />;
            return <ReviewDialog key={spec} review={r!} onClose={close} onAction={go} />;
        case 'book': {
            const pid = a1 as PersonId | undefined;
            if (!canManage(p)) return <CantDoThis onClose={close} what="book a review" who={WHO_MANAGES} />;
            if (pid && (!PEOPLE[pid] || !housesOf(p).includes(PEOPLE[pid].house))) return <NotFoundDialog onClose={close} />;
            return <BookDialog key={spec} pid={pid} onClose={close} returnFocus={rf} />;
        }
        case 'record':
        case 'appt':
        case 'move':
        case 'cancel':
            if (!seen) return <NotFoundDialog onClose={close} />;
            if (!canManage(p)) return <CantDoThis onClose={close} what={kind === 'record' ? 'record a review’s outcome' : kind === 'appt' ? 'book the appointment' : kind === 'move' ? 'move a review' : 'cancel a review'} who={WHO_MANAGES} />;
            if (r!.state !== 'booked') return <NothingToDo onClose={close} title={r!.state === 'recorded' ? 'Already recorded' : r!.state === 'cancelled' ? 'Cancelled' : 'Closed'}>{`${r!.id} is ${r!.state === 'recorded' ? 'recorded' : r!.state === 'cancelled' ? 'cancelled' : 'closed'} — it’s kept as it is.`}</NothingToDo>;
            if (kind === 'record') return <RecordDialog key={spec} review={r!} onClose={close} returnFocus={rf} />;
            if (kind === 'appt') return <ApptDialog key={spec} review={r!} onClose={close} returnFocus={rf} />;
            if (kind === 'move') return <MoveDialog key={spec} review={r!} onClose={close} returnFocus={rf} />;
            return <CancelDialog key={spec} review={r!} onClose={close} onAction={go} returnFocus={rf} />;
        case 'change':
            if (!seen || !item) return <NotFoundDialog onClose={close} />;
            return <ChangeDialog key={spec} review={r!} orderId={a2} onClose={close} onAction={go} />;
        case 'decision':
        case 'enter':
        case 'outcome': {
            if (!seen || !item) return <NotFoundDialog onClose={close} />;
            const st = changeState(item).step;
            if (kind === 'enter' && !canEnter(p)) return <CantDoThis onClose={close} what="enter an order change" who={WHO_ENTERS} />;
            if (kind !== 'enter' && !canManage(p)) return <CantDoThis onClose={close} what={kind === 'decision' ? 'record the prescriber’s decision' : 'add an outcome'} who={WHO_MANAGES} />;
            if (hidden) return <CantDoThis onClose={close} what="change a controlled medicine’s review" who="a house lead with controlled-medicine access" />;
            if (kind === 'decision' && st !== 'waiting') return <NothingToDo onClose={close} title="Already decided">{`The prescriber’s decision is recorded: ${lowerFirst(changeState(item).label)}.`}</NothingToDo>;
            if (kind === 'enter' && st !== 'agreed') return <NothingToDo onClose={close} title={st === 'waiting' ? 'Not agreed yet' : 'Already entered'}>{st === 'waiting' ? 'It waits for the prescriber’s decision. Nothing is entered in Orders until they agree.' : 'This change is already in Orders.'}</NothingToDo>;
            if (kind === 'outcome' && st !== 'pending') return <NothingToDo onClose={close} title="Outcome already recorded">This medicine’s outcome is on the review.</NothingToDo>;
            if (kind === 'decision') return <DecisionDialog key={spec} review={r!} orderId={a2} onClose={close} returnFocus={rf} />;
            if (kind === 'enter') return <EnterDialog key={spec} review={r!} orderId={a2} onClose={close} returnFocus={rf} />;
            return <OutcomeDialog key={spec} review={r!} orderId={a2} onClose={close} returnFocus={rf} />;
        }
        case 'interval': {
            const pid = a1 as PersonId;
            if (!PEOPLE[pid] || !housesOf(p).includes(PEOPLE[pid].house)) return <NotFoundDialog onClose={close} />;
            if (!canManage(p)) return <CantDoThis onClose={close} what="change how often reviews are due" who={WHO_MANAGES} />;
            return <IntervalDialog key={spec} pid={pid} onClose={close} returnFocus={rf} />;
        }
        case 'p11review':
            return <ReviewSettings key={spec} onClose={close} />;
        case 'p11hist':
            return <HistDetail key={spec} id={a1} onClose={close} />;
        default:
            return <NotFoundDialog onClose={close} />;
    }
}
