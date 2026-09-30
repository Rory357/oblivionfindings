/* P05 rules — medication reviews: where they live, how often, what starts one,
 * who does it and who took part, the outcome per order and the prescriber’s
 * decision before anything reaches Orders (P04), watching after a change,
 * moving and cancelling, and who can do what. Decisions: Main’s answers under
 * Stephan’s delegation, 30 September (README Q1–Q9 and the additions). */
import { has, OUTCOME_LABEL, ORG_DEFAULT, orderOf, PEOPLE, PERSONAS, REVIEWS, type Item, type PersonaId, type PersonId, type Review } from './data';

/* ───────────── viewer scenarios (mockup only) ───────────── */
export type Scenario = 'normal' | 'loading' | 'empty' | 'unavailable' | 'stale' | 'offline';
export const SCENARIOS: { key: Scenario; label: string }[] = [
    { key: 'normal', label: 'Monday 9:12 am (normal)' },
    { key: 'loading', label: 'Loading' },
    { key: 'empty', label: 'No reviews yet' },
    { key: 'unavailable', label: 'Couldn’t load' },
    { key: 'stale', label: 'Out of date' },
    { key: 'offline', label: 'Offline' },
];

/* ───────────── runtime (what the preview records; survives persona switches) ───────────── */
export interface Runtime {
    reviews: Review[];
    patch: Record<string, Partial<Review>>;
    items: Record<string, Partial<Item>>;
    intervals: Partial<Record<PersonId, number | null>>;
    org: { months: number; reviewed: boolean; by?: string; at?: string };
    history: { id: string; what: string; from: string; to: string; who: string; when: string; scope: string; area: string; fresh?: boolean }[];
    /** P11 frame: the unsaved draft of the organisation default, and the in-page message after a save. */
    orgDraft: string | null;
    flash: string | null;
}
export const EMPTY_RT: Runtime = { reviews: [], patch: {}, items: {}, intervals: {}, org: { ...ORG_DEFAULT }, history: [], orgDraft: null, flash: null };

/* ───────────── who can do what (Main, Q9) ───────────── */
/** Book, move, cancel, record the outcome and the prescriber’s decision: the new medications.reviews.manage. */
export const canManage = (p: PersonaId) => has(p, 'reviews.manage');
/** Entering an agreed change stays P04’s (orders.manage); checking it stays orders.verify. */
export const canEnter = (p: PersonaId) => has(p, 'orders.manage');
export const cdView = (p: PersonaId) => has(p, 'cd.view');
/** The clinician’s free-text summary: only people who manage reviews (Main, Q9 addition). */
export const canSeeSummary = (p: PersonaId) => canManage(p);
export const canSetOrg = (p: PersonaId) => has(p, 'settings.org');
export const WHO_MANAGES = 'house leads, clinical leads, coordinators and managers';
export const WHO_ENTERS = 'house leads, clinical leads, coordinators and managers who enter orders';

/* ───────────── dates (all NZ days — WorkerClock, Main) ───────────── */
export const TODAY_ISO = '2026-09-28';
const DAY = 86400000;
const iso = (s: string) => Date.UTC(+s.slice(0, 4), +s.slice(5, 7) - 1, +s.slice(8, 10));
/** Whole NZ days from today to the date (negative = past). */
export const daysUntil = (dueIso: string) => Math.round((iso(dueIso) - iso(TODAY_ISO)) / DAY);
const WD = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
const MO = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
export function labelIso(s: string) {
    const d = new Date(iso(s));
    const y = d.getUTCFullYear();
    return `${WD[d.getUTCDay()]} ${d.getUTCDate()} ${MO[d.getUTCMonth()]}${y !== 2026 ? ` ${y}` : ''}`;
}
/** Months after a date, without overflow (31 Jan + 1 month = 28 Feb), as the build’s addMonthsNoOverflow. */
export function addMonths(s: string, months: number) {
    const y = +s.slice(0, 4), m = +s.slice(5, 7) - 1 + months, d = +s.slice(8, 10);
    const ty = y + Math.floor(m / 12), tm = ((m % 12) + 12) % 12;
    const last = new Date(Date.UTC(ty, tm + 1, 0)).getUTCDate();
    return `${ty}-${String(tm + 1).padStart(2, '0')}-${String(Math.min(d, last)).padStart(2, '0')}`;
}

/* ───────────── reviews ───────────── */
export const itemKey = (rid: string, orderId: string) => `${rid}:${orderId}`;
export function allReviews(rt: Runtime): Review[] {
    return [...rt.reviews, ...REVIEWS].map((r) => {
        const p = rt.patch[r.id];
        const m: Review = p ? ({ ...r, ...p } as Review) : r;
        if (!m.recorded) return m;
        return { ...m, recorded: { ...m.recorded, items: m.recorded.items.map((it) => ({ ...it, ...rt.items[itemKey(m.id, it.orderId)] })) } };
    });
}
export const reviewOf = (rt: Runtime, id: string) => allReviews(rt).find((r) => r.id === id) ?? null;
export const housesOf = (p: PersonaId) => PERSONAS[p].houses;
export const inScope = (p: PersonaId, r: Review) => housesOf(p).includes(PEOPLE[r.pid].house);
export const reviewsIn = (rt: Runtime, p: PersonaId) => allReviews(rt).filter((r) => inScope(p, r));
export const nextFor = (rt: Runtime, pid: PersonId) => allReviews(rt).filter((r) => r.pid === pid && r.state === 'booked').sort((a, b) => iso(a.dueIso) - iso(b.dueIso))[0] ?? null;

/** How often this person’s regular review comes round (Q2). */
export function intervalOf(rt: Runtime, pid: PersonId): { months: number; own: boolean; set?: string } {
    const o = pid in rt.intervals ? rt.intervals[pid] : PEOPLE[pid].interval;
    if (o) return { months: o, own: true, set: pid in rt.intervals ? `${PERSONAS.lead.name}, Mon 28 Sep` : PEOPLE[pid].intervalSet };
    return { months: rt.org.months, own: false };
}

export type Tone = 'critical' | 'warning' | 'info' | 'success' | 'neutral';
/** A review’s state, in words (colour is never the only signal). */
export function reviewState(r: Review): { label: string; variant: Tone } {
    if (r.state === 'cancelled') return { label: 'Cancelled', variant: 'neutral' };
    if (r.state === 'closed') return { label: 'Closed automatically', variant: 'neutral' };
    if (r.state === 'recorded') return { label: 'Recorded', variant: 'neutral' };
    const n = daysUntil(r.dueIso);
    if (r.booked && daysUntil(r.booked.iso) <= 0) return { label: 'Outcome to record', variant: 'warning' };
    if (n < 0) return { label: `Overdue · ${-n} ${-n === 1 ? 'day' : 'days'}`, variant: 'critical' };
    if (n === 0) return { label: 'Due today', variant: 'warning' };
    if (n <= 30) return { label: `Due in ${n} ${n === 1 ? 'day' : 'days'}`, variant: 'info' };
    return { label: 'Booked', variant: 'neutral' };
}
/** “Email to Dr Lena Chen” → “email to Dr Lena Chen” — names keep their capitals. */
export const lowerFirst = (t: string) => t.charAt(0).toLowerCase() + t.slice(1);
export const kindText = (r: Review) => (r.kind === 'regular' ? 'Regular' : 'Triggered');

/* ───────────── changes (Q6, Q7) ───────────── */
export const isChange = (it: Item) => ['change', 'stop', 'start', 'swap'].includes(it.outcome);
export type ChangeStep = 'pending' | 'waiting' | 'agreed' | 'written' | 'check' | 'done' | 'notAgreed' | 'watching' | 'watched' | 'none';
export function changeState(it: Item): { step: ChangeStep; label: string; variant: Tone; open: boolean } {
    if (it.pendingCd) return { step: 'pending', label: 'Outcome to add — needs controlled-medicine access', variant: 'warning', open: true };
    if (it.outcome === 'watch') {
        if (it.watch?.done) return { step: 'watched', label: 'Watched — done', variant: 'neutral', open: false };
        return { step: 'watching', label: `Watching until ${it.watch?.until}`, variant: 'info', open: true };
    }
    if (!isChange(it)) return { step: 'none', label: OUTCOME_LABEL[it.outcome], variant: 'neutral', open: false };
    const d = it.decision;
    if (!d || d.state === 'waiting') return { step: 'waiting', label: 'Waiting for the prescriber’s decision', variant: 'warning', open: true };
    if (d.state === 'notAgreed') return { step: 'notAgreed', label: 'Not agreed by the prescriber', variant: 'neutral', open: false };
    const e = it.entered;
    if (!e) return { step: 'agreed', label: 'Agreed — to enter in Orders', variant: 'warning', open: true };
    if (e.phone && !e.phone.written) return { step: 'written', label: 'Entered — waiting for the written confirmation', variant: 'info', open: true };
    if (!e.checked) return { step: 'check', label: 'Entered — waiting to be checked', variant: 'info', open: true };
    return { step: 'done', label: `Order changed — ${e.version}`, variant: 'success', open: false };
}
export interface ChangeRow {
    review: Review;
    item: Item;
}
export function changesIn(rt: Runtime, p: PersonaId, onlyOpen = true): ChangeRow[] {
    return reviewsIn(rt, p)
        .filter((r) => r.recorded)
        .flatMap((r) => r.recorded!.items.filter((it) => it.outcome !== 'continue' || it.pendingCd).map((item) => ({ review: r, item })))
        .filter((c) => !onlyOpen || changeState(c.item).open);
}
/** P02’s redaction inside the person’s review (Main, Q9 addition). */
export const hiddenFor = (p: PersonaId, orderId: string) => !!orderOf(orderId).cd && !cdView(p);
export const medText = (p: PersonaId, orderId: string) => (hiddenFor(p, orderId) ? 'Controlled medicine' : `${orderOf(orderId).med} ${orderOf(orderId).strength}`);
