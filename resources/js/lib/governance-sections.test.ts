import { describe, expect, it } from 'vitest';

import {
    GOVERNANCE_SECTIONS,
    governanceHubContainsUrl,
    governanceSectionForUrl,
    visibleSectionTabs,
} from './governance-sections';

const manager = {
    governance: {
        view: true,
        meetings: { view: true, manage: true },
        packs: { view: true },
        'ceo-reports': { view: true },
        resolutions: { view: true },
        actions: { view: true },
        risks: { view: true },
        compliance: { view: true },
        clinical: { view: true },
        'te-tiriti': { view: true },
        budgets: { view: true },
        spend: { view: true },
        strategy: { view: true },
        performance: { view: true },
        policies: { view: true },
        documents: { view: true },
        interests: { view: true },
        evaluations: { view: true },
        settings: { view: true },
        audit: { view: true },
    },
    roadmap: { view: true },
};

describe('Governance hubs', () => {
    it('groups every former sidebar register into one of eight hubs', () => {
        expect(GOVERNANCE_SECTIONS).toHaveLength(8);
        const hrefs = GOVERNANCE_SECTIONS.flatMap((section) =>
            visibleSectionTabs(section, manager).map((tab) => tab.href),
        );
        expect(hrefs).toEqual(
            expect.arrayContaining([
                '/governance/meetings',
                '/governance/packs',
                '/governance/ceo-reports',
                '/governance/resolutions',
                '/governance/actions',
                '/governance/risks',
                '/governance/compliance',
                '/governance/clinical',
                '/governance/te-tiriti',
                '/governance/budgets',
                '/governance/spend-approvals',
                '/governance/strategy',
                '/governance/performance',
                '/roadmap/dashboard',
                '/governance/policies',
                '/governance/documents',
                '/governance/records',
                '/governance/admin/board-members',
                '/governance/interests',
                '/governance/evaluations',
                '/governance/settings',
                '/governance/audit-log',
            ]),
        );
        expect(new Set(hrefs).size).toBe(hrefs.length);
    });

    it('resolves the hub and tab for register sub-pages and records', () => {
        expect(
            governanceSectionForUrl('/governance/risks/heatmap?x=1'),
        ).toMatchObject({ section: { key: 'assurance' }, tab: { key: 'risks' } });
        expect(
            governanceSectionForUrl('http://app.test/governance/packs/12'),
        ).toMatchObject({ section: { key: 'meetings' }, tab: { key: 'packs' } });
        expect(governanceSectionForUrl('/governance/dashboard')).toBeNull();
        expect(governanceSectionForUrl('/governance/meetingsx')).toBeNull();
    });

    it('keeps a hub sidebar entry active across its sibling registers', () => {
        expect(
            governanceHubContainsUrl('/governance/meetings', '/governance/ceo-reports/3'),
        ).toBe(true);
        // A viewer whose hub entry opens a later tab still gets the highlight.
        expect(
            governanceHubContainsUrl('/governance/packs', '/governance/meetings'),
        ).toBe(true);
        expect(
            governanceHubContainsUrl('/governance/meetings', '/governance/resolutions'),
        ).toBe(false);
        expect(
            governanceHubContainsUrl('/governance/dashboard', '/governance/dashboard'),
        ).toBe(false);
    });

    it('fails closed: tabs without the permission are hidden', () => {
        const member = { governance: { view: true, policies: { view: true } } };
        const records = GOVERNANCE_SECTIONS.find((s) => s.key === 'records')!;
        expect(visibleSectionTabs(records, member).map((t) => t.key)).toEqual([
            'policies',
            'records',
        ]);
        const board = GOVERNANCE_SECTIONS.find((s) => s.key === 'board')!;
        expect(visibleSectionTabs(board, member)).toEqual([]);
        expect(visibleSectionTabs(board, null)).toEqual([]);
    });
});
