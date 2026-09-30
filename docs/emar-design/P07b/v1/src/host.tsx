/* The URL-driven dialog host (P01 / P07a / P08a / P03 / P04 / P06 pattern):
 * every dialog is addressable with ?open=… so the review session can deep-link
 * each state, and closing returns focus to the control that opened it. Without
 * controlled-medicine view, a controlled record looks the same as one that
 * doesn’t exist (404). A deep link to an action your role can’t take — or one
 * with nothing left to do — says why and who can. */
import { PEOPLE, PERSONAS, type House } from './data';
import { ReportLossDialog, LossDialog, LossNoteDialog, CloseLossDialog, ReturnDialog, ReceiptDialog, DestructionDialog, VoidDestructionDialog } from './dialogs-loss-dest';
import { OverrideDialog } from './dialogs-override';
import { BreakageDialog, ClassDialog, DiscrepancyDialog, MedDialog, ResolveDialog, VoidEntryDialog } from './dialogs-register';
import { Modal, NotFoundDialog } from './modal';
import { allDestructions, allDiscrepancies, allEntries, allLosses, allOverrides, canCloseLoss, canManage, canRecord, cdView, WHO_MANAGES, whyCantResolve } from './model';
import { MEDS } from './data';
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
            <p className="text-sm">{PERSONAS[s.route.persona].name} can see the register. Ask the house lead if something needs doing.</p>
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
const RECORDERS = 'staff with controlled-medicine record access';

export function DialogHost() {
    const s = useStore();
    const close = useClose();
    const spec = s.route.q.get('open');
    if (!spec) return null;
    const [kind, a1, a2] = spec.split(':');
    const p = s.route.persona;
    const me = PERSONAS[p];
    const rf = () => returnFocus();
    const go = (x: string) => s.set({ open: x });
    const seeMed = (id: string | undefined) => {
        const m = id ? MEDS.find((x) => x.id === id) : undefined;
        return !!m && cdView(p) && me.houses.includes(PEOPLE[m.pid].house as House);
    };
    switch (kind) {
        case 'med':
            if (!seeMed(a1)) return <NotFoundDialog onClose={close} />;
            return <MedDialog key={spec} id={a1} onClose={close} onAction={go} />;
        case 'class':
            if (!seeMed(a1)) return <NotFoundDialog onClose={close} />;
            if (!canManage(p)) return <CantDoThis onClose={close} what="set a medicine’s class" who={WHO_MANAGES} />;
            return <ClassDialog key={spec} id={a1} onClose={close} returnFocus={rf} />;
        case 'breakage':
            if (!seeMed(a1)) return <NotFoundDialog onClose={close} />;
            if (!canRecord(p)) return <CantDoThis onClose={close} what="record a breakage" who={RECORDERS} />;
            return <BreakageDialog key={spec} id={a1} onClose={close} returnFocus={rf} />;
        case 'void': {
            const e = allEntries(s.rt).find((x) => x.id === a1);
            if (!e || !seeMed(e.medId)) return <NotFoundDialog onClose={close} />;
            if (!canManage(p)) return <CantDoThis onClose={close} what="void a register entry" who={WHO_MANAGES} />;
            if (e.voided) return <NothingToDo onClose={close} title="Already voided">This entry is already voided. It stays in the register, struck through.</NothingToDo>;
            if (e.kind === 'count') return <NothingToDo onClose={close} title="A count isn’t voided" description="Counts stay in the register as they were recorded.">If a count found a difference, resolve its discrepancy — that’s how the balance is corrected.</NothingToDo>;
            return <VoidEntryDialog key={spec} id={a1} onClose={close} returnFocus={rf} />;
        }
        case 'disc':
        case 'resolve': {
            const d = allDiscrepancies(s.rt).find((x) => x.id === a1);
            if (!d || !seeMed(d.medId)) return <NotFoundDialog onClose={close} />;
            if (kind === 'disc') return <DiscrepancyDialog key={spec} id={d.id} onClose={close} onAction={go} />;
            if (d.status === 'closed') return <NothingToDo onClose={close} title="Already resolved">{`${d.id} is closed.`}</NothingToDo>;
            const why = whyCantResolve(d, p);
            if (why) return <CantDoThis onClose={close} what="resolve this discrepancy" who={why.startsWith('You') ? 'someone who didn’t count or witness it — the house lead, or a manager if the house lead counted' : WHO_MANAGES} />;
            if (d.status === 'under_review' && !me.perms.includes('manager')) return <CantDoThis onClose={close} what="resolve an escalated discrepancy" who="a manager" />;
            return <ResolveDialog key={spec} id={d.id} onClose={close} returnFocus={rf} />;
        }
        case 'loss': {
            if (a1 === 'new') {
                if (a2 && !seeMed(a2)) return <NotFoundDialog onClose={close} />;
                if (!cdView(p)) return <NotFoundDialog onClose={close} />;
                if (!canRecord(p)) return <CantDoThis onClose={close} what="report a loss" who={RECORDERS} />;
                return <ReportLossDialog key={spec} medId={a2} onClose={close} returnFocus={rf} />;
            }
            const l = allLosses(s.rt).find((x) => x.id === a1);
            if (!l || !seeMed(l.medId)) return <NotFoundDialog onClose={close} />;
            return <LossDialog key={spec} id={l.id} onClose={close} onAction={go} />;
        }
        case 'lossnote':
        case 'lossclose': {
            const l = allLosses(s.rt).find((x) => x.id === a1);
            if (!l || !seeMed(l.medId)) return <NotFoundDialog onClose={close} />;
            if (l.status === 'closed') return <NothingToDo onClose={close} title="Closed">{`${l.id} is closed; its investigation can’t be added to.`}</NothingToDo>;
            if (kind === 'lossnote') return canRecord(p) ? <LossNoteDialog key={spec} id={l.id} onClose={close} returnFocus={rf} /> : <CantDoThis onClose={close} what="add to the investigation" who={RECORDERS} />;
            return canCloseLoss(p) ? <CloseLossDialog key={spec} id={l.id} onClose={close} returnFocus={rf} /> : <CantDoThis onClose={close} what="close a loss" who="managers" />;
        }
        case 'destroy':
            if (a2 && !seeMed(a2)) return <NotFoundDialog onClose={close} />;
            if (!cdView(p)) return <NotFoundDialog onClose={close} />;
            if (!canRecord(p)) return <CantDoThis onClose={close} what="return medicines for destruction" who={RECORDERS} />;
            return <ReturnDialog key={spec} medId={a2} onClose={close} returnFocus={rf} />;
        case 'dest':
        case 'receipt':
        case 'voiddest': {
            const d = allDestructions(s.rt).find((x) => x.id === a1);
            if (!d || !seeMed(d.medId)) return <NotFoundDialog onClose={close} />;
            if (kind === 'dest') return <DestructionDialog key={spec} id={d.id} onClose={close} onAction={go} />;
            if (!canManage(p)) return <CantDoThis onClose={close} what={kind === 'receipt' ? 'record the pharmacist’s receipt' : 'void a destruction'} who={WHO_MANAGES} />;
            if (d.voided) return <NothingToDo onClose={close} title="Voided">{`${d.id} is voided.`}</NothingToDo>;
            if (kind === 'receipt' && (d.method !== 'return' || d.received)) return <NothingToDo onClose={close} title="Nothing to record">{`${d.id} isn’t waiting for the pharmacist.`}</NothingToDo>;
            return kind === 'receipt' ? <ReceiptDialog key={spec} id={d.id} onClose={close} returnFocus={rf} /> : <VoidDestructionDialog key={spec} id={d.id} onClose={close} returnFocus={rf} />;
        }
        case 'override': {
            const o = allOverrides(s.rt).find((x) => x.id === a1);
            if (!o || !cdView(p) || !me.houses.includes(o.house)) return <NotFoundDialog onClose={close} />;
            return <OverrideDialog key={spec} id={o.id} onClose={close} />;
        }
        default:
            return <NotFoundDialog onClose={close} />;
    }
}
