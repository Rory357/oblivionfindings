/* P06’s stock item viewer and the Receive wizard — redesigning today’s
 * ReceiveStockDialog, the non-CD “receive against order” path and the
 * controlled delivery (Main, Q2, Q4, Q6, Q9). Real WizardShell (viewer and
 * sequential), ReviewCard/ReviewRow, WizardSuccessPane, TilePicker, Select,
 * Input, Checkbox, Textarea, FileDropzone + StagedFileCard. */
import ConfirmDialog from '@/components/confirm-dialog';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { FileDropzone, StagedFileCard } from '@/components/ui/file-dropzone';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import { ReviewCard, ReviewRow, WizardShell, WizardStepPane, WizardSuccessPane } from '@/components/wizard/shell';
import { ArrowLeftRight, Camera, Check, ChevronLeft, ChevronRight, Home, Image as ImageIcon, Info, Loader2, Package, PackageCheck, Pill, Plus, ShieldCheck, Truck, Undo2, X } from 'lucide-react';
import { useState, type ReactNode } from 'react';
import { NOW_LABEL } from './clock';
import { ITEMS, MOVE_LABEL, PEOPLE, PEOPLE_ORDER, PERSONAS, SOURCE_LABEL, STAFF_ON_SHIFT, type Lot, type LotSource, type PersonId, type PharmacyOrder } from './data';
import { allMovements, allOrders, canManage, canReceive, concealed, daysFrom, daysOfSupply, expiryText, fefo, itemOf, lotsOf, onHand, ordersFor, photosOf, receivable, SETTINGS, statusOf, unitLabel } from './model';
import { useStore } from './store';
import { DefaultChip, KV, Notice, OrderStateBadge, StockBadge, TilePicker } from './ui';

const cantSaveOffline = 'You’re offline, so this can’t be saved yet. Nothing is lost — keep the delivery aside, unopened, and save when you reconnect.';
const focusFirst = (x: Record<string, string>) => window.setTimeout(() => document.getElementById(Object.keys(x)[0])?.focus(), 50);
export const restore = (rf?: () => HTMLElement | null) => (ev: Event) => {
    const el = rf?.();
    if (el) {
        ev.preventDefault();
        el.focus();
    }
};
export const stamp = () => `Mon 28 Sep, ${NOW_LABEL}`;
export const Req = () => <span className="text-status-critical">*</span>;
/** “02/2027” → { label, iso (last day of the month) } or null. */
export function parseExpiry(v: string): { label: string; iso: string } | null {
    const m = /^\s*(\d{1,2})\s*\/\s*(\d{4})\s*$/.exec(v);
    if (!m) return null;
    const mm = Number(m[1]);
    if (mm < 1 || mm > 12) return null;
    const last = new Date(Date.UTC(Number(m[2]), mm, 0)).getUTCDate();
    return { label: `${String(mm).padStart(2, '0')}/${m[2]}`, iso: `${m[2]}-${String(mm).padStart(2, '0')}-${String(last).padStart(2, '0')}` };
}

/* ───────────── a synthetic pack photo (the build shows the stored image) ───────────── */
export function PackPhoto({ label }: { label: string }) {
    return (
        <span className="flex aspect-[4/3] w-full flex-col items-center justify-center gap-1 rounded-lg border border-dashed bg-muted text-muted-foreground" role="img" aria-label={`Pack photo — ${label}`}>
            <ImageIcon className="size-6" aria-hidden="true" />
            <span className="text-[11px]">Synthetic pack photo</span>
        </span>
    );
}

/* ═════════════ The stock item: a viewer with sections ═════════════ */
export function ItemDialog({ id, onClose, onAction }: { id: string; onClose: () => void; onAction: (spec: string) => void }) {
    const s = useStore();
    const p = s.route.persona;
    const i = itemOf(id);
    const [sec, setSec] = useState(0);
    const st = statusOf(i, s.rt);
    const lots = lotsOf(i, s.rt);
    const moves = allMovements(s.rt).filter((m) => m.itemId === i.id);
    const photos = photosOf(i, s.rt);
    const orders = ordersFor(i, s.rt);
    const next = fefo(i, s.rt);
    const d = daysOfSupply(i, s.rt);
    const SECS = [
        { key: 'now', label: 'This medicine', blurb: i.selfManaged ? 'Self-managed' : unitLabel(i, onHand(i, s.rt)), icon: Pill },
        { key: 'lots', label: 'Packs', blurb: `${lots.filter((l) => l.qty > 0).length} open · ${lots.length} kept`, icon: Package },
        { key: 'moves', label: 'Movements', blurb: `${moves.length} recorded`, icon: ArrowLeftRight },
        { key: 'photos', label: 'Pack photos', blurb: photos.length ? `${photos.length} kept` : 'None yet', icon: Camera },
        { key: 'orders', label: 'Pharmacy orders', blurb: `${orders.length} for this medicine`, icon: Truck },
    ];
    const body: ReactNode[] = [
        <div key="now" className="space-y-4">
            <div className="flex flex-wrap items-center gap-2">
                <StockBadge state={st.state} />
            </div>
            {st.lines.map((l) => (
                <p key={l} className="text-sm">
                    {l}
                </p>
            ))}
            <KV
                rows={[
                    ['Person', `${PEOPLE[i.pid].legal} (“${PEOPLE[i.pid].pref}”)`],
                    ['Medicine', `${i.med} ${i.strength}`],
                    ['On hand', i.selfManaged ? 'Not counted — the person manages it' : `${unitLabel(i, onHand(i, s.rt))}${d != null ? ` · ${d} days’ supply` : ''}`],
                    ['Low stock', i.selfManaged ? '—' : i.perDay ? <DefaultChip key="l" value={`Under ${SETTINGS.lowDays} days’ supply`} /> : i.reorderLevel != null ? `At or below ${i.reorderLevel} (reorder level)` : 'No reorder level — as needed'],
                    ['Use next', next ? `${next.batch ?? 'Pack with no batch printed'} · ${expiryText(next).toLowerCase()}` : '—'],
                    ...(i.coldChain ? ([['Storage', 'Fridge, 2–8 °C']] as [string, string][]) : []),
                    ['Last counted', i.lastCount ?? 'Not counted yet'],
                ]}
            />
            {i.cd ? <Notice tone="info" icon={ShieldCheck} title="A controlled medicine">Counted in Controlled checks, and adjusted or destroyed in the controlled register. Deliveries are received here as a register entry, with a witness.</Notice> : null}
        </div>,
        <ul key="lots" className="divide-y rounded-lg border">
            {lots.length ? (
                lots.map((l) => {
                    const dd = l.expiryIso ? daysFrom(l.expiryIso) : null;
                    return (
                        <li key={l.id} className="space-y-1 px-3 py-2.5 text-sm">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <span className="font-semibold">
                                    {l.batch ? `Batch ${l.batch}` : 'Batch not printed on the pack'} · {expiryText(l).toLowerCase()}
                                </span>
                                <StatusBadge variant={l.qty === 0 ? 'neutral' : dd != null && dd < 0 ? 'critical' : l === next ? 'info' : 'success'} className="rounded-[8px]">
                                    {l.qty === 0 ? 'Used up' : dd != null && dd < 0 ? 'Expired' : l === next ? 'Use next' : 'Open'}
                                </StatusBadge>
                            </div>
                            <p>
                                {unitLabel(i, l.qty)} left of {l.received}
                            </p>
                            <p className="text-caption">
                                {SOURCE_LABEL[l.source]}
                                {l.ref ? ` · ${l.ref}` : ''} · received {l.receivedAt} by {l.receivedBy}
                                {l.reasonShortExpiry ? ` · short expiry accepted: “${l.reasonShortExpiry}”` : ''}
                            </p>
                        </li>
                    );
                })
            ) : (
                <li className="px-3 py-2.5 text-sm text-muted-foreground">No packs received yet.</li>
            )}
        </ul>,
        <ul key="moves" className="divide-y rounded-lg border">
            {moves.length ? (
                moves.map((m) => (
                    <li key={m.id} className="space-y-0.5 px-3 py-2.5 text-sm">
                        <p className="font-semibold">
                            {MOVE_LABEL[m.kind]}
                            {m.qty ? ` · ${m.qty > 0 ? '+' : ''}${unitLabel(i, m.qty)}` : ''}
                        </p>
                        <p className="text-caption">
                            {m.at} · {m.by}
                            {m.lotId ? ` · ${lots.find((l) => l.id === m.lotId)?.batch ?? 'pack'}` : ''}
                            {m.note ? ` — ${m.note}` : ''}
                        </p>
                    </li>
                ))
            ) : (
                <li className="px-3 py-2.5 text-sm text-muted-foreground">Doses, counts, removals and movements appear here. Nothing yet.</li>
            )}
        </ul>,
        <div key="photos" className="space-y-3">
            {photos.length ? (
                <div className="grid gap-3 sm:grid-cols-3">
                    {photos.map((ph) => (
                        <figure key={ph.id} className="space-y-1.5">
                            <PackPhoto label={ph.pack} />
                            <figcaption className="text-caption">
                                {ph.takenAt} · {ph.takenBy} · {lots.find((l) => l.id === ph.lotId)?.batch ?? 'no batch'}
                                <br />
                                {ph.pack}
                            </figcaption>
                        </figure>
                    ))}
                </div>
            ) : (
                <p className="text-sm text-muted-foreground">No pack photo yet. The next receipt prompts for one.</p>
            )}
            <p className="text-caption">Photos are stored privately{i.cd ? ', and shown only to people with controlled-medicine access' : ''}. The latest is shown in the dose dialog as “Pack photo — received …”. Earlier photos are kept.</p>
        </div>,
        <ul key="orders" className="divide-y rounded-lg border">
            {orders.length ? (
                orders.map((o) => (
                    <li key={o.id} className="flex flex-wrap items-center justify-between gap-2 px-3 py-2.5 text-sm">
                        <span>
                            <span className="font-semibold">{o.id}</span> · {unitLabel(i, o.qty)} · {o.pharmacy}
                        </span>
                        <OrderStateBadge state={o.state} />
                    </li>
                ))
            ) : (
                <li className="px-3 py-2.5 text-sm text-muted-foreground">No pharmacy orders for this medicine.</li>
            )}
        </ul>,
    ];
    const actions: ReactNode[] = [];
    if (!i.selfManaged && !i.cd) {
        const rcv = orders.find(receivable);
        if (canReceive(p)) actions.push(<Button key="r" onClick={() => onAction(rcv ? `receive:${rcv.id}` : `receive:item:${i.id}`)}>Receive</Button>);
        if (canReceive(p) && lots.length) actions.push(<Button key="c" variant="outline" onClick={() => onAction(`count:${i.id}`)}>Count</Button>);
        if (canManage(p) && lots.some((l) => l.qty > 0)) actions.push(<Button key="a" variant="outline" onClick={() => onAction(`adjust:${i.id}`)}>Adjust or remove</Button>);
    }
    return (
        <WizardShell
            open
            onClose={onClose}
            title={`${i.med} — ${PEOPLE[i.pid].pref}`}
            description="The medicine’s stock: packs, movements, pack photos and pharmacy orders."
            railIcon={Package}
            railTitle={`${i.med} ${i.strength}`}
            railSub={`${PEOPLE[i.pid].pref} ${PEOPLE[i.pid].surname}${i.selfManaged ? ' · self-managed' : ` · ${unitLabel(i, onHand(i, s.rt))}`}`}
            steps={SECS}
            stepIndex={sec}
            onStepClick={setSec}
            headerLabel={SECS[sec].label}
            sequential={false}
            pct={null}
            footerStart={
                <Button type="button" variant="outline" onClick={onClose}>
                    Close
                </Button>
            }
            footerEnd={actions.length ? <span className="flex flex-wrap items-center gap-2">{actions}</span> : undefined}
            maxWidth="min(94vw, 1000px)"
            maxHeight="min(86vh, 760px)"
        >
            <WizardStepPane>
                <div className="space-y-4">{body[sec]}</div>
            </WizardStepPane>
        </WizardShell>
    );
}

/* ═════════════ Receive a delivery (Q2, Q4, Q6, Q9) ═════════════ */
interface PackRow {
    key: string;
    batch: string;
    noBatch: boolean;
    expiry: string;
    noExpiry: boolean;
    qty: string;
    shortReason: string;
}
const emptyPack = (n: number, pre?: Partial<PackRow>): PackRow => ({ key: `p${n}`, batch: '', noBatch: false, expiry: '', noExpiry: false, qty: '', shortReason: '', ...pre });
export function ReceiveDialog({ spec, onClose, returnFocus }: { spec: string; onClose: () => void; returnFocus?: () => HTMLElement | null }) {
    const s = useStore();
    const p = s.route.persona;
    const me = PERSONAS[p];
    const [, a1, a2] = spec.split(':');
    const presetOrder = a1 && a1 !== 'new' && a1 !== 'item' ? allOrders(s.rt).find((o) => o.id === a1) : undefined;
    const presetItem = a1 === 'item' ? itemOf(a2) : presetOrder ? itemOf(presetOrder.itemId) : undefined;
    const itemOrder = presetItem && !presetOrder ? ordersFor(presetItem, s.rt).find(receivable) : undefined;
    const [source, setSource] = useState<LotSource | ''>(presetOrder || itemOrder ? 'pharmacy' : '');
    const [orderId, setOrderId] = useState(presetOrder?.id ?? itemOrder?.id ?? '');
    const [pid, setPid] = useState<PersonId | ''>(presetItem?.pid ?? '');
    const [itemId, setItemId] = useState(presetItem?.id ?? '');
    const [from, setFrom] = useState('');
    const order = orderId ? allOrders(s.rt).find((o) => o.id === orderId) : undefined;
    const item = order ? itemOf(order.itemId) : itemId ? itemOf(itemId) : undefined;
    const expectedQty = order ? (order.state === 'part' ? order.stillToCome! : order.dispensed?.qty ?? order.qty) : null;
    /** The pharmacy’s batch and expiry are pre-filled to check against the label; the quantity never is — staff count it. */
    const prefill = (o?: PharmacyOrder) => (o?.dispensed && o.state === 'dispensed' ? { batch: o.dispensed.batch ?? '', noBatch: !o.dispensed.batch, expiry: o.dispensed.expiry ?? '' } : {});
    const [packs, setPacks] = useState<PackRow[]>([emptyPack(0, prefill(order))]);
    const [labelMatches, setLabelMatches] = useState(false);
    const [outcome, setOutcome] = useState<'' | 'toCome' | 'short'>('');
    const [shortWhy, setShortWhy] = useState('');
    const [file, setFile] = useState<File | null>(null);
    const [looksDifferent, setLooksDifferent] = useState(false);
    const [witness, setWitness] = useState('');
    const [pin, setPin] = useState('');
    const [step, setStep] = useState(0);
    const [e, setE] = useState<Record<string, string>>({});
    const [phase, setPhase] = useState<'edit' | 'sending' | 'done'>('edit');
    const [discard, setDiscard] = useState(false);
    const [offlineErr, setOfflineErr] = useState(false);
    const cd = !!item?.cd;
    const total = packs.reduce((n, x) => n + (Number(x.qty) || 0), 0);
    const shortBy = expectedQty != null && total < expectedQty ? expectedQty - total : 0;
    const hasPhoto = item ? photosOf(item, s.rt).length > 0 : false;
    const promptPhoto = !hasPhoto || looksDifferent;
    const steps = [
        { key: 'what', label: 'What arrived', blurb: order ? order.id : 'Where it came from', icon: Truck },
        { key: 'packs', label: 'Packs', blurb: 'Batch, expiry, quantity', icon: Package },
        { key: 'photo', label: 'Pack photo', blurb: promptPhoto ? 'Take one' : 'Optional', icon: Camera },
        ...(cd ? [{ key: 'register', label: 'Register entry', blurb: 'With a witness', icon: ShieldCheck }] : []),
        { key: 'review', label: 'Review & save', blurb: 'Check, then save', icon: Check },
    ];
    const cur = steps[step].key;
    const snapshot = JSON.stringify({ packs, file: !!file, source, orderId, pid, itemId, from, labelMatches, outcome, shortWhy, looksDifferent, witness, pin });
    const [initial] = useState(snapshot);
    const dirty = snapshot !== initial;
    const setPack = (k: string, patch: Partial<PackRow>) => (setPacks((xs) => xs.map((x) => (x.key === k ? { ...x, ...patch } : x))), setE({}));
    const receivableOrders = allOrders(s.rt).filter((o) => receivable(o) && me.houses.includes(o.house) && (!o.cd || canManage(p)));
    function validate(k: string) {
        const x: Record<string, string> = {};
        if (k === 'what') {
            if (!source) x['rc-source'] = 'Choose where it came from.';
            if (source === 'pharmacy' && !orderId) x['rc-order'] = 'Choose the pharmacy order.';
            if (source && source !== 'pharmacy') {
                if (!pid) x['rc-pid'] = 'Choose the person.';
                if (!itemId) x['rc-item'] = 'Choose the medicine.';
                if (!from.trim()) x['rc-from'] = source === 'broughtIn' ? 'Say who brought it in.' : 'Say where it came back from.';
            }
        }
        if (k === 'packs') {
            for (const r of packs) {
                if (!r.noBatch && !r.batch.trim()) x[`rc-batch-${r.key}`] = 'Enter the batch, or tick “Not printed on the pack”.';
                const ex = parseExpiry(r.expiry);
                if (!r.noExpiry && !ex) x[`rc-exp-${r.key}`] = 'Enter the expiry as month/year, e.g. 02/2027 — or tick “Not printed on the pack”.';
                else if (ex && daysFrom(ex.iso) < 0) x[`rc-exp-${r.key}`] = 'This pack has expired — don’t receive it. Put it aside to go back to the pharmacy.';
                else if (ex && daysFrom(ex.iso) <= SETTINGS.expiryCritical && !r.shortReason.trim()) x[`rc-short-${r.key}`] = `It expires within ${SETTINGS.expiryCritical} days — say why you’re receiving it.`;
                if (!(Number(r.qty) > 0)) x[`rc-qty-${r.key}`] = 'Enter how many arrived.';
            }
            if (order?.state === 'dispensed' && !labelMatches) x['rc-label'] = 'Check the pharmacy label against what arrived.';
            if (shortBy > 0 && !outcome) x['rc-outcome'] = `${shortBy} fewer than expected — say whether the rest is coming.`;
            if (shortBy > 0 && outcome === 'short' && !shortWhy.trim()) x['rc-short-why'] = 'Say why no more is coming.';
            if (expectedQty != null && total > expectedQty) x[`rc-qty-${packs[0].key}`] = `More than the pharmacy sent (${expectedQty}) — check the count and the label.`;
        }
        if (k === 'register') {
            if (!witness) x['rc-witness'] = 'Choose who is witnessing.';
            if (!/^\d{6}$/.test(pin)) x['rc-pin'] = 'Enter their 6-digit witness PIN.';
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
        if (!item) return;
        setPhase('sending');
        window.setTimeout(() => {
            const newLots: Lot[] = packs.map((r, n) => {
                const ex = parseExpiry(r.expiry);
                return { id: `lot-new-${Date.now()}-${n}`, batch: r.noBatch ? null : r.batch.trim().toUpperCase(), expiry: r.noExpiry ? null : ex!.label, expiryIso: r.noExpiry ? null : ex!.iso, qty: Number(r.qty), received: Number(r.qty), source: source as LotSource, ref: order?.id ?? from.trim(), receivedBy: me.name, receivedAt: stamp(), reasonShortExpiry: r.shortReason.trim() || undefined };
            });
            s.update((rt) => {
                const next = { ...rt, lots: { ...rt.lots, [item.id]: [...lotsOf(item, rt), ...newLots] } };
                if (order) {
                    const receipts = [...(order.receipts ?? []), ...newLots.map((l) => ({ qty: l.qty, at: stamp(), by: me.name, lotId: l.id }))];
                    const state: PharmacyOrder['state'] = shortBy > 0 ? (outcome === 'toCome' ? 'part' : 'closedShort') : 'received';
                    next.orders = { ...rt.orders, [order.id]: { ...rt.orders[order.id], state, receipts, stillToCome: shortBy || undefined, expected: shortBy && outcome === 'toCome' ? 'when the pharmacy says' : order.expected, ended: state === 'closedShort' ? { reason: shortWhy.trim(), by: me.name, at: stamp() } : undefined } };
                }
                next.movements = [...newLots.map((l, n) => ({ id: `mv-new-${Date.now()}-${n}`, itemId: item.id, at: stamp(), kind: 'received' as const, qty: l.qty, lotId: l.id, by: me.name, note: `${order ? order.id : `${SOURCE_LABEL[source as LotSource]} — ${from.trim()}`}${cd ? ` · register entry witnessed by ${witness}` : ''}` })), ...rt.movements];
                if (file) next.photos = [{ id: `ph-new-${Date.now()}`, itemId: item.id, lotId: newLots[0].id, takenBy: me.name, takenAt: stamp(), pack: looksDifferent ? 'Pack looks different from last time' : 'Pack as received' }, ...rt.photos];
                return next;
            });
            setPhase('done');
        }, 700);
    }
    const body: Record<string, ReactNode> = {
        what: (
            <>
                {presetOrder ? null : (
                    <div className="space-y-2">
                        <Label id="rc-source-l">
                            Where did it come from? <Req />
                        </Label>
                        <div id="rc-source" tabIndex={-1}>
                            <TilePicker
                                labelledBy="rc-source-l"
                                value={source || null}
                                invalid={!!e['rc-source']}
                                onChange={(k) => (setSource(k as LotSource), setE({}))}
                                tiles={[
                                    { key: 'pharmacy', label: 'A pharmacy order', description: receivableOrders.length ? `${receivableOrders.length} due to be received` : 'Nothing due from the pharmacy', icon: Truck, disabled: receivableOrders.length ? null : 'No pharmacy delivery is due' },
                                    { key: 'broughtIn', label: 'Brought in', description: 'By the person or whānau — at move-in or respite', icon: Home },
                                    { key: 'cameBack', label: 'Came back with the person', description: 'From a day programme, whānau, hospital or another house', icon: Undo2 },
                                ]}
                            />
                        </div>
                        <InputError message={e['rc-source']} />
                    </div>
                )}
                {source === 'pharmacy' && !presetOrder ? (
                    <div className="space-y-1.5">
                        <Label htmlFor="rc-order">
                            Pharmacy order <Req />
                        </Label>
                        <Select
                            value={orderId || undefined}
                            onValueChange={(v) => {
                                setOrderId(v);
                                const o = allOrders(s.rt).find((x) => x.id === v);
                                setPacks([emptyPack(0, prefill(o))]);
                                setE({});
                            }}
                        >
                            <SelectTrigger id="rc-order" className="w-full" aria-invalid={!!e['rc-order']}>
                                <SelectValue placeholder="Choose" />
                            </SelectTrigger>
                            <SelectContent>
                                {receivableOrders.map((o) => (
                                    <SelectItem key={o.id} value={o.id}>
                                        {o.id} · {itemOf(o.itemId).med} for {PEOPLE[itemOf(o.itemId).pid].pref}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={e['rc-order']} />
                    </div>
                ) : null}
                {source && source !== 'pharmacy' ? (
                    <div className="grid gap-3 sm:grid-cols-2">
                        <div className="space-y-1.5">
                            <Label htmlFor="rc-pid">
                                Person <Req />
                            </Label>
                            <Select value={pid || undefined} onValueChange={(v) => (setPid(v as PersonId), setItemId(''), setE({}))} disabled={!!presetItem}>
                                <SelectTrigger id="rc-pid" className="w-full" aria-invalid={!!e['rc-pid']}>
                                    <SelectValue placeholder="Choose" />
                                </SelectTrigger>
                                <SelectContent>
                                    {PEOPLE_ORDER.filter((x) => me.houses.includes(PEOPLE[x].house)).map((x) => (
                                        <SelectItem key={x} value={x}>
                                            {PEOPLE[x].legal}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={e['rc-pid']} />
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="rc-item">
                                Medicine <Req />
                            </Label>
                            <Select value={itemId || undefined} onValueChange={(v) => (setItemId(v), setE({}))} disabled={!pid || !!presetItem}>
                                <SelectTrigger id="rc-item" className="w-full" aria-invalid={!!e['rc-item']}>
                                    <SelectValue placeholder={pid ? 'Choose' : 'Choose the person first'} />
                                </SelectTrigger>
                                <SelectContent>
                                    {ITEMS.filter((x) => x.pid === pid && !x.selfManaged && !x.cd && !concealed(x, p)).map((x) => (
                                        <SelectItem key={x.id} value={x.id}>
                                            {x.med} {x.strength}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <p className="text-caption">Only medicines on {pid ? PEOPLE[pid as PersonId].pref : 'their'}’s chart. Anything else is reconciled first (Orders & reviews).</p>
                            <InputError message={e['rc-item']} />
                        </div>
                        <div className="space-y-1.5 sm:col-span-2">
                            <Label htmlFor="rc-from">
                                {source === 'broughtIn' ? 'Brought in by' : 'Came back from'} <Req />
                            </Label>
                            <Input id="rc-from" value={from} aria-invalid={!!e['rc-from']} placeholder={source === 'broughtIn' ? 'e.g. Hine, with her pharmacy pack' : 'e.g. The day programme — handed back by Losa'} onChange={(ev) => (setFrom(ev.target.value), setE({}))} />
                            <InputError message={e['rc-from']} />
                        </div>
                    </div>
                ) : null}
                {order && item ? (
                    <KV
                        rows={[
                            ['Order', `${order.id} · ${order.pharmacy}`],
                            ['Medicine', `${item.med} ${item.strength} · ${PEOPLE[item.pid].legal}`],
                            ['The pharmacy sent', order.state === 'part' ? `The rest: ${unitLabel(item, order.stillToCome!)} (${unitLabel(item, order.receipts!.reduce((n, r) => n + r.qty, 0))} already received)` : `${unitLabel(item, order.dispensed!.qty)} · batch ${order.dispensed!.batch ?? 'not printed'} · expires ${order.dispensed!.expiry ?? 'not printed'}`],
                        ]}
                    />
                ) : null}
                {cd ? (
                    <Notice tone="info" icon={ShieldCheck} title="A controlled delivery">
                        You receive it into the controlled register, with a witness who types their PIN. The register balance goes up by what you count in.
                    </Notice>
                ) : null}
            </>
        ),
        packs: (
            <>
                {order?.state === 'dispensed' ? (
                    <label className="flex items-start gap-2 rounded-lg border px-3 py-2 text-sm">
                        <Checkbox id="rc-label" checked={labelMatches} onCheckedChange={(c) => (setLabelMatches(!!c), setE({}))} aria-invalid={!!e['rc-label'] || undefined} />
                        The pharmacy label is for {item ? PEOPLE[item.pid].pref : 'this person'}, and the medicine and strength match the order
                    </label>
                ) : null}
                <InputError message={e['rc-label']} />
                <ul className="space-y-3">
                    {packs.map((r, n) => {
                        const ex = parseExpiry(r.expiry);
                        const soon = ex && daysFrom(ex.iso) >= 0 && daysFrom(ex.iso) <= SETTINGS.expiryCritical;
                        return (
                            <li key={r.key} className="space-y-3 rounded-lg border p-3">
                                <div className="flex items-center justify-between gap-2">
                                    <p className="text-sm font-semibold">Pack {n + 1}</p>
                                    {packs.length > 1 ? (
                                        <Button type="button" size="sm" variant="ghost" aria-label={`Remove pack ${n + 1}`} onClick={() => setPacks((xs) => xs.filter((x) => x.key !== r.key))}>
                                            <X className="size-4" /> Remove
                                        </Button>
                                    ) : null}
                                </div>
                                <div className="grid gap-3 sm:grid-cols-3">
                                    <div className="space-y-1.5">
                                        <Label htmlFor={`rc-batch-${r.key}`}>
                                            Batch <Req />
                                        </Label>
                                        <Input id={`rc-batch-${r.key}`} value={r.batch} disabled={r.noBatch} aria-invalid={!!e[`rc-batch-${r.key}`]} placeholder="As printed, e.g. CX5521" onChange={(ev) => setPack(r.key, { batch: ev.target.value })} />
                                        <label className="flex items-center gap-2 text-[12.5px]">
                                            <Checkbox checked={r.noBatch} onCheckedChange={(c) => setPack(r.key, { noBatch: !!c, batch: '' })} />
                                            Not printed on the pack
                                        </label>
                                        <InputError message={e[`rc-batch-${r.key}`]} />
                                    </div>
                                    <div className="space-y-1.5">
                                        <Label htmlFor={`rc-exp-${r.key}`}>
                                            Expiry <Req />
                                        </Label>
                                        <Input id={`rc-exp-${r.key}`} value={r.expiry} disabled={r.noExpiry} inputMode="numeric" aria-invalid={!!e[`rc-exp-${r.key}`]} placeholder="MM/YYYY, e.g. 02/2027" onChange={(ev) => setPack(r.key, { expiry: ev.target.value })} />
                                        <label className="flex items-center gap-2 text-[12.5px]">
                                            <Checkbox checked={r.noExpiry} onCheckedChange={(c) => setPack(r.key, { noExpiry: !!c, expiry: '' })} />
                                            Not printed on the pack
                                        </label>
                                        <InputError message={e[`rc-exp-${r.key}`]} />
                                    </div>
                                    <div className="space-y-1.5">
                                        <Label htmlFor={`rc-qty-${r.key}`}>
                                            How many arrived <Req />
                                        </Label>
                                        <Input id={`rc-qty-${r.key}`} value={r.qty} inputMode="numeric" aria-invalid={!!e[`rc-qty-${r.key}`]} placeholder={item ? `In ${item.unit}` : 'Count them'} onChange={(ev) => setPack(r.key, { qty: ev.target.value.replace(/[^\d.]/g, '') })} />
                                        <p className="text-caption">Count them — don’t copy the label.</p>
                                        <InputError message={e[`rc-qty-${r.key}`]} />
                                    </div>
                                </div>
                                {soon || e[`rc-short-${r.key}`] ? (
                                    <div className="space-y-1.5">
                                        <Label htmlFor={`rc-short-${r.key}`}>
                                            It expires within {SETTINGS.expiryCritical} days — why receive it? <Req />
                                        </Label>
                                        <Input id={`rc-short-${r.key}`} value={r.shortReason} aria-invalid={!!e[`rc-short-${r.key}`]} placeholder="e.g. The course finishes before then — Dr Chen agreed" onChange={(ev) => setPack(r.key, { shortReason: ev.target.value })} />
                                        <InputError message={e[`rc-short-${r.key}`]} />
                                    </div>
                                ) : null}
                            </li>
                        );
                    })}
                </ul>
                <Button type="button" variant="outline" size="sm" onClick={() => setPacks((xs) => [...xs, emptyPack(xs.length + 1)])}>
                    <Plus className="size-4" /> Another pack with a different batch
                </Button>
                {shortBy > 0 ? (
                    <div className="space-y-2">
                        <Label id="rc-outcome-l">
                            {shortBy} fewer than the pharmacy sent — is the rest coming? <Req />
                        </Label>
                        <div id="rc-outcome" tabIndex={-1}>
                            <TilePicker
                                labelledBy="rc-outcome-l"
                                value={outcome || null}
                                invalid={!!e['rc-outcome']}
                                onChange={(k) => (setOutcome(k as 'toCome' | 'short'), setE({}))}
                                tiles={[
                                    { key: 'toCome', label: 'The rest is still to come', description: `The order stays open: ${shortBy} still to come`, icon: Truck },
                                    { key: 'short', label: 'No more is coming', description: 'Close the order short, with a reason', icon: X },
                                ]}
                            />
                        </div>
                        <InputError message={e['rc-outcome']} />
                        {outcome === 'short' ? (
                            <div className="space-y-1.5">
                                <Label htmlFor="rc-short-why">
                                    Why? <Req />
                                </Label>
                                <Input id="rc-short-why" value={shortWhy} aria-invalid={!!e['rc-short-why']} placeholder="e.g. The pharmacy is out of stock — Dr Chen is changing the prescription" onChange={(ev) => (setShortWhy(ev.target.value), setE({}))} />
                                <InputError message={e['rc-short-why']} />
                            </div>
                        ) : null}
                    </div>
                ) : null}
            </>
        ),
        photo: (
            <>
                {promptPhoto ? (
                    <Notice tone="info" icon={Camera} title={hasPhoto ? 'The pack looks different — take a photo' : `No pack photo of ${item?.med ?? 'this medicine'} yet — take one`}>
                        Photograph the pack with its pharmacy label showing. Staff see it in the dose dialog, so they know what the medicine should look like. It’s optional — you can skip it.
                    </Notice>
                ) : (
                    <p className="text-sm text-muted-foreground">There’s already a photo of this pack. Take a new one only if it looks different.</p>
                )}
                {hasPhoto ? (
                    <label className="flex items-start gap-2 rounded-lg border px-3 py-2 text-sm">
                        <Checkbox checked={looksDifferent} onCheckedChange={(c) => setLooksDifferent(!!c)} />
                        The pack or the tablets look different from last time (a new brand, shape or colour)
                    </label>
                ) : null}
                <div className="space-y-1.5">
                    <Label id="rc-photo-l">Pack photo (optional)</Label>
                    {file ? <StagedFileCard file={file} onRemove={() => setFile(null)} /> : <FileDropzone id="rc-photo" aria-labelledby="rc-photo-l" multiple={false} accept="image/*" hint="A photo of the pack and its label" onFiles={(x) => setFile(x[0] ?? null)} />}
                </div>
                {hasPhoto && item ? (
                    <div className="grid gap-3 sm:grid-cols-3">
                        <figure className="space-y-1.5">
                            <PackPhoto label="last photo" />
                            <figcaption className="text-caption">Last photo · {photosOf(item, s.rt)[0].takenAt}</figcaption>
                        </figure>
                    </div>
                ) : null}
                <p className="text-caption">Stored privately{cd ? ' and shown only to people with controlled-medicine access' : ''}. Earlier photos are kept.</p>
            </>
        ),
        register: item ? (
            <>
                <KV rows={[['Register balance now', unitLabel(item, onHand(item, s.rt))], ['Counted in', unitLabel(item, total)], ['Balance after', unitLabel(item, onHand(item, s.rt) + total)]]} />
                <div className="grid gap-3 sm:grid-cols-2">
                    <div className="space-y-1.5">
                        <Label htmlFor="rc-witness">
                            Witness <Req />
                        </Label>
                        <Select value={witness || undefined} onValueChange={(v) => (setWitness(v), setE({}))}>
                            <SelectTrigger id="rc-witness" className="w-full" aria-invalid={!!e['rc-witness']}>
                                <SelectValue placeholder="Someone on shift who watched the count" />
                            </SelectTrigger>
                            <SelectContent>
                                {STAFF_ON_SHIFT.filter((x) => x !== me.name).map((x) => (
                                    <SelectItem key={x} value={x}>
                                        {x}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={e['rc-witness']} />
                    </div>
                    <div className="space-y-1.5">
                        <Label htmlFor="rc-pin">
                            Witness’s 6-digit PIN <Req />
                        </Label>
                        <Input id="rc-pin" type="password" inputMode="numeric" autoComplete="off" maxLength={6} value={pin} aria-invalid={!!e['rc-pin']} onChange={(ev) => (setPin(ev.target.value.replace(/\D/g, '')), setE({}))} />
                        <p className="text-caption">Their own witness PIN — not their login password.</p>
                        <InputError message={e['rc-pin']} />
                    </div>
                </div>
            </>
        ) : null,
        review: item ? (
            <div className="grid gap-3 sm:grid-cols-2">
                <ReviewCard icon={Truck} title="What arrived" onEdit={() => setStep(0)}>
                    <ReviewRow label="Medicine" value={`${item.med} ${item.strength}`} />
                    <ReviewRow label="Person" value={PEOPLE[item.pid].legal} />
                    <ReviewRow label="From" value={order ? `${order.id} · ${order.pharmacy}` : `${SOURCE_LABEL[source as LotSource]} — ${from}`} />
                </ReviewCard>
                <ReviewCard icon={Package} title="Packs" onEdit={() => setStep(1)}>
                    {packs.map((r, n) => (
                        <ReviewRow key={r.key} label={`Pack ${n + 1}`} value={`${r.noBatch ? 'No batch printed' : r.batch.toUpperCase()} · ${r.noExpiry ? 'no expiry printed' : `expires ${parseExpiry(r.expiry)?.label}`} · ${r.qty}`} />
                    ))}
                    {shortBy > 0 ? <ReviewRow label="Still to come" value={outcome === 'toCome' ? `${shortBy}` : `None — closed short: ${shortWhy}`} /> : null}
                </ReviewCard>
                <ReviewCard icon={Camera} title="Pack photo" onEdit={() => setStep(2)}>
                    <ReviewRow label="Photo" value={file ? file.name : 'Skipped'} />
                </ReviewCard>
                {cd ? (
                    <ReviewCard icon={ShieldCheck} title="Register entry" onEdit={() => setStep(3)}>
                        <ReviewRow label="Witness" value={witness} />
                        <ReviewRow label="Balance" value={`${onHand(item, s.rt)} → ${onHand(item, s.rt) + total}`} />
                    </ReviewCard>
                ) : null}
                <div className="sm:col-span-2">
                    <Notice tone="neutral" icon={Info} title="When you save">
                        {packs.length === 1 ? 'A new pack is' : `${packs.length} new packs are`} added to {PEOPLE[item.pid].pref}’s {item.med}
                        {order ? `, and ${order.id} becomes “${shortBy > 0 ? (outcome === 'toCome' ? 'Part received' : 'Closed short') : 'Received'}” — read only from then on` : ''}. Doses come off the pack that expires first.
                    </Notice>
                </div>
                {offlineErr ? (
                    <div className="sm:col-span-2">
                        <Notice tone="critical" title="Not saved">
                            {cantSaveOffline}
                        </Notice>
                    </div>
                ) : null}
            </div>
        ) : null,
    };
    return (
        <>
            <WizardShell
                open
                onClose={() => (phase === 'sending' ? undefined : phase === 'done' || !dirty ? onClose() : setDiscard(true))}
                onCloseAutoFocus={restore(returnFocus)}
                title={item ? `Receive ${item.med} — ${PEOPLE[item.pid].pref}` : 'Receive a delivery'}
                description="What arrived, the packs, a pack photo, then review and save."
                railIcon={PackageCheck}
                railTitle={cd ? 'Receive into the register' : 'Receive a delivery'}
                railSub={item ? `${PEOPLE[item.pid].pref} · ${item.med}` : 'Count it in'}
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
                            {cur === 'photo' && !file ? 'Continue without a photo' : 'Continue'} <ChevronRight className="size-4" />
                        </Button>
                    ) : (
                        <Button type="button" onClick={save} disabled={phase === 'sending'}>
                            {phase === 'sending' ? <Loader2 className="size-4 animate-spin motion-reduce:animate-none" /> : <Check className="size-4" />}
                            {phase === 'sending' ? 'Saving…' : cd ? 'Save the register entry' : 'Receive it'}
                        </Button>
                    )
                }
                success={
                    phase === 'done' && item ? (
                        <WizardSuccessPane
                            title={cd ? 'Received into the register' : 'Received'}
                            blurb={`${unitLabel(item, total)} of ${item.med} for ${PEOPLE[item.pid].pref} — on hand is now ${unitLabel(item, onHand(item, s.rt))}.${order && shortBy > 0 && outcome === 'toCome' ? ` ${shortBy} still to come.` : ''}`}
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
                title="Discard this receipt?"
                description="Nothing has been saved. Keep the delivery aside until it’s received."
                confirmText="Discard"
                cancelText="Keep going"
            />
        </>
    );
}

