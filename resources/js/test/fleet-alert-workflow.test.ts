import { describe, expect, it } from 'vitest';

import {
    countFleetAlertActions,
    fleetAlertNextAction,
    isFleetAlertActionEligible,
} from '@/lib/fleet-alert-workflow';

describe('Fleet alert Control Room handoff', () => {
    it.each([
        ['open', 'acknowledge'],
        ['ack', 'triage'],
        ['triaging', 'resolve'],
        ['confirmed', 'resolve'],
        ['resolved', null],
        ['closed', null],
        ['dismissed', null],
    ] as const)(
        'gives %s alerts one truthful next action',
        (status, action) => {
            expect(fleetAlertNextAction(status)).toBe(action);
        },
    );

    it('never offers resolve before triage has started', () => {
        expect(isFleetAlertActionEligible('open', 'resolve')).toBe(false);
        expect(isFleetAlertActionEligible('ack', 'resolve')).toBe(false);
        expect(isFleetAlertActionEligible('triaging', 'resolve')).toBe(true);
        expect(isFleetAlertActionEligible('confirmed', 'resolve')).toBe(true);
    });

    it('counts only the selected alerts ready for each bulk next step', () => {
        expect(
            countFleetAlertActions([
                { status: 'open' },
                { status: 'ack' },
                { status: 'ack' },
                { status: 'triaging' },
                { status: 'confirmed' },
                { status: 'resolved' },
            ]),
        ).toEqual({ acknowledge: 1, triage: 2, resolve: 2 });
    });
});
