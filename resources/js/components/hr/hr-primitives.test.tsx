import { act, renderHook } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { kiaOra } from './hr-hero';
import { STATUS_TONE_CLASS, statusTone } from './status-badge';
import { useWizard } from './wizard';

describe('statusTone', () => {
    it('maps known statuses to tones (case-insensitive)', () => {
        expect(statusTone('approved')).toBe('success');
        expect(statusTone('Pending')).toBe('warning');
        expect(statusTone('expired')).toBe('critical');
        expect(statusTone('locked')).toBe('primary');
        expect(statusTone('submitted')).toBe('info');
    });

    it('falls back to neutral for unknown statuses', () => {
        expect(statusTone('frobnicated')).toBe('neutral');
        expect(statusTone('')).toBe('neutral');
    });
});

describe('StatusBadge tone classes', () => {
    it('never uses an invisible same-token bg+text pair', () => {
        for (const cls of Object.values(STATUS_TONE_CLASS)) {
            const bg = cls.match(/bg-(\S+)/)?.[1];
            const text = cls.match(/text-(\S+)/)?.[1];
            // The recurring bug is `bg-status-x text-status-x` (same colour →
            // invisible label). Every tone must pair distinct bg/text tokens.
            expect(Boolean(bg) && Boolean(text) && bg === text).toBe(false);
        }
    });
});

describe('kiaOra', () => {
    it('greets by first name', () => {
        expect(kiaOra('Ana')).toBe('Kia ora Ana');
    });

    it('falls back when no name is known', () => {
        expect(kiaOra('')).toBe('Kia ora team');
        expect(kiaOra(null)).toBe('Kia ora team');
        expect(kiaOra(undefined, 'everyone')).toBe('Kia ora everyone');
    });
});

describe('useWizard', () => {
    it('advances, clamps, and reports progress', () => {
        const { result } = renderHook(() => useWizard(3));
        expect(result.current.index).toBe(0);
        expect(result.current.isFirst).toBe(true);
        expect(result.current.progress).toBe(33);

        act(() => result.current.next());
        expect(result.current.index).toBe(1);

        act(() => {
            result.current.next();
            result.current.next();
        });
        expect(result.current.index).toBe(2); // clamped at last step
        expect(result.current.isLast).toBe(true);
        expect(result.current.progress).toBe(100);

        act(() => result.current.back());
        expect(result.current.index).toBe(1);

        act(() => result.current.goTo(99));
        expect(result.current.index).toBe(2); // clamped

        act(() => result.current.reset());
        expect(result.current.index).toBe(0);
    });
});
