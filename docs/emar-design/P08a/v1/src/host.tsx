/* The URL-driven dialog host (P01 / P07a pattern): every dialog is addressable
 * with ?open=… so the review session can deep-link each state, and closing
 * returns focus to the control that opened it. */
import { useEffect } from 'react';
import { PERSONAS, fuById } from './data';
import {
    AsNeededDoseDialog,
    CountersignDialog,
    FollowUpDetailDialog,
    HeadsUpDialog,
    ReassignDialog,
    SignOffDialog,
    WereYouThereDialog,
} from './dialogs';
import { EffectCheckDialog } from './effect-dialog';
import { HandoverDetailDialog, HandoverDraftDialog } from './handover-dialogs';
import { NotFoundDialog } from './modal';
import { visible } from './model';
import { RefusalDialog } from './refusal-dialog';
import { useStore } from './store';

/** The witness-override follow-up is P07a’s approved dialog: say so and return. */
function OverrideHandoff({ onClose }: { onClose: () => void }) {
    const s = useStore();
    useEffect(() => {
        s.toast('info', 'Opens P07a’s approved “Check doses given without a witness” dialog (witnessed count, then sign-off) — outside this preview.');
        onClose();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);
    return null;
}

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
export function returnFocus() {
    return lastTrigger?.isConnected ? lastTrigger : null;
}

export function DialogHost() {
    const s = useStore();
    const close = useClose();
    const open = useOpen();
    const spec = s.route.q.get('open');
    if (!spec) return null;
    const [kind, ...rest] = spec.split(':');
    const arg = rest.join(':');
    // A follow-up outside your houses, or a controlled one without controlled-medicine view, looks like one that doesn’t exist.
    const f = fuById(arg);
    if (['fu', 'effect', 'refusal', 'confirm', 'signoff', 'countersign', 'headsup', 'reassign', 'prn', 'override'].includes(kind) && (!f || !visible(f, s.route.persona))) return <NotFoundDialog onClose={close} />;
    const rf = () => returnFocus();
    switch (kind) {
        case 'fu':
            return <FollowUpDetailDialog id={arg} onClose={close} onAction={open} />;
        case 'effect':
            return <EffectCheckDialog key={spec} id={arg} onClose={close} returnFocus={rf} />;
        case 'refusal':
            return <RefusalDialog key={spec} id={arg} onClose={close} returnFocus={rf} />;
        case 'confirm':
            return <WereYouThereDialog id={arg} onClose={close} />;
        case 'signoff':
            return <SignOffDialog id={arg} onClose={close} />;
        case 'countersign':
            return <CountersignDialog id={arg} onClose={close} />;
        case 'headsup':
            return <HeadsUpDialog id={arg} onClose={close} />;
        case 'reassign':
            return <ReassignDialog id={arg} onClose={close} />;
        case 'prn':
            return <AsNeededDoseDialog id={arg} onClose={close} onAction={open} />;
        case 'override':
            return <OverrideHandoff onClose={close} />;
        case 'handover':
            return <HandoverDetailDialog id={arg} onClose={close} onOpenFollowUp={(id) => open(`fu:${id}`)} />;
        case 'handover-draft':
            return PERSONAS[s.route.persona].shift ? <HandoverDraftDialog onClose={close} onOpenFollowUp={(id) => open(`fu:${id}`)} /> : <NotFoundDialog onClose={close} />;
        default:
            return null;
    }
}
