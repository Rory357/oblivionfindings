import { describe, expect, it } from 'vitest';

import {
    EMAR_HUBS,
    emarBreadcrumbs,
    emarHubForUrl,
    emarHubLinkActive,
    emarSearchEntries,
    emarSidebar,
    isFrontlineMedication,
    visibleEmarHubs,
    visibleEmarViews,
    type EmarNavigationPermissions,
} from './emar-navigation';

type MedKey = keyof NonNullable<EmarNavigationPermissions['medications']>;
const persona = (
    keys: MedKey[],
    extra: Partial<EmarNavigationPermissions> = {},
): EmarNavigationPermissions => ({
    medications: Object.fromEntries(keys.map((k) => [k, true])),
    ...extra,
});

/** Seeded medication grants (RbacSeeder + the role-baseline migration). */
const PERSONAS = {
    supportWorker: persona([
        'view',
        'administerRecord',
        'controlledView',
        'controlledRecord',
        'controlledWitness',
    ]),
    teamLead: persona([
        'view',
        'ordersVerify',
        'administerRecord',
        'controlledView',
        'controlledRecord',
        'controlledWitness',
    ]),
    coordinator: persona([
        'view',
        'ordersManage',
        'ordersVerify',
        'settingsManage',
        'administerRecord',
        'stockUpdate',
        'controlledView',
        'controlledRecord',
        'controlledWitness',
        'auditView',
        'reportsExport',
    ]),
    clinicalLead: persona([
        'view',
        'ordersManage',
        'ordersVerify',
        'settingsManage',
        'administerRecord',
        'auditView',
    ]),
    auditor: persona(['view', 'auditView']),
    finance: persona(['view', 'reportsExport', 'stockUpdate']),
    providerManager: persona([
        'view',
        'ordersManage',
        'ordersVerify',
        'settingsManage',
        'administerRecord',
        'stockUpdate',
        'controlledView',
        'controlledRecord',
        'controlledWitness',
        'auditView',
        'reportsExport',
        'breakGlass',
    ]),
    reportsViewAnyOnly: persona([], { reports: { viewAny: true } }),
    frontlineBreakGlass: persona([
        'view',
        'administerRecord',
        'controlledView',
        'controlledRecord',
        'controlledWitness',
        'breakGlass',
    ]),
} satisfies Record<string, EmarNavigationPermissions>;

const hubLabels = (can: EmarNavigationPermissions) =>
    visibleEmarHubs(can).map((hub) => hub.label);
const viewLabels = (can: EmarNavigationPermissions, key: string) => {
    const hub = EMAR_HUBS.find((h) => h.key === key)!;
    return visibleEmarViews(hub, can).map((v) => v.label);
};

describe('emarSidebar — role matrix (eMAR second review §3, P00 HUBS)', () => {
    it('gives a support worker one Meds today entry; CD record/witness never promote', () => {
        expect(isFrontlineMedication(PERSONAS.supportWorker)).toBe(true);
        expect(emarSidebar(PERSONAS.supportWorker)).toEqual({
            mode: 'frontline',
            href: '/meds/today',
        });
    });

    it.each([
        [
            'team lead',
            PERSONAS.teamLead,
            [
                ['Meds today', '/meds/today'],
                ['MAR & medicines', '/emar/mar'],
                ['Orders & reviews', '/emar/prescriptions'],
                ['Stock & controlled drugs', '/emar/controlled'],
                ['Safety & oversight', '/emar'],
            ],
        ],
        [
            'coordinator',
            PERSONAS.coordinator,
            [
                ['Meds today', '/meds/today'],
                ['MAR & medicines', '/emar/mar'],
                ['Orders & reviews', '/emar/prescriptions'],
                ['Stock & controlled drugs', '/emar/stock'],
                ['Safety & oversight', '/emar'],
                ['Reports & audit', '/emar/reports'],
                ['Settings', '/emar/settings'],
            ],
        ],
        [
            'clinical lead',
            PERSONAS.clinicalLead,
            [
                ['Meds today', '/meds/today'],
                ['MAR & medicines', '/emar/mar'],
                ['Orders & reviews', '/emar/prescriptions'],
                ['Safety & oversight', '/emar'],
                ['Reports & audit', '/emar/audit'],
                ['Settings', '/emar/settings'],
            ],
        ],
        [
            'auditor',
            PERSONAS.auditor,
            [
                ['MAR & medicines', '/emar/mar'],
                ['Safety & oversight', '/emar/errors'],
                ['Reports & audit', '/emar/audit'],
            ],
        ],
        [
            'finance',
            PERSONAS.finance,
            [
                ['Stock & controlled drugs', '/emar/stock'],
                ['Reports & audit', '/emar/reports'],
            ],
        ],
        [
            'provider manager',
            PERSONAS.providerManager,
            [
                ['Meds today', '/meds/today'],
                ['MAR & medicines', '/emar/mar'],
                ['Orders & reviews', '/emar/prescriptions'],
                ['Stock & controlled drugs', '/emar/stock'],
                ['Safety & oversight', '/emar'],
                ['Reports & audit', '/emar/reports'],
                ['Settings', '/emar/settings'],
            ],
        ],
    ])(
        'shows a %s the Medication module with their hubs at the first permitted landing',
        (_name, can, expected) => {
            const sidebar = emarSidebar(can);
            expect(sidebar.mode).toBe('module');
            if (sidebar.mode !== 'module') return;
            expect(sidebar.label).toBe('Medication');
            expect(sidebar.hubs.map((h) => [h.title, h.href])).toEqual(
                expected,
            );
        },
    );

    it('lets reports.viewAny alone reveal Reports & audit › Reports only, not the module', () => {
        expect(emarSidebar(PERSONAS.reportsViewAnyOnly)).toEqual({
            mode: 'module',
            label: 'Medication',
            hubs: [
                expect.objectContaining({
                    title: 'Reports & audit',
                    href: '/emar/reports',
                }),
            ],
        });
        expect(viewLabels(PERSONAS.reportsViewAnyOnly, 'reports')).toEqual([
            'Reports',
        ]);
    });

    it('gives a break-glass frontline worker Meds today plus Safety & oversight with Emergency access only', () => {
        expect(hubLabels(PERSONAS.frontlineBreakGlass)).toEqual([
            'Meds today',
            'Safety & oversight',
        ]);
        expect(viewLabels(PERSONAS.frontlineBreakGlass, 'safety')).toEqual([
            'Emergency access',
        ]);
    });

    it('shows nothing to someone with no medication access', () => {
        expect(emarSidebar({})).toEqual({ mode: 'none' });
        expect(emarSidebar(null)).toEqual({ mode: 'none' });
    });
});

describe('hub rail views per role', () => {
    it('keeps Stock & pharmacy for stock holders and the CD views for controlled.view holders', () => {
        expect(viewLabels(PERSONAS.finance, 'stock')).toEqual([
            'Stock & pharmacy',
        ]);
        expect(viewLabels(PERSONAS.teamLead, 'stock')).toEqual([
            'Controlled register',
            'Destructions & returns',
        ]);
        expect(viewLabels(PERSONAS.coordinator, 'stock')).toEqual([
            'Stock & pharmacy',
            'Controlled register',
            'Destructions & returns',
        ]);
    });

    it('gives leads the oversight views and auditors medication errors only', () => {
        expect(viewLabels(PERSONAS.coordinator, 'safety')).toEqual([
            'Overview',
            'Medication errors',
            'Handovers',
            'Staff eligibility',
        ]);
        expect(viewLabels(PERSONAS.providerManager, 'safety')).toEqual([
            'Overview',
            'Medication errors',
            'Handovers',
            'Staff eligibility',
            'Emergency access',
        ]);
        expect(viewLabels(PERSONAS.auditor, 'safety')).toEqual([
            'Medication errors',
        ]);
    });

    it('splits Reports & audit by permission', () => {
        expect(viewLabels(PERSONAS.clinicalLead, 'reports')).toEqual([
            'Audit trail',
        ]);
        expect(viewLabels(PERSONAS.finance, 'reports')).toEqual(['Reports']);
        expect(viewLabels(PERSONAS.coordinator, 'reports')).toEqual([
            'Reports',
            'Audit trail',
        ]);
    });

    it('never puts more than 8 views on a hub rail (DESIGN.md)', () => {
        for (const hub of EMAR_HUBS)
            expect(hub.views.length).toBeLessThanOrEqual(8);
    });
});

describe('emarHubForUrl and active state', () => {
    it.each([
        ['/emar', 'safety', 'overview'],
        ['/emar/mar?client_id=4&date=2026-10-03', 'mar', 'charts'],
        ['/emar/medications/12/detail', 'mar', 'medicines'],
        ['/emar/controlled', 'stock', 'controlled'],
        ['/emar/safety/eligibility', 'safety', 'eligibility'],
        ['/emar/competency', 'safety', 'eligibility'],
        ['/emar/settings', 'settings', 'rules'],
        ['/meds/today', 'today', 'schedule'],
        ['/emar/rounds/5/guided', 'today', 'rounds'],
    ])('places %s in %s › %s', (url, hub, view) => {
        const match = emarHubForUrl(url);
        expect(match?.hub.key).toBe(hub);
        expect(match?.view.key).toBe(view);
    });

    it('owns nothing outside the module', () => {
        expect(emarHubForUrl('/operations/clients/4')).toBeUndefined();
        expect(emarHubLinkActive('/emar/mar', '/fleet-assets')).toBeUndefined();
    });

    it('lights a hub link on every page of its hub, whatever its landing', () => {
        expect(emarHubLinkActive('/emar/prn', '/emar/mar')).toBe(true);
        expect(
            emarHubLinkActive('/emar/destructions', '/emar/controlled'),
        ).toBe(true);
        expect(emarHubLinkActive('/emar/errors', '/emar')).toBe(true);
        expect(emarHubLinkActive('/emar/mar', '/emar')).toBe(false);
    });
});

describe('emarSearchEntries', () => {
    it('indexes every permitted view with eMAR as a synonym', () => {
        const entries = emarSearchEntries(PERSONAS.finance);
        expect(entries.map((e) => [e.group, e.label, e.href])).toEqual([
            ['Stock & controlled drugs', 'Stock & pharmacy', '/emar/stock'],
            ['Reports & audit', 'Reports', '/emar/reports'],
        ]);
        expect(entries.every((e) => e.keywords.includes('eMAR'))).toBe(true);
    });
});

describe('emarBreadcrumbs', () => {
    const trail = (url: string, can: EmarNavigationPermissions) =>
        emarBreadcrumbs(url, can).map((c) => `${c.title} ${c.href}`);

    it('roots every Medication page at Home, in the rail labels', () => {
        expect(trail('/emar/stock', PERSONAS.coordinator)).toEqual([
            'Home /dashboard',
            'Medication /meds/today',
            'Stock & controlled drugs /emar/stock',
            'Stock & pharmacy /emar/stock',
        ]);
        expect(trail('/meds/today', PERSONAS.supportWorker)).toEqual([
            'Home /dashboard',
            'Medication /meds/today',
            'Meds today /meds/today',
            'Schedule /meds/today',
        ]);
    });

    it('links the hub and module crumbs to pages this viewer can open', () => {
        // Team lead: no stock.update, so the Stock hub lands on the register.
        expect(trail('/emar/destructions', PERSONAS.teamLead)).toEqual([
            'Home /dashboard',
            'Medication /meds/today',
            'Stock & controlled drugs /emar/controlled',
            'Destructions & returns /emar/destructions',
        ]);
        // Auditor: no Meds today or Overview.
        expect(trail('/emar/errors', PERSONAS.auditor)).toEqual([
            'Home /dashboard',
            'Medication /emar/mar',
            'Safety & oversight /emar/errors',
            'Medication errors /emar/errors',
        ]);
        expect(trail('/emar/reports', PERSONAS.reportsViewAnyOnly)).toEqual([
            'Home /dashboard',
            'Medication /emar/reports',
            'Reports & audit /emar/reports',
            'Reports /emar/reports',
        ]);
    });

    it('keeps a deeper page under its view', () => {
        expect(trail('/emar/controlled/12', PERSONAS.coordinator).at(-1)).toBe(
            'Controlled register /emar/controlled',
        );
    });
});
