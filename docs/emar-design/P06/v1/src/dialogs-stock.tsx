/* P06’s count, sign-off, adjust/remove and going-out/coming-back dialogs —
 * redesigning today’s StockCountDialog, AdjustStockDialog, the scheduled-count
 * completion and ordinary wastage (Main, Q7, Q10). Blind counts; every movement
 * a record with a reason. Real WizardShell, ReviewCard/ReviewRow,
 * WizardSuccessPane, the Fleet Settings Modal, ConfirmDialog, TilePicker,
 * Select, Input, Textarea and the PKG-01 DateTimeField. */
import ConfirmDialog from '@/components/confirm-dialog';
import { DateTimeField } from '@/components/fleet-assets/maintenance/date-time-field';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import { ReviewCard, ReviewRow, WizardShell, WizardStepPane, WizardSuccessPane } from '@/components/wizard/shell';
import { ArrowDownToLine, ArrowUpFromLine, Check, ChevronLeft, ChevronRight, ClipboardCheck, EyeOff, FileText, Hammer, Loader2, RefreshCw, Scale, Search, Trash2, Undo2 } from 'lucide-react';
import { useState, type ReactNode } from 'react';
import { NOW_LOCAL } from './clock';
import { HOUSES, ITEMS, PEOPLE, PERSONAS, type Count, type CountLine, type Lot, type MoveKind, type Movement, type StockItem } from './data';
import { Req, restore, stamp } from './dialogs-receive';
import { Modal } from './modal';
import { allCounts, allMovements, canManage, concealed, daysFrom, differences, expiryText, fefo, itemOf, lotsOf, onHand, openLots, unitLabel } from './model';
import { useStore } from './store';
import { KV, Notice, TilePicker } from './ui';

const cantSaveOffline = 'You’re offline, so this can’t be saved yet. Nothing is lost — keep this open and save when you reconnect.';
const focusFirst = (x: Record<string, string>) => window.setTimeout(() => document.getElementById(Object.keys(x)[0])?.focus(), 50);
const who = (i: StockItem) => `${PEOPLE[i.pid].pref} ${PEOPLE[i.pid].surname}`;
/** Take n from the packs, first expiry first (expired packs are never used). */
function takeFefo(lots: Lot[], n: number, prefer?: string): { lots: Lot[]; from: string } {
    const order = [...lots].filter((l) => l.qty > 0 && (!l.expiryIso || daysFrom(l.expiryIso) >= 0)).sort((a, b) => (a.expiryIso ?? '9').localeCompare(b.expiryIso ?? '9'));
    if (prefer) order.sort((a, b) => (a.id === prefer ? -1 : b.id === prefer ? 1 : 0));
    let left = n;
    const next = lots.map((l) => ({ ...l }));
    for (const l of order) {
        if (!left) break;
        const t = next.find((x) => x.id === l.id)!;
        const take = Math.min(t.qty, left);
        t.qty -= take;
        left -= take;
    }
    return { lots: next, from: order[0]?.id ?? '' };
}

/* ═════════════ Count stock — blind (Q7) ═════════════ */
const DIFF_REASONS = ['Dropped or lost', 'Found extra', 'Given but not recorded', 'Recorded but not given', 'Last count was wrong', 'Don’t know'];
export function CountDialog({ scope, onClose, returnFocus }: { scope: string; onClose: () => void; returnFocus?: () => HTMLElement | null }) {
    const s = useStore();
    const p = s.route.persona;
    const me = PERSONAS[p];
    const house = scope === 'house' ? me.houses[0] : PEOPLE[itemOf(scope).pid].house;
    const items = scope === 'house' ? ITEMS.filter((i) => PEOPLE[i.pid].house === house && !i.selfManaged && !i.cd && !concealed(i, p) && lotsOf(i, s.rt).length > 0) : [itemOf(scope)];
    const cdHere = scope === 'house' ? ITEMS.filter((i) => PEOPLE[i.pid].house === house && i.cd).length : 0;
    const [step, setStep] = useState(0);
    const [counted, setCounted] = useState<Record<string, string>>({});
    const [reasons, setReasons] = useState<Record<string, { reason: string; note: string }>>({});
    const [e, setE] = useState<Record<string, string>>({});
    const [phase, setPhase] = useState<'edit' | 'sending' | 'done'>('edit');
    const [discard, setDiscard] = useState(false);
    const [offlineErr, setOfflineErr] = useState(false);
    const expected = (i: StockItem) => onHand(i, s.rt);
    const diffs = items.filter((i) => counted[i.id] !== undefined && Number(counted[i.id]) !== expected(i));
    const steps = [
        { key: 'count', label: 'Count', blurb: `${items.length} ${items.length === 1 ? 'medicine' : 'medicines'}`, icon: ClipboardCheck },
        { key: 'diff', label: 'Check the differences', blurb: 'Expected vs counted', icon: Scale },
        { key: 'review', label: 'Review & save', blurb: 'Check, then save', icon: Check },
    ];
    const cur = steps[step].key;
    const dirty = Object.keys(counted).length > 0;
    function validate(k: string) {
        const x: Record<string, string> = {};
        if (k === 'count') for (const i of items) if (!/^\d+(\.\d+)?$/.test(counted[i.id] ?? '')) x[`ct-${i.id}`] = 'Enter what you counted — 0 if there are none.';
        if (k === 'diff')
            for (const i of diffs) {
                if (!reasons[i.id]?.reason) x[`ct-why-${i.id}`] = 'Choose the most likely reason.';
                else if (['Don’t know', 'Dropped or lost', 'Found extra'].includes(reasons[i.id].reason) && !reasons[i.id].note.trim()) x[`ct-note-${i.id}`] = 'Add what you know — the house lead reads it.';
            }
        return x;
    }
    function next() {
        const x = validate(cur);
        setE(x);
        if (Object.keys(x).length) return focusFirst(x);
        setStep(step + 1);
    }
    function save() {
        if (s.route.scenario === 'offline') return setOfflineErr(true);
        setPhase('sending');
        window.setTimeout(() => {
            const lines: CountLine[] = items.map((i) => ({ itemId: i.id, expected: expected(i), counted: Number(counted[i.id]), reason: reasons[i.id]?.reason, note: reasons[i.id]?.note || undefined }));
            const c: Count = { id: `cnt-new-${Date.now()}`, house, scope: scope === 'house' ? 'house' : 'one', by: me.name, at: stamp(), lines, signOff: diffs.length ? null : { by: 'no differences', at: stamp(), outcome: 'accepted' }, followUpDue: diffs.length ? 'end of today' : undefined };
            s.update((rt) => ({ ...rt, counts: [c, ...rt.counts], movements: [...items.map((i, n) => ({ id: `mv-ct-${Date.now()}-${n}`, itemId: i.id, at: stamp(), kind: 'counted' as const, qty: 0, by: me.name, note: Number(counted[i.id]) === expected(i) ? `Counted ${counted[i.id]} — matches` : `Counted ${counted[i.id]}, expected ${expected(i)} — waiting for the house lead` })), ...rt.movements] }));
            setPhase('done');
        }, 700);
    }
    const body: Record<string, ReactNode> = {
        count: (
            <>
                <Notice tone="info" icon={EyeOff} title="Count what’s there — the expected number shows after">
                    Count every open pack in the cabinet{items.some((i) => i.coldChain) ? ' and the fridge' : ''}. Leave out packs set aside as expired.
                </Notice>
                <ul className="divide-y rounded-lg border">
                    {items.map((i) => (
                        <li key={i.id} className="grid items-center gap-3 px-3 py-2.5 sm:grid-cols-[1fr_180px]">
                            <span className="min-w-0 text-sm">
                                <span className="block font-semibold">
                                    {i.med} {i.strength}
                                </span>
                                <span className="block text-caption">
                                    {who(i)} · {openLots(i, s.rt).filter((l) => !l.expiryIso || daysFrom(l.expiryIso) >= 0).length} open {openLots(i, s.rt).length === 1 ? 'pack' : 'packs'}
                                    {i.coldChain ? ' · in the fridge' : ''}
                                </span>
                            </span>
                            <span className="space-y-1">
                                <Label htmlFor={`ct-${i.id}`} className="sr-only">
                                    How many {i.med} for {PEOPLE[i.pid].pref}
                                </Label>
                                <Input id={`ct-${i.id}`} value={counted[i.id] ?? ''} inputMode="numeric" placeholder={`How many ${i.unit}`} aria-invalid={!!e[`ct-${i.id}`]} onChange={(ev) => (setCounted((c) => ({ ...c, [i.id]: ev.target.value.replace(/[^\d.]/g, '') })), setE({}))} />
                                <InputError message={e[`ct-${i.id}`]} />
                            </span>
                        </li>
                    ))}
                </ul>
                {cdHere ? <p className="text-caption">{cdHere} controlled {cdHere === 1 ? 'medicine is' : 'medicines are'} counted in Controlled checks, with a witness — not here.</p> : null}
            </>
        ),
        diff: (
            <>
                {diffs.length ? (
                    <Notice tone="warning" title={`${diffs.length} ${diffs.length === 1 ? 'difference' : 'differences'}`}>
                        Say what most likely happened. The house lead gets a follow-up to sign it off by the end of today. Stock stays as recorded until then.
                    </Notice>
                ) : (
                    <Notice tone="success" title="Everything matches">
                        Nothing to explain — the count is done when you save.
                    </Notice>
                )}
                <ul className="divide-y rounded-lg border">
                    {items.map((i) => {
                        const d = Number(counted[i.id]) - expected(i);
                        return (
                            <li key={i.id} className="space-y-2 px-3 py-2.5">
                                <div className="flex flex-wrap items-center justify-between gap-2 text-sm">
                                    <span>
                                        <span className="font-semibold">{i.med}</span> · {PEOPLE[i.pid].pref} — counted {counted[i.id]}, expected {expected(i)}
                                    </span>
                                    <StatusBadge variant={d === 0 ? 'success' : 'warning'} className="rounded-[8px]">
                                        {d === 0 ? 'Matches' : `${d > 0 ? '+' : ''}${d}`}
                                    </StatusBadge>
                                </div>
                                {d !== 0 ? (
                                    <div className="grid gap-3 sm:grid-cols-2">
                                        <div className="space-y-1.5">
                                            <Label htmlFor={`ct-why-${i.id}`}>
                                                Most likely reason <Req />
                                            </Label>
                                            <Select value={reasons[i.id]?.reason || undefined} onValueChange={(v) => (setReasons((r) => ({ ...r, [i.id]: { note: r[i.id]?.note ?? '', reason: v } })), setE({}))}>
                                                <SelectTrigger id={`ct-why-${i.id}`} className="w-full" aria-invalid={!!e[`ct-why-${i.id}`]}>
                                                    <SelectValue placeholder="Choose" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {DIFF_REASONS.map((x) => (
                                                        <SelectItem key={x} value={x}>
                                                            {x}
                                                        </SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                            <InputError message={e[`ct-why-${i.id}`]} />
                                        </div>
                                        <div className="space-y-1.5">
                                            <Label htmlFor={`ct-note-${i.id}`}>What you know</Label>
                                            <Input id={`ct-note-${i.id}`} value={reasons[i.id]?.note ?? ''} aria-invalid={!!e[`ct-note-${i.id}`]} placeholder="e.g. Two dropped at breakfast — one found" onChange={(ev) => (setReasons((r) => ({ ...r, [i.id]: { reason: r[i.id]?.reason ?? '', note: ev.target.value } })), setE({}))} />
                                            <InputError message={e[`ct-note-${i.id}`]} />
                                        </div>
                                    </div>
                                ) : null}
                            </li>
                        );
                    })}
                </ul>
                {diffs.some((i) => reasons[i.id]?.reason === 'Given but not recorded' || reasons[i.id]?.reason === 'Recorded but not given') ? <Notice tone="info" title="A dose may be recorded wrongly">Check today’s record in Meds today with the person who gave it. The house lead may raise a medication error (P08b).</Notice> : null}
            </>
        ),
        review: (
            <div className="grid gap-3 sm:grid-cols-2">
                <ReviewCard icon={ClipboardCheck} title="Counted" onEdit={() => setStep(0)}>
                    <ReviewRow label="What" value={scope === 'house' ? `Whole house · ${HOUSES[house]} · ${items.length} medicines` : `${items[0].med} · ${PEOPLE[items[0].pid].pref}`} />
                    <ReviewRow label="By" value={`${me.name} · now`} />
                </ReviewCard>
                <ReviewCard icon={Scale} title="Differences" onEdit={() => setStep(1)}>
                    {diffs.length ? diffs.map((i) => <ReviewRow key={i.id} label={i.med} value={`${counted[i.id]} vs ${expected(i)} · ${reasons[i.id]?.reason}`} />) : <ReviewRow label="None" value="Everything matches" />}
                </ReviewCard>
                {offlineErr ? (
                    <div className="sm:col-span-2">
                        <Notice tone="critical" title="Not saved">
                            {cantSaveOffline}
                        </Notice>
                    </div>
                ) : null}
            </div>
        ),
    };
    return (
        <>
            <WizardShell
                open
                onClose={() => (phase === 'sending' ? undefined : phase === 'done' || !dirty ? onClose() : setDiscard(true))}
                onCloseAutoFocus={restore(returnFocus)}
                title={scope === 'house' ? `Count stock — ${HOUSES[house]}` : `Count ${items[0].med} — ${PEOPLE[items[0].pid].pref}`}
                description="Count what’s there, check the differences, then save."
                railIcon={ClipboardCheck}
                railTitle="Count stock"
                railSub={scope === 'house' ? `${HOUSES[house]} · ${items.length} medicines` : `${PEOPLE[items[0].pid].pref} · ${items[0].med}`}
                steps={steps.map((x, n) => ({ ...x, disabled: n > step || phase === 'sending' }))}
                stepIndex={step}
                onStepClick={(n) => n <= step && setStep(n)}
                pct={Math.round(((step + (phase === 'done' ? 1 : 0)) / steps.length) * 100)}
                pctLabel="Completeness"
                footerStart={
                    step > 0 && phase !== 'sending' ? (
                        <Button type="button" variant="ghost" onClick={() => setStep(step - 1)}>
                            <ChevronLeft className="size-4" /> Back
                        </Button>
                    ) : (
                        <Button type="button" variant="outline" onClick={() => (dirty ? setDiscard(true) : onClose())} disabled={phase === 'sending'}>
                            Cancel
                        </Button>
                    )
                }
                footerEnd={
                    cur !== 'review' ? (
                        <Button type="button" onClick={next}>
                            {cur === 'count' ? 'Show what was expected' : 'Continue'} <ChevronRight className="size-4" />
                        </Button>
                    ) : (
                        <Button type="button" onClick={save} disabled={phase === 'sending'}>
                            {phase === 'sending' ? <Loader2 className="size-4 animate-spin motion-reduce:animate-none" /> : <Check className="size-4" />}
                            {phase === 'sending' ? 'Saving…' : 'Save the count'}
                        </Button>
                    )
                }
                success={
                    phase === 'done' ? (
                        <WizardSuccessPane
                            title="Count saved"
                            blurb={diffs.length ? `${diffs.length} ${diffs.length === 1 ? 'difference goes' : 'differences go'} to the house lead to sign off by the end of today.` : 'Everything matched. Nothing else to do.'}
                            actions={
                                <Button type="button" autoFocus onClick={onClose}>
                                    Done
                                </Button>
                            }
                        />
                    ) : undefined
                }
                maxWidth="min(94vw, 1000px)"
                maxHeight="min(88vh, 820px)"
            >
                <WizardStepPane>
                    <div className="space-y-4">{body[cur]}</div>
                </WizardStepPane>
            </WizardShell>
            <ConfirmDialog
                open={discard}
                onClose={() => setDiscard(false)}
                onConfirm={() => {
                    setDiscard(false);
                    onClose();
                }}
                title="Discard this count?"
                description="Nothing has been saved."
                confirmText="Discard"
                cancelText="Keep counting"
            />
        </>
    );
}

/* ═════════════ Sign off a count difference (the house lead) ═════════════ */
export function SignOffDialog({ id, onClose, returnFocus }: { id: string; onClose: () => void; returnFocus?: () => HTMLElement | null }) {
    const s = useStore();
    const p = s.route.persona;
    const me = PERSONAS[p];
    const c = allCounts(s.rt).find((x) => x.id === id)!;
    const lines = differences(c);
    const [decision, setDecision] = useState('');
    const [note, setNote] = useState('');
    const [e, setE] = useState<Record<string, string>>({});
    const manage = canManage(p) && !c.signOff;
    function save() {
        const x: Record<string, string> = {};
        if (!decision) x['so-decision'] = 'Choose accept or recount.';
        if (decision === 'recount' && !note.trim()) x['so-note'] = 'Say who recounts, and what to look for.';
        setE(x);
        if (Object.keys(x).length) return focusFirst(x);
        if (s.route.scenario === 'offline') return setE({ 'so-decision': cantSaveOffline });
        s.update((rt) => {
            const next = { ...rt, signOffs: { ...rt.signOffs, [c.id]: decision === 'accept' ? { by: me.name, at: stamp(), outcome: 'accepted' as const } : null } };
            if (decision === 'accept') {
                const lotsPatch = { ...rt.lots };
                const moves: Movement[] = [];
                for (const l of lines) {
                    const i = itemOf(l.itemId);
                    const d = l.counted - l.expected;
                    const base = lotsOf(i, rt);
                    if (d < 0) lotsPatch[i.id] = takeFefo(base, -d).lots;
                    else {
                        const first = fefo(i, rt);
                        lotsPatch[i.id] = base.map((x) => (first && x.id === first.id ? { ...x, qty: x.qty + d } : x));
                    }
                    moves.push({ id: `mv-so-${Date.now()}-${l.itemId}`, itemId: l.itemId, at: stamp(), kind: 'correction', qty: d, by: me.name, note: `Count signed off — ${l.reason}${note.trim() ? `: ${note.trim()}` : ''}` });
                }
                next.lots = lotsPatch;
                next.movements = [...moves, ...rt.movements];
            }
            return next;
        });
        s.toast('success', decision === 'accept' ? 'Signed off — stock now matches the count. The follow-up is closed.' : 'Recount asked for. The follow-up stays open.');
        onClose();
    }
    return (
        <Modal
            width={720}
            title={`Count difference — ${lines.map((l) => itemOf(l.itemId).med).join(', ')}`}
            description={`Counted ${c.at} by ${c.by} · ${HOUSES[c.house]}`}
            onClose={onClose}
            onCloseAutoFocus={restore(returnFocus)}
            footer={
                <>
                    <Button variant="outline" onClick={onClose}>
                        {manage ? 'Cancel' : 'Close'}
                    </Button>
                    {manage ? <Button onClick={save}>{decision === 'recount' ? 'Ask for a recount' : 'Sign off'}</Button> : null}
                </>
            }
        >
            {lines.map((l) => (
                <KV
                    key={l.itemId}
                    rows={[
                        ['Medicine', `${itemOf(l.itemId).med} ${itemOf(l.itemId).strength} · ${who(itemOf(l.itemId))}`],
                        ['Expected', unitLabel(itemOf(l.itemId), l.expected)],
                        ['Counted', `${unitLabel(itemOf(l.itemId), l.counted)} (${l.counted - l.expected > 0 ? '+' : ''}${l.counted - l.expected})`],
                        ['Reason given', `${l.reason}${l.note ? ` — “${l.note}”` : ''}`],
                    ]}
                />
            ))}
            {c.signOff ? (
                <Notice tone="success" title="Signed off">
                    {c.signOff.by}, {c.signOff.at}.
                </Notice>
            ) : manage ? (
                <>
                    <div className="space-y-2">
                        <Label id="so-decision-l">
                            Your decision <Req />
                        </Label>
                        <div id="so-decision" tabIndex={-1}>
                            <TilePicker
                                labelledBy="so-decision-l"
                                value={decision || null}
                                invalid={!!e['so-decision']}
                                onChange={(k) => (setDecision(k), setE({}))}
                                tiles={[
                                    { key: 'accept', label: 'Accept — set stock to the count', description: 'A count correction is recorded, with the reason', icon: Check },
                                    { key: 'recount', label: 'Recount first', description: 'Someone else counts again; the follow-up stays open', icon: RefreshCw },
                                ]}
                            />
                        </div>
                        <InputError message={e['so-decision']} />
                    </div>
                    <div className="space-y-1.5">
                        <Label htmlFor="so-note">Note {decision === 'recount' ? <Req /> : <span className="text-subtle">(optional)</span>}</Label>
                        <Textarea id="so-note" rows={2} value={note} aria-invalid={!!e['so-note']} placeholder={decision === 'recount' ? 'e.g. Mere to recount at 3:00 pm — check the other cabinet shelf' : 'e.g. Talked to Priya — the dropped tablets match the handover note'} onChange={(ev) => (setNote(ev.target.value), setE({}))} />
                        <InputError message={e['so-note']} />
                    </div>
                    {lines.some((l) => l.reason === 'Given but not recorded' || l.reason === 'Recorded but not given') ? <Notice tone="info" title="Consider a medication error report">A dose may be recorded wrongly. Report it from Meds today (P08b) if so.</Notice> : null}
                </>
            ) : (
                <p className="text-caption">The house lead signs it off. It’s in their follow-ups, due by the {c.followUpDue ?? 'end of today'}.</p>
            )}
        </Modal>
    );
}

/* ═════════════ Adjust or remove (reasoned movements — Q7) ═════════════ */
const ADJUST: { key: MoveKind; label: string; description: string; icon: typeof Trash2; out: boolean }[] = [
    { key: 'removed', label: 'Expired — removed', description: 'Into the returns box for the pharmacy', icon: Trash2, out: true },
    { key: 'damaged', label: 'Damaged or dropped', description: 'Can’t be given', icon: Hammer, out: true },
    { key: 'returned', label: 'Returned to the pharmacy', description: 'Stopped, changed or not needed', icon: Undo2, out: true },
    { key: 'found', label: 'Found', description: 'Adds to a pack — say where it was', icon: Search, out: false },
    { key: 'correction', label: 'Count correction', description: 'Use Count instead, unless the house lead is correcting', icon: Scale, out: true },
];
export function AdjustDialog({ itemId, lotId, onClose, returnFocus }: { itemId: string; lotId?: string; onClose: () => void; returnFocus?: () => HTMLElement | null }) {
    const s = useStore();
    const me = PERSONAS[s.route.persona];
    const i = itemOf(itemId);
    const lots = lotsOf(i, s.rt).filter((l) => l.qty > 0);
    const expiredLot = lotId ? lots.find((l) => l.id === lotId) : lots.find((l) => l.expiryIso && daysFrom(l.expiryIso) < 0);
    const [kind, setKind] = useState<MoveKind | ''>(expiredLot && expiredLot.expiryIso && daysFrom(expiredLot.expiryIso) < 0 ? 'removed' : '');
    const [pack, setPack] = useState(expiredLot?.id ?? fefo(i, s.rt)?.id ?? lots[0]?.id ?? '');
    const lot = lots.find((l) => l.id === pack);
    const [qty, setQty] = useState(kind === 'removed' && lot ? String(lot.qty) : '');
    const [note, setNote] = useState('');
    const [confirm, setConfirm] = useState(false);
    const [e, setE] = useState<Record<string, string>>({});
    const def = ADJUST.find((x) => x.key === kind);
    function check() {
        const x: Record<string, string> = {};
        if (!kind) x['ad-kind'] = 'Choose what happened.';
        if (!pack) x['ad-pack'] = 'Choose the pack.';
        if (!(Number(qty) > 0)) x['ad-qty'] = 'Enter how many.';
        else if (def?.out && lot && Number(qty) > lot.qty) x['ad-qty'] = `That pack has only ${lot.qty}.`;
        if ((kind === 'found' || kind === 'correction' || kind === 'damaged') && !note.trim()) x['ad-note'] = 'Say what happened.';
        setE(x);
        if (Object.keys(x).length) return focusFirst(x);
        if (s.route.scenario === 'offline') return setE({ 'ad-kind': cantSaveOffline });
        if (def?.out) setConfirm(true);
        else commit();
    }
    function commit() {
        const n = Number(qty) * (def!.out ? -1 : 1);
        s.update((rt) => ({ ...rt, lots: { ...rt.lots, [i.id]: lotsOf(i, rt).map((l) => (l.id === pack ? { ...l, qty: l.qty + n } : l)) }, movements: [{ id: `mv-ad-${Date.now()}`, itemId: i.id, at: stamp(), kind: kind as MoveKind, qty: n, lotId: pack, by: me.name, note: note.trim() || undefined }, ...rt.movements] }));
        s.toast('success', `${def!.label}: ${unitLabel(i, Math.abs(n))} of ${i.med} for ${PEOPLE[i.pid].pref}. The reason is kept on the movement.`);
        onClose();
    }
    return (
        <>
            <Modal
                width={720}
                title={`Adjust or remove — ${i.med}, ${PEOPLE[i.pid].pref}`}
                description={`${i.strength} · on hand ${unitLabel(i, onHand(i, s.rt))}`}
                onClose={onClose}
                onCloseAutoFocus={restore(returnFocus)}
                footer={
                    <>
                        <Button variant="outline" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button variant={def?.out ? 'destructive' : 'default'} onClick={check}>
                            {def?.out ? 'Remove from stock' : 'Save'}
                        </Button>
                    </>
                }
            >
                <div className="space-y-2">
                    <Label id="ad-kind-l">
                        What happened? <Req />
                    </Label>
                    <div id="ad-kind" tabIndex={-1}>
                        <TilePicker labelledBy="ad-kind-l" value={kind || null} invalid={!!e['ad-kind']} onChange={(k) => (setKind(k as MoveKind), setE({}))} tiles={ADJUST.map((x) => ({ key: x.key, label: x.label, description: x.description, icon: x.icon }))} />
                    </div>
                    <InputError message={e['ad-kind']} />
                </div>
                <div className="grid gap-3 sm:grid-cols-2">
                    <div className="space-y-1.5">
                        <Label htmlFor="ad-pack">
                            Pack <Req />
                        </Label>
                        <Select value={pack || undefined} onValueChange={(v) => (setPack(v), setE({}))}>
                            <SelectTrigger id="ad-pack" className="w-full" aria-invalid={!!e['ad-pack']}>
                                <SelectValue placeholder="Choose" />
                            </SelectTrigger>
                            <SelectContent>
                                {lots.map((l) => (
                                    <SelectItem key={l.id} value={l.id}>
                                        {l.batch ?? 'No batch printed'} · {expiryText(l).toLowerCase()} · {l.qty} left
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={e['ad-pack']} />
                    </div>
                    <div className="space-y-1.5">
                        <Label htmlFor="ad-qty">
                            How many <Req />
                        </Label>
                        <Input id="ad-qty" value={qty} inputMode="numeric" aria-invalid={!!e['ad-qty']} onChange={(ev) => (setQty(ev.target.value.replace(/[^\d.]/g, '')), setE({}))} />
                        <InputError message={e['ad-qty']} />
                    </div>
                </div>
                <div className="space-y-1.5">
                    <Label htmlFor="ad-note">Note {kind === 'found' || kind === 'correction' || kind === 'damaged' ? <Req /> : <span className="text-subtle">(optional)</span>}</Label>
                    <Textarea id="ad-note" rows={2} value={note} aria-invalid={!!e['ad-note']} placeholder={kind === 'found' ? 'e.g. Found in Sam’s jacket pocket' : 'e.g. Put in the returns box for Thursday’s pharmacy run'} onChange={(ev) => (setNote(ev.target.value), setE({}))} />
                    <InputError message={e['ad-note']} />
                </div>
                <p className="text-caption">Kept as a stock movement with the reason. Controlled medicines are adjusted in the controlled register, not here.</p>
            </Modal>
            <ConfirmDialog
                open={confirm}
                onClose={() => setConfirm(false)}
                onConfirm={() => {
                    setConfirm(false);
                    commit();
                }}
                title={`Remove ${qty} ${i.med} from stock?`}
                description={lot && lot.expiryIso && daysFrom(lot.expiryIso) < 0 ? `${def?.label}. The expired pack ${lot.batch ?? ""} (${qty}) comes off the list; on hand stays ${onHand(i, s.rt)}. The movement and its reason are kept.` : `${def?.label}. On hand goes from ${onHand(i, s.rt)} to ${Math.max(0, onHand(i, s.rt) - Number(qty))}. The movement and its reason are kept.`}
                confirmText="Remove from stock"
                cancelText="Keep it"
            />
        </>
    );
}

/* ═════════════ Going out / coming back with the person (Q10 — P07a’s pattern, no witness) ═════════════ */
const WHERE = ['Day programme', 'Whānau or family', 'Respite', 'Hospital', 'Another house'];
export function MoveDialog({ itemId, backOf, onClose, returnFocus }: { itemId: string; backOf?: string; onClose: () => void; returnFocus?: () => HTMLElement | null }) {
    const s = useStore();
    const me = PERSONAS[s.route.persona];
    const i = itemOf(itemId);
    const outMove = backOf ? allMovements(s.rt).find((m) => m.id === backOf) : undefined;
    const [dir, setDir] = useState<'' | 'out' | 'back'>(outMove ? 'back' : '');
    const [qty, setQty] = useState(outMove ? String(Math.abs(outMove.qty)) : '');
    const [where, setWhere] = useState(outMove ? 'Day programme' : '');
    const [person, setPerson] = useState('');
    const [at, setAt] = useState(NOW_LOCAL);
    const [e, setE] = useState<Record<string, string>>({});
    const given = outMove && Number(qty) >= 0 ? Math.abs(outMove.qty) - Number(qty) : 0;
    function save() {
        const x: Record<string, string> = {};
        if (!dir) x['mv-dir'] = 'Choose going out or coming back.';
        if (!(Number(qty) >= (dir === 'back' ? 0 : 1)) || qty === '') x['mv-qty'] = dir === 'back' ? 'Enter how many came back — 0 if none.' : 'Enter how many are going.';
        else if (dir === 'out' && Number(qty) > onHand(i, s.rt)) x['mv-qty'] = `Only ${onHand(i, s.rt)} on hand.`;
        else if (outMove && Number(qty) > Math.abs(outMove.qty)) x['mv-qty'] = `Only ${Math.abs(outMove.qty)} went out.`;
        if (!where) x['mv-where'] = 'Choose where.';
        if (!person.trim()) x['mv-person'] = dir === 'back' ? 'Who brought it back?' : 'Who was it handed to?';
        setE(x);
        if (Object.keys(x).length) return focusFirst(x);
        if (s.route.scenario === 'offline') return setE({ 'mv-dir': cantSaveOffline });
        const n = Number(qty);
        s.update((rt) => {
            const base = lotsOf(i, rt);
            const lots = dir === 'out' ? takeFefo(base, n).lots : base.map((l) => (l.id === (outMove?.lotId ?? fefo(i, rt)?.id) ? { ...l, qty: l.qty + n } : l));
            const m: Movement = { id: `mv-mv-${Date.now()}`, itemId: i.id, at: stamp(), kind: dir as 'out' | 'back', qty: dir === 'out' ? -n : n, lotId: outMove?.lotId ?? fefo(i, rt)?.id, by: me.name, note: `${dir === 'out' ? 'To' : 'From'} ${where.toLowerCase()} · ${dir === 'out' ? 'handed to' : 'brought back by'} ${person.trim()}${outMove && given > 0 ? ` · ${given} given while out` : ''}`, open: dir === 'out' };
            return { ...rt, lots: { ...rt.lots, [i.id]: lots }, movements: [m, ...rt.movements], closedMoves: outMove ? [...rt.closedMoves, outMove.id] : rt.closedMoves };
        });
        s.toast('success', dir === 'out' ? `${unitLabel(i, n)} went out with ${PEOPLE[i.pid].pref}. It’s in Stock alerts until it comes back.` : `${unitLabel(i, n)} came back${given > 0 ? ` — ${given} given while out` : ''}.`);
        onClose();
    }
    return (
        <Modal
            width={720}
            title={`${outMove ? 'Coming back' : 'Going out or coming back'} — ${i.med}, ${PEOPLE[i.pid].pref}`}
            description={outMove ? `Went out ${outMove.at}: ${outMove.note}` : `${i.strength} · on hand ${unitLabel(i, onHand(i, s.rt))}`}
            onClose={onClose}
            onCloseAutoFocus={restore(returnFocus)}
            footer={
                <>
                    <Button variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button onClick={save}>Save</Button>
                </>
            }
        >
            {outMove ? null : (
            <div className="space-y-2">
                <Label id="mv-dir-l">
                    What’s happening? <Req />
                </Label>
                <div id="mv-dir" tabIndex={-1}>
                    <TilePicker
                        labelledBy="mv-dir-l"
                        value={dir || null}
                        invalid={!!e['mv-dir']}
                        onChange={(k) => (setDir(k as 'out' | 'back'), setE({}))}
                        tiles={[
                            { key: 'out', label: 'Going out with them', description: 'Day programme, whānau, respite, hospital', icon: ArrowUpFromLine },
                            { key: 'back', label: 'Coming back', description: 'Count what came back', icon: ArrowDownToLine },
                        ]}
                    />
                </div>
                <InputError message={e['mv-dir']} />
            </div>
            )}
            <div className="grid gap-3 sm:grid-cols-2">
                <div className="space-y-1.5">
                    <Label htmlFor="mv-qty">
                        {dir === 'back' ? 'How many came back' : 'How many'} <Req />
                    </Label>
                    <Input id="mv-qty" value={qty} inputMode="numeric" aria-invalid={!!e['mv-qty']} onChange={(ev) => (setQty(ev.target.value.replace(/[^\d.]/g, '')), setE({}))} />
                    <InputError message={e['mv-qty']} />
                </div>
                <div className="space-y-1.5">
                    <Label htmlFor="mv-where">
                        {dir === 'back' ? 'From' : 'Where to'} <Req />
                    </Label>
                    <Select value={where || undefined} onValueChange={(v) => (setWhere(v), setE({}))}>
                        <SelectTrigger id="mv-where" className="w-full" aria-invalid={!!e['mv-where']}>
                            <SelectValue placeholder="Choose" />
                        </SelectTrigger>
                        <SelectContent>
                            {WHERE.map((x) => (
                                <SelectItem key={x} value={x}>
                                    {x}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <InputError message={e['mv-where']} />
                </div>
                <div className="space-y-1.5 sm:col-span-2">
                    <Label htmlFor="mv-person">
                        {dir === 'back' ? 'Brought back by' : 'Handed to'} <Req />
                    </Label>
                    <Input id="mv-person" value={person} aria-invalid={!!e['mv-person']} placeholder="e.g. Losa — day programme staff" onChange={(ev) => (setPerson(ev.target.value), setE({}))} />
                    <InputError message={e['mv-person']} />
                </div>
            </div>
            <DateTimeField id="mv-at" label={dir === 'back' ? 'Came back' : 'Went out'} value={at} onChange={setAt} />
            {outMove && given > 0 ? <Notice tone="info" icon={FileText} title={`${given} given while out`}>Make sure the dose is recorded — by the day programme on their sheet, and on the MAR as given away from the house (P01).</Notice> : null}
            <p className="text-caption">Controlled medicines go out and come back in Controlled checks, with a witness.</p>
        </Modal>
    );
}

