import {
    actionFromUrl,
    sectionFromUrl,
} from '@/pages/health-safety/events/show';
import { describe, expect, it } from 'vitest';

describe('H&S event direct-action links', () => {
    it.each([
        ['?action=accept-handover', 'accept_handover'],
        ['?action=worksafe-decision', 'worksafe_decision'],
        ['?action=worksafe-notify', 'worksafe_notify'],
        ['?action=worksafe-acknowledge', 'worksafe_acknowledge'],
        ['?action=investigation', 'investigation'],
    ] as const)('maps %s to the matching event pane', (query, expected) => {
        expect(actionFromUrl(`/health-safety/events/17${query}`)).toBe(
            expected,
        );
    });

    it('ignores unsupported action values', () => {
        expect(
            actionFromUrl('/health-safety/events/17?action=unknown'),
        ).toBeNull();
    });

    it('opens a direct event section without inventing a workflow action', () => {
        expect(
            sectionFromUrl('/health-safety/events/17?section=investigation'),
        ).toBe('investigation');
        expect(
            sectionFromUrl('/health-safety/events/17?section=unknown'),
        ).toBeNull();
    });
});
