/* Preview state: the hash route (page, persona, scenario, open dialog) plus
 * records made during the session. Records survive persona switches (so a
 * colleague's answer or a manager's approval shows up for the support worker)
 * and reset on reload or when the scenario changes. Nothing is sent anywhere. */
import { createContext, useCallback, useContext, useEffect, useState, type ReactNode } from 'react';
import { CD_MEDS, ENTRIES, PERSONAS, type CdMed, type Entry, type HouseKey, type PersonaId } from './data';
import { setSharedProps } from './inertia-shim';
import { SCENARIOS, type Scenario } from './model';

/* ───────────── route ───────────── */
export interface Route {
    path: string;
    q: URLSearchParams;
    persona: PersonaId;
    scenario: Scenario;
}
function parse(): Route {
    const raw = window.location.hash.replace(/^#/, '') || '/meds/today?view=controlled';
    const [path, query = ''] = raw.split('?');
    const q = new URLSearchParams(query);
    const persona = (q.get('as') as PersonaId) || 'sw';
    const scenario = (q.get('scn') as Scenario) || 'normal';
    return {
        path: path || '/meds/today',
        q,
        persona: PERSONAS[persona] ? persona : 'sw',
        scenario: SCENARIOS.some((s) => s.key === scenario) ? scenario : 'normal',
    };
}
/** Build a hash for a path, carrying the viewer's persona and scenario. */
export function hrefFor(path: string, params: Record<string, string | undefined> = {}, keep?: Route) {
    const q = new URLSearchParams();
    const [p, existing] = path.split('?');
    if (existing) new URLSearchParams(existing).forEach((v, k) => q.set(k, v));
    Object.entries(params).forEach(([k, v]) => (v == null ? q.delete(k) : q.set(k, v)));
    if (keep) {
        if (!q.has('as') && keep.persona !== 'sw') q.set('as', keep.persona);
        if (!q.has('scn') && keep.scenario !== 'normal') q.set('scn', keep.scenario);
    }
    const s = q.toString();
    return `#${p}${s ? `?${s}` : ''}`;
}

/* ───────────── runtime records ───────────── */
export interface CountRecord {
    at: string;
    by: string;
    witness: string;
    result: 'matched' | 'discrepancy';
    counted: number;
    register: number;
    firstCount?: number;
}
export interface Discrepancy {
    id: string;
    ref: string;
    cdMed: string;
    startedAt: string;
    by: string;
    witness: string;
    register: number;
    firstCount: number;
    recount: number;
    found: string;
    did: string;
    owner: string;
}
export interface Ask {
    id: string;
    from: string;
    to: string;
    what: string;
    kind: 'count' | 'dose';
    house: HouseKey;
    at: string;
    note: string;
    status: 'sent' | 'coming' | 'cantcome' | 'cancelled' | 'done';
    answeredAt?: string;
    reason?: string;
}
export type OverrideState = 'none' | 'waiting' | 'approved' | 'declined';

const SEED_DISC: Discrepancy = {
    id: 'disc-14',
    ref: 'CD-2026-014',
    cdMed: 'cd1',
    startedAt: '7:04 am',
    by: 'Daniel Ahn',
    witness: 'Priya Shah',
    register: 28,
    firstCount: 27,
    recount: 27,
    found: 'One blister on the current strip is empty and there’s no dose on the chart for it. Checked the bin and the cupboard floor — not found.',
    did: 'Locked the cupboard, told Jordan Tipene by phone at 7:06 am, and kept the strip aside for the house lead.',
    owner: 'Jordan Tipene',
};
const SEED_ASK: Ask = { id: 'ask-1', from: 'Priya Shah', to: 'Jordan Tipene', what: 'the 3:00 pm shift-change count', kind: 'count', house: 'kowhai', at: '2:46 pm', note: 'At the medicine cupboard when you’re ready.', status: 'sent' };

export interface Store {
    route: Route;
    go: (path: string, params?: Record<string, string | undefined>) => void;
    setViewer: (patch: Partial<Pick<Route, 'persona' | 'scenario'>>) => void;
    counted: Record<string, CountRecord>;
    discrepancies: Discrepancy[];
    asks: Ask[];
    override: OverrideState;
    overrideDecline: string;
    followUp: 'open' | 'signed';
    movements: Entry[];
    balances: Record<string, number>;
    clockedIn: boolean;
    recordCounts: (recs: Record<string, CountRecord>, discs: Discrepancy[]) => void;
    addAsk: (a: Ask) => void;
    answerAsk: (id: string, status: Ask['status'], reason?: string) => void;
    setOverride: (s: OverrideState, decline?: string) => void;
    signFollowUp: () => void;
    addMovement: (e: Entry) => void;
    clockIn: () => void;
    toast: (kind: 'success' | 'warning' | 'critical' | 'info', msg: string) => void;
    toasts: { id: number; kind: string; msg: string }[];
    balanceOf: (m: CdMed) => number;
    entries: () => Entry[];
}
const StoreCtx = createContext<Store | null>(null);
export const useStore = () => useContext(StoreCtx)!;

export function StoreProvider({ children }: { children: ReactNode }) {
    const [route, setRoute] = useState<Route>(parse);
    const [counted, setCounted] = useState<Record<string, CountRecord>>({});
    const [discrepancies, setDisc] = useState<Discrepancy[]>(() => (parse().scenario === 'discrepancyOpen' ? [SEED_DISC] : []));
    const [asks, setAsks] = useState<Ask[]>(() => (['normal', 'balanceChanged'].includes(parse().scenario) ? [SEED_ASK] : []));
    const [override, setOverrideState] = useState<OverrideState>('none');
    const [overrideDecline, setDecline] = useState('');
    const [followUp, setFollowUp] = useState<'open' | 'signed'>('open');
    const [movements, setMovements] = useState<Entry[]>([]);
    const [balances, setBalances] = useState<Record<string, number>>({});
    const [clockedIn, setClockedIn] = useState(false);
    const [toasts, setToasts] = useState<{ id: number; kind: string; msg: string }[]>([]);

    const pushToast = useCallback((kind: string, msg: string) => {
        const id = Date.now() + Math.random();
        setToasts((t) => [...t, { id, kind, msg }]);
        window.setTimeout(() => setToasts((t) => t.filter((x) => x.id !== id)), 6500);
    }, []);

    useEffect(() => {
        const on = () => setRoute(parse());
        window.addEventListener('hashchange', on);
        const onToast = (e: Event) => pushToast('info', (e as CustomEvent<string>).detail);
        window.addEventListener('preview:toast', onToast);
        return () => {
            window.removeEventListener('hashchange', on);
            window.removeEventListener('preview:toast', onToast);
        };
    }, [pushToast]);

    useEffect(() => {
        const p = PERSONAS[route.persona];
        setSharedProps({ auth: { user: { id: p.userId, name: p.name, email: `${p.id}@example.test` } } });
    }, [route.persona]);

    const go = useCallback(
        (path: string, params: Record<string, string | undefined> = {}) => {
            window.location.hash = hrefFor(path, params, route);
        },
        [route],
    );
    const setViewer = useCallback(
        (patch: Partial<Pick<Route, 'persona' | 'scenario'>>) => {
            const q = new URLSearchParams(route.q);
            q.delete('open');
            const persona = patch.persona ?? route.persona;
            const scenario = patch.scenario ?? route.scenario;
            if (persona === 'sw') q.delete('as');
            else q.set('as', persona);
            if (scenario === 'normal') q.delete('scn');
            else q.set('scn', scenario);
            if (patch.scenario) {
                setCounted({});
                setDisc(scenario === 'discrepancyOpen' ? [SEED_DISC] : []);
                setAsks(['normal', 'balanceChanged'].includes(scenario) ? [SEED_ASK] : []);
                setOverrideState('none');
                setFollowUp('open');
                setMovements([]);
                setBalances({});
                setClockedIn(false);
            }
            const s = q.toString();
            window.location.hash = `#${route.path}${s ? `?${s}` : ''}`;
        },
        [route],
    );

    // "Discrepancy started at 7:04 am": the 7:00 am count found methylphenidate 1 short (27, then 27 again), the
    // register was set to 27 (as today), and the 8:06 am dose left 26.
    const disc = route.scenario === 'discrepancyOpen';
    const balanceOf = useCallback((m: CdMed) => balances[m.id] ?? (disc && m.id === 'cd1' ? 26 : m.balance), [balances, disc]);
    const entries = useCallback(
        () => [
            ...movements,
            ...ENTRIES.map((e) =>
                !disc ? e : e.id === 'e3' ? { ...e, change: -1, after: 27, detail: '7:00 am shift-change count · 1 short on the second count · discrepancy started' } : e.id === 'e2' ? { ...e, after: 26 } : e,
            ),
        ],
        [movements, disc],
    );

    const store: Store = {
        route,
        go,
        setViewer,
        counted,
        discrepancies,
        asks,
        override,
        overrideDecline,
        followUp,
        movements,
        balances,
        clockedIn,
        recordCounts: (recs, discs) => {
            setCounted((x) => ({ ...x, ...recs }));
            setDisc((x) => [...discs, ...x]);
            // The register follows what was counted, as today (EmarController storeBalanceCheck sets on_hand to the count).
            setBalances((x) => {
                const n = { ...x };
                Object.entries(recs).forEach(([id, r]) => (n[id] = r.counted));
                return n;
            });
            setMovements((x) => [
                ...Object.entries(recs).map(([id, r]) => ({
                    id: `m-${id}-${Date.now()}`,
                    cdMed: id,
                    at: r.at,
                    day: 'Today' as const,
                    kind: 'count' as const,
                    change: r.counted - r.register,
                    after: r.counted,
                    by: r.by,
                    witness: r.witness,
                    detail: r.result === 'matched' ? (r.firstCount != null ? 'Count · matches on the second count' : 'Count · matches') : `Count · ${Math.abs(r.counted - r.register)} ${r.counted < r.register ? 'short' : 'over'} · discrepancy started`,
                })),
                ...x,
            ]);
            // The shift-change count answers any open request to witness it.
            setAsks((x) => x.map((a) => (a.kind === 'count' && ['sent', 'coming'].includes(a.status) ? { ...a, status: 'done' } : a)));
        },
        addAsk: (a) => setAsks((x) => [a, ...x]),
        answerAsk: (id, status, reason) => setAsks((x) => x.map((a) => (a.id === id ? { ...a, status, reason, answeredAt: '2:48 pm' } : a))),
        setOverride: (s, decline) => {
            setOverrideState(s);
            if (decline != null) setDecline(decline);
        },
        signFollowUp: () => setFollowUp('signed'),
        addMovement: (e) => {
            setMovements((x) => [e, ...x]);
            setBalances((x) => ({ ...x, [e.cdMed]: e.after }));
        },
        clockIn: () => setClockedIn(true),
        toast: pushToast,
        toasts,
        balanceOf,
        entries,
    };
    return <StoreCtx.Provider value={store}>{children}</StoreCtx.Provider>;
}

/** Controlled medicines at the houses a persona can see (concealed entirely without controlled-medicine view). */
export function useCdMeds(houses: HouseKey[]) {
    return CD_MEDS.filter((m) => houses.includes(m.house));
}
