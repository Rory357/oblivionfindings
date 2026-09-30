/* Preview state (the P03 / P08a / P01 pattern): the hash route (page, persona,
 * scenario, open dialog) plus what was recorded during the session. Records
 * survive persona switches and reset on reload or when the scenario changes.
 * Nothing is sent anywhere. */
import { createContext, useCallback, useContext, useEffect, useState, type ReactNode } from 'react';
import { PERSONAS, type PersonaId } from './data';
import { setSharedProps } from './inertia-shim';
import { EMPTY_RT, SCENARIOS, type Runtime, type Scenario } from './model';

export interface Route {
    path: string;
    q: URLSearchParams;
    persona: PersonaId;
    scenario: Scenario;
}
const DEFAULT = '/emar/prescriptions?as=lead';
function parse(): Route {
    const raw = window.location.hash.replace(/^#/, '') || DEFAULT;
    const [path, query = ''] = raw.split('?');
    const q = new URLSearchParams(query);
    const persona = (q.get('as') as PersonaId) || 'sw';
    const scenario = (q.get('scn') as Scenario) || 'normal';
    return {
        path: path || '/emar/prescriptions',
        q,
        persona: PERSONAS[persona] ? persona : 'sw',
        scenario: SCENARIOS.some((s) => s.key === scenario) ? scenario : 'normal',
    };
}
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

export interface Store {
    route: Route;
    go: (path: string, params?: Record<string, string | undefined>) => void;
    /** Merge params into the current route. */
    set: (params: Record<string, string | undefined>) => void;
    setViewer: (patch: Partial<Pick<Route, 'persona' | 'scenario'>>) => void;
    rt: Runtime;
    update: (fn: (rt: Runtime) => Runtime) => void;
    toast: (kind: 'success' | 'warning' | 'critical' | 'info', msg: string) => void;
    toasts: { id: number; kind: string; msg: string }[];
}
const StoreCtx = createContext<Store | null>(null);
export const useStore = () => useContext(StoreCtx)!;

export function StoreProvider({ children }: { children: ReactNode }) {
    const [route, setRoute] = useState<Route>(parse);
    const [rt, setRt] = useState<Runtime>(EMPTY_RT);
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
        setSharedProps({ auth: { user: { id: 10, name: p.name, email: `${p.id}@example.test` } } });
    }, [route.persona]);

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
        (patch: Partial<Pick<Route, 'persona' | 'scenario'>>) => {
            const q = new URLSearchParams(route.q);
            q.delete('open');
            const persona = patch.persona ?? route.persona;
            const scenario = patch.scenario ?? route.scenario;
            if (persona === 'sw') q.delete('as');
            else q.set('as', persona);
            if (scenario === 'normal') q.delete('scn');
            else q.set('scn', scenario);
            if (patch.scenario) setRt(EMPTY_RT);
            const s = q.toString();
            window.location.hash = `#${route.path}${s ? `?${s}` : ''}`;
        },
        [route],
    );

    const store: Store = { route, go, set, setViewer, rt, update: (fn) => setRt((x) => fn(x)), toast: pushToast, toasts };
    return <StoreCtx.Provider value={store}>{children}</StoreCtx.Provider>;
}
