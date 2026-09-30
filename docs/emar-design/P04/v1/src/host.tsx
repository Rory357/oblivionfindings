/* The URL-driven dialog host (P01 / P07a / P08a / P03 pattern): every dialog is
 * addressable with ?open=… so the review session can deep-link each state, and
 * closing returns focus to the control that opened it. A person outside your
 * houses, or a controlled medicine without controlled-medicine view, looks the
 * same as a record that doesn’t exist (404). A deep link to an action your role
 * can’t take — or one with nothing left to do — says why and who can (it never
 * leaks anything the page doesn’t). */
import { PEOPLE, PERSONAS, PHONE_INSTRUCTION, type PersonId } from './data';
import { CovertDialog, ReconcileDialog, ReconcileViewDialog, RevokeCovertDialog } from './dialogs-covert-rec';
import { AllergyDialog, CheckDialog, EntryDialog, OrderDialog, StopDialog, WrittenDialog } from './dialogs-order';
import { PhoneCountersignDialog } from './dialog-phone';
import { Modal, NotFoundDialog } from './modal';
import { allOrders, allRecs, canCheck, canEnter, concealed, covertOf, currentOf, pendingOf, stoppedOf } from './model';
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

const WHO_ENTERS = 'house leads, coordinators, clinical leads and managers';
function CantDoThis({ onClose, what, who = WHO_ENTERS }: { onClose: () => void; what: string; who?: string }) {
    const s = useStore();
    return (
        <Modal title={`You can’t ${what}`} description={`This is done by ${who}.`} onClose={onClose}>
            <p className="text-sm">{PERSONAS[s.route.persona].name} can see orders. Ask the house lead if something needs to change.</p>
        </Modal>
    );
}
function NothingToDo({ onClose, title, children }: { onClose: () => void; title: string; children: string }) {
    return (
        <Modal title={title} description="It may have been done already." onClose={onClose}>
            <p className="text-sm">{children}</p>
        </Modal>
    );
}

export function DialogHost() {
    const s = useStore();
    const close = useClose();
    const open = useOpen();
    const spec = s.route.q.get('open');
    if (!spec) return null;
    const [kind, a1] = spec.split(':');
    const p = s.route.persona;
    const me = PERSONAS[p];
    const seePerson = (pid: string | undefined): pid is PersonId => !!pid && pid in PEOPLE && me.houses.includes(PEOPLE[pid as PersonId].house);
    const rf = () => returnFocus();
    const order = a1 ? allOrders(s.rt).find((o) => o.id === a1) : undefined;
    const orderOk = !!order && seePerson(order.pid) && !concealed(order, p);
    const stopped = order ? !!stoppedOf(order, s.rt) : false;
    switch (kind) {
        case 'order':
            if (!orderOk) return <NotFoundDialog onClose={close} />;
            return <OrderDialog key={spec} id={order!.id} onClose={close} onAction={(x) => s.set({ open: x })} />;
        case 'new':
            if (!canEnter(p)) return <CantDoThis onClose={close} what="enter an order" />;
            return <EntryDialog key={spec} onClose={close} returnFocus={rf} />;
        case 'change':
            if (!orderOk) return <NotFoundDialog onClose={close} />;
            if (!canEnter(p)) return <CantDoThis onClose={close} what="enter a change" />;
            if (stopped) return <NothingToDo onClose={close} title="This order is stopped">A stopped order can’t be changed. Enter a new order if the prescriber restarts it.</NothingToDo>;
            return <EntryDialog key={spec} orderId={order!.id} onClose={close} returnFocus={rf} />;
        case 'check': {
            if (!orderOk) return <NotFoundDialog onClose={close} />;
            if (!canCheck(p)) return <CantDoThis onClose={close} what="check orders" />;
            const pend = pendingOf(order!, s.rt);
            const cur = currentOf(order!, s.rt);
            if (stopped || !((pend && pend.state === 'waiting') || (!pend && cur?.state === 'lone'))) return <NothingToDo onClose={close} title="Nothing to check">This order has no version waiting to be checked.</NothingToDo>;
            return <CheckDialog key={spec} orderId={order!.id} onClose={close} returnFocus={rf} />;
        }
        case 'allergy': {
            if (!orderOk) return <NotFoundDialog onClose={close} />;
            if (!canEnter(p)) return <CantDoThis onClose={close} what="record the prescriber’s confirmation" />;
            const pend = pendingOf(order!, s.rt);
            if (stopped || !pend?.allergy || pend.allergy.confirmed) return <NothingToDo onClose={close} title="No confirmation needed">This order has no allergy match waiting for the prescriber.</NothingToDo>;
            return <AllergyDialog key={spec} orderId={order!.id} onClose={close} returnFocus={rf} />;
        }
        case 'written': {
            if (!orderOk) return <NotFoundDialog onClose={close} />;
            if (!canEnter(p)) return <CantDoThis onClose={close} what="attach the written confirmation" />;
            const cur = currentOf(order!, s.rt);
            if (stopped || !cur?.written || cur.written.done) return <NothingToDo onClose={close} title="No written confirmation due">This order isn’t waiting for the prescriber’s written confirmation.</NothingToDo>;
            return <WrittenDialog key={spec} orderId={order!.id} onClose={close} returnFocus={rf} />;
        }
        case 'stop':
            if (!orderOk) return <NotFoundDialog onClose={close} />;
            if (!canEnter(p)) return <CantDoThis onClose={close} what="stop an order" />;
            if (stopped) return <NothingToDo onClose={close} title="Already stopped">This order is already stopped.</NothingToDo>;
            return <StopDialog key={spec} orderId={order!.id} onClose={close} returnFocus={rf} />;
        case 'covert':
            if (!orderOk) return <NotFoundDialog onClose={close} />;
            if (!canEnter(p)) return <CantDoThis onClose={close} what="review covert giving" who="house leads, clinical leads and managers, with the GP" />;
            if (stopped) return <NothingToDo onClose={close} title="This order is stopped">Covert giving ended with the order.</NothingToDo>;
            return <CovertDialog key={spec} orderId={order!.id} onClose={close} onRevoke={() => open(`revoke:${order!.id}`)} returnFocus={rf} />;
        case 'revoke':
            if (!orderOk) return <NotFoundDialog onClose={close} />;
            if (!canEnter(p)) return <CantDoThis onClose={close} what="stop covert giving" who="house leads, clinical leads and managers" />;
            if (!covertOf(order!.id, s.rt, s.route.scenario)) return <NothingToDo onClose={close} title="No covert authorisation">This medicine isn’t authorised for covert giving.</NothingToDo>;
            return <RevokeCovertDialog key={spec} orderId={order!.id} onClose={close} returnFocus={rf} />;
        case 'reconcile': {
            if (a1 === 'new') return canEnter(p) ? <ReconcileDialog key={spec} recId="new" onClose={close} returnFocus={rf} /> : <CantDoThis onClose={close} what="start a reconciliation" />;
            const r = allRecs(s.rt).find((x) => x.id === a1);
            if (!r || !seePerson(r.pid)) return <NotFoundDialog onClose={close} />;
            const status = s.rt.recs[r.id]?.status ?? r.status;
            if (status === 'open' && canEnter(p)) return <ReconcileDialog key={spec} recId={r.id} onClose={close} returnFocus={rf} />;
            return <ReconcileViewDialog recId={r.id} onClose={close} />;
        }
        case 'countersign':
            if (a1 !== 'phone' || !seePerson(PHONE_INSTRUCTION.pid)) return <NotFoundDialog onClose={close} />;
            if (!canCheck(p)) return <CantDoThis onClose={close} what="countersign a phone instruction" who="house and clinical leads" />;
            if (s.rt.phoneDone) return <NothingToDo onClose={close} title="Already done">{`${s.rt.phoneDone}.`}</NothingToDo>;
            return <PhoneCountersignDialog key={spec} onClose={close} returnFocus={rf} />;
        default:
            return <NotFoundDialog onClose={close} />;
    }
}
