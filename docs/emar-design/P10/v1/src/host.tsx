/* The URL-driven dialog host (the P01–P09 pattern): every dialog is
 * addressable with ?open=… so the review session can deep-link each state,
 * and closing returns focus to the control that opened it. A record at a
 * house outside yours looks the same as one that doesn’t exist (404); anyone
 * without emergency access is never told it exists. A deep link to an action
 * your role can’t take says why and who can. */
import { ORDERS, PEOPLE, PERSONAS, has, type PersonId } from './data';
import { AboutExportDialog, EventDialog, ExportDialog } from './dialogs';
import { AckDialog, DoneDialog, EndDialog, ExtendDialog, GrantDialog, RequestWizard, ReviewDialog } from './dialogs-ea';
import { DeclareDialog, FinishDialog, PackDialog, PaperEntryDialog } from './dialogs-paper';
import { RecordDialog } from './dialogs-record';
import { canExtendNow, ENDING_SOON } from './ea-ui';
import { Modal, NotFoundDialog } from './modal';
import {
    EA_EXPORT,
    EXPORT_DEFS,
    REVIEW_BLOCK_TEXT,
    allDowntimes,
    allGrants,
    atText,
    canAudit,
    canEndOthers,
    canExportKind,
    canPack,
    canRequest,
    canSeeAccess,
    canSeeDowntime,
    endOf,
    flagsOf,
    houseOfGrant,
    housesOf,
    isLive,
    itemState,
    latestOf,
    logWithRuntime,
    needsReview,
    reviewBlock,
    toMin,
    type ExportKind,
} from './model';
import { canEnterItem } from './pages/downtime';
import { whoCan } from './pages/exports';
import { canViewChart, covers, myEndedGrant, myLiveGrant, todayRows } from './pages/mar-frame';
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
    const scn = s.route.scenario;
    const me = PERSONAS[p];
    const rf = () => returnFocus();
    const grant = a1 ? allGrants(s.rt, scn).find((g) => g.id === a1) : undefined;
    const grantVisible = !!grant && canSeeAccess(p) && housesOf(p).includes(houseOfGrant(grant));
    switch (kind) {
        /* ── P09’s exports, with the downtime pack and the emergency access history ── */
        case 'export': {
            if (a1 === 'pack') return canPack(p) ? <PackDialog key={spec} onClose={close} returnFocus={rf} /> : <CantDoThis onClose={close} what="make the downtime pack" who="house leads, clinical leads, coordinators and managers, for their houses" />;
            if (a1 === 'ea') return !canSeeAccess(p) ? <NotFoundDialog onClose={close} /> : canExportKind(p, 'ea') ? <ExportDialog key={spec} kind="ea" onClose={close} returnFocus={rf} /> : <CantDoThis onClose={close} what="export the emergency access history" who={whoCan(EA_EXPORT)} />;
            const def = EXPORT_DEFS.find((d) => d.key === a1);
            if (!def) return <NotFoundDialog onClose={close} />;
            if (!canExportKind(p, def.key)) return <CantDoThis onClose={close} what={`make the ${def.name.toLowerCase()} ${def.format}`} who={whoCan(def)} />;
            return <ExportDialog key={spec} kind={def.key as ExportKind} arg={a2} onClose={close} returnFocus={rf} />;
        }
        case 'about': {
            const def = EXPORT_DEFS.find((d) => d.key === a1);
            return def ? <AboutExportDialog key={spec} kind={def.key} onClose={close} /> : <NotFoundDialog onClose={close} />;
        }
        case 'event': {
            const e = logWithRuntime(s.rt).find((x) => x.id === spec.slice(6));
            if (!canAudit(p)) return <CantDoThis onClose={close} what="open the audit trail" who="clinical leads, coordinators, managers and auditors" />;
            if (!e || !housesOf(p).includes(e.house)) return <NotFoundDialog onClose={close} />;
            return <EventDialog key={spec} id={e.id} onClose={close} />;
        }
        /* ── emergency access (Q3–Q7) ── */
        case 'request': {
            if (!canRequest(p)) return <NotFoundDialog onClose={close} />;
            if (a1 && (!PEOPLE[a1 as PersonId] || !housesOf(p).includes(PEOPLE[a1 as PersonId].house))) return <NotFoundDialog onClose={close} />;
            if (scn === 'offline') return <NothingToDo onClose={close} title="You’re offline" description="Emergency access needs a connection to start.">If a dose is due now and you can’t connect, ask someone on shift, or record it on the paper pack and enter it when you’re back.</NothingToDo>;
            return <RequestWizard key={spec} pid={a1} onClose={close} returnFocus={rf} />;
        }
        case 'grant':
            return grantVisible ? <GrantDialog key={spec} id={a1} onClose={close} /> : <NotFoundDialog onClose={close} />;
        case 'review':
        case 'correct': {
            if (!grantVisible) return <NotFoundDialog onClose={close} />;
            const block = reviewBlock(p, grant!);
            if (block === 'noaccess' || block === 'house') return <CantDoThis onClose={close} what="review emergency access" who="clinical leads, coordinators, managers and auditors" />;
            if (block === 'own' || block === 'confirmed') return <NothingToDo onClose={close} title={block === 'own' ? 'You can’t review your own emergency access' : 'You can’t review a grant you confirmed'} description="Someone else reviews it.">{REVIEW_BLOCK_TEXT[block]}</NothingToDo>;
            if (block === 'live') return <NothingToDo onClose={close} title={`${grant!.id} is still running`} description={`It ends ${atText(endOf(grant!))}.`}>{REVIEW_BLOCK_TEXT.live}</NothingToDo>;
            if (kind === 'review' && !needsReview(grant!)) return <NothingToDo onClose={close} title={`${grant!.id} is already reviewed`} description="A review is never overwritten.">{`Open the grant and choose “Correct the review” to add a correction — the first review stays visible.`}</NothingToDo>;
            if (kind === 'correct' && needsReview(grant!)) return <NothingToDo onClose={close} title={`${grant!.id} hasn’t been reviewed yet`} description="There’s nothing to correct.">Review it instead.</NothingToDo>;
            return <ReviewDialog key={spec} id={a1} mode={kind} onClose={close} returnFocus={rf} />;
        }
        case 'extend': {
            if (!grantVisible) return <NotFoundDialog onClose={close} />;
            if (grant!.by !== me.name) return <CantDoThis onClose={close} what="extend someone else’s emergency access" who="the person using it" />;
            if (!isLive(grant!)) return <NothingToDo onClose={close} title={`${grant!.id} has ended`} description="An ended grant can’t be extended.">Start emergency access again if you still need it.</NothingToDo>;
            if (toMin(endOf(grant!)) >= toMin(latestOf(grant!))) return <NothingToDo onClose={close} title="It can’t be extended" description={`It already runs to the longest time — ${atText(latestOf(grant!))}.`}>Ask someone on shift to take over, or start emergency access again once it ends if you still need it.</NothingToDo>;
            if (!canExtendNow(grant!)) return <NothingToDo onClose={close} title="Not yet" description={`You can extend it once ${ENDING_SOON} minutes or less remain.`}>{`It ends ${atText(endOf(grant!))}.`}</NothingToDo>;
            return <ExtendDialog key={spec} id={a1} onClose={close} returnFocus={rf} />;
        }
        case 'done':
            if (!grantVisible) return <NotFoundDialog onClose={close} />;
            if (grant!.by !== me.name) return <CantDoThis onClose={close} what="end someone else’s emergency access from here" who="the person using it — or, with a reason, a clinical lead, coordinator or manager" />;
            if (!isLive(grant!)) return <NothingToDo onClose={close} title={`${grant!.id} has already ended`} description="Nothing to end.">It goes to reviewers.</NothingToDo>;
            return <DoneDialog key={spec} id={a1} onClose={close} />;
        case 'end':
            if (!grantVisible) return <NotFoundDialog onClose={close} />;
            if (grant!.by === me.name) return <DoneDialog key={spec} id={a1} onClose={close} />;
            if (!canEndOthers(p)) return <CantDoThis onClose={close} what="end someone else’s emergency access" who="clinical leads, coordinators and managers" />;
            if (!isLive(grant!)) return <NothingToDo onClose={close} title={`${grant!.id} has already ended`} description="Nothing to end.">It goes to reviewers.</NothingToDo>;
            return <EndDialog key={spec} id={a1} onClose={close} returnFocus={rf} />;
        case 'ack': {
            const f = flagsOf(s.rt, scn).find((x) => x.id === a1);
            if (!f || !canAudit(p)) return <NotFoundDialog onClose={close} />;
            if (f.who === me.name) return <CantDoThis onClose={close} what="acknowledge your own repeat use" who="a reviewer — a clinical lead, coordinator or auditor" />;
            if (f.ack) return <NothingToDo onClose={close} title="Already acknowledged" description={`By ${f.ack.by}, ${atText(f.ack.at)}.`}>{`It comes back if ${f.who} uses emergency access again.`}</NothingToDo>;
            return <AckDialog key={spec} id={a1} onClose={close} returnFocus={rf} />;
        }
        /* ── P01’s record dialog, as a frame ── */
        case 'record': {
            const pid = a1 as PersonId;
            if (!PEOPLE[pid] || !canViewChart(p, pid) || !ORDERS.some((o) => o.id === a2 && o.pid === pid)) return <NotFoundDialog onClose={close} />;
            const ok = covers(p, pid) || !!myLiveGrant(s.rt, scn, p, pid) || !!myEndedGrant(s.rt, scn, p, pid);
            if (!ok) return <NothingToDo onClose={close} title={`You’re not on a shift for ${PEOPLE[pid].pref}`} description="Recording is for the staff on shift.">{canRequest(p) ? `To record for ${PEOPLE[pid].pref} now, start emergency access from their record.` : `Clock in, or call ${PEOPLE[pid].house === 'kowhai' ? 'Jordan Tipene — 021 555 0163' : 'Sione Taufa — 021 555 0177'}, the on-call contact.`}</NothingToDo>;
            if (!todayRows(s.rt, pid).some((d) => d.order.id === a2 && d.state === 'due')) return <NothingToDo onClose={close} title="Nothing to record now" description="This dose isn’t due.">It’s either recorded already or not due yet.</NothingToDo>;
            return <RecordDialog key={spec} pid={pid} orderId={a2} onClose={close} returnFocus={rf} />;
        }
        /* ── downtime and paper records (Q9–Q11) ── */
        case 'declare':
            return canSeeDowntime(p) && has(p, 'errors.manage') ? <DeclareDialog key={spec} onClose={close} returnFocus={rf} /> : <CantDoThis onClose={close} what="record a downtime" who="house leads, clinical leads, coordinators and managers" />;
        case 'paper': {
            const d = allDowntimes(s.rt, scn).find((x) => x.items.some((i) => i.id === a1));
            const it = d?.items.find((i) => i.id === a1);
            if (!d || !it || !housesOf(p).includes(d.house)) return <NotFoundDialog onClose={close} />;
            if (itemState(s.rt, it.id) !== 'toEnter') return <NothingToDo onClose={close} title="Already entered" description="It’s in the record as entered from paper.">Open the downtime to see who entered it, and when.</NothingToDo>;
            if (!canEnterItem(p, it)) return <CantDoThis onClose={close} what="enter someone else’s paper record" who="the person who gave it, or a house lead" />;
            return <PaperEntryDialog key={spec} itemId={a1} onClose={close} returnFocus={rf} />;
        }
        case 'paperadd': {
            const d = allDowntimes(s.rt, scn).find((x) => x.id === a1);
            if (!d || !housesOf(p).includes(d.house)) return <NotFoundDialog onClose={close} />;
            if (!has(p, 'errors.manage')) return <CantDoThis onClose={close} what="add a dose from the paper" who="house leads, clinical leads, coordinators and managers" />;
            return <PaperEntryDialog key={spec} addTo={a1} onClose={close} returnFocus={rf} />;
        }
        case 'finish': {
            const d = allDowntimes(s.rt, scn).find((x) => x.id === a1);
            if (!d || !housesOf(p).includes(d.house)) return <NotFoundDialog onClose={close} />;
            if (!has(p, 'errors.manage')) return <CantDoThis onClose={close} what="finish a downtime" who="house leads, clinical leads, coordinators and managers" />;
            const left = d.items.filter((i) => itemState(s.rt, i.id) === 'toEnter').length;
            if (left) return <NothingToDo onClose={close} title="Not yet" description={`${left} paper ${left === 1 ? 'record is' : 'records are'} still to enter.`}>Enter them first — then finish it.</NothingToDo>;
            return <FinishDialog key={spec} id={a1} onClose={close} />;
        }
        /* ── P11 frame ── */
        case 'p11review':
            return <ReviewSettings key={spec} onClose={close} />;
        case 'p11hist':
            return <HistDetail key={spec} id={a1} onClose={close} />;
        default:
            return <NotFoundDialog onClose={close} />;
    }
}
