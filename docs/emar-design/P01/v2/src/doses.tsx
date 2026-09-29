/* Dose rows, row actions and the URL-driven dialog host. One MenuItem[] feeds
 * the kebab AND the right-click / Shift+F10 menu (LIST_STYLE_GUIDE §1); row
 * click opens the row's main action. Every dialog is addressable with
 * ?open=… so the review session can deep-link each state. */
import { EntityContextMenu, compactMenu, type MenuItem } from '@/components/lists/entity-menu';
import { Button } from '@/components/ui/button';
import { StatusBadge } from '@/components/ui/status-badge';
import {
    CheckCircle2,
    ClipboardList,
    Flag,
    HelpCircle,
    Info,
    Lock,
    PauseCircle,
    Pill,
    Repeat,
    Search,
    Send,
    ShieldCheck,
    Truck,
    Users,
    XCircle,
} from 'lucide-react';
import { useEffect, useRef, useState, type ReactNode } from 'react';
import { BLOCKS, type DoseState, fill, requirementsFor, windowOf } from './contract';
import { PEOPLE, allDoseById, type Dose } from './data';
import {
    DoseDetailDialog,
    DosePickerDialog,
    EligibilityDialog,
    ErrorCreatedDialog,
    FallbackConfirmDialog,
    OverrideApproveDialog,
    OverrideRequestDialog,
    PrnPickerDialog,
    RejectedReviewDialog,
    WhyDialog,
} from './dialogs';
import { type EntryPoint, RecordDoseDialog, type Mode } from './record-dialog';
import { hrefFor, useStore } from './store';
import { DoseBadge, EntityChipRow } from './ui';

/* ───────────── opening dialogs (URL) with focus return ───────────── */
let lastTrigger: HTMLElement | null = null;
export function useOpen() {
    const s = useStore();
    return (spec: string, extra: Record<string, string | undefined> = {}) => {
        lastTrigger = document.activeElement instanceof HTMLElement ? document.activeElement : null;
        const q: Record<string, string | undefined> = {};
        s.route.q.forEach((v, k) => (q[k] = v));
        s.go(s.route.path, { ...q, open: spec, ...extra });
    };
}
function useCloseDialog() {
    const s = useStore();
    return () => {
        const q: Record<string, string | undefined> = {};
        s.route.q.forEach((v, k) => (q[k] = v));
        delete q.open;
        delete q.from;
        s.go(s.route.path, { ...q, open: undefined, from: undefined });
    };
}
/** Focus the element that opened the dialog; if the row re-rendered, its current action. */
export function returnFocusFor(rowId?: string) {
    return () => {
        if (lastTrigger?.isConnected) return lastTrigger;
        if (rowId) return document.querySelector<HTMLElement>(`[data-return="${rowId}"]`);
        return null;
    };
}

/* ───────────── the one menu for a dose ───────────── */
export function useDoseMenu() {
    const s = useStore();
    const open = useOpen();
    return (d: Dose, from: EntryPoint = 'meds-today'): MenuItem[] => {
        const st = s.stateOf(d);
        const req = requirementsFor(d, s.ctx);
        const p = PEOPLE[d.pid];
        const canRecord = s.ctx.persona !== 'pm' || true;
        const openable = st === 'due' || st === 'late';
        const block = req.blockAll ?? req.blockGiven;
        const items: (MenuItem | false)[] = [];
        if (canRecord && openable) {
            if (block) {
                if (!req.blockAll) items.push({ label: 'Record not given', icon: PauseCircle, onClick: () => open(`notgiven:${d.id}`, { from }) });
                items.push({ label: 'Why can’t I record this?', icon: HelpCircle, onClick: () => open(`why:${d.id}`) });
                if (block === 'noWitness' && s.override === 'none') items.push({ label: 'Ask a manager for a witness override', icon: Send, onClick: () => open(`ovr-request:${d.id}`) });
            } else if (req.competency !== 'current') {
                items.push({ label: 'Record not given', icon: PauseCircle, onClick: () => open(`notgiven:${d.id}`, { from }) });
                items.push({ label: 'Why can’t I record this?', icon: HelpCircle, onClick: () => open(`why:${d.id}`) });
            } else {
                items.push({ label: 'Record dose', icon: CheckCircle2, onClick: () => open(`record:${d.id}`, { from }) });
            }
        } else if (st === 'refused' && d.id === 'r4') {
            items.push({ label: 'Record re-offer', icon: Repeat, onClick: () => open(`reoffer:${d.id}`, { from }) });
            items.push({ label: 'View dose details', icon: Info, onClick: () => open(`detail:${d.id}`) });
        } else if (st === 'rejected') {
            items.push({ label: 'Review and try again', icon: XCircle, onClick: () => open(`record:${d.id}`, { from }) });
        } else if (st === 'uncertain') {
            items.push({ label: 'Check the chart', icon: Search, onClick: () => s.go('/emar/mar', { client: p.id }) });
        } else if (st !== 'notdue' && st !== 'selfmanaged' && st !== 'sending') {
            items.push({ label: 'View dose details', icon: Info, onClick: () => open(`detail:${d.id}`) });
        } else if (st === 'notdue') {
            items.push({ label: 'View dose details', icon: Info, onClick: () => open(`detail:${d.id}`) });
        }
        items.push({ separator: true });
        items.push({ label: `Open ${p.pref}’s medication record`, icon: ClipboardList, onClick: () => s.go('/emar/mar', { client: p.id }) });
        items.push({ label: 'Report a medication error', icon: Flag, onClick: () => s.toast('info', 'Opens the existing Report a medication error dialog (redesigned in P08b) — outside this preview.') });
        items.push({ label: 'Add to shift handover', icon: Repeat, onClick: () => s.toast('info', 'Adds this dose to the medication handover as a live item (designed in P08a) — outside this preview.') });
        return compactMenu(items);
    };
}
/** Main action on row click: record / why / review / detail. */
export function useRowOpen() {
    const s = useStore();
    const open = useOpen();
    return (d: Dose, from: EntryPoint = 'meds-today') => {
        const st = s.stateOf(d);
        const req = requirementsFor(d, s.ctx);
        if (st === 'due' || st === 'late') {
            if (req.blockAll || req.blockGiven) return open(`why:${d.id}`);
            if (req.competency !== 'current') return open(`notgiven:${d.id}`, { from });
            return open(`record:${d.id}`, { from });
        }
        if (st === 'rejected') return open(`record:${d.id}`, { from });
        if (st === 'refused' && d.id === 'r4') return open(`reoffer:${d.id}`, { from });
        if (st === 'uncertain') return s.go('/emar/mar', { client: d.pid });
        return open(`detail:${d.id}`);
    };
}

/* ───────────── state cell: badge + plain lines ───────────── */
export function DoseStateCell({ d }: { d: Dose }) {
    const s = useStore();
    const st = s.stateOf(d);
    const req = requirementsFor(d, s.ctx);
    const r = s.recordOf(d);
    const p = PEOPLE[d.pid];
    const lines: ReactNode[] = [];
    const open = st === 'due' || st === 'late';
    const L = (key: string, node: ReactNode, tone?: 'warning' | 'critical' | 'success') => (
        <span key={key} className={`flex items-start gap-1 text-[12px] leading-snug ${tone === 'critical' ? 'font-semibold text-status-critical' : tone === 'warning' ? 'font-semibold text-status-warning' : tone === 'success' ? 'text-status-success' : 'text-muted-foreground'}`}>
            {node}
        </span>
    );
    if (st === 'sending') lines.push(L('s', 'Sending — not yet confirmed'));
    else if (st === 'queued') lines.push(L('q', 'Saved on this device · not sent yet'));
    else if (st === 'rejected') lines.push(L('rj', 'Tried 9:12 am · not saved — review needed', 'critical'));
    else if (st === 'uncertain') lines.push(L('u', 'Sent 9:12 am · no confirmation — check the chart before trying again', 'warning'));
    else if (r) lines.push(L('r', r.line));
    else if (st === 'selfmanaged') lines.push(L('sm', `${p.pref} manages this medicine · nothing to record`));
    else if (st === 'notdue') lines.push(L('nd', `Due ${d.slot} · window opens ${windowOf(d.slotMin).label.split('–')[0]}`));
    else if (st === 'due') lines.push(L('du', `Due now · ${d.slot} · window until ${windowOf(d.slotMin).label.split('–')[1]}`));
    else if (st === 'late') lines.push(L('la', `Due ${d.slot} · outside today’s window (${windowOf(d.slotMin).label})`));
    if (open && (req.blockAll || req.blockGiven)) {
        const b = BLOCKS[(req.blockAll ?? req.blockGiven)!];
        lines.push(L('b', <><Lock className="mt-0.5 size-3 shrink-0" /> {fill(b.title, p.pref, d.med)}</>, b.safety || b.tone === 'critical' ? 'critical' : 'warning'));
    } else if (open && req.competency !== 'current') {
        lines.push(L('c', <><Lock className="mt-0.5 size-3 shrink-0" /> {req.competency === 'expired' ? 'Can’t record as given — your competency expired. Refusal, withhold or absence still OK.' : 'Can’t sign as given — your competency is restricted. Refusal, withhold or absence still OK.'}</>, 'critical'));
    }
    if (open && req.allergy.match && req.allergy.rule === 'warn') lines.push(L('al', <><ShieldCheck className="mt-0.5 size-3 shrink-0" /> Possible allergy match — check before giving</>, 'critical'));
    if (open && req.witness === 'override') lines.push(L('ov', <><ShieldCheck className="mt-0.5 size-3 shrink-0" /> Witness override by Rangi Parata until 3:00 pm — can be recorded without a witness</>, 'warning'));
    if (open && req.blockGiven === 'noWitness' && s.override === 'waiting') lines.push(L('ow', <><Send className="mt-0.5 size-3 shrink-0" /> Witness override requested 9:13 am · waiting for a manager</>));
    if (open && req.blockGiven === 'noWitness' && s.override === 'declined') lines.push(L('od', <>Override declined by Rangi Parata: “{s.overrideDecline}” · record it as not given, or wait</>, 'critical'));
    if (open && d.transport) lines.push(L('tr', <><Truck className="mt-0.5 size-3 shrink-0" /> Packed for the {d.transport.vehicle} trip at 8:05 am — record it here or from the transport</>));
    if (open && d.photo.state === 'changed') lines.push(L('ph', 'Pack or brand changed — check the label', 'warning'));
    if (r?.second) {
        const sec = r.second;
        lines.push(
            sec.forgot
                ? sec.answer === 'yes'
                    ? L('sec', <><Users className="mt-0.5 size-3 shrink-0" /> {sec.name} confirmed “I was there” · second person PIN forgotten</>)
                    : sec.answer === 'no'
                      ? L('sec', <>Second person disputed — {sec.name} answered “I wasn’t there” · follow-up for the house lead</>, 'critical')
                      : L('sec', <>Second person not verified — PIN forgotten · waiting for {sec.name} to confirm (by 9:42 am)</>, 'warning')
                : L('sec', <><Users className="mt-0.5 size-3 shrink-0" /> {sec.kind === 'witness' ? 'Witnessed by' : sec.kind === 'amount' ? 'Different amount confirmed by' : 'Confirmed by'} {sec.name} (witness PIN)</>),
        );
    }
    r?.warn?.filter((w) => !w.startsWith('Second person')).forEach((w, i) => lines.push(L(`w${i}`, w, w.startsWith('More than') ? 'critical' : 'warning')));
    r?.extra?.forEach((x, i) => lines.push(L(`x${i}`, x)));
    return (
        <span className="flex flex-col items-start gap-1 py-0.5">
            <DoseBadge state={st} />
            {lines}
        </span>
    );
}

export function DoseActionCell({ d, from = 'meds-today' }: { d: Dose; from?: EntryPoint }) {
    const s = useStore();
    const open = useOpen();
    const st = s.stateOf(d);
    const req = requirementsFor(d, s.ctx);
    const stop = (fn: () => void) => (e: React.MouseEvent) => {
        e.stopPropagation();
        fn();
    };
    const openable = st === 'due' || st === 'late';
    if (st === 'sending') return <span className="text-caption">Sending…</span>;
    if (openable && (req.blockAll || req.blockGiven))
        return (
            <Button data-return={d.id} variant="outline" className="frontline-tap" onClick={stop(() => open(`why:${d.id}`))}>
                <HelpCircle className="size-4" /> Why can’t I record?
            </Button>
        );
    if (openable && req.competency !== 'current')
        return (
            <Button data-return={d.id} variant="outline" className="frontline-tap" onClick={stop(() => open(`notgiven:${d.id}`, { from }))}>
                Record not given
            </Button>
        );
    if (openable)
        return (
            <Button data-return={d.id} className="frontline-tap" onClick={stop(() => open(`record:${d.id}`, { from }))}>
                Record
            </Button>
        );
    if (st === 'rejected')
        return (
            <Button data-return={d.id} variant="outline" className="frontline-tap" onClick={stop(() => open(`record:${d.id}`, { from }))}>
                Review
            </Button>
        );
    if (st === 'refused' && d.id === 'r4')
        return (
            <Button data-return={d.id} variant="outline" className="frontline-tap" onClick={stop(() => open(`reoffer:${d.id}`, { from }))}>
                <Repeat className="size-4" /> Record re-offer
            </Button>
        );
    if (st === 'selfmanaged') return <span className="text-caption">Nothing to record</span>;
    return (
        <Button data-return={d.id} variant="ghost" className="frontline-tap" onClick={stop(() => open(`detail:${d.id}`))}>
            View
        </Button>
    );
}

export function MedicineCell({ d }: { d: Dose }) {
    return (
        <span className="flex min-w-0 flex-col gap-1 py-0.5">
            <span className="truncate text-[13px] font-semibold">
                {d.med} <span className="font-normal text-muted-foreground">{d.strength}</span>
            </span>
            <EntityChipRow d={d} />
        </span>
    );
}

/* ───────────── right-click menu hook with the row's title ───────────── */
export function useRowContext() {
    const [ctx, setCtx] = useState<{ x: number; y: number; title: string; items: MenuItem[] } | null>(null);
    const openAt = (e: React.MouseEvent, title: string, items: MenuItem[]) => {
        if (e.target instanceof Element && e.target.closest('a[href]')) return;
        e.preventDefault();
        const b = (e.currentTarget as HTMLElement).getBoundingClientRect();
        const kb = e.clientX === 0 && e.clientY === 0;
        setCtx({ x: kb ? b.left + 12 : e.clientX, y: kb ? b.top + 12 : e.clientY, title, items });
    };
    const node = ctx ? <EntityContextMenu x={ctx.x} y={ctx.y} icon={Pill} title={ctx.title} items={ctx.items} onClose={() => setCtx(null)} /> : null;
    return { openAt, node };
}

/* ───────────── dialog host (reads ?open=) ───────────── */
export function DialogHost() {
    const s = useStore();
    const close = useCloseDialog();
    const open = useOpen();
    const spec = s.route.q.get('open');
    const from = (s.route.q.get('from') as EntryPoint) || (s.route.path.startsWith('/emar/mar') ? 'mar' : 'meds-today');
    const [kind, arg] = spec ? [spec.split(':')[0], spec.split(':').slice(1).join(':')] : [null, null];
    const nextFor = useNextDose();
    const lastSpec = useRef<string | null>(null);
    useEffect(() => {
        lastSpec.current = spec;
    }, [spec]);
    if (!kind) return null;
    if (kind === 'record' || kind === 'notgiven' || kind === 'reoffer') {
        const mode: Mode = kind === 'record' ? 'record' : kind === 'notgiven' ? 'notgiven' : 'reoffer';
        const next = from === 'round' || from === 'meds-today' ? nextFor(arg!) : null;
        return (
            <RecordDoseDialog
                key={spec!}
                target={{ kind: 'scheduled', doseId: arg! }}
                entry={from}
                mode={mode}
                onClose={close}
                returnFocus={returnFocusFor(arg!)}
                nextLabel={next ? `${from === 'round' ? 'Next in the round' : 'Next due'}: ${PEOPLE[next.pid].pref} · ${next.med}` : null}
                onNext={next ? () => open(`record:${next.id}`, { from }) : undefined}
                onAction={(a, id) => {
                    if (a === 'ovr-request') open(`ovr-request:${arg}`);
                    else if (a === 'eligibility') open('eligibility');
                    else if (a === 'chart') s.go('/emar/mar', { client: allDoseById(arg!).pid });
                    else if (a === 'error-created') window.setTimeout(() => open(`error:${id}`), 60);
                }}
            />
        );
    }
    if (kind === 'prn')
        return <RecordDoseDialog key={spec!} target={{ kind: 'prn', orderId: arg! }} entry="as-needed" onClose={close} returnFocus={returnFocusFor()} onAction={(a) => a === 'eligibility' && open('eligibility')} />;
    if (kind === 'prn-pick') return <PrnPickerDialog onClose={close} onPick={(id) => open(`prn:${id}`)} />;
    if (kind === 'dose-pick') return <DosePickerDialog pid={arg!} onClose={close} onPick={(id) => open(`record:${id}`, { from: 'client-profile' })} />;
    if (kind === 'why') {
        const d = allDoseById(arg!);
        const req = requirementsFor(d, s.ctx);
        const block = req.blockAll ?? req.blockGiven ?? (req.competency === 'expired' ? 'competencyExpired' : req.competency === 'restricted' ? 'restrictedBlock' : null);
        if (!block) return <DoseDetailDialog doseId={arg!} onClose={close} />;
        return (
            <WhyDialog
                block={block}
                doseId={arg!}
                onClose={close}
                onRecordNotGiven={() => open(`notgiven:${arg}`, { from })}
                onAction={(a) => {
                    if (a === 'clock-in') {
                        s.clockIn();
                        s.toast('success', 'Clocked in at 9:12 am · Kōwhai House. You can record now.');
                        close();
                    } else if (a === 'eligibility') open('eligibility');
                    else if (a === 'ovr-request') open(`ovr-request:${arg}`);
                    else s.toast('info', 'Opens Messages with Jordan Tipene — outside this preview.');
                }}
            />
        );
    }
    if (kind === 'detail') return <DoseDetailDialog doseId={arg!} onClose={close} />;
    if (kind === 'eligibility') return <EligibilityDialog onClose={close} />;
    if (kind === 'rejected') return <RejectedReviewDialog onClose={close} />;
    if (kind === 'ovr-request') return <OverrideRequestDialog doseId={arg!} onClose={close} />;
    if (kind === 'ovr-approve') return <OverrideApproveDialog onClose={close} startDeclining={arg === 'decline'} />;
    if (kind === 'confirm') return <FallbackConfirmDialog doseId={arg!} onClose={close} />;
    if (kind === 'error') {
        const [id, amt] = arg!.split('|');
        return <ErrorCreatedDialog doseId={id} amount={amt ?? ''} onClose={close} />;
    }
    return null;
}

/** Next open dose after this one: same slot first, then later slots. */
function useNextDose() {
    const s = useStore();
    return (id: string): Dose | null => {
        const doses = s.visibleDoses();
        const me = doses.find((d) => d.id === id);
        if (!me) return null;
        const open = doses.filter((d) => d.id !== id && ['due', 'late'].includes(s.stateOf(d)) && !requirementsFor(d, s.ctx).blockAll);
        open.sort((a, b) => a.slotMin - b.slotMin);
        return open.find((d) => d.slotMin === me.slotMin) ?? open[0] ?? null;
    };
}

