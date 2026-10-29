import { describe, expect, it } from 'vitest';

import { launcherWorkflows } from './report-launcher';

const labels = (can: Record<string, unknown>) =>
    // eslint-disable-next-line @typescript-eslint/no-explicit-any -- partial permission fixtures
    launcherWorkflows(can as any).map((workflow) => workflow.label);

describe('Report launcher tiles', () => {
    it('shows a view-only reader nothing they would be refused', () => {
        expect(labels({ hazards: { view: true } })).toEqual([]);
    });

    it('names each tile after the page it opens and follows its permission', () => {
        expect(
            labels({
                hazards: { view: true, create: true },
                incidents: { create: true },
            }),
        ).toEqual([
            'Report an incident',
            'Report a hazard',
            'Record first aid',
            'Report a workplace injury',
            'Record a chemical exposure',
            'Plan an emergency drill',
            'Add a chemical',
        ]);
        expect(labels({ restraints: { create: true } })).toEqual([
            'Record a restraint',
        ]);
        expect(labels({ hazards: { view: true, manage: true } })).toContain(
            'Start working alone',
        );
        expect(labels({ hazards: { view: true, manage: true } })).toContain(
            'Add a health and safety representative',
        );
    });
});
