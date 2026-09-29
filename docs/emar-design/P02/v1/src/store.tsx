/* P02 preview state. The hash route carries the viewer's persona (`as`), the
 * page state (`state`), the person (`client_id`), the record section (`tab`,
 * `view`) and an open P02 dialog (`dlg`). P01's store (src/p01/store.tsx, used
 * unchanged) reads the same hash for its own dialog (`open`), so recording from
 * the chart opens P01's approved dialog and its records show on this chart.
 * Changes made in the preview live in memory and reset on reload. */
import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react';
import {
    ALERTS,
    FACTS,
    INR,
    INR_STALE,
    INR_UNLINKED,
    INR_BY_PERSON,
    P02_PERSONAS,
    type ChartAlert,
    type Correction,
    type DriverCheck,
    type InrReading,
    type PersonId,
    type PersonaId,
    has,
    personByClientId,
} from './data';
import { setSharedProps } from './p01/inertia-shim';
import { useStore as useStore01 } from './p01/store';

export type P02State = 'normal' | 'loading' | 'empty' | 'unavailable' | 'stale' | 'inrStale' | 'inrUnlinked' | 'inrTwoOrders';
export const STATES: { key: P02State; label: string; group: string }[] = [
    { key: 'normal', label: 'Normal', group: 'Page' },
    { key: 'loading', label: 'Loading', group: 'Page' },
    { key: 'empty', label: 'No medicines on the chart', group: 'Page' },
    { key: 'unavailable', label: 'Couldn’t load', group: 'Page' },
    { key: 'stale', label: 'Out of date', group: 'Page' },
    { key: 'inrStale', label: 'INR test overdue', group: 'Clinical' },
    { key: 'inrUnlinked', label: 'INR saved with no medicine linked (NF-23)', group: 'Clinical' },
    { key: 'inrTwoOrders', label: 'Two anticoagulant orders', group: 'Clinical' },
];
export type Tab = 'chart' | 'medicines' | 'support' | 'allergies' | 'clinical' | 'history';
export const VIEWS: Record<Tab, { key: string; label: string }[]> = {
    chart: [
        { key: 'scheduled', label: 'Scheduled doses' },
        { key: 'asneeded', label: 'As needed' },
    ],
    medicines: [
        { key: 'current', label: 'Current' },
        { key: 'stopped', label: 'Stopped' },
        { key: 'photos', label: 'Photos' },
    ],
    support: [
        { key: 'bymedicine', label: 'By medicine' },
        { key: 'assessment', label: 'Assessment' },
    ],
    allergies: [
        { key: 'allergies', label: 'Allergies' },
        { key: 'alerts', label: 'Chart alerts' },
        { key: 'interactions', label: 'Interactions' },
    ],
    clinical: [
        { key: 'inr', label: 'INR' },
        { key: 'driver', label: 'Syringe driver' },
        { key: 'observations', label: 'Observations' },
    ],
    history: [
        { key: 'doses', label: 'Doses' },
        { key: 'corrections', label: 'Corrections' },
        { key: 'changes', label: 'All changes' },
    ],
};

export interface Route {
    path: string;
    q: URLSearchParams;
    persona: PersonaId;
    state: P02State;
}
function parse(): Route {
    const raw = window.location.hash.replace(/^#/, '') || '/emar/mar?client_id=201';
    const [path, query = ''] = raw.split('?');
    const q = new URLSearchParams(query);
    const persona = (q.get('as') as PersonaId) || 'sw';
    const state = (q.get('state') as P02State) || 'normal';
    return {
        path: path || '/emar/mar',
        q,
        persona: P02_PERSONAS[persona] ? persona : 'sw',
        state: STATES.some((s) => s.key === state) ? state : 'normal',
    };
}
/** Build a hash for a path, keeping the viewer's persona and state. */
export function hrefFor(path: string, params: Record<string, string | undefined> = {}, keep?: Route) {
    const q = new URLSearchParams();
    const [p, existing] = path.split('?');
    if (existing) new URLSearchParams(existing).forEach((v, k) => q.set(k, v));
    Object.entries(params).forEach(([k, v]) => (v == null ? q.delete(k) : q.set(k, v)));
    if (keep) {
        if (!q.has('as') && keep.persona !== 'sw') q.set('as', keep.persona);
        if (!q.has('state') && keep.state !== 'normal') q.set('state', keep.state);
    }
    const s = q.toString();
    return `#${p}${s ? `?${s}` : ''}`;
}

export interface Store {
    route: Route;
    go: (path: string, params?: Record<string, string | undefined>) => void;
    /** Change query params on the current page (null removes). */
    set: (params: Record<string, string | undefined>) => void;
    setViewer: (patch: { persona?: PersonaId; state?: P02State }) => void;
    pid: PersonId | null;
    can: (k: Parameters<typeof has>[1]) => boolean;
    cdView: boolean;
    alerts: ChartAlert[];
    saveAlert: (a: ChartAlert) => void;
    resolveAlert: (id: string) => void;
    alertsRead: Record<string, string>;
    readAlerts: (pid: string) => void;
    paused: Record<string, { basis: string; reason: string; by: string; at: string } | null>;
    setPaused: (pid: string, v: { basis: string; reason: string } | null) => void;
    inrFor: (pid: string) => InrReading[];
    addInr: (pid: string, r: InrReading) => void;
    disableInr: (id: string, reason: string) => void;
    linkInr: (id: string, medKey: string) => void;
    corrections: Record<string, Correction>;
    setCorrection: (adminId: string, c: Correction) => void;
    reviewed: Record<string, { by: string; on: string; how: string; nkda?: boolean }>;
    review: (pid: string, how: string, nkda: boolean) => void;
    driverChecks: DriverCheck[];
    addDriverCheck: (c: DriverCheck) => void;
    driverFinished: string | null;
    finishDriver: () => void;
    toast: (kind: 'success' | 'warning' | 'critical' | 'info', msg: string) => void;
}
const Ctx = createContext<Store | null>(null);
export const useP02 = () => useContext(Ctx)!;

export function P02Provider({ children }: { children: ReactNode }) {
    const [route, setRoute] = useState<Route>(parse);
    const [alerts, setAlerts] = useState<ChartAlert[]>(ALERTS);
    const [alertsRead, setAlertsRead] = useState<Record<string, string>>({});
    const [paused, setPausedState] = useState<Store['paused']>({});
    const [extraInr, setExtraInr] = useState<(InrReading & { pid: string })[]>([]);
    const [disabled, setDisabled] = useState<Record<string, string>>({});
    const [links, setLinks] = useState<Record<string, { med: string; by: string }>>({});
    const [corrections, setCorrections] = useState<Record<string, Correction>>({});
    const [reviewed, setReviewed] = useState<Store['reviewed']>({});
    const [driverChecks, setDriverChecks] = useState<DriverCheck[]>([]);
    const [driverFinished, setDriverFinished] = useState<string | null>(null);
    const s01 = useStore01();

    useEffect(() => {
        const on = () => setRoute(parse());
        window.addEventListener('hashchange', on);
        return () => window.removeEventListener('hashchange', on);
    }, []);
    // Deferred so it lands after P01's own persona effect (its provider is the parent):
    // the real components' usePage() sees the P02 persona.
    useEffect(() => {
        const p = P02_PERSONAS[route.persona];
        const t = window.setTimeout(() => setSharedProps({ auth: { user: { id: 90 + Object.keys(P02_PERSONAS).indexOf(p.id), name: p.name, email: `${p.id}@example.test` } }, calendarFeedUrl: null }), 0);
        return () => window.clearTimeout(t);
    }, [route]);

    const me = P02_PERSONAS[route.persona];
    const pid = useMemo<PersonId | null>(() => {
        const byKey = route.q.get('client');
        if (byKey && FACTS[byKey as PersonId]) return byKey as PersonId;
        const id = Number(route.q.get('client_id') ?? (route.path.startsWith('/operations/clients/') ? route.path.split('/')[3] : '201'));
        return personByClientId(id);
    }, [route]);

    const go = useCallback((path: string, params: Record<string, string | undefined> = {}) => {
        window.location.hash = hrefFor(path, params, route);
    }, [route]);
    const set = useCallback(
        (params: Record<string, string | undefined>) => {
            const q: Record<string, string | undefined> = {};
            route.q.forEach((v, k) => (q[k] = v));
            window.location.hash = hrefFor(route.path, { ...q, ...params }, route);
        },
        [route],
    );
    const setViewer = useCallback(
        (patch: { persona?: PersonaId; state?: P02State }) => {
            const q = new URLSearchParams(route.q);
            ['dlg', 'open', 'from'].forEach((k) => q.delete(k));
            const persona = patch.persona ?? route.persona;
            const state = patch.state ?? route.state;
            if (persona === 'sw') q.delete('as');
            else q.set('as', persona);
            if (state === 'normal') q.delete('state');
            else q.set('state', state);
            const s = q.toString();
            window.location.hash = `#${route.path}${s ? `?${s}` : ''}`;
        },
        [route],
    );

    const inrFor = (p: string): InrReading[] => {
        const base = p === 'aroha' ? (route.state === 'inrStale' ? INR_STALE : route.state === 'inrUnlinked' || route.state === 'inrTwoOrders' ? INR_UNLINKED : INR) : (INR_BY_PERSON[p as PersonId] ?? []);
        return [...extraInr.filter((r) => r.pid === p), ...base].map((r) => {
            const link = !r.med ? links[r.id] : undefined;
            return {
                ...r,
                med: r.med ?? link?.med ?? null,
                unlinkedReason: link ? undefined : r.unlinkedReason,
                linkedLater: link ? `Linked by ${link.by} at 9:12 am today` : r.linkedLater,
                disabled: disabled[r.id] ? { by: me.name, reason: disabled[r.id] } : r.disabled,
            };
        });
    };
    const today = '9:12 am';
    const store: Store = {
        route,
        go,
        set,
        setViewer,
        pid,
        can: (k) => has(route.persona, k),
        cdView: has(route.persona, 'cd.view'),
        alerts,
        saveAlert: (a) => setAlerts((x) => (x.some((y) => y.id === a.id) ? x.map((y) => (y.id === a.id ? a : y)) : [a, ...x])),
        resolveAlert: (id) => setAlerts((x) => x.map((y) => (y.id === id ? { ...y, resolved: { by: me.name, on: '28 Sep 2026' } } : y))),
        alertsRead,
        readAlerts: (p) => setAlertsRead((x) => ({ ...x, [`${route.persona}:${p}`]: today })),
        paused,
        setPaused: (p, v) => setPausedState((x) => ({ ...x, [p]: v ? { ...v, by: me.name, at: `${today} today` } : null })),
        inrFor,
        addInr: (p, r) => setExtraInr((x) => [{ ...r, pid: p }, ...x]),
        disableInr: (id, reason) => setDisabled((x) => ({ ...x, [id]: reason })),
        linkInr: (id, medKey) => setLinks((x) => ({ ...x, [id]: { med: medKey, by: me.name } })),
        corrections,
        setCorrection: (adminId, c) => setCorrections((x) => ({ ...x, [adminId]: c })),
        reviewed,
        review: (p, how, nkda) => setReviewed((x) => ({ ...x, [p]: { by: me.name, on: '28 September 2026', how, nkda } })),
        driverChecks,
        addDriverCheck: (c) => setDriverChecks((x) => [c, ...x]),
        driverFinished,
        finishDriver: () => setDriverFinished(`${today} today by ${me.name}`),
        toast: s01.toast,
    };
    return <Ctx.Provider value={store}>{children}</Ctx.Provider>;
}

/* ───────────── opening P02 dialogs (`dlg=`) with focus return ───────────── */
let lastTrigger: HTMLElement | null = null;
export function useDlg() {
    const s = useP02();
    return {
        open: (spec: string) => {
            lastTrigger = document.activeElement instanceof HTMLElement ? document.activeElement : null;
            s.set({ dlg: spec });
        },
        close: () => s.set({ dlg: undefined }),
        spec: s.route.q.get('dlg'),
    };
}
export const returnFocus = () => (lastTrigger?.isConnected ? lastTrigger : null);
