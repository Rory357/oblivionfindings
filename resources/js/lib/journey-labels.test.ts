import { describe, expect, it } from 'vitest';
import { journeyActivityLabel, journeyTermDefinition } from './journey-labels';

describe('journey activity labels', () => {
    it.each([
        ['healthSafety.correctiveAction.completed', 'Owner submitted evidence'],
        [
            'healthSafety.correctiveAction.returnedForRework',
            'Action returned for rework',
        ],
        [
            'healthSafety.correctiveAction.verified',
            'Action independently verified',
        ],
        ['healthSafety.event.handoverAccepted', 'H&S handover accepted'],
        ['controlRoom.shift.handoverPrepared', 'Shift handover prepared'],
        [
            'controlRoom.shift.handoverAccepted',
            'Incoming lead accepted handover',
        ],
    ])('translates %s', (action, label) => {
        expect(journeyActivityLabel(action)).toBe(label);
    });

    it('uses a safe human fallback for unknown machine actions', () => {
        expect(journeyActivityLabel('App\\Models\\Unknown.update')).toBe(
            'Activity recorded',
        );
    });
});

describe('journey term definitions', () => {
    it('defines every ambiguous cross-module term in plain language', () => {
        expect(journeyTermDefinition('status')).toBe(
            'The current lifecycle state.',
        );
        expect(journeyTermDefinition('severity')).toBe('Potential harm.');
        expect(journeyTermDefinition('priority')).toBe('Work order.');
        expect(journeyTermDefinition('escalation')).toBe(
            'Management attention level.',
        );
        expect(journeyTermDefinition('sla')).toBe('Required response time.');
        expect(journeyTermDefinition('governance_stage')).toBe(
            'Accountable review state.',
        );
    });
});
