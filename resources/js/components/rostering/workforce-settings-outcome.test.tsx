import { router } from '@inertiajs/react';
import { act, renderHook } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
    settingsReceipt,
    useSettingsCommand,
    type SettingsCommand,
} from './workforce-settings-outcome';
vi.mock('@inertiajs/react', () => ({ router: { reload: vi.fn() } }));
const expected: SettingsCommand = {
    action: 'preferences',
    actor_id: 7,
    expected_revision: 'a'.repeat(64),
    values: { default_tab: 'calendar', roster_view: 'list' },
};
const receipt = {
    ...expected,
    prior_revision: expected.expected_revision,
    revision: 'b'.repeat(64),
    changed: true,
};
const page = (value: unknown, actor = 7) =>
    ({
        props: {
            auth: { user: { id: actor } },
            flash: { workforce_settings_result: value },
        },
    }) as never;
beforeEach(() => vi.resetAllMocks());
describe('Workforce settings exact outcomes', () => {
    it('accepts matching persisted preferences without substituting concurrent page values', () => {
        expect(settingsReceipt(page(receipt), expected)).toEqual(receipt);
    });
    it.each([
        ['missing', null],
        ['actor', { ...receipt, actor_id: 8 }],
        ['action', { ...receipt, action: 'staffing_rules' }],
        ['prior revision', { ...receipt, prior_revision: 'c'.repeat(64) }],
        [
            'requested revision',
            { ...receipt, expected_revision: 'c'.repeat(64) },
        ],
        ['invalid result revision', { ...receipt, revision: 'bad' }],
        [
            'different values',
            { ...receipt, values: { ...receipt.values, roster_view: 'grid' } },
        ],
        ['missing changed state', { ...receipt, changed: undefined }],
    ])('rejects %s evidence', (_, value) =>
        expect(settingsReceipt(page(value), expected)).toBeNull(),
    );
    it('does not accept a result projected for another signed-in account', () => {
        expect(settingsReceipt(page(receipt, 8), expected)).toBeNull();
    });
    it('distinguishes staged staffing refresh from no-op and rejects missing staging identity', () => {
        const command = {
            ...expected,
            action: 'staffing_rules' as const,
            values: { max_hours_per_day: 12 },
        };
        const saved = {
            ...receipt,
            ...command,
            refresh: { status: 'staged', recheck_id: 5, source_version: 2 },
        };
        expect(settingsReceipt(page(saved), command)).toEqual(saved);
        expect(
            settingsReceipt(
                page({
                    ...saved,
                    refresh: { ...saved.refresh, recheck_id: null },
                }),
                command,
            ),
        ).toBeNull();
        const noop = {
            ...saved,
            changed: false,
            revision: expected.expected_revision,
            refresh: {
                status: 'not_requested',
                recheck_id: null,
                source_version: null,
            },
        };
        expect(settingsReceipt(page(noop), command)).toEqual(noop);
        expect(
            settingsReceipt(
                page({ ...noop, revision: 'b'.repeat(64) }),
                command,
            ),
        ).toBeNull();
    });
});
describe('Workforce settings command recovery', () => {
    it('locks same-tick requests and confirms only its immutable command', () => {
        const hook = renderHook(() => useSettingsCommand(7));
        const send = vi.fn();
        const confirmed = vi.fn();
        act(() => {
            hook.result.current.run(expected, send, confirmed);
            hook.result.current.run(expected, send, confirmed);
        });
        expect(send).toHaveBeenCalledOnce();
        act(() => {
            send.mock.calls[0][0].onSuccess(page(receipt));
            send.mock.calls[0][0].onFinish();
        });
        expect(confirmed).toHaveBeenCalledWith(receipt);
        expect(hook.result.current.locked).toBe(false);
    });
    it.each(['missing', 'cancel', 'finish', 'empty-errors', 'throw'])(
        'holds %s outcomes until an explicit valid read',
        (event) => {
            const hook = renderHook(() => useSettingsCommand(7));
            const send = vi.fn();
            const confirmed = vi.fn();
            if (event === 'throw')
                send.mockImplementation(() => {
                    throw new Error('Network');
                });
            act(() => hook.result.current.run(expected, send, confirmed));
            if (event !== 'throw')
                act(() => {
                    const options = send.mock.calls[0][0];
                    if (event === 'missing') options.onSuccess(page(null));
                    if (event === 'cancel') options.onCancel();
                    if (event === 'empty-errors') options.onError({});
                    options.onFinish();
                    hook.result.current.run(expected, send, confirmed);
                });
            expect(send).toHaveBeenCalledOnce();
            expect(confirmed).not.toHaveBeenCalled();
            expect(hook.result.current.uncertain).toBe(true);
            const reviewed = vi.fn();
            act(() =>
                hook.result.current.check(
                    ['preferences'],
                    (props) =>
                        props.preferences === 'complete' ? 'accepted' : null,
                    reviewed,
                ),
            );
            const read = vi.mocked(router.reload).mock.calls.at(-1)![0]!;
            act(() => {
                read.onSuccess?.({
                    props: {
                        auth: { user: { id: 7 } },
                        preferences: 'incomplete',
                    },
                } as never);
                read.onFinish?.({} as never);
            });
            expect(hook.result.current.uncertain).toBe(true);
            expect(reviewed).not.toHaveBeenCalled();
            act(() =>
                hook.result.current.check(
                    ['preferences'],
                    (props) =>
                        props.preferences === 'complete' ? 'accepted' : null,
                    reviewed,
                ),
            );
            const next = vi.mocked(router.reload).mock.calls.at(-1)![0]!;
            act(() => {
                next.onSuccess?.({
                    props: {
                        auth: { user: { id: 7 } },
                        preferences: 'complete',
                    },
                } as never);
                next.onFinish?.({} as never);
            });
            expect(hook.result.current.uncertain).toBe(false);
            expect(reviewed).toHaveBeenCalledWith('accepted');
        },
    );
    it('retains a known validation rejection without falsely holding an unknown result', () => {
        const hook = renderHook(() => useSettingsCommand(7));
        const send = vi.fn();
        act(() => hook.result.current.run(expected, send, vi.fn()));
        act(() => {
            send.mock.calls[0][0].onError({
                expected_revision: 'Changed elsewhere',
            });
            send.mock.calls[0][0].onFinish();
        });
        expect(hook.result.current.error).toBe('Changed elsewhere');
        expect(hook.result.current.uncertain).toBe(false);
    });
    it('ignores late command results after the form unmounts', () => {
        const hook = renderHook(() => useSettingsCommand(7));
        const send = vi.fn();
        const confirmed = vi.fn();
        act(() => hook.result.current.run(expected, send, confirmed));
        hook.unmount();
        act(() => {
            send.mock.calls[0][0].onSuccess(page(receipt));
            send.mock.calls[0][0].onFinish();
        });
        expect(confirmed).not.toHaveBeenCalled();
    });
});
