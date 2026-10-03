/* Medication › Settings views and their tabs (eMAR P11 v5 `nav.tsx`). A view
 * or tab is shown only once it is built (hide-unbuilt); the address is
 * `#<view>/<tab>` so a link opens the right place. */
import type { ViewKey } from './_model';

export const SET_VIEWS: Record<
    ViewKey,
    { label: string; secs: [string, string][] }
> = {
    rules: {
        label: 'Medication rules',
        secs: [
            ['overview', 'Overview'],
            ['medicines', 'Medicine rules'],
            ['safety', 'Safety checks'],
            ['reviews', 'Medication reviews'],
            ['controlled', 'Controlled drugs'],
            ['photos', 'Medicine photos'],
            ['records', 'Records & reporting'],
        ],
    },
    rounds: {
        label: 'Rounds & timing',
        secs: [
            ['overview', 'Overview'],
            ['templates', 'Round templates'],
            ['timing', 'Dose timing'],
        ],
    },
    staff: {
        label: 'Staff & PINs',
        secs: [
            ['overview', 'Overview'],
            ['competency', 'Competency'],
            ['exemptions', 'Exemption limit'],
            ['pins', 'Witness PINs'],
            ['status', 'PIN status'],
        ],
    },
    alerts: {
        label: 'Alerts & access',
        secs: [
            ['overview', 'Overview'],
            ['alerts', 'Alerts'],
            ['delivery', 'Delivery'],
            ['triage', 'Error triage'],
            ['oncall', 'On-call contacts'],
            ['emergency', 'Emergency access'],
            ['log', 'Alert log'],
        ],
    },
    history: {
        label: 'Change history',
        secs: [
            ['decide', 'Still to decide'],
            ['changes', 'All changes'],
        ],
    },
};

export const sectionLabel = (view: ViewKey, sec: string) =>
    SET_VIEWS[view].secs.find(([k]) => k === sec)?.[1] ?? '';

export type Built = Partial<Record<ViewKey, string[]>>;

/** The views and tabs a person can open, in the approved order. */
export function visibleViews(built: Built): ViewKey[] {
    return (Object.keys(SET_VIEWS) as ViewKey[]).filter(
        (v) => (built[v] ?? []).length > 0,
    );
}

export function visibleSections(built: Built, view: ViewKey) {
    return SET_VIEWS[view].secs.filter(([k]) => built[view]?.includes(k));
}

/** Read `#view/tab`, falling back to the first view and tab that exist. */
export function parseHash(hash: string, built: Built) {
    const [rawView, rawSec] = hash.replace(/^#\/?/, '').split('/');
    const views = visibleViews(built);
    const view = (
        views.includes(rawView as ViewKey) ? rawView : views[0]
    ) as ViewKey;
    const secs = visibleSections(built, view).map(([k]) => k);
    const sec = secs.includes(rawSec) ? rawSec : secs[0];
    return { view, sec };
}

export const settingsHash = (view: ViewKey, sec?: string) =>
    `#${view}${sec ? `/${sec}` : ''}`;
