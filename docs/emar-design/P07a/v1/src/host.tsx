/* The URL-driven dialog host (P01 v1 pattern): every dialog is addressable
 * with ?open=… so the review session can deep-link each state, and closing
 * returns focus to the control that opened it. */
import { CountDialog } from './count-dialog';
import { CD_MEDS, PERSONAS } from './data';
import {
    AnswerAskDialog,
    AskWitnessDialog,
    CancelAskConfirm,
    CantCountDialog,
    DiscrepancyDialog,
    HistoryDialog,
    NotFoundDialog,
    OverrideApproveDialog,
    OverrideDetailDialog,
    OverrideRequestDialog,
    WitnessEligibilityDialog,
} from './dialogs';
import { FollowUpDialog } from './followup-dialog';
import { MovementDialog } from './movement-dialog';
import { useStore } from './store';

let lastTrigger: HTMLElement | null = null;
export function useOpen() {
    const s = useStore();
    return (spec: string) => {
        lastTrigger = document.activeElement instanceof HTMLElement ? document.activeElement : null;
        const q: Record<string, string | undefined> = {};
        s.route.q.forEach((v, k) => (q[k] = v));
        s.go(s.route.path, { ...q, open: spec });
    };
}
function useClose() {
    const s = useStore();
    return () => {
        const q: Record<string, string | undefined> = {};
        s.route.q.forEach((v, k) => (q[k] = v));
        s.go(s.route.path, { ...q, open: undefined });
    };
}
/** Focus the element that opened the dialog; if the row re-rendered, its current action. */
export function returnFocusFor(key?: string) {
    return () => {
        if (lastTrigger?.isConnected) return lastTrigger;
        if (key) return document.querySelector<HTMLElement>(`[data-return="${key}"]`);
        return null;
    };
}

const CD_KINDS = ['count', 'move', 'ask', 'answer', 'cancelask', 'disc', 'hist', 'elig', 'cantcount', 'ovr', 'ovr-request', 'ovr-approve', 'followup'];

export function DialogHost() {
    const s = useStore();
    const close = useClose();
    const spec = s.route.q.get('open');
    if (!spec) return null;
    const [kind, ...rest] = spec.split(':');
    const arg = rest.join(':');
    const p = PERSONAS[s.route.persona];
    // Controlled records leave no trace for roles without controlled-medicine view (EM-12, P00 v5).
    if (CD_KINDS.includes(kind) && !p.perms.includes('cd.view')) return <NotFoundDialog onClose={close} />;
    if (kind === 'count') {
        const ids = arg === 'all' || !arg ? CD_MEDS.filter((m) => m.house === 'kowhai').map((m) => m.id) : [arg];
        const m = CD_MEDS.find((x) => x.id === ids[0]);
        if (!m || !p.houses.includes(m.house)) return <NotFoundDialog onClose={close} />;
        return <CountDialog key={spec} medIds={ids} mode={arg === 'all' || !arg ? 'shift' : 'one'} onClose={close} returnFocus={returnFocusFor(arg === 'all' ? 'count-all' : `count-${arg}`)} />;
    }
    if (kind === 'move') return <MovementDialog key={spec} lockedId={arg || null} onClose={close} returnFocus={returnFocusFor('move')} />;
    if (kind === 'ask') return <AskWitnessDialog target={arg || 'count'} onClose={close} />;
    if (kind === 'answer') return <AnswerAskDialog id={arg} onClose={close} />;
    if (kind === 'cancelask') return <CancelAskConfirm id={arg} onClose={close} />;
    if (kind === 'disc') return <DiscrepancyDialog id={arg} onClose={close} />;
    if (kind === 'hist') return <HistoryDialog cdId={arg} onClose={close} />;
    if (kind === 'elig') return <WitnessEligibilityDialog onClose={close} />;
    if (kind === 'cantcount') return <CantCountDialog onClose={close} />;
    if (kind === 'ovr') return <OverrideDetailDialog onClose={close} />;
    if (kind === 'ovr-request') return <OverrideRequestDialog doseId={arg || 'd8'} onClose={close} />;
    if (kind === 'ovr-approve') return <OverrideApproveDialog onClose={close} startDeclining={arg === 'decline'} />;
    if (kind === 'followup') return s.route.persona === 'lead' || s.route.persona === 'pm' ? <FollowUpDialog onClose={close} returnFocus={returnFocusFor('followup')} /> : <OverrideDetailDialog onClose={close} />;
    return null;
}
