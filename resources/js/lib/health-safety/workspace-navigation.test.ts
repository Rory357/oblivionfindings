import {
    buildNavSearchCatalog,
    buildSafetySubPanelGroups,
    isSubItemActive,
} from '@/components/app-sidebar';
import { describe, expect, it } from 'vitest';
import {
    SAFETY_HUBS,
    safetyHubForUrl,
    safetySearchEntries,
    safetySidebar,
    type SafetyNavigationPermissions,
} from './workspace-navigation';

const manager: SafetyNavigationPermissions = {
    hazards: { view: true },
    incidents: { viewAny: true },
    safeguarding: { viewAny: true },
    fleet: { viewAny: true },
    restraints: { view: true },
    procedures: { view: true },
    governance: { view: true },
    compliance: { view: true },
    privacy: { viewRequests: true },
};

describe('Health & Safety workspace navigation', () => {
    it('has the six approved hubs (drawing D4) and keeps every former register discoverable', () => {
        const items = buildSafetySubPanelGroups({ can: manager }).flatMap(
            (group) => group.items,
        );
        expect(items.map((item) => item.title)).toEqual([
            'Overview',
            'Incidents and safety reviews',
            'Injuries and care',
            'Risks and prevention',
            'People and participation',
            'Reports',
        ]);
        const oldPaths = [
            '/health-safety',
            '/incidents',
            '/incidents?tab=near_misses',
            '/fleet-assets/incidents',
            '/safeguarding',
            '/health-safety/events',
            '/health-safety/corrective-actions',
            '/health-safety/analytics',
            '/compliance/hazards',
            '/health-safety/worker-participation?tab=representatives',
            '/health-safety/lone-workers',
            '/health-safety/drills',
            '/health-safety/substances',
            '/health-safety/ppe',
            '/health-safety/first-aid',
            '/health-safety/restraints',
            '/health-safety/injuries',
            '/health-safety/procedures',
            '/compliance',
            '/health-safety/risk-assessments',
            '/privacy/dashboard',
        ];
        expect(oldPaths).toHaveLength(21);
        const catalog = buildNavSearchCatalog({ can: manager }).filter(
            (entry) => entry.section === 'Health & Safety',
        );
        for (const href of oldPaths)
            expect(
                catalog.some((entry) => entry.href === href),
                href,
            ).toBe(true);
        expect(
            catalog.find((entry) => entry.label === 'Procedures')?.keywords,
        ).toContain('Safe Work Procedures');
        expect(
            catalog.find((entry) => entry.label === 'Safety reviews')?.keywords,
        ).toContain('Events');
    });

    it.each([
        [{ compliance: { view: true } }, ['reports']],
        [{ procedures: { view: true } }, ['prevention']],
        [{ restraints: { view: true } }, ['care']],
        [{ hr: { wellbeing: { view: true } } }, ['care']],
        [{ incidents: { viewAssigned: true } }, ['concerns']],
        [{ safeguarding: { create: true } }, ['concerns']],
        // WorkSafe notifications sit with incidents and safety reviews (drawing D4).
        [{ governance: { view: true } }, ['concerns', 'reports']],
        [{}, []],
    ] as const)(
        'only shows destinations this viewer can use: %j',
        (can, keys) => {
            expect(safetySidebar(can).map((item) => item.key)).toEqual(keys);
        },
    );

    it('does not treat general compliance, clinical, risk or reporting access as H&S register authority', () => {
        const can = {
            compliance: { view: true },
            clinical: { dashboard: true },
            risks: { viewAny: true },
            reports: { viewAny: true },
        };
        expect(safetySearchEntries(can).map((entry) => entry.href)).toEqual([
            '/compliance',
        ]);
        expect(safetySidebar({ incidents: { viewAny: true } })[0].href).toBe(
            '/incidents',
        );
        expect(
            safetySearchEntries({ incidents: { viewAny: true } }).some(
                (entry) => entry.href === '/fleet-assets/incidents',
            ),
        ).toBe(false);
        expect(safetySidebar({ safeguarding: { create: true } })[0].href).toBe(
            '/safeguarding/create',
        );
        expect(safetySidebar({ procedures: { view: true } })[0].href).toBe(
            '/health-safety/procedures',
        );
    });

    it.each([
        ['/incidents/43?section=history&return_to=%2Ftasks', 'concerns'],
        ['/incidents?site_id=3&tab=near_misses', 'concerns'],
        ['/safeguarding/9', 'concerns'],
        ['/fleet-assets/incidents/12', 'concerns'],
        ['/health-safety/events/8?section=investigations', 'concerns'],
        ['/health-safety/corrective-actions/4?action=owner', 'concerns'],
        ['/health-safety/procedures/6', 'prevention'],
        ['/hazards/8?section=actions', 'prevention'],
        ['/sites/3/hazards?tab=overdue', 'prevention'],
        [
            '/health-safety/worker-participation?tab=consultations',
            'participation',
        ],
        ['/health-safety/chemical-exposures?site_id=3', 'care'],
        ['/health-safety/chemical-exposures/22?section=connections', 'care'],
        ['/health-safety/chemical-exposures/capture?substance_id=9', 'care'],
        ['/health-safety/reports?view=board', 'reports'],
        ['/health-safety/reports?view=notifications', 'reports'],
        ['/health-safety/lone-workers/5', 'prevention'],
        ['/safeguarding/create', 'concerns'],
        ['/health-safety/analytics?tab=trends', 'reports'],
        ['/health-safety', 'overview'],
    ])('lights exactly one H&S entry for %s', (url, hub) => {
        expect(safetyHubForUrl(url)?.key).toBe(hub);
        expect(
            safetySidebar(manager)
                .filter((item) => isSubItemActive(url, item.href))
                .map((item) => item.key),
        ).toEqual([hub]);
    });

    it('keeps external destinations canonical and never sends a report link to a raw-data endpoint', () => {
        expect(safetyHubForUrl('/health-safety-elsewhere')).toBeUndefined();
        const reports = SAFETY_HUBS.find((hub) => hub.key === 'reports')!;
        expect(
            reports.views
                .filter((view) => view.visible({ governance: { view: true } }))
                .every((view) =>
                    view.href.startsWith('/health-safety/reports?view='),
                ),
        ).toBe(true);
    });

    it('makes the exposure register discoverable without adding a sidebar destination or treating wellbeing alone as safety access', () => {
        const href = '/health-safety/chemical-exposures';
        const safety = { hazards: { view: true } };
        expect(safetySidebar(manager)).toHaveLength(6);
        expect(
            safetySearchEntries(safety).find((entry) => entry.href === href),
        ).toMatchObject({
            label: 'Chemical exposures',
            group: 'Injuries and care',
        });
        expect(
            safetySearchEntries({ hr: { wellbeing: { view: true } } }).some(
                (entry) => entry.href === href,
            ),
        ).toBe(false);
        expect(
            safetySearchEntries({}).some((entry) => entry.href === href),
        ).toBe(false);
        const care = SAFETY_HUBS.find((hub) => hub.key === 'care')!;
        expect(care.views.some((view) => view.href === href)).toBe(true);
    });
});
