/* P06 rules — lot-level stock (Main: EM-10), first-expiry-first-out, days of
 * supply, blind counts, reasoned movements and one supply record per pharmacy
 * order. Decisions: Main’s answers under Stephan’s delegation, 30 September
 * (README Q1–Q10), P11 v5 (stock settings are “Default — not yet reviewed”,
 * Q5) and P07a (controlled counts and movements stay there). Synthetic only. */
import { TODAY } from './clock';
import { COUNTS, ITEMS, MOVEMENTS, ORDERS, PEOPLE, PERSONAS, PHOTOS, type Count, type Lot, type Movement, type PersonaId, type Photo, type PharmacyOrder, type StockItem } from './data';

/* ───────────── viewer scenarios (mockup only) ───────────── */
export type Scenario = 'normal' | 'loading' | 'empty' | 'unavailable' | 'stale' | 'offline';
export const SCENARIOS: { key: Scenario; label: string }[] = [
    { key: 'normal', label: 'Monday 9:12 am (normal)' },
    { key: 'loading', label: 'Loading' },
    { key: 'empty', label: 'No stock yet' },
    { key: 'unavailable', label: 'Couldn’t load' },
    { key: 'stale', label: 'Out of date' },
    { key: 'offline', label: 'Offline' },
];

/* ───────────── settings (P11 v5 Q5: defaults shown as not yet reviewed) ───────────── */
export const SETTINGS = {
    lowDays: 7,
    expiryWarn: 30,
    expiryCritical: 7,
    scheduledCounts: false,
    reviewed: false,
};

/* ───────────── runtime (what the preview records; survives persona switches) ───────────── */
export interface Runtime {
    lots: Record<string, Lot[]>;
    orders: Record<string, Partial<PharmacyOrder>>;
    newOrders: PharmacyOrder[];
    movements: Movement[];
    counts: Count[];
    signOffs: Record<string, Count['signOff']>;
    photos: Photo[];
    closedMoves: string[];
}
export const EMPTY_RT: Runtime = { lots: {}, orders: {}, newOrders: [], movements: [], counts: [], signOffs: {}, photos: [], closedMoves: [] };

/* ───────────── who can do what (Main, Q5) ───────────── */
const can = (p: PersonaId, k: Parameters<typeof PERSONAS.sw.perms.includes>[0]) => PERSONAS[p].perms.includes(k);
/** Receive a delivery, photograph the pack, record going out / coming back, count: staff who give medicines (new key medications.stock.receive). */
export const canReceive = (p: PersonaId) => can(p, 'stock.receive');
/** Orders, adjustments and removals, count sign-off: medications.stock.update (house leads, managers). */
export const canManage = (p: PersonaId) => can(p, 'stock.update');
export const cdView = (p: PersonaId) => can(p, 'cd.view');
export const concealed = (i: StockItem, p: PersonaId) => !!i.cd && !cdView(p);
export const WHO_RECEIVES = 'staff who give medicines';
export const WHO_MANAGES = 'house leads and managers';

/* ───────────── dates ───────────── */
export const daysFrom = (iso: string) => Math.round((Date.parse(iso) - Date.parse(TODAY)) / 86400000);
export const expiryText = (l: Lot) => (l.expiry ? `Expires ${l.expiry}` : 'Expiry not printed on the pack');

/* ───────────── an item, as it stands now ───────────── */
export const allItems = () => ITEMS;
export const lotsOf = (i: StockItem, rt: Runtime): Lot[] => rt.lots[i.id] ?? i.lots;
export const openLots = (i: StockItem, rt: Runtime) => lotsOf(i, rt).filter((l) => l.qty > 0);
/** First expiry first out: the open lot to use next (unexpired first; “not printed” last). */
export function fefo(i: StockItem, rt: Runtime): Lot | null {
    const lots = openLots(i, rt).filter((l) => !l.expiryIso || daysFrom(l.expiryIso) >= 0);
    return [...lots].sort((a, b) => (a.expiryIso ?? '9999').localeCompare(b.expiryIso ?? '9999'))[0] ?? null;
}
export const onHand = (i: StockItem, rt: Runtime) => openLots(i, rt).filter((l) => !l.expiryIso || daysFrom(l.expiryIso) >= 0).reduce((n, l) => n + l.qty, 0);
export const unitLabel = (i: StockItem, n: number) => `${Number.isInteger(n) ? n : n.toFixed(1)} ${Math.abs(n) === 1 ? i.unit.replace(/s$/, '') : i.unit}`;
export function daysOfSupply(i: StockItem, rt: Runtime): number | null {
    if (!i.perDay) return null;
    return Math.floor(onHand(i, rt) / i.perDay);
}

export type StockState = 'selfManaged' | 'none' | 'arriving' | 'out' | 'expired' | 'low' | 'countDiff' | 'useFirst' | 'expiring' | 'ok';
export const STATE_BADGE: Record<StockState, { label: string; variant: 'critical' | 'warning' | 'info' | 'success' | 'neutral' }> = {
    out: { label: 'Out of stock', variant: 'critical' },
    expired: { label: 'Expired pack', variant: 'critical' },
    low: { label: 'Running low', variant: 'warning' },
    countDiff: { label: 'Count to sign off', variant: 'warning' },
    useFirst: { label: 'Use first', variant: 'warning' },
    expiring: { label: 'Expiring', variant: 'info' },
    arriving: { label: 'Delivery due', variant: 'info' },
    none: { label: 'No stock', variant: 'warning' },
    ok: { label: 'In stock', variant: 'success' },
    selfManaged: { label: 'Self-managed', variant: 'neutral' },
};
export interface ItemStatus {
    state: StockState;
    lines: string[];
}
export function ordersFor(i: StockItem, rt: Runtime) {
    return allOrders(rt).filter((o) => o.itemId === i.id);
}
export function statusOf(i: StockItem, rt: Runtime): ItemStatus {
    const lines: string[] = [];
    if (i.selfManaged) return { state: 'selfManaged', lines: [`${PEOPLE[i.pid].pref} manages it — not counted, and doses don’t come off stock`] };
    const open = ordersFor(i, rt).filter((o) => ['sent', 'dispensed', 'part'].includes(o.state));
    const draft = ordersFor(i, rt).find((o) => o.state === 'draft');
    const orderLine = open[0] ? (open[0].state === 'sent' ? `Ordered ${open[0].sentAt} (${open[0].id})` : open[0].state === 'dispensed' ? `Delivery due ${open[0].expected} (${open[0].id})` : `${open[0].stillToCome} still to come ${open[0].expected} (${open[0].id})`) : draft ? `Order drafted by ${draft.createdBy} — not sent yet (${draft.id})` : null;
    const expired = openLots(i, rt).filter((l) => l.expiryIso && daysFrom(l.expiryIso) < 0);
    const lots = lotsOf(i, rt);
    const have = onHand(i, rt);
    if (!lots.length || (!have && !expired.length)) {
        if (open.some((o) => o.state === 'dispensed')) return { state: 'arriving', lines: [orderLine!] };
        if (!lots.length) return { state: 'none', lines: [orderLine ?? 'Not ordered yet — order it from the pharmacy'] };
        return { state: 'out', lines: [orderLine ?? 'Order it from the pharmacy now'] };
    }
    if (expired.length) {
        lines.push(`${expired.map((l) => `${unitLabel(i, l.qty)} from ${l.batch ?? 'a pack with no batch'} expired ${l.expiry}`).join('; ')} — take out of use`);
    }
    const d = daysOfSupply(i, rt);
    const enoughUntil = i.until && d != null && d >= daysFrom(i.until.iso);
    let low = false;
    if (d != null && !enoughUntil && d < SETTINGS.lowDays) {
        low = true;
        lines.push(`${d} day${d === 1 ? '' : 's'}’ supply — below ${SETTINGS.lowDays} days`);
    } else if (i.perDay == null && i.reorderLevel != null && have <= i.reorderLevel) {
        low = true;
        lines.push(`${unitLabel(i, have)} left — at or below the reorder level (${i.reorderLevel})`);
    }
    const diff = allCounts(rt).filter(awaitingSignOff).flatMap((c) => differences(c).filter((l) => l.itemId === i.id).map((l) => ({ c, l })))[0];
    if (diff) lines.push(`Counted ${diff.l.counted}, expected ${diff.l.expected} (${diff.c.at}) — the house lead signs it off`);
    if (enoughUntil) lines.push(`Enough until ${i.until!.label}, when ${i.until!.why}`);
    if (orderLine && (low || open.length)) lines.push(orderLine);
    const next = fefo(i, rt);
    const soon = openLots(i, rt).filter((l) => l.expiryIso && daysFrom(l.expiryIso) >= 0 && daysFrom(l.expiryIso) <= SETTINGS.expiryWarn);
    const multi = openLots(i, rt).filter((l) => !l.expiryIso || daysFrom(l.expiryIso) >= 0).length > 1;
    if (next && multi && soon.includes(next)) lines.push(`Use ${next.batch ?? 'the pack with no batch'} first — ${expiryText(next).toLowerCase()} (${unitLabel(i, next.qty)})`);
    else if (soon.length) lines.push(`${soon.map((l) => `${l.batch ?? 'Pack'} ${expiryText(l).toLowerCase()}`).join('; ')}`);
    const state: StockState = expired.length ? 'expired' : low ? 'low' : diff ? 'countDiff' : next && multi && soon.includes(next) && daysFrom(next.expiryIso!) <= SETTINGS.expiryCritical ? 'useFirst' : soon.length ? 'expiring' : 'ok';
    return { state, lines };
}
export const needsAttention = (s: StockState) => !['ok', 'selfManaged'].includes(s);

/* ───────────── pharmacy orders (Q4) ───────────── */
export const allOrders = (rt: Runtime): PharmacyOrder[] => [...ORDERS, ...rt.newOrders].map((o) => ({ ...o, ...rt.orders[o.id] }) as PharmacyOrder);
export const itemOf = (id: string) => ITEMS.find((i) => i.id === id)!;
export const ORDER_STATE: Record<PharmacyOrder['state'], { label: string; variant: 'critical' | 'warning' | 'info' | 'success' | 'neutral' }> = {
    draft: { label: 'Draft', variant: 'neutral' },
    sent: { label: 'Sent', variant: 'info' },
    dispensed: { label: 'To receive', variant: 'warning' },
    part: { label: 'Part received', variant: 'warning' },
    received: { label: 'Received', variant: 'success' },
    closedShort: { label: 'Closed short', variant: 'neutral' },
    cancelled: { label: 'Cancelled', variant: 'neutral' },
};
export const receivable = (o: PharmacyOrder) => o.state === 'dispensed' || o.state === 'part';

/* ───────────── counts (Q7) ───────────── */
export const allCounts = (rt: Runtime): Count[] => [...rt.counts, ...COUNTS].map((c) => (c.id in rt.signOffs ? { ...c, signOff: rt.signOffs[c.id] } : c));
export const differences = (c: Count) => c.lines.filter((l) => l.counted !== l.expected);
export const awaitingSignOff = (c: Count) => differences(c).length > 0 && !c.signOff;

/* ───────────── movements and photos ───────────── */
export const allMovements = (rt: Runtime) => [...rt.movements, ...MOVEMENTS];
export const openOuts = (rt: Runtime) => allMovements(rt).filter((m) => m.kind === 'out' && m.open && !rt.closedMoves.includes(m.id));
export const photosOf = (i: StockItem, rt: Runtime) => [...rt.photos, ...PHOTOS].filter((p) => p.itemId === i.id);
export const REMOVAL_KINDS = ['removed', 'returned', 'damaged', 'correction'] as const;
