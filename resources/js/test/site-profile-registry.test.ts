import {
    dataGroupForTab,
    resolveSiteProfileTab,
    siteProfileGroups,
    siteProfileTabs,
    siteProfileTerminology,
    visibleSiteProfileTabs,
    warningTotalsByGroup,
} from '@/pages/sites/tabs/registry';
import { describe, expect, it } from 'vitest';

const allPermissions = Object.fromEntries(
    siteProfileTabs
        .flatMap((tab) =>
            Array.isArray(tab.permission)
                ? tab.permission
                : tab.permission
                  ? [tab.permission]
                  : [],
        )
        .map((permission) => [permission, true]),
);

describe('site profile registry', () => {
    it('defines the approved five groups and complete tab order once', () => {
        expect(siteProfileGroups.map((group) => group.id)).toEqual([
            'overview',
            'people',
            'safety',
            'operations',
            'admin',
        ]);
        expect(siteProfileTabs.map((tab) => tab.id)).toEqual([
            'overview',
            'readiness',
            'clients',
            'contacts',
            'staff_requirements',
            'shift_coverage',
            'hazards',
            'risk_assessments',
            'inspections',
            'drills',
            'first_aid',
            'ppe',
            'emergency_plan',
            'calendar',
            'checklists',
            'meal_planner',
            'assets',
            'fleet',
            'hardware',
            'plan',
            'documents',
            'financials',
            'vendors',
            'services',
        ]);
    });

    it('derives people, occupancy, and plan language from Site type', () => {
        expect(siteProfileTerminology('house')).toMatchObject({
            people: 'Residents',
            occupancy: 'Bedrooms',
            plan: 'Plan & Rooms',
        });
        expect(siteProfileTerminology('residential').people).toBe('Residents');
        expect(siteProfileTerminology('facility')).toMatchObject({
            people: 'Attendees',
            occupancy: 'Places',
            plan: 'Plan & Zones',
        });
        expect(siteProfileTerminology('head_office')).toMatchObject({
            people: 'Clients',
            occupancy: 'Resources',
            plan: 'Plan & Resources',
        });
    });

    it('hides inapplicable head-office tabs without changing other groups', () => {
        const visible = visibleSiteProfileTabs('head_office', allPermissions);
        const ids = visible.map((tab) => tab.id);

        expect(ids).not.toContain('clients');
        expect(ids).not.toContain('shift_coverage');
        expect(ids).not.toContain('meal_planner');
        expect(ids).toContain('contacts');
        expect(ids).toContain('hazards');
        expect(ids).toContain('documents');
    });

    it('keeps permission-restricted navigation neutral and strips its counts', () => {
        const visible = visibleSiteProfileTabs('house', {
            ...allPermissions,
            'finance.dashboard': false,
        });
        const financials = visible.find((tab) => tab.id === 'financials');

        expect(financials).toMatchObject({ locked: true, count: undefined });
    });

    it('unlocks tabs when the viewer holds any accepted scoped permission', () => {
        const clients = visibleSiteProfileTabs('house', {
            'clients.viewAssigned': true,
        }).find((tab) => tab.id === 'clients');
        const assets = visibleSiteProfileTabs('house', {
            'assets.viewAssigned': true,
        }).find((tab) => tab.id === 'assets');

        expect(clients?.locked).toBe(false);
        expect(assets?.locked).toBe(false);
    });

    it('normalizes unknown or type-hidden deep links without looping', () => {
        expect(
            resolveSiteProfileTab('hazards', 'house', allPermissions).id,
        ).toBe('hazards');
        expect(
            resolveSiteProfileTab('meal_planner', 'head_office', allPermissions)
                .id,
        ).toBe('overview');
        expect(
            resolveSiteProfileTab('retired-tab', 'house', allPermissions).id,
        ).toBe('overview');
        expect(
            resolveSiteProfileTab('financials', 'house', {
                ...allPermissions,
                'finance.dashboard': false,
            }).id,
        ).toBe('overview');
    });

    it('maps every deferred tab to exactly one optional payload group', () => {
        expect(dataGroupForTab('clients')).toBe('peopleData');
        expect(dataGroupForTab('hazards')).toBe('safetyData');
        expect(dataGroupForTab('checklists')).toBe('operationsData');
        expect(dataGroupForTab('vendors')).toBe('adminData');
        expect(dataGroupForTab('overview')).toBeUndefined();
    });

    it('totals warning counts by group without treating ordinary counts as warnings', () => {
        expect(
            warningTotalsByGroup({
                clients: 8,
                staff_requirements: 2,
                hazards: 3,
                inspections: 1,
                documents: 4,
            }),
        ).toEqual({
            overview: 0,
            people: 2,
            safety: 4,
            operations: 0,
            admin: 4,
        });
    });
});
