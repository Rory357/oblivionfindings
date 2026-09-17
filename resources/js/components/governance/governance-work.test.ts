import { describe, expect, it } from 'vitest';

import {
    isDueStateStatus,
    receiptTitle,
    unavailableWorkMessage,
    workKindLabel,
    workStatusChip,
} from './governance-work';

describe('My work wording', () => {
    it('names each kind of work the same way everywhere', () => {
        expect(['vote', 'read', 'act', 'know'].map(workKindLabel)).toEqual([
            'Vote',
            'Read',
            'Do',
            'For your information',
        ]);
        // Unknown kinds never leak a raw key.
        expect(workKindLabel('something_new')).toBe('Do');
    });

    it('only colours due state; to-do and coming-up items are neutral', () => {
        expect(workStatusChip('overdue')).toEqual({ label: 'Overdue', variant: 'critical' });
        expect(workStatusChip('due_soon')).toEqual({ label: 'Due soon', variant: 'warning' });
        expect(workStatusChip('blocked')).toEqual({ label: 'Blocked', variant: 'critical' });
        expect(workStatusChip('pending')).toEqual({ label: 'To do', variant: 'neutral' });
        expect(workStatusChip('upcoming')).toEqual({ label: 'Coming up', variant: 'neutral' });
        expect(workStatusChip('completed')).toEqual({ label: 'Done', variant: 'success' });
        expect(isDueStateStatus('pending')).toBe(false);
        expect(isDueStateStatus('overdue')).toBe(true);
    });

    it('explains missing sources in plain words, not developer text', () => {
        expect(unavailableWorkMessage({ board_packs: 'available' })).toBeNull();
        expect(unavailableWorkMessage({ board_packs: 'unavailable' })).toBe(
            "We couldn't load your board packs right now, so this list may be missing items. Try again in a few minutes.",
        );
        expect(
            unavailableWorkMessage({ board_packs: 'unavailable', action_items: 'unavailable', policies: 'unavailable' }),
        ).toContain('board packs, actions and policies');
    });

    it('titles receipts without overclaiming', () => {
        expect(receiptTitle('vote')).toBe('Your vote is recorded');
        expect(receiptTitle('read')).toBe('Record of completion');
    });
});
