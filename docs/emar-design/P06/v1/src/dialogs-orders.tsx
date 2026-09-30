/* P06’s pharmacy order dialogs — one supply record per order (Main, Q4):
 * redesigning today’s CreatePharmacyOrderDialog, the forward-only “advance”
 * button and P04’s DispenseDialog (moved here). The Fleet Settings Modal,
 * ConfirmDialog, TilePicker, Select, Input, Textarea and the PKG-01
 * DateTimeField. */
import ConfirmDialog from '@/components/confirm-dialog';
import { DateTimeField } from '@/components/fleet-assets/maintenance/date-time-field';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { Ban, Check, CircleSlash, FileText, PackageX, RefreshCw, Stethoscope } from 'lucide-react';
import { useState } from 'react';
import { NOW_LOCAL } from './clock';
import { ITEMS, PEOPLE, PERSONAS, PHARMACY, type PharmacyOrder } from './data';
import { parseExpiry, Req, restore, stamp } from './dialogs-receive';
import { Modal } from './modal';
import { allOrders, concealed, daysOfSupply, itemOf, onHand, ORDER_STATE, receivable, unitLabel } from './model';
import { useStore } from './store';
import { KV, Notice, OrderStateBadge, TilePicker } from './ui';

const cantSaveOffline = 'You’re offline, so this can’t be saved yet. Nothing is lost — keep this open and save when you reconnect.';
const focusFirst = (x: Record<string, string>) => window.setTimeout(() => document.getElementById(Object.keys(x)[0])?.focus(), 50);

/* ═════════════ Order from the pharmacy ═════════════ */
export function NewOrderDialog({ itemId, onClose, returnFocus }: { itemId?: string; onClose: () => void; returnFocus?: () => HTMLElement | null }) {
    const s = useStore();
    const p = s.route.persona;
    const me = PERSONAS[p];
    const [id, setId] = useState(itemId ?? '');
    const i = id ? itemOf(id) : null;
    const suggest = i ? (i.perDay ? Math.ceil(i.perDay * 28) : (i.reorderLevel ?? 16) * 2) : 0;
    const [qty, setQty] = useState(i ? String(suggest) : '');
    const [needed, setNeeded] = useState('');
    const [note, setNote] = useState('');
    const [e, setE] = useState<Record<string, string>>({});
    const options = ITEMS.filter((x) => me.houses.includes(PEOPLE[x.pid].house) && !x.selfManaged && !x.cd && !concealed(x, p));
    const open = i ? allOrders(s.rt).find((o) => o.itemId === i.id && ['draft', 'sent', 'dispensed', 'part'].includes(o.state)) : undefined;
    function save(send: boolean) {
        const x: Record<string, string> = {};
        if (!id) x['no-item'] = 'Choose the medicine.';
        if (!(Number(qty) > 0)) x['no-qty'] = 'Enter how many to order.';
        setE(x);
        if (Object.keys(x).length) return focusFirst(x);
        if (s.route.scenario === 'offline') return setE({ 'no-item': cantSaveOffline });
        const n: PharmacyOrder = { id: `PO-${1043 + s.rt.newOrders.length}`, house: PEOPLE[i!.pid].house, itemId: i!.id, qty: Number(qty), pharmacy: PHARMACY, state: send ? 'sent' : 'draft', createdBy: me.name, createdAt: stamp(), sentAt: send ? stamp() : undefined, neededBy: needed.trim() || undefined };
        s.update((rt) => ({ ...rt, newOrders: [...rt.newOrders, n] }));
        s.toast('success', send ? `${n.id} sent to ${PHARMACY}. Record what they dispense when the label arrives.` : `${n.id} saved as a draft — it isn’t sent yet.`);
        onClose();
    }
    return (
        <Modal
            width={720}
            title={i ? `Order ${i.med} — ${PEOPLE[i.pid].pref}` : 'Order from the pharmacy'}
            description={`${PHARMACY} · orders go by the pharmacy’s usual route (email or portal)`}
            onClose={onClose}
            onCloseAutoFocus={restore(returnFocus)}
            footer={
                <>
                    <Button variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button variant="outline" onClick={() => save(false)}>
                        Save as draft
                    </Button>
                    <Button onClick={() => save(true)}>Send to the pharmacy</Button>
                </>
            }
        >
            <div className="grid gap-3 sm:grid-cols-2">
                <div className="space-y-1.5 sm:col-span-2">
                    <Label htmlFor="no-item">
                        Medicine <Req />
                    </Label>
                    <Select value={id || undefined} onValueChange={(v) => (setId(v), setQty(String(itemOf(v).perDay ? Math.ceil(itemOf(v).perDay! * 28) : (itemOf(v).reorderLevel ?? 16) * 2)), setE({}))} disabled={!!itemId}>
                        <SelectTrigger id="no-item" className="w-full" aria-invalid={!!e['no-item']}>
                            <SelectValue placeholder="Choose" />
                        </SelectTrigger>
                        <SelectContent>
                            {options.map((x) => (
                                <SelectItem key={x.id} value={x.id}>
                                    {x.med} {x.strength} — {PEOPLE[x.pid].pref}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <InputError message={e['no-item']} />
                </div>
                {i ? (
                    <div className="sm:col-span-2">
                        <KV rows={[['On hand', `${unitLabel(i, onHand(i, s.rt))}${daysOfSupply(i, s.rt) != null ? ` · ${daysOfSupply(i, s.rt)} days’ supply` : ''}`], ['Suggested', `${unitLabel(i, suggest)} — ${i.perDay ? '28 days of regular doses' : 'twice the reorder level'}`]]} />
                    </div>
                ) : null}
                <div className="space-y-1.5">
                    <Label htmlFor="no-qty">
                        How many <Req />
                    </Label>
                    <Input id="no-qty" value={qty} inputMode="numeric" aria-invalid={!!e['no-qty']} onChange={(ev) => (setQty(ev.target.value.replace(/[^\d]/g, '')), setE({}))} />
                    <InputError message={e['no-qty']} />
                </div>
                <div className="space-y-1.5">
                    <Label htmlFor="no-needed">Needed by (optional)</Label>
                    <Input id="no-needed" value={needed} placeholder="e.g. Wed 30 Sep" onChange={(ev) => setNeeded(ev.target.value)} />
                </div>
                <div className="space-y-1.5 sm:col-span-2">
                    <Label htmlFor="no-note">Note for the pharmacy (optional)</Label>
                    <Textarea id="no-note" rows={2} value={note} placeholder="e.g. Blister-pack with the other morning tablets" onChange={(ev) => setNote(ev.target.value)} />
                </div>
            </div>
            {open ? (
                <Notice tone="warning" title={`${open.id} is already ${ORDER_STATE[open.state].label.toLowerCase()}`}>
                    {open.state === 'draft' ? 'Send that draft instead of ordering again.' : 'Order again only if more is needed on top of it.'}
                </Notice>
            ) : null}
            <p className="text-caption">A new order is a draft until it’s sent. Controlled medicines are ordered with a prescription through the controlled register.</p>
        </Modal>
    );
}

/* ═════════════ The order: its lifecycle, one record (Q4) ═════════════ */
const STEPS: { key: string; label: string; states: PharmacyOrder['state'][] }[] = [
    { key: 'draft', label: 'Draft', states: ['draft'] },
    { key: 'sent', label: 'Sent', states: ['sent'] },
    { key: 'dispensed', label: 'Dispensed', states: ['dispensed'] },
    { key: 'received', label: 'Received', states: ['part', 'received', 'closedShort'] },
];
export function OrderDialog({ id, onClose, onAction, returnFocus }: { id: string; onClose: () => void; onAction: (spec: string) => void; returnFocus?: () => HTMLElement | null }) {
    const s = useStore();
    const p = s.route.persona;
    const me = PERSONAS[p];
    const o = allOrders(s.rt).find((x) => x.id === id)!;
    const i = itemOf(o.itemId);
    const manage = PERSONAS[p].perms.includes('stock.update');
    const receive = PERSONAS[p].perms.includes('stock.receive');
    const at = STEPS.findIndex((x) => x.states.includes(o.state));
    function send() {
        if (s.route.scenario === 'offline') return s.toast('warning', cantSaveOffline);
        s.update((rt) => ({ ...rt, orders: { ...rt.orders, [o.id]: { ...rt.orders[o.id], state: 'sent', sentAt: stamp() } } }));
        s.toast('success', `${o.id} sent to ${o.pharmacy}.`);
        onClose();
    }
    return (
        <Modal
            width={900}
            title={`${o.id} — ${i.med}, ${PEOPLE[i.pid].pref}`}
            description={`${unitLabel(i, o.qty)} · ${o.pharmacy} · created ${o.createdAt} by ${o.createdBy}`}
            onClose={onClose}
            onCloseAutoFocus={restore(returnFocus)}
            footer={
                <>
                    <Button variant="outline" onClick={onClose}>
                        Close
                    </Button>
                    {(o.state === 'draft' || o.state === 'sent') && manage ? (
                        <Button variant="outline" onClick={() => onAction(`cancel:${o.id}`)}>
                            Cancel the order
                        </Button>
                    ) : null}
                    {o.state === 'part' && manage ? (
                        <Button variant="outline" onClick={() => onAction(`short:${o.id}`)}>
                            Close it short
                        </Button>
                    ) : null}
                    {o.state === 'draft' && manage ? <Button onClick={send}>Send to the pharmacy</Button> : null}
                    {o.state === 'sent' && manage ? <Button onClick={() => onAction(`dispensed:${o.id}`)}>Record what they dispensed</Button> : null}
                    {receivable(o) && receive && !(o.cd && !manage) ? <Button onClick={() => onAction(`receive:${o.id}`)}>{o.cd ? 'Receive into the register' : 'Receive it'}</Button> : null}
                </>
            }
        >
            <ol className="grid grid-cols-4 gap-2" aria-label="Order progress">
                {STEPS.map((x, n) => (
                    <li key={x.key} className={`rounded-lg border px-3 py-2 text-[12.5px] ${n === at ? 'border-primary bg-primary/10 font-semibold' : n < at ? 'text-foreground' : 'text-muted-foreground'}`} aria-current={n === at ? 'step' : undefined}>
                        {n < at ? <Check className="mr-1 inline size-3.5" aria-hidden="true" /> : null}
                        {x.label}
                    </li>
                ))}
            </ol>
            <div className="flex flex-wrap items-center gap-2">
                <OrderStateBadge state={o.state} />
                {['received', 'closedShort', 'cancelled'].includes(o.state) ? <span className="text-caption">Read only — kept</span> : null}
            </div>
            <KV
                rows={[
                    ['Medicine', `${i.med} ${i.strength} · ${PEOPLE[i.pid].legal}`],
                    ['Ordered', `${unitLabel(i, o.qty)}${o.neededBy ? ` · needed by ${o.neededBy}` : ''}`],
                    ['Sent', o.sentAt ?? 'Not sent yet'],
                    ['Dispensed', o.dispensed ? `${unitLabel(i, o.dispensed.qty)} · batch ${o.dispensed.batch ?? 'not printed'} · expires ${o.dispensed.expiry ?? 'not printed'} · ${o.dispensed.at} (recorded by ${o.dispensed.recordedBy})` : 'Not yet'],
                    ['Received', o.receipts?.length ? o.receipts.map((r) => `${unitLabel(i, r.qty)} ${r.at} by ${r.by}`).join(' · ') : 'Not yet'],
                    ...(o.state === 'part' ? ([['Still to come', `${unitLabel(i, o.stillToCome!)} — expected ${o.expected}`]] as [string, string][]) : []),
                    ...(o.ended ? ([[o.state === 'cancelled' ? 'Cancelled' : 'Closed short', `${o.ended.at} by ${o.ended.by}: “${o.ended.reason}”`]] as [string, string][]) : []),
                ]}
            />
            {o.state === 'draft' ? <p className="text-caption">Not sent — {me.name === o.createdBy ? 'you drafted it' : `${o.createdBy} drafted it`}. Sending emails the order to {o.pharmacy}.</p> : null}
        </Modal>
    );
}

/* ═════════════ What the pharmacy dispensed (P04’s DispenseDialog, moved here — Q4) ═════════════ */
export function DispensedDialog({ id, onClose, returnFocus }: { id: string; onClose: () => void; returnFocus?: () => HTMLElement | null }) {
    const s = useStore();
    const me = PERSONAS[s.route.persona];
    const o = allOrders(s.rt).find((x) => x.id === id)!;
    const i = itemOf(o.itemId);
    const [batch, setBatch] = useState('');
    const [noBatch, setNoBatch] = useState(false);
    const [expiry, setExpiry] = useState('');
    const [noExpiry, setNoExpiry] = useState(false);
    const [qty, setQty] = useState(String(o.qty));
    const [at, setAt] = useState(NOW_LOCAL);
    const [when, setWhen] = useState('');
    const [e, setE] = useState<Record<string, string>>({});
    function save() {
        const x: Record<string, string> = {};
        if (!noBatch && !batch.trim()) x['ds-batch'] = 'Enter the batch from the label, or tick “Not printed”.';
        const ex = parseExpiry(expiry);
        if (!noExpiry && !ex) x['ds-exp'] = 'Enter the expiry as month/year, e.g. 02/2027 — or tick “Not printed”.';
        if (!(Number(qty) > 0)) x['ds-qty'] = 'Enter how many they dispensed.';
        if (!when.trim()) x['ds-when'] = 'Say when it’s due to arrive.';
        setE(x);
        if (Object.keys(x).length) return focusFirst(x);
        if (s.route.scenario === 'offline') return setE({ 'ds-batch': cantSaveOffline });
        s.update((rt) => ({ ...rt, orders: { ...rt.orders, [o.id]: { ...rt.orders[o.id], state: 'dispensed', dispensed: { batch: noBatch ? null : batch.trim().toUpperCase(), expiry: noExpiry ? null : ex!.label, qty: Number(qty), at: stamp(), recordedBy: me.name }, expected: when.trim() } } }));
        s.toast('success', `${o.id}: dispensing recorded — it’s now in “To receive”.`);
        onClose();
    }
    return (
        <Modal width={720} title={`What the pharmacy dispensed — ${o.id}`} description={`${i.med} ${i.strength} for ${PEOPLE[i.pid].pref} · ordered ${unitLabel(i, o.qty)}`} onClose={onClose} onCloseAutoFocus={restore(returnFocus)} footer={<><Button variant="outline" onClick={onClose}>Cancel</Button><Button onClick={save}>Save</Button></>}>
            <p className="text-sm">From the pharmacy’s dispensing label or delivery note. Staff check the real packs against this when they receive them.</p>
            <div className="grid gap-3 sm:grid-cols-3">
                <div className="space-y-1.5">
                    <Label htmlFor="ds-batch">
                        Batch <Req />
                    </Label>
                    <Input id="ds-batch" value={batch} disabled={noBatch} aria-invalid={!!e['ds-batch']} onChange={(ev) => (setBatch(ev.target.value), setE({}))} />
                    <label className="flex items-center gap-2 text-[12.5px]">
                        <Checkbox checked={noBatch} onCheckedChange={(c) => (setNoBatch(!!c), setBatch(''), setE({}))} />
                        Not printed
                    </label>
                    <InputError message={e['ds-batch']} />
                </div>
                <div className="space-y-1.5">
                    <Label htmlFor="ds-exp">
                        Expiry <Req />
                    </Label>
                    <Input id="ds-exp" value={expiry} disabled={noExpiry} placeholder="MM/YYYY" aria-invalid={!!e['ds-exp']} onChange={(ev) => (setExpiry(ev.target.value), setE({}))} />
                    <label className="flex items-center gap-2 text-[12.5px]">
                        <Checkbox checked={noExpiry} onCheckedChange={(c) => (setNoExpiry(!!c), setExpiry(''), setE({}))} />
                        Not printed
                    </label>
                    <InputError message={e['ds-exp']} />
                </div>
                <div className="space-y-1.5">
                    <Label htmlFor="ds-qty">
                        How many <Req />
                    </Label>
                    <Input id="ds-qty" value={qty} inputMode="numeric" aria-invalid={!!e['ds-qty']} onChange={(ev) => (setQty(ev.target.value.replace(/[^\d]/g, '')), setE({}))} />
                    <InputError message={e['ds-qty']} />
                </div>
            </div>
            <DateTimeField id="ds-at" label="Dispensed" value={at} onChange={setAt} />
            <div className="space-y-1.5">
                <Label htmlFor="ds-when">
                    Due to arrive <Req />
                </Label>
                <Input id="ds-when" value={when} aria-invalid={!!e['ds-when']} placeholder="e.g. today by 12:00 pm" onChange={(ev) => (setWhen(ev.target.value), setE({}))} />
                <InputError message={e['ds-when']} />
            </div>
            {Number(qty) > 0 && Number(qty) < o.qty ? <Notice tone="info" title={`${o.qty - Number(qty)} fewer than ordered`}>When it arrives, staff say whether the rest is still to come or the order closes short.</Notice> : null}
        </Modal>
    );
}

/* ═════════════ Cancel an order / close it short (reasons kept — Q4) ═════════════ */
export function EndOrderDialog({ id, mode, onClose, returnFocus }: { id: string; mode: 'cancel' | 'short'; onClose: () => void; returnFocus?: () => HTMLElement | null }) {
    const s = useStore();
    const me = PERSONAS[s.route.persona];
    const o = allOrders(s.rt).find((x) => x.id === id)!;
    const i = itemOf(o.itemId);
    const [why, setWhy] = useState('');
    const [note, setNote] = useState('');
    const [confirm, setConfirm] = useState(false);
    const [e, setE] = useState<Record<string, string>>({});
    const tiles =
        mode === 'cancel'
            ? [
                  { key: 'The prescriber changed or stopped it', label: 'The prescriber changed or stopped it', description: 'Wait for the new order', icon: Stethoscope },
                  { key: 'Ordered by mistake', label: 'Ordered by mistake', description: 'Wrong medicine, person or quantity', icon: CircleSlash },
                  { key: 'Enough stock came from elsewhere', label: 'Enough stock came from elsewhere', description: 'Brought in, or came back', icon: RefreshCw },
                  { key: 'Other', label: 'Other', description: 'Say what happened', icon: FileText },
              ]
            : [
                  { key: 'The pharmacy is out of stock', label: 'The pharmacy is out of stock', description: 'Ask the prescriber about an alternative', icon: PackageX },
                  { key: 'The prescriber changed or stopped it', label: 'The prescriber changed or stopped it', description: 'The rest isn’t needed', icon: Stethoscope },
                  { key: 'Other', label: 'Other', description: 'Say what happened', icon: FileText },
              ];
    function check() {
        const x: Record<string, string> = {};
        if (!why) x['eo-why'] = 'Choose why.';
        if (why === 'Other' && !note.trim()) x['eo-note'] = 'Say what happened.';
        setE(x);
        if (Object.keys(x).length) return focusFirst(x);
        if (s.route.scenario === 'offline') return setE({ 'eo-why': cantSaveOffline });
        setConfirm(true);
    }
    function commit() {
        const reason = why === 'Other' ? note.trim() : `${why}${note.trim() ? ` — ${note.trim()}` : ''}`;
        s.update((rt) => ({ ...rt, orders: { ...rt.orders, [o.id]: { ...rt.orders[o.id], state: mode === 'cancel' ? 'cancelled' : 'closedShort', ended: { reason, by: me.name, at: stamp() } } } }));
        s.toast('success', mode === 'cancel' ? `${o.id} is cancelled${o.state === 'sent' ? ` — tell ${o.pharmacy}` : ''}. The reason is kept on the order.` : `${o.id} is closed short. The reason is kept on the order.`);
        onClose();
    }
    return (
        <>
            <Modal width={720} title={`${mode === 'cancel' ? 'Cancel' : 'Close short'} ${o.id} — ${i.med}, ${PEOPLE[i.pid].pref}`} description={`${ORDER_STATE[o.state].label} · ${unitLabel(i, o.qty)} from ${o.pharmacy}`} onClose={onClose} onCloseAutoFocus={restore(returnFocus)} footer={<><Button variant="outline" onClick={onClose}>Keep the order</Button><Button variant="destructive" onClick={check}>{mode === 'cancel' ? 'Cancel the order' : 'Close it short'}</Button></>}>
                <div className="space-y-2">
                    <Label id="eo-why-l">
                        Why? <Req />
                    </Label>
                    <div id="eo-why" tabIndex={-1}>
                        <TilePicker labelledBy="eo-why-l" value={why || null} invalid={!!e['eo-why']} onChange={(k) => (setWhy(k), setE({}))} tiles={tiles} />
                    </div>
                    <InputError message={e['eo-why']} />
                </div>
                <div className="space-y-1.5">
                    <Label htmlFor="eo-note">Note {why === 'Other' ? <Req /> : <span className="text-subtle">(optional)</span>}</Label>
                    <Textarea id="eo-note" rows={2} value={note} aria-invalid={!!e['eo-note']} onChange={(ev) => (setNote(ev.target.value), setE({}))} />
                    <InputError message={e['eo-note']} />
                </div>
                {mode === 'cancel' && o.state === 'sent' ? <Notice tone="info" icon={Ban} title="It’s already with the pharmacy">Cancelling here records it; tell {o.pharmacy} too.</Notice> : null}
            </Modal>
            <ConfirmDialog
                open={confirm}
                onClose={() => setConfirm(false)}
                onConfirm={() => {
                    setConfirm(false);
                    commit();
                }}
                title={mode === 'cancel' ? `Cancel ${o.id}?` : `Close ${o.id} short?`}
                description={mode === 'cancel' ? 'It can’t be sent or received after this. The order and its reason are kept.' : `The ${o.stillToCome} still to come won’t be received against it. The order and its reason are kept.`}
                confirmText={mode === 'cancel' ? 'Cancel the order' : 'Close it short'}
                cancelText="Keep it"
            />
        </>
    );
}
