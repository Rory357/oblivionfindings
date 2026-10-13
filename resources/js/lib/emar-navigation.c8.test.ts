import { describe, expect, it } from 'vitest';
import {
    EMAR_HUBS,
    emarBreadcrumbs,
    emarHubLanding,
    emarModuleLanding,
    inEmarHubOrder,
    visibleEmarHubs,
    visibleEmarViews,
    type EmarNavigationPermissions,
} from './emar-navigation';
import { medicationReturnTo } from './medication-navigation';

/* eMAR Lane C8 — navigation and information architecture against the
 * approved P00 hub map (EA-079, EA-091, EA-127, EA-128, EA-134, EA-159,
 * EA-168, EA-192, EA-209, EA-221) and the P06 Stock landing. */
type MedKey = keyof NonNullable<EmarNavigationPermissions['medications']>;
const permissions = (...keys: MedKey[]): EmarNavigationPermissions => ({
    medications: Object.fromEntries(keys.map((key) => [key, true])),
});
const finance = permissions(
    'view',
    'reportsExport',
    'stockUpdate',
    'reportsView',
);
const auditor = permissions('view', 'auditView', 'reportsView');
const coordinator = permissions(
    'view',
    'administerRecord',
    'ordersManage',
    'ordersVerify',
    'stockUpdate',
    'reportsView',
    'reportsExport',
    'auditView',
    'controlledView',
);
const houseLead = permissions(
    'view',
    'administerRecord',
    'ordersVerify',
    'controlledView',
);
const hub = (key: string) => EMAR_HUBS.find((item) => item.key === key)!;
const labels = (key: string, can: EmarNavigationPermissions) =>
    visibleEmarViews(hub(key), can).map((view) => view.label);

describe('Meds today is for people who record doses and leads (EA-127, EA-168)', () => {
    it('leaves finance and the auditor on their own hubs', () => {
        for (const can of [finance, auditor]) {
            expect(visibleEmarHubs(can).map((item) => item.key)).not.toContain(
                'today',
            );
            expect(emarModuleLanding(can)).not.toBe('/meds/today');
        }
        expect(emarModuleLanding(finance)).toBe('/emar/stock/packs');
        expect(emarModuleLanding(auditor)).toBe('/emar/mar');
    });
});

describe('Meds today views follow the approved P00 order (EA-209)', () => {
    it('orders the hub map and the page rail the same way', () => {
        expect(hub('today').views.map((view) => view.key)).toEqual([
            'schedule',
            'rounds',
            'asneeded',
            'followups',
            'controlled',
            'stockalerts',
            'activity',
        ]);
        expect(
            inEmarHubOrder('today', [
                { key: 'activity' },
                { key: 'stockalerts' },
                { key: 'schedule' },
                { key: 'controlled' },
            ]).map((item) => item.key),
        ).toEqual(['schedule', 'controlled', 'stockalerts', 'activity']);
    });
});

describe('Emergency access reviewers find it (EA-128, EA-159)', () => {
    it('shows Emergency access to audit.view reviewers', () => {
        expect(labels('safety', auditor)).toContain('Emergency access');
        expect(labels('safety', permissions('view', 'breakGlass'))).toContain(
            'Emergency access',
        );
    });
});

describe('Reports views (EA-221)', () => {
    it('keeps Protected backups from finance-only users', () => {
        expect(labels('reports', finance)).not.toContain('Protected backups');
        expect(labels('reports', coordinator)).toContain('Protected backups');
    });
});

describe('Orders & reviews labels match the approved P04 rail (EA-134)', () => {
    it('uses Orders, To check, Covert', () => {
        expect(labels('orders', coordinator).slice(0, 5)).toEqual([
            'Orders',
            'To check',
            'Covert',
            'Reconciliation',
            'Medication reviews',
        ]);
    });
});

describe('Stock lands on the approved P06 hub', () => {
    it('opens the Stock hub first and maps the older page to it', () => {
        expect(emarHubLanding(hub('stock'), coordinator)).toBe(
            '/emar/stock/packs',
        );
        expect(
            emarBreadcrumbs('/emar/stock?site_id=2', coordinator).at(-1),
        ).toEqual({ title: 'Stock', href: '/emar/stock/packs' });
    });
});

describe('Home-rooted crumbs open pages the viewer can open (EA-079, EA-091)', () => {
    it('sends a house lead without stock access to their first stock view', () => {
        const crumbs = emarBreadcrumbs('/emar/controlled', houseLead);
        expect(crumbs[0]).toEqual({ title: 'Home', href: '/dashboard' });
        expect(crumbs[2].title).toBe('Stock & controlled drugs');
        expect(crumbs[2].href).not.toBe('/emar/stock/packs');
        expect(crumbs[2].href).toBe(emarHubLanding(hub('stock'), houseLead));
    });
});

describe('The way back from Follow-ups (EA-192)', () => {
    it('allows a return to the follow-up queue', () => {
        expect(medicationReturnTo('/medication-followups?open=4')).toBe(
            '/medication-followups?open=4',
        );
    });
});
