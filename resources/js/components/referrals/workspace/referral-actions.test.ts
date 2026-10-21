import { describe, expect, it, vi } from 'vitest';
import { referralActions } from './referral-actions';

const handlers = () => ({ open: vi.fn(), act: vi.fn(), handover: vi.fn() });
const everything = {
    assign: true,
    contact: true,
    convert: true,
    transition: true,
    'assessment.view': true,
    'funding.view': true,
};

describe('referralActions', () => {
    it('keeps every approved action listed, with a reason when it is blocked', () => {
        const items = referralActions({ stage: 'triage' }, {}, handlers());
        expect(items.map((item) => item.label)).toEqual([
            'Open referral',
            'Assign owner',
            'Log contact',
            'Set priority',
            'Review handover',
        ]);
        expect(items[0].disabled).toBeUndefined();
        expect(items[1].disabled).toBe('Requires Assign referrals');
        expect(items[2].disabled).toBe('Requires Log contact');
        expect(items[3].disabled).toBe(
            'Requires permission to edit or move referrals',
        );
        expect(items[4].disabled).toBe(
            'Requires Create / link supported person',
        );
    });

    it('enables permitted actions and routes them to the in-place wizard', () => {
        const run = handlers();
        const items = referralActions({ stage: 'assessment' }, everything, run);
        expect(items.every((item) => !item.disabled)).toBe(true);
        items[1].onClick?.();
        items[2].onClick?.();
        items[3].onClick?.();
        items[5].onClick?.();
        expect(run.act.mock.calls).toEqual([
            ['assign'],
            ['contact'],
            ['priority'],
            ['transition'],
        ]);
        items[4].onClick?.();
        expect(run.handover).toHaveBeenCalledOnce();
    });

    it('explains why closed referrals cannot be worked and hides stage review', () => {
        const items = referralActions(
            { stage: 'closed' },
            everything,
            handlers(),
        );
        expect(items).toHaveLength(5);
        expect(items[1].disabled).toMatch(/Reopen/);
        expect(items[2].disabled).toMatch(/Reopen/);
        expect(items[3].disabled).toBe(
            'Reopen the referral before changing its priority',
        );
        expect(items[4].disabled).toMatch(/Closed referrals/);
    });

    it('keeps the last priority of a handed-over referral', () => {
        const items = referralActions(
            { stage: 'converted' },
            everything,
            handlers(),
        );
        const priority = items.find((item) => item.label === 'Set priority');
        expect(priority?.disabled).toBe(
            'Handed-over referrals keep their last priority',
        );
    });

    it('lets anyone who edits or moves the referral set its priority', () => {
        const run = handlers();
        for (const permissions of [{ edit: true }, { transition: true }]) {
            const priority = referralActions(
                { stage: 'triage' },
                permissions,
                run,
            ).find((item) => item.label === 'Set priority');
            expect(priority?.disabled).toBeUndefined();
            priority?.onClick?.();
        }
        expect(run.act.mock.calls).toEqual([['priority'], ['priority']]);
    });
});
