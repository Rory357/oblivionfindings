/* Hash routes for the preview: #/settings/<view>/<section>, #/safety/<view>/<section>,
 * #/today/<view>, #/guide, #/outside/<label>. Query after “?” sets up states for screenshots. */
import { createContext, useContext } from 'react';

export type Page = 'settings' | 'safety' | 'today' | 'guide' | 'outside';
export type Route = { page: Page; view: string; sec: string; q: URLSearchParams };
export const SET_VIEWS: Record<string, { label: string; secs: [string, string][] }> = {
    rules: { label: 'Medication rules', secs: [['overview', 'Overview'], ['medicines', 'Medicine rules'], ['safety', 'Safety checks'], ['controlled', 'Controlled drugs'], ['photos', 'Medicine photos']] },
    rounds: { label: 'Rounds & timing', secs: [['overview', 'Overview'], ['templates', 'Round templates'], ['timing', 'Dose timing']] },
    staff: { label: 'Staff & PINs', secs: [['overview', 'Overview'], ['competency', 'Competency'], ['exemptions', 'Exemption limit'], ['pins', 'Witness PINs'], ['status', 'PIN status']] },
    alerts: { label: 'Alerts & access', secs: [['overview', 'Overview'], ['alerts', 'Alerts'], ['delivery', 'Delivery'], ['oncall', 'On-call contacts'], ['emergency', 'Emergency access'], ['log', 'Alert log']] },
    history: { label: 'Change history', secs: [['decide', 'Still to decide'], ['changes', 'All changes']] },
};
// v1 and v2 addresses keep working.
const ALIAS: Record<string, [string, string]> = { templates: ['rounds', 'templates'], secondperson: ['staff', 'pins'], eligrules: ['staff', 'competency'], eapolicy: ['alerts', 'emergency'] };
const SEC_ALIAS: Record<string, string> = { recipients: 'alerts', channels: 'delivery' };
export const ELIG_SECS: [string, string][] = [['register', 'Register'], ['renewals', 'Renewals'], ['exemptions', 'Exemptions'], ['witness', 'Witnesses & PINs']];

export function parseRoute(hash: string): Route {
    const [path, query] = hash.replace(/^#\/?/, '').split('?');
    const seg = path.split('/').filter(Boolean).map(decodeURIComponent);
    const q = new URLSearchParams(query || '');
    const page = (['settings', 'safety', 'today', 'guide', 'outside'].includes(seg[0]) ? seg[0] : 'settings') as Page;
    if (page === 'settings') {
        let view = seg[1] || 'rules', sec = SEC_ALIAS[seg[2]] || seg[2] || '';
        if (ALIAS[view]) [view, sec] = [ALIAS[view][0], sec || ALIAS[view][1]];
        if (!SET_VIEWS[view]) view = 'rules';
        if (!SET_VIEWS[view].secs.some(([k]) => k === sec)) sec = SET_VIEWS[view].secs[0][0];
        return { page, view, sec, q };
    }
    if (page === 'safety') {
        const view = seg[1] || 'eligibility';
        const sec = ELIG_SECS.some(([k]) => k === seg[2]) ? seg[2] : 'register';
        return { page, view, sec, q };
    }
    return { page, view: seg[1] || (page === 'today' ? 'schedule' : ''), sec: seg[2] || '', q };
}
export const settingsHref = (view: string, sec?: string) => `#/settings/${view}${sec ? `/${sec}` : ''}`;
export const eligHref = (sec?: string, query?: string) => `#/safety/eligibility${sec ? `/${sec}` : ''}${query ? `?${query}` : ''}`;

export type Dlg = { kind: string; arg?: string; step?: string; extra?: Record<string, string> } | null;
export type Nav = { route: Route; go: (href: string) => void; open: (d: Dlg) => void; close: () => void; dialog: Dlg };
export const NavCtx = createContext<Nav | null>(null);
export const useNav = () => useContext(NavCtx)!;
