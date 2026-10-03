import { describe, expect, it } from 'vitest';
import {
    EMAR_HUBS,
    canOpenEmarAudit,
    canOpenEmarReports,
    emarBreadcrumbs,
    emarHubForUrl,
    emarHubLinkActive,
    emarReportsHref,
    emarSearchEntries,
    emarSidebar,
    visibleEmarViews,
    type EmarNavigationPermissions,
} from './emar-navigation';

type MedKey = keyof NonNullable<EmarNavigationPermissions['medications']>;
const permissions = (...keys: MedKey[]): EmarNavigationPermissions => ({
    medications: Object.fromEntries(keys.map((key) => [key, true])),
});
// Server capabilities are independent: export and generic reporting do not
// imply medications.reports.view. No fixture creates or modifies role grants.
const worker = permissions(
    'view',
    'administerRecord',
    'controlledView',
    'controlledRecord',
    'controlledWitness',
);
const lead = permissions(
    'view',
    'administerRecord',
    'ordersVerify',
    'controlledView',
);
const manager = permissions(
    'view',
    'administerRecord',
    'ordersManage',
    'ordersVerify',
    'settingsManage',
    'stockUpdate',
    'controlledView',
    'auditView',
    'reportsView',
    'reportsExport',
    'witnessPinReset',
    'alertsManageHouse',
);
const reportReader = permissions('reportsView');
const auditReader = permissions('view', 'auditView', 'reportsView');
const labels = (can: EmarNavigationPermissions, key: string) =>
    visibleEmarViews(EMAR_HUBS.find((hub) => hub.key === key)!, can).map(
        (view) => view.label,
    );

describe('server-authorised medication discovery', () => {
    it('retains one Meds today sidebar entry for an ordinary frontline worker', () => {
        expect(emarSidebar(worker)).toEqual({
            mode: 'frontline',
            href: '/meds/today',
        });
        expect(labels(worker, 'safety')).toEqual([]);
        expect(
            emarSearchEntries(worker).some(
                (entry) => entry.label === 'Follow-ups',
            ),
        ).toBe(true);
        expect(
            emarSearchEntries(worker).every(
                (entry) => entry.group === 'Meds today',
            ),
        ).toBe(true);
    });
    it('does not expose medication reports for generic reports or export access alone', () => {
        for (const can of [
            { reports: { viewAny: true } },
            permissions('reportsExport'),
            permissions('auditView'),
        ]) {
            expect(labels(can, 'reports')).toEqual([]);
            expect(canOpenEmarReports(can)).toBe(false);
            expect(canOpenEmarAudit(can)).toBe(false);
        }
        expect(emarSidebar({ reports: { viewAny: true } })).toEqual({
            mode: 'none',
        });
    });
    it('shows the built report views and adds audit only with its additional permission', () => {
        expect(labels(reportReader, 'reports')).toEqual([
            'Standard reports',
            'Report builder',
            'Print & exports',
        ]);
        expect(labels(auditReader, 'reports')).toEqual([
            'Standard reports',
            'Audit trail',
            'Report builder',
            'Print & exports',
        ]);
        expect(canOpenEmarAudit(auditReader)).toBe(true);
    });
    it('retains the manager followup screen and existing handover feature', () => {
        expect(labels(lead, 'safety')).toEqual([
            'Overview',
            'Follow-ups',
            'Medication errors',
            'Handovers',
            'Staff eligibility',
            'Witness overrides',
        ]);
        expect(emarSearchEntries(lead)).toContainEqual(
            expect.objectContaining({
                label: 'Follow-ups',
                href: '/medication-followups',
            }),
        );
    });
    it.each([
        ['settings manager', permissions('settingsManage'), '/emar/settings'],
        ['audit reader', permissions('auditView'), '/emar/settings'],
        [
            'round template manager',
            permissions('ordersManage'),
            '/emar/settings#rounds/templates',
        ],
        [
            'house alert manager',
            permissions('alertsManageHouse'),
            '/emar/settings#alerts/overview',
        ],
        [
            'witness PIN resetter',
            permissions('witnessPinReset'),
            '/emar/settings#staff/pins',
        ],
        [
            'scoped emergency policy access',
            permissions('emergencyPolicyAccess'),
            '/emar/settings#alerts/emergency',
        ],
    ])('gives %s the appropriate Settings landing', (_name, can, href) => {
        const sidebar = emarSidebar(can);
        expect(sidebar.mode).toBe('module');
        if (sidebar.mode === 'module')
            expect(
                sidebar.hubs.find((hub) => hub.key === 'settings')?.href,
            ).toBe(href);
    });
    it('does not expose rule/history sections to PIN or house-alert access', () => {
        expect(labels(permissions('witnessPinReset'), 'settings')).toEqual([
            'Witness PINs',
        ]);
        expect(labels(permissions('alertsManageHouse'), 'settings')).toEqual([
            'Alerts & access',
        ]);
        expect(labels(permissions('view'), 'settings')).toEqual([]);
    });
    it('keeps emergency access limited to its existing authority', () => {
        expect(labels(permissions('breakGlass'), 'safety')).toEqual([
            'Emergency access',
        ]);
    });
    it('keeps all hub rails within the shared eight-view limit', () => {
        for (const hub of EMAR_HUBS)
            expect(hub.views.length).toBeLessThanOrEqual(8);
    });
});

describe('query/hash-aware route identity', () => {
    it.each([
        ['/emar/reports', 'reports', 'reports'],
        ['/emar/reports?site_id=2&view=audit', 'reports', 'audit'],
        ['/emar/reports?view=exports&client_id=4', 'reports', 'exports'],
        ['/emar/reports/builder?template=1', 'reports', 'builder'],
        ['/emar/audit?site_id=2', 'reports', 'audit'],
        ['/medications/audit', 'reports', 'audit'],
        [
            '/emar/prescriptions?view=reconciliation&site_id=2',
            'orders',
            'reconciliation',
        ],
        ['/emar/prescriptions?view=to_check', 'orders', 'to-check'],
        ['/emar/prescriptions?view=covert', 'orders', 'covert'],
        ['/emar/prescriptions/legacy', 'orders', 'prescriptions'],
        ['/emar/controlled?view=losses', 'stock', 'losses'],
        ['/emar/controlled/loss-reports', 'stock', 'losses'],
        ['/emar/controlled?view=destructions', 'stock', 'destructions'],
        ['/medication-followups/42', 'safety', 'followups'],
        ['/meds/today?view=followups', 'today', 'followups'],
        ['/meds/today?view=rounds', 'today', 'rounds'],
        ['/emar/rounds/5/guided', 'today', 'rounds'],
        ['/emar/settings?log_house=2#alerts/log', 'settings', 'alerts'],
        ['/emar/settings#alerts/emergency', 'settings', 'emergency-policy'],
        ['/emar/settings#staff/status', 'settings', 'witness-pins'],
        ['/emar/settings#rounds/templates', 'settings', 'templates'],
        ['/emar/settings#history/changes', 'settings', 'changes'],
    ])('identifies %s as %s / %s', (url, hub, view) => {
        const match = emarHubForUrl(url);
        expect(match?.hub.key).toBe(hub);
        expect(match?.view.key).toBe(view);
    });
    it('does not let Overview swallow other eMAR pages or similarly named routes', () => {
        expect(emarHubForUrl('/emar/reports-extra')).toBeUndefined();
        expect(emarHubForUrl('/operations/clients/4')).toBeUndefined();
        expect(
            emarHubLinkActive(
                '/emar/reports?view=audit',
                '/emar/reports?view=exports',
            ),
        ).toBe(true);
        expect(emarHubLinkActive('/emar/reports?view=audit', '/emar')).toBe(
            false,
        );
        expect(
            emarHubLinkActive('/emar/reports', '/fleet-assets'),
        ).toBeUndefined();
    });
    it('uses the matching query/hash view in Home-rooted breadcrumbs', () => {
        expect(
            emarBreadcrumbs('/emar/reports?site_id=2&view=audit', manager).at(
                -1,
            ),
        ).toEqual({ title: 'Audit trail', href: '/emar/reports?view=audit' });
        expect(
            emarBreadcrumbs('/emar/settings#alerts/emergency', manager).at(-1)
                ?.title,
        ).toBe('Emergency access policy');
        expect(
            emarBreadcrumbs(
                '/emar/prescriptions?view=reconciliation',
                manager,
            ).at(-1)?.title,
        ).toBe('Reconciliation');
        expect(
            emarBreadcrumbs('/emar/reports?view=exports', reportReader).map(
                (crumb) => crumb.href,
            ),
        ).toEqual([
            '/dashboard',
            '/emar/reports',
            '/emar/reports',
            '/emar/reports?view=exports',
        ]);
    });
});

describe('canonical report source links', () => {
    it('preserves the day, house, person and text scope with the supported period fields', () => {
        const href = emarReportsHref('audit', {
            date: '2026-10-03',
            site_id: 2,
            client_id: 4,
            report: 'controlled',
            q: '  Pack & dose  ',
        });
        const query = new URL(href, 'https://medication.local').searchParams;
        expect(Object.fromEntries(query)).toEqual({
            view: 'audit',
            period: 'custom',
            date_from: '2026-10-03',
            date_to: '2026-10-03',
            site_id: '2',
            client_id: '4',
            q: 'Pack & dose',
            report: 'controlled',
        });
        expect(queries(href)).not.toHaveProperty('date');
    });
    it('does not turn an all-houses/persons scope into a zero filter', () => {
        expect(
            emarReportsHref('exports', {
                site_id: null,
                client_id: null,
                q: ' ',
            }),
        ).toBe('/emar/reports?view=exports');
    });
});
function queries(href: string) {
    return Object.fromEntries(
        new URL(href, 'https://medication.local').searchParams,
    );
}
