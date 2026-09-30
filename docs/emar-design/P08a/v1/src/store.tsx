/* Preview state (the P07a / P01 pattern): the hash route (page, persona,
 * scenario, open dialog) plus changes made during the session. Changes survive
 * persona switches (so a colleague’s answer or a handover acknowledgement shows
 * up for everyone) and reset on reload or when the scenario changes. Nothing
 * is sent anywhere. */
import { createContext, useCallback, useContext, useEffect, useState, type ReactNode } from 'react';
import { FOLLOW_UPS, PERSONAS, type FollowUp, type PersonaId } from './data';
import { setSharedProps } from './inertia-shim';
import { SCENARIOS, ownerOf, stateOf, visible, type RuntimeFu, type Scenario } from './model';
import type { FuState } from './ui';

export interface Route {
    path: string;
    q: URLSearchParams;
    persona: PersonaId;
    scenario: Scenario;
}
function parse(): Route {
    const raw = window.location.hash.replace(/^#/, '') || '/meds/today?view=followups';
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

export interface Row {
    f: FollowUp;
    rt: RuntimeFu | undefined;
    owner: string | null;
    state: FuState;
    /** When the carried-over owner was set (the handover acknowledgement). */
    ownerSetAt?: string;
}
export interface Store {
    route: Route;
    go: (path: string, params?: Record<string, string | undefined>) => void;
    setViewer: (patch: Partial<Pick<Route, 'persona' | 'scenario'>>) => void;
    rt: Record<string, RuntimeFu>;
    acked: Record<string, { by: string; at: string }>;
    update: (id: string, patch: Partial<RuntimeFu>) => void;
    acknowledge: (handoverId: string, by: string) => void;
    toast: (kind: 'success' | 'warning' | 'critical' | 'info', msg: string) => void;
    toasts: { id: number; kind: string; msg: string }[];
    /** Every follow-up this persona may see, with its current owner and state. */
    rows: () => Row[];
    row: (id: string) => Row | null;
    /** Controlled follow-ups at the persona’s houses that their role can’t see — counted in captions, never listed (P02 rule). */
    concealed: () => Row[];
}
const StoreCtx = createContext<Store | null>(null);
export const useStore = () => useContext(StoreCtx)!;

export function StoreProvider({ children }: { children: ReactNode }) {
    const [route, setRoute] = useState<Route>(parse);
    const [rt, setRt] = useState<Record<string, RuntimeFu>>({});
    const [acked, setAcked] = useState<Record<string, { by: string; at: string }>>({});
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

    const go = useCallback((path: string, params: Record<string, string | undefined> = {}) => {
        window.location.hash = hrefFor(path, params, route);
    }, [route]);
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
                setRt({});
                setAcked({});
            }
            const s = q.toString();
            window.location.hash = `#${route.path}${s ? `?${s}` : ''}`;
        },
        [route],
    );

    const kowhaiAcked = route.scenario !== 'ackPending' || !!acked['h-kow-am'];
    const rows = useCallback((): Row[] => {
        if (route.scenario === 'empty') return FOLLOW_UPS.filter((f) => f.done && visible(f, route.persona)).map((f) => ({ f, rt: rt[f.id], owner: f.owner, state: stateOf(f, rt[f.id], route.persona) }));
        const setAt = (f: (typeof FOLLOW_UPS)[number]) => (f.carried ? (route.scenario === 'ackPending' ? acked['h-kow-am']?.at : f.carried.ownerSetAt) : undefined);
        return FOLLOW_UPS.filter((f) => visible(f, route.persona)).map((f) => ({ f, rt: rt[f.id], owner: ownerOf(f, rt[f.id], route.scenario, kowhaiAcked), state: stateOf(f, rt[f.id], route.persona), ownerSetAt: setAt(f) }));
    }, [route.scenario, route.persona, rt, kowhaiAcked, acked]);
    const row = useCallback((id: string) => rows().find((r) => r.f.id === id) ?? null, [rows]);
    const concealed = useCallback((): Row[] => {
        const p = PERSONAS[route.persona];
        if (p.perms.includes('cd.view')) return [];
        return FOLLOW_UPS.filter((f) => f.cd && p.houses.includes(f.house) && (route.scenario !== 'empty' || !!f.done)).map((f) => ({ f, rt: rt[f.id], owner: f.owner, state: stateOf(f, rt[f.id], route.persona) }));
    }, [route.persona, route.scenario, rt]);

    const store: Store = {
        route,
        go,
        setViewer,
        rt,
        acked,
        update: (id, patch) => setRt((x) => ({ ...x, [id]: { ...x[id], ...patch } })),
        acknowledge: (hid, by) => setAcked((x) => ({ ...x, [hid]: { by, at: '9:12 am' } })),
        toast: pushToast,
        toasts,
        rows,
        row,
        concealed,
    };
    return <StoreCtx.Provider value={store}>{children}</StoreCtx.Provider>;
}
