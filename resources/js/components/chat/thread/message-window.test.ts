import { describe, expect, it } from 'vitest';
import { sumHeights, visibleMessageRange } from './message-window';

describe('message window', () => {
    const rows = Array.from({ length: 300 }, () => 100);

    it('keeps the visible rows and the overscan on each side', () => {
        // Rows 50-55 cover 5,000-5,600 px.
        expect(visibleMessageRange(rows, 5000, 600, 10)).toEqual({
            start: 40,
            end: 65,
        });
    });

    it('stops at the first and last rows', () => {
        expect(visibleMessageRange(rows, 0, 600, 40)).toEqual({
            start: 0,
            end: 45,
        });
        expect(visibleMessageRange(rows, 29_400, 600, 40)).toEqual({
            start: 254,
            end: 299,
        });
        expect(visibleMessageRange(rows, 99_999, 600, 40)).toEqual({
            start: 259,
            end: 299,
        });
    });

    it('follows rows of different heights', () => {
        const mixed = [40, 400, 40, 40, 40, 40];
        // Row 1 is 40-440, row 2 440-480, row 3 480-520: a 100 px view at 420 shows rows 1 to 3.
        expect(visibleMessageRange(mixed, 420, 100, 0)).toEqual({
            start: 1,
            end: 3,
        });
    });

    it('has nothing to show for an empty history', () => {
        expect(visibleMessageRange([], 0, 600)).toEqual({ start: 0, end: -1 });
    });

    it('sizes the spacers from the rows they replace', () => {
        const mixed = [10, 20, 30, 40];
        expect(sumHeights(mixed, 0, 2)).toBe(30);
        expect(sumHeights(mixed, 3, 4)).toBe(40);
        expect(sumHeights(mixed, 4, 9)).toBe(0);
    });
});
