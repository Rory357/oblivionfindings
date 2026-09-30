/* The URL-driven dialog host (P01 / P07a / P08a pattern): every dialog is
 * addressable with ?open=… so the review session can deep-link each state, and
 * closing returns focus to the control that opened it. A person outside your
 * houses, or a controlled medicine without controlled-medicine view, looks the
 * same as a record that doesn’t exist (404). A deep link to an action your role
 * can’t take says why and who can (it never leaks anything the page doesn’t). */
import { ASSESSMENTS, MEDICINES, PEOPLE, PERSONAS, type PersonId } from './data';
import { AgreementDialog, AssessDialog, ConsentDialog, SupportDialog, ViewAssessmentDialog } from './dialogs';
import { Modal, NotFoundDialog } from './modal';
import { canAssess, canRecordConsent, concealed, whoAssesses } from './model';
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

function CantDoThis({ onClose, what }: { onClose: () => void; what: string }) {
    const s = useStore();
    const p = s.route.persona;
    return (
        <Modal title={`You can’t ${what}`} description={`Support is assessed and changed by ${whoAssesses}.`} onClose={onClose}>
            <p className="text-sm">
                {PERSONAS[p].name} can see the support plan. Ask the house lead if something needs to change.
                {canRecordConsent(p) ? ' You can record a change the person asks for from their support plan.' : ''}
            </p>
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
    const me = PERSONAS[p];
    const seePerson = (pid: string | undefined): pid is PersonId => !!pid && pid in PEOPLE && me.houses.includes(PEOPLE[pid as PersonId].house);
    const rf = () => returnFocus();
    switch (kind) {
        case 'assess':
            if (!seePerson(a1)) return <NotFoundDialog onClose={close} />;
            if (!canAssess(p)) return <CantDoThis onClose={close} what="assess support" />;
            return <AssessDialog key={spec} pid={a1} onClose={close} returnFocus={rf} />;
        case 'agreement':
            if (!seePerson(a1)) return <NotFoundDialog onClose={close} />;
            if (!canAssess(p)) return <CantDoThis onClose={close} what="record the agreement" />;
            return <AgreementDialog key={spec} pid={a1} onClose={close} returnFocus={rf} />;
        case 'support': {
            const m = MEDICINES.find((x) => x.key === a1);
            if (!m || !seePerson(m.pid) || concealed(m, p)) return <NotFoundDialog onClose={close} />;
            if (!canAssess(p)) return <CantDoThis onClose={close} what="change support" />;
            return <SupportDialog key={spec} medKey={m.key} onClose={close} returnFocus={rf} />;
        }
        case 'consent': {
            if (!seePerson(a1)) return <NotFoundDialog onClose={close} />;
            const m = a2 ? MEDICINES.find((x) => x.key === a2 && x.pid === a1) : undefined;
            if (a2 && (!m || concealed(m, p))) return <NotFoundDialog onClose={close} />;
            if (!canRecordConsent(p)) return <CantDoThis onClose={close} what="record a change" />;
            return <ConsentDialog key={spec} pid={a1} medKey={a2} onClose={close} returnFocus={rf} />;
        }
        case 'assessment': {
            const a = ASSESSMENTS.find((x) => x.id === a1);
            if (!a || !seePerson(a.pid)) return <NotFoundDialog onClose={close} />;
            return <ViewAssessmentDialog id={a.id} onClose={close} />;
        }
        default:
            return <NotFoundDialog onClose={close} />;
    }
}
