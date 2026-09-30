/* The URL-driven dialog host (the P01–P08b pattern): every dialog is
 * addressable with ?open=… so the review session can deep-link each state,
 * and closing returns focus to the control that opened it. A record at a
 * house outside yours looks the same as one that doesn’t exist (404). A deep
 * link to an action your role can’t take says why and who can. */
import { EXPORT_DEFS, type ExportKind } from './model';
import { PEOPLE, PERSONAS, has } from './data';
import { AboutExportDialog, CloseWithSacDialog, EventDialog, ExportDialog, RangeDialog, VerifyDialog } from './dialogs';
import { Modal, NotFoundDialog } from './modal';
import { allErrors, canAudit, canExportKind, housesOf, logWithRuntime } from './model';
import { canCloseErr } from './pages/errors-frame';
import { whoCan } from './pages/exports';
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
            <p className="text-sm">{PERSONAS[s.route.persona].name}’s role doesn’t include this. Ask one of them if you need it.</p>
        </Modal>
    );
}
function NothingToDo({ onClose, title, description, children }: { onClose: () => void; title: string; description: string; children: string }) {
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
    switch (kind) {
        case 'export': {
            const def = EXPORT_DEFS.find((d) => d.key === a1);
            if (!def) return <NotFoundDialog onClose={close} />;
            if (!canExportKind(p, def.key)) return <CantDoThis onClose={close} what={`make the ${def.name.toLowerCase()} ${def.format}`} who={whoCan(def)} />;
            if (def.key === 'mar' && a2 && (!PEOPLE[a2 as keyof typeof PEOPLE] || !housesOf(p).includes(PEOPLE[a2 as keyof typeof PEOPLE].house))) return <NotFoundDialog onClose={close} />;
            return <ExportDialog key={spec} kind={def.key as ExportKind} arg={a2} onClose={close} returnFocus={rf} />;
        }
        case 'about': {
            const def = EXPORT_DEFS.find((d) => d.key === a1);
            if (!def) return <NotFoundDialog onClose={close} />;
            return <AboutExportDialog key={spec} kind={def.key} onClose={close} />;
        }
        case 'event': {
            const e = logWithRuntime(s.rt).find((x) => x.id === spec.slice(6));
            if (!canAudit(p)) return <CantDoThis onClose={close} what="open the audit trail" who="clinical leads, coordinators, managers and auditors" />;
            if (!e || !housesOf(p).includes(e.house)) return <NotFoundDialog onClose={close} />;
            return <EventDialog key={spec} id={e.id} onClose={close} />;
        }
        case 'verify':
            if (!canAudit(p)) return <CantDoThis onClose={close} what="check the audit chain" who="clinical leads, coordinators, managers and auditors" />;
            return <VerifyDialog key={spec} onClose={close} />;
        case 'range':
            return <RangeDialog key={spec} onClose={close} />;
        case 'close': {
            const e = allErrors(s.rt).find((x) => x.id === a1);
            if (!e || !housesOf(p).includes(PEOPLE[e.pid].house)) return <NotFoundDialog onClose={close} />;
            if (!has(p, 'errors.manage')) return <CantDoThis onClose={close} what="close an error" who="house leads, clinical leads, coordinators and managers" />;
            if (!canCloseErr(p, e)) return <CantDoThis onClose={close} what="close a report you made" who="someone else who manages errors — the person who reported it never closes it" />;
            if (e.stage === 'closed') return <NothingToDo onClose={close} title="Already closed" description="Reopen it, with a reason, to change it.">{`${e.id} was closed by ${e.closed?.by}, ${e.closed?.at}.`}</NothingToDo>;
            if (e.stage === 'triage' || e.openActions > 0 || (e.reached !== 'no' && !e.told)) return <NothingToDo onClose={close} title={`${e.id} isn’t ready to close`} description="Closing needs every step done.">{[e.stage === 'triage' ? 'It hasn’t been triaged yet.' : '', e.openActions ? `${e.openActions} action is still open.` : '', e.reached !== 'no' && !e.told ? 'Telling the person hasn’t been recorded.' : ''].filter(Boolean).join(' ')}</NothingToDo>;
            return <CloseWithSacDialog key={spec} error={e} onClose={close} returnFocus={rf} />;
        }
        case 'p11review':
            return <ReviewSettings key={spec} onClose={close} />;
        case 'p11hist':
            return <HistDetail key={spec} id={a1} onClose={close} />;
        default:
            return <NotFoundDialog onClose={close} />;
    }
}
