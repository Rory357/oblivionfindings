/* Preview state: the hash route (page, persona, scenario, open dialog) plus
 * in-memory records made during the session. Records survive persona switches
 * (so a manager's approval or a colleague's "I was there" shows up for the
 * support worker) and reset on reload. Nothing is sent anywhere. */
import {
    createContext,
    useCallback,
    useContext,
    useEffect,
    useMemo,
    useState,
    type ReactNode,
} from 'react';
import { NOW_MIN } from './clock';
import {
    type AllergyRule,
    type Ctx,
    type DoseState,
    type Outcome,
    type Scenario,
    SCENARIOS,
    asOrdered,
    requirementsFor,
    windowState,
} from './contract';
import {
    DOSES,
    EXTRA_DOSES,
    FOLLOW_UPS,
    PERSONAS,
    SEEDED,
    type Dose,
    type FollowUp,
    type PersonaId,
    type Recorded,
} from './data';
import { setSharedProps } from './inertia-shim';

/* ───────────── route ───────────── */
export interface Route {
    path: string;
    q: URLSearchParams;
    persona: PersonaId;
    scenario: Scenario;
    allergyRule: AllergyRule;
}
function parse(): Route {
    const raw = window.location.hash.replace(/^#/, '') || '/meds/today';
    const [path, query = ''] = raw.split('?');
    const q = new URLSearchParams(query);
    const persona = (q.get('as') as PersonaId) || 'sw';
    const scenario = (q.get('scn') as Scenario) || 'normal';
    return {
        path: path || '/meds/today',
        q,
        persona: PERSONAS[persona] ? persona : 'sw',
        scenario: SCENARIOS.some((s) => s.key === scenario) ? scenario : 'normal',
        allergyRule: q.get('allergy') === 'confirm' ? 'confirm' : 'warn',
    };
}
/** Build a hash for a path, carrying the viewer's persona / scenario / allergy rule. */
export function hrefFor(path: string, params: Record<string, string | undefined> = {}, keep?: Route) {
    const q = new URLSearchParams();
    const [p, existing] = path.split('?');
    if (existing) new URLSearchParams(existing).forEach((v, k) => q.set(k, v));
    Object.entries(params).forEach(([k, v]) => (v == null ? q.delete(k) : q.set(k, v)));
    if (keep) {
        if (!q.has('as') && keep.persona !== 'sw') q.set('as', keep.persona);
        if (!q.has('scn') && keep.scenario !== 'normal') q.set('scn', keep.scenario);
        if (!q.has('allergy') && keep.allergyRule !== 'warn') q.set('allergy', keep.allergyRule);
    }
    const s = q.toString();
    return `#${p}${s ? `?${s}` : ''}`;
}

/* ───────────── runtime records ───────────── */
export interface RuntimeRecord extends Recorded {
    second?: { kind: 'witness' | 'countersign' | 'amount'; name: string; forgot: boolean; answer?: 'yes' | 'no' };
}
export interface PrnRecord {
    id: string;
    orderId: string;
    at: string;
    amount: string;
    reason: string;
    by: string;
    state: 'recorded' | 'queued' | 'rejected';
    checkBy: string;
}
export interface Store {
    route: Route;
    go: (path: string, params?: Record<string, string | undefined>) => void;
    setViewer: (patch: Partial<Pick<Route, 'persona' | 'scenario' | 'allergyRule'>>) => void;
    ctx: Ctx;
    records: Record<string, RuntimeRecord>;
    sending: Record<string, boolean>;
    flags: Record<string, 'queued' | 'rejected' | 'uncertain'>;
    prnRecords: PrnRecord[];
    followUps: FollowUp[];
    override: Ctx['override'];
    overrideDecline: string;
    clockedIn: boolean;
    offlineRefusedDismissed: boolean;
    handoverNoted: Record<string, boolean>;
    setRecord: (id: string, r: RuntimeRecord | null) => void;
    setSending: (id: string, on: boolean) => void;
    setFlag: (id: string, f: 'queued' | 'rejected' | 'uncertain' | null) => void;
    addPrn: (r: PrnRecord) => void;
    addFollowUp: (f: FollowUp) => void;
    setOverride: (s: Ctx['override'], decline?: string) => void;
    clockIn: () => void;
    dismissOfflineRefused: () => void;
    answerFallback: (doseId: string, answer: 'yes' | 'no') => void;
    toast: (kind: 'success' | 'warning' | 'critical' | 'info', msg: string) => void;
    toasts: { id: number; kind: string; msg: string }[];
    stateOf: (d: Dose) => DoseState;
    recordOf: (d: Dose) => RuntimeRecord | null;
    visibleDoses: () => Dose[];
}
const StoreCtx = createContext<Store | null>(null);
export const useStore = () => useContext(StoreCtx)!;

export function StoreProvider({ children }: { children: ReactNode }) {
    const [route, setRoute] = useState<Route>(parse);
    const [records, setRecords] = useState<Record<string, RuntimeRecord>>({});
    const [sending, setSendingState] = useState<Record<string, boolean>>({});
    const [flags, setFlags] = useState<Record<string, 'queued' | 'rejected' | 'uncertain'>>({});
    const [prnRecords, setPrn] = useState<PrnRecord[]>([]);
    const [followUps, setFollowUps] = useState<FollowUp[]>(FOLLOW_UPS);
    const [override, setOverrideState] = useState<Ctx['override']>('none');
    const [overrideDecline, setDecline] = useState('');
    const [clockedIn, setClockedIn] = useState(false);
    const [offlineRefusedDismissed, setOrd] = useState(false);
    const [handoverNoted] = useState<Record<string, boolean>>({});
    const [toasts, setToasts] = useState<{ id: number; kind: string; msg: string }[]>([]);

    useEffect(() => {
        const on = () => setRoute(parse());
        window.addEventListener('hashchange', on);
        const onToast = (e: Event) => pushToast('info', (e as CustomEvent<string>).detail);
        window.addEventListener('preview:toast', onToast);
        return () => {
            window.removeEventListener('hashchange', on);
            window.removeEventListener('preview:toast', onToast);
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    useEffect(() => {
        const p = PERSONAS[route.persona];
        setSharedProps({ auth: { user: { id: p.userId, name: p.name, email: `${p.id}@example.test` } }, calendarFeedUrl: null });
    }, [route.persona]);

    const pushToast = useCallback((kind: string, msg: string) => {
        const id = Date.now() + Math.random();
        setToasts((t) => [...t, { id, kind, msg }]);
        window.setTimeout(() => setToasts((t) => t.filter((x) => x.id !== id)), 6500);
    }, []);

    const go = useCallback(
        (path: string, params: Record<string, string | undefined> = {}) => {
            window.location.hash = hrefFor(path, params, route);
        },
        [route],
    );
    const setViewer = useCallback(
        (patch: Partial<Pick<Route, 'persona' | 'scenario' | 'allergyRule'>>) => {
            const q = new URLSearchParams(route.q);
            q.delete('open');
            const persona = patch.persona ?? route.persona;
            const scenario = patch.scenario ?? route.scenario;
            const allergy = patch.allergyRule ?? route.allergyRule;
            if (persona === 'sw') q.delete('as');
            else q.set('as', persona);
            if (scenario === 'normal') q.delete('scn');
            else q.set('scn', scenario);
            if (allergy === 'warn') q.delete('allergy');
            else q.set('allergy', allergy);
            if (patch.scenario) {
                setRecords({});
                setFlags({});
                setSendingState({});
                setPrn([]);
                setFollowUps(FOLLOW_UPS);
                setOverrideState('none');
                setClockedIn(false);
                setOrd(false);
            }
            const s = q.toString();
            window.location.hash = `#${route.path}${s ? `?${s}` : ''}`;
        },
        [route],
    );

    const ctx: Ctx = useMemo(
        () => ({ persona: route.persona, scenario: route.scenario, allergyRule: route.allergyRule, clockedIn, override }),
        [route.persona, route.scenario, route.allergyRule, clockedIn, override],
    );

    const recordOf = useCallback(
        (d: Dose): RuntimeRecord | null => {
            if (records[d.id]) return records[d.id];
            if (route.scenario === 'empty') return null;
            return SEEDED[d.id] ?? null;
        },
        [records, route.scenario],
    );
    const stateOf = useCallback(
        (d: Dose): DoseState => {
            if (sending[d.id]) return 'sending';
            if (flags[d.id]) return flags[d.id];
            const r = recordOf(d);
            if (r) return r.outcome as Outcome;
            if (d.support === 'independent') return 'selfmanaged';
            return windowState(d.slotMin, NOW_MIN);
        },
        [sending, flags, recordOf],
    );
    const visibleDoses = useCallback(() => {
        if (route.scenario === 'empty') return [];
        const extra = route.scenario === 'notOnShift' ? EXTRA_DOSES.filter((d) => d.pid === 'hine') : route.scenario === 'siteNotApproved' ? EXTRA_DOSES.filter((d) => d.pid === 'ben') : [];
        return [...DOSES, ...extra].map((d) => asOrdered(d, route.scenario));
    }, [route.scenario]);

    const store: Store = {
        route,
        go,
        setViewer,
        ctx,
        records,
        sending,
        flags,
        prnRecords,
        followUps,
        override,
        overrideDecline,
        clockedIn,
        offlineRefusedDismissed,
        handoverNoted,
        setRecord: (id, r) =>
            setRecords((x) => {
                const n = { ...x };
                if (r) n[id] = r;
                else delete n[id];
                return n;
            }),
        setSending: (id, on) => setSendingState((x) => ({ ...x, [id]: on })),
        setFlag: (id, f) =>
            setFlags((x) => {
                const n = { ...x };
                if (f) n[id] = f;
                else delete n[id];
                return n;
            }),
        addPrn: (r) => setPrn((x) => [r, ...x]),
        addFollowUp: (f) => setFollowUps((x) => [f, ...x.filter((y) => y.id !== f.id)]),
        setOverride: (s, decline) => {
            setOverrideState(s);
            if (decline != null) setDecline(decline);
        },
        clockIn: () => setClockedIn(true),
        dismissOfflineRefused: () => setOrd(true),
        answerFallback: (doseId, answer) =>
            setRecords((x) => {
                const r = x[doseId];
                if (!r?.second) return x;
                return { ...x, [doseId]: { ...r, second: { ...r.second, answer } } };
            }),
        toast: pushToast,
        toasts,
        stateOf,
        recordOf,
        visibleDoses,
    };
    return <StoreCtx.Provider value={store}>{children}</StoreCtx.Provider>;
}

/* ───────────── shared counts — one schedule, one set of numbers (EM-01) ───────────── */
export function useCounts() {
    const s = useStore();
    // Every persona in this preview holds controlled-medicine view access, so no
    // controlled rows are concealed here (concealment is the P00 contract).
    const doses = s.visibleDoses();
    const st = doses.map((d) => ({ d, st: s.stateOf(d) }));
    const open = (x: DoseState) => x === 'due' || x === 'late';
    const blocked = (d: Dose) => {
        const r = requirementsFor(d, s.ctx);
        return !!(r.blockAll || r.blockGiven);
    };
    const due = st.filter((x) => x.st === 'due');
    const late = st.filter((x) => x.st === 'late');
    const needsHelp = st.filter((x) => open(x.st) && blocked(x.d));
    const recordedN = st.filter((x) => ['given', 'prompted', 'assisted', 'reoffered', 'refused', 'withheld', 'away'].includes(x.st)).length;
    const denom = st.filter((x) => x.st !== 'notdue' && x.st !== 'selfmanaged').length;
    return { st, due, late, needsHelp, recordedN, denom };
}
