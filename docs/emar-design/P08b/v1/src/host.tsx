/* The URL-driven dialog host (P01 / P07a / P08a / P03 / P04 / P06 / P07b / P05
 * pattern): every dialog is addressable with ?open=… so the review session can
 * deep-link each state, and closing returns focus to the control that opened
 * it. An error at a house outside yours — or another person’s report, for a
 * support worker — looks the same as one that doesn’t exist (404). A deep link
 * to an action your role can’t take, or one with nothing left to do, says why
 * and who can. */
import { ORDERS, PEOPLE, PERSONAS, type PersonId } from './data';
import { AccountDialog, ActionDialog, CloseDialog, DiscloseDialog, DoneDialog, ErrorDialog, ExportDialog, IncidentCloseDialog, NoteDialog, ReopenDialog, TriageDialog } from './dialogs-error';
import { ReportDialog } from './dialogs-report';
import { Modal, NotFoundDialog } from './modal';
import { canCloseError, canCloseIncident, canManage, canReport, canSeeAll, errorOf, housesOf, incidentsIn, isReady, redacted, visible, WHO_CLOSES_INCIDENTS, WHO_MANAGES } from './model';
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
            <p className="text-sm">{PERSONAS[s.route.persona].name}’s role doesn’t include this. Ask the house lead if something needs doing.</p>
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
    const e = a1 && kind !== 'report' && kind !== 'incclose' ? errorOf(s.rt, a1) : null;
    const seen = !!e && visible(p, e);
    const CD = <CantDoThis onClose={close} what="act on a controlled-medicine error" who="a house lead with controlled-medicine access" />;
    switch (kind) {
        case 'error':
            if (!seen) return <NotFoundDialog onClose={close} />;
            return <ErrorDialog key={spec} error={e!} section={a2} onClose={close} onAction={go} />;
        case 'report': {
            if (!canReport(p)) return <CantDoThis onClose={close} what="report a medication error" who="anyone who gives medicines" />;
            const mode = a1 === 'dose' || a1 === 'notright' || a1 === 'more' ? a1 : 'page';
            const order = a2 ? ORDERS.find((o) => o.id === a2) : undefined;
            if (a1 === 'person') {
                const pid = a2 as PersonId;
                if (!PEOPLE[pid] || !housesOf(p).includes(PEOPLE[pid].house)) return <NotFoundDialog onClose={close} />;
                return <ReportDialog key={spec} mode="page" pid={pid} onClose={close} onAction={go} returnFocus={rf} />;
            }
            if (mode !== 'page' && (!order || !housesOf(p).includes(PEOPLE[order.pid].house))) return <NotFoundDialog onClose={close} />;
            return <ReportDialog key={spec} mode={mode} orderId={order?.id} onClose={close} onAction={go} returnFocus={rf} />;
        }
        case 'triage':
        case 'note':
        case 'action':
        case 'done':
        case 'disclose':
        case 'reopen':
        case 'close': {
            if (!seen) return <NotFoundDialog onClose={close} />;
            const what = { triage: 'triage an error', note: 'add a note', action: 'add an action', done: 'mark an action done', disclose: 'record telling the person', reopen: 'reopen an error', close: 'close an error' }[kind];
            if (!canManage(p)) return <CantDoThis onClose={close} what={what} who={WHO_MANAGES} />;
            if (redacted(p, e!)) return CD;
            const x = e!;
            if (kind === 'triage') {
                if (x.stage !== 'triage') return <NothingToDo onClose={close} title="Already triaged">{`${x.id} was triaged by ${x.triaged?.by}, ${x.triaged?.at}. It’s with ${x.owner}.`}</NothingToDo>;
                return <TriageDialog key={spec} error={x} onClose={close} returnFocus={rf} />;
            }
            if (kind === 'reopen') {
                if (x.stage !== 'closed') return <NothingToDo onClose={close} title="It’s open" description="Only a closed error can be reopened.">{`${x.id} is open, with ${x.owner ?? 'nobody yet — it’s waiting for triage'}.`}</NothingToDo>;
                return <ReopenDialog key={spec} error={x} onClose={close} returnFocus={rf} />;
            }
            if (x.stage === 'closed') return <NothingToDo onClose={close} title="Closed" description="Nothing more can be added.">{`${x.id} was closed by ${x.closed?.by}, ${x.closed?.at}. Reopen it, with a reason, to add more.`}</NothingToDo>;
            if (kind === 'disclose') {
                if (x.reach === 'no') return <NothingToDo onClose={close} title="Not needed" description="A near miss didn’t reach the person.">{`${x.id} didn’t reach ${PEOPLE[x.pid].pref}, so there’s nothing to tell them.`}</NothingToDo>;
                return <DiscloseDialog key={spec} error={x} onClose={close} returnFocus={rf} />;
            }
            if (x.stage === 'triage') return <NothingToDo onClose={close} title="Not triaged yet" description="Triage comes first.">{`Triage ${x.id} first — it gets an owner and a due date.`}</NothingToDo>;
            if (kind === 'note') return <NoteDialog key={spec} error={x} onClose={close} returnFocus={rf} />;
            if (kind === 'action') return <ActionDialog key={spec} error={x} onClose={close} returnFocus={rf} />;
            if (kind === 'done') {
                const a = x.actions.find((y) => y.id === a2);
                if (!a) return <NotFoundDialog onClose={close} />;
                if (a.done) return <NothingToDo onClose={close} title="Already done">{`${a.id} was done by ${a.done.by}, ${a.done.at}.`}</NothingToDo>;
                return <DoneDialog key={spec} error={x} action={a} onClose={close} returnFocus={rf} />;
            }
            if (!canCloseError(p, x)) return <CantDoThis onClose={close} what="close a report you made" who="someone else who manages errors — the person who reported it never closes it" />;
            return <CloseDialog key={spec} error={x} onClose={close} returnFocus={rf} />;
        }
        case 'account':
            if (!seen) return <NotFoundDialog onClose={close} />;
            if (!canReport(p)) return <CantDoThis onClose={close} what="add an account" who="anyone who gives medicines" />;
            if (redacted(p, e!)) return CD;
            if (e!.stage === 'closed') return <NothingToDo onClose={close} title="Closed" description="Accounts are added while it’s open.">{`${e!.id} is closed. Tell the house lead if there’s more to add — it can be reopened.`}</NothingToDo>;
            return <AccountDialog key={spec} error={e!} onClose={close} returnFocus={rf} />;
        case 'incclose': {
            const i = incidentsIn(s.rt, p).find((x) => x.id === a1);
            if (!i) return <NotFoundDialog onClose={close} />;
            if (!canCloseIncident(p)) return <CantDoThis onClose={close} what="close an incident" who={WHO_CLOSES_INCIDENTS} />;
            if (i.status === 'closed') return <NothingToDo onClose={close} title="Already closed">{`${i.id} was closed by ${i.closed?.by}, ${i.closed?.at}.`}</NothingToDo>;
            if (!isReady(s.rt, i)) return <NothingToDo onClose={close} title="Not ready to close" description="The medication side is still open.">{`${i.id} is ready to close when ${i.ref} is closed.`}</NothingToDo>;
            return <IncidentCloseDialog key={spec} incident={i} onClose={close} returnFocus={rf} />;
        }
        case 'export':
            if (!canSeeAll(p)) return <CantDoThis onClose={close} what="export medication errors" who={`${WHO_MANAGES}, and auditors`} />;
            return <ExportDialog key={spec} onClose={close} returnFocus={rf} />;
        case 'p11review':
            return <ReviewSettings key={spec} onClose={close} />;
        case 'p11hist':
            return <HistDetail key={spec} id={a1} onClose={close} />;
        default:
            return <NotFoundDialog onClose={close} />;
    }
}
