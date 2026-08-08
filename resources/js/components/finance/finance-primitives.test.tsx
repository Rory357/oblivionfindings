import { act, renderHook } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { useFinanceTab } from './finance-tabs';
import { formatMoney, formatMoneyCompact } from './money';
import { journalBalance } from './posting-preview';
import { useWizard } from './wizard';

describe('formatMoney (en-NZ, NZD)', () => {
    it('formats numbers and decimal strings identically', () => {
        expect(formatMoney(1234.5)).toBe('$1,234.50');
        expect(formatMoney('1234.50')).toBe('$1,234.50');
        expect(formatMoney(0)).toBe('$0.00');
    });

    it('handles null/empty/garbage as zero', () => {
        expect(formatMoney(null)).toBe('$0.00');
        expect(formatMoney('')).toBe('$0.00');
        expect(formatMoney(undefined)).toBe('$0.00');
        expect(formatMoney('not-a-number')).toBe('$0.00');
    });

    it('prefixes a + for positive amounts when signed', () => {
        expect(formatMoney(50, { signed: true })).toBe('+$50.00');
        expect(formatMoney(-50, { signed: true })).toBe('-$50.00');
        expect(formatMoney(0, { signed: true })).toBe('$0.00');
    });

    it('compacts large amounts for hero KPI stats', () => {
        expect(formatMoneyCompact(500)).toBe('$500.00');
        expect(formatMoneyCompact(12000)).toMatch(/12(\.\d)?K/i);
    });
});

describe('journalBalance — double-entry balance check', () => {
    it('reports balanced when debits == credits (to the cent)', () => {
        const b = journalBalance([
            { accountName: 'Bank', debit: '100.00' },
            { accountName: 'Revenue', credit: 100 },
        ]);
        expect(b.balanced).toBe(true);
        expect(b.difference).toBe(0);
    });

    it('is immune to float drift (0.1 + 0.2 style)', () => {
        const b = journalBalance([
            { accountName: 'A', debit: 0.1 },
            { accountName: 'B', debit: 0.2 },
            { accountName: 'C', credit: 0.3 },
        ]);
        expect(b.balanced).toBe(true);
    });

    it('flags an out-of-balance journal with the difference', () => {
        const b = journalBalance([
            { accountName: 'A', debit: 100 },
            { accountName: 'B', credit: 90 },
        ]);
        expect(b.balanced).toBe(false);
        expect(b.difference).toBe(10);
    });

    it('an all-zero journal is not "balanced" (nothing to post)', () => {
        expect(journalBalance([{ accountName: 'A' }]).balanced).toBe(false);
    });
});

describe('useFinanceTab', () => {
    it('defaults to the given tab and switches', () => {
        const { result } = renderHook(() =>
            useFinanceTab('summary', { syncUrl: false }),
        );
        expect(result.current[0]).toBe('summary');
        act(() => result.current[1]('executive'));
        expect(result.current[0]).toBe('executive');
    });
});

describe('useWizard', () => {
    it('advances, clamps, and reports progress', () => {
        const { result } = renderHook(() => useWizard(3));
        expect(result.current.index).toBe(0);
        expect(result.current.progress).toBe(33);
        act(() => result.current.next());
        act(() => {
            result.current.next();
            result.current.next();
        });
        expect(result.current.index).toBe(2);
        expect(result.current.isLast).toBe(true);
        act(() => result.current.reset());
        expect(result.current.index).toBe(0);
    });
});
