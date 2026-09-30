/* The URL-driven dialog host (P01 / P07a / P08a / P03 / P04 pattern): every
 * dialog is addressable with ?open=… so the review session can deep-link each
 * state, and closing returns focus to the control that opened it. A person
 * outside your houses, or a controlled medicine without controlled-medicine
 * view, looks the same as a record that doesn’t exist (404). A deep link to an
 * action your role can’t take — or one with nothing left to do — says why and
 * who can. */
import { PEOPLE, PERSONAS, type House } from './data';
import { DispensedDialog, EndOrderDialog, NewOrderDialog, OrderDialog } from './dialogs-orders';
import { ItemDialog, ReceiveDialog } from './dialogs-receive';
import { AdjustDialog, CountDialog, MoveDialog, SignOffDialog } from './dialogs-stock';
import { Modal, NotFoundDialog } from './modal';
import { allCounts, allMovements, allOrders, canManage, canReceive, cdView, concealed, itemOf, lotsOf, receivable, WHO_MANAGES, WHO_RECEIVES } from './model';
import { ITEMS } from './data';
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
            <p className="text-sm">{PERSONAS[s.route.persona].name} can see stock. Ask the house lead if something needs doing.</p>
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
const CD_ELSEWHERE = 'Controlled medicines are counted, moved and adjusted in Controlled checks and the controlled register, with a witness.';

export function DialogHost() {
    const s = useStore();
    const close = useClose();
    const spec = s.route.q.get('open');
    if (!spec) return null;
    const [kind, a1, a2, a3] = spec.split(':');
    const p = s.route.persona;
    const me = PERSONAS[p];
    const seeHouse = (h: House) => me.houses.includes(h);
    const rf = () => returnFocus();
    const go = (x: string) => s.set({ open: x });
    const item = kind !== 'po' && kind !== 'dispensed' && kind !== 'cancel' && kind !== 'short' && kind !== 'signoff' && a1 && a1 !== 'new' && a1 !== 'house' && a1 !== 'item' ? ITEMS.find((i) => i.id === a1) : undefined;
    const itemOk = (id: string | undefined) => {
        const i = id ? ITEMS.find((x) => x.id === id) : undefined;
        return !!i && seeHouse(PEOPLE[i.pid].house) && !concealed(i, p);
    };
    const order = ['po', 'dispensed', 'cancel', 'short', 'receive'].includes(kind) && a1 ? allOrders(s.rt).find((o) => o.id === a1) : undefined;
    const orderOk = !!order && seeHouse(order.house) && (!order.cd || cdView(p));
    switch (kind) {
        case 'item':
            if (!itemOk(a1)) return <NotFoundDialog onClose={close} />;
            return <ItemDialog key={spec} id={a1} onClose={close} onAction={go} />;
        case 'receive': {
            if (a1 === 'item' && !itemOk(a2)) return <NotFoundDialog onClose={close} />;
            if (a1 !== 'new' && a1 !== 'item' && !orderOk) return <NotFoundDialog onClose={close} />;
            if (!canReceive(p)) return <CantDoThis onClose={close} what="receive deliveries" who={WHO_RECEIVES} />;
            if (order && order.cd && !canManage(p)) return <CantDoThis onClose={close} what="receive a controlled delivery" who="house leads, with a witness" />;
            if (order && !receivable(order)) return <NothingToDo onClose={close} title="Nothing to receive">{`${order.id} isn’t waiting to be received.`}</NothingToDo>;
            if (a1 === 'item' && itemOf(a2).cd) return <NothingToDo onClose={close} title="Received from its pharmacy order">Controlled deliveries are received against their pharmacy order, into the register.</NothingToDo>;
            return <ReceiveDialog key={spec} spec={spec} onClose={close} returnFocus={rf} />;
        }
        case 'order':
            if (a2 && !itemOk(a2)) return <NotFoundDialog onClose={close} />;
            if (!canManage(p)) return <CantDoThis onClose={close} what="order from the pharmacy" who={WHO_MANAGES} />;
            if (a2 && (itemOf(a2).cd || itemOf(a2).selfManaged)) return <NothingToDo onClose={close} title="Not ordered here">{itemOf(a2).cd ? 'Controlled medicines are ordered with a prescription through the controlled register.' : 'The person manages this medicine themselves.'}</NothingToDo>;
            return <NewOrderDialog key={spec} itemId={a2} onClose={close} returnFocus={rf} />;
        case 'po':
            if (!orderOk) return <NotFoundDialog onClose={close} />;
            return <OrderDialog key={spec} id={order!.id} onClose={close} onAction={go} returnFocus={rf} />;
        case 'dispensed':
            if (!orderOk) return <NotFoundDialog onClose={close} />;
            if (!canManage(p)) return <CantDoThis onClose={close} what="record the pharmacy’s dispensing" who={WHO_MANAGES} />;
            if (order!.state !== 'sent') return <NothingToDo onClose={close} title="Already recorded">{`${order!.id} isn’t waiting for the pharmacy.`}</NothingToDo>;
            return <DispensedDialog key={spec} id={order!.id} onClose={close} returnFocus={rf} />;
        case 'cancel':
        case 'short':
            if (!orderOk) return <NotFoundDialog onClose={close} />;
            if (!canManage(p)) return <CantDoThis onClose={close} what={kind === 'cancel' ? 'cancel an order' : 'close an order short'} who={WHO_MANAGES} />;
            if (kind === 'cancel' ? !['draft', 'sent'].includes(order!.state) : order!.state !== 'part') return <NothingToDo onClose={close} title={kind === 'cancel' ? 'It can’t be cancelled now' : 'Nothing to close'}>{kind === 'cancel' ? 'Only a draft or sent order can be cancelled. A dispensed order is received, or returned to the pharmacy.' : 'Only a part-received order can be closed short.'}</NothingToDo>;
            return <EndOrderDialog key={spec} id={order!.id} mode={kind} onClose={close} returnFocus={rf} />;
        case 'count':
            if (a1 !== 'house' && !itemOk(a1)) return <NotFoundDialog onClose={close} />;
            if (!canReceive(p)) return <CantDoThis onClose={close} what="count stock" who={WHO_RECEIVES} />;
            if (item?.cd) return <NothingToDo onClose={close} title="Counted in Controlled checks">{CD_ELSEWHERE}</NothingToDo>;
            if (item && (item.selfManaged || !lotsOf(item, s.rt).length)) return <NothingToDo onClose={close} title="Nothing to count">{item.selfManaged ? 'The person manages this medicine — it isn’t counted.' : 'No packs received yet.'}</NothingToDo>;
            return <CountDialog key={spec} scope={a1} onClose={close} returnFocus={rf} />;
        case 'signoff': {
            const c = allCounts(s.rt).find((x) => x.id === a1);
            if (!c || !seeHouse(c.house)) return <NotFoundDialog onClose={close} />;
            return <SignOffDialog key={spec} id={c.id} onClose={close} returnFocus={rf} />;
        }
        case 'adjust':
            if (!itemOk(a1)) return <NotFoundDialog onClose={close} />;
            if (!canManage(p)) return <CantDoThis onClose={close} what="adjust or remove stock" who={WHO_MANAGES} />;
            if (item?.cd) return <NothingToDo onClose={close} title="Adjusted in the controlled register">{CD_ELSEWHERE}</NothingToDo>;
            if (!lotsOf(item!, s.rt).some((l) => l.qty > 0)) return <NothingToDo onClose={close} title="Nothing to adjust">No open packs.</NothingToDo>;
            return <AdjustDialog key={spec} itemId={a1} lotId={a2} onClose={close} returnFocus={rf} />;
        case 'move': {
            if (!itemOk(a1)) return <NotFoundDialog onClose={close} />;
            if (!canReceive(p)) return <CantDoThis onClose={close} what="record medicines going out or coming back" who={WHO_RECEIVES} />;
            if (item?.cd) return <NothingToDo onClose={close} title="Recorded in Controlled checks">{CD_ELSEWHERE}</NothingToDo>;
            const back = a2 === 'back' ? allMovements(s.rt).find((m) => m.id === a3 && m.kind === 'out' && m.itemId === a1 && !s.rt.closedMoves.includes(m.id)) : undefined;
            if (a2 === 'back' && !back) return <NothingToDo onClose={close} title="Already back">It was recorded coming back.</NothingToDo>;
            return <MoveDialog key={spec} itemId={a1} backOf={back?.id} onClose={close} returnFocus={rf} />;
        }
        default:
            return <NotFoundDialog onClose={close} />;
    }
}
