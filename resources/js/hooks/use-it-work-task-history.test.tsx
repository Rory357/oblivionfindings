import {
    taskCompletionEntry as completionEntry,
    taskReadiness,
} from '@/test/it-work-task-fixtures';
import { act, cleanup, renderHook, waitFor } from '@testing-library/react';
import axios from 'axios';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { readItWorkTaskHistoryPage } from './it-work-task-lifecycle';
import { useItWorkTaskHistory } from './use-it-work-task-history';

const page = (
    nonce: string,
    sequences = [2],
    overrides: Record<string, unknown> = {},
) => ({
    status: 'ok',
    data: {
        viewer_user_id: 7,
        ticket_id: 42,
        task_id: 10,
        lock_version: 4,
        review_nonce: nonce,
        current_completion_id: 102,
        readiness: taskReadiness({
            completion: 'valid',
            can_complete: false,
            can_reopen: true,
        }),
        affected_tasks: [
            { id: 11, title: 'Dependent task', status: 'completed' },
        ],
        history: {
            entries: sequences.map(completionEntry),
            has_more: sequences.at(-1)! > 1,
            next_before_sequence:
                sequences.at(-1)! > 1 ? sequences.at(-1)! : null,
            total_count: 2,
        },
        ...overrides,
    },
});
const props = {
    actorId: 7,
    ticketId: 42,
    taskId: 10,
    enabled: true,
    onAccessLost: vi.fn(),
    onSessionExpired: vi.fn(),
};
const rejected = (status: number) => ({
    isAxiosError: true,
    response: { status },
});
beforeEach(() => {
    props.onAccessLost.mockClear();
    props.onSessionExpired.mockClear();
    vi.spyOn(axios, 'get').mockImplementation(async (_url, config) => ({
        status: 200,
        data: page(
            config?.params.review_nonce,
            config?.params.before_sequence ? [1] : [2],
        ),
    }));
});
afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
});

describe('canonical task history reader', () => {
    it('uses nonce-bound read-only GETs and appends only the same version in sequence', async () => {
        const { result } = renderHook(() => useItWorkTaskHistory(props));
        expect(axios.get).not.toHaveBeenCalled();
        await act(async () => {
            await result.current.load();
        });
        expect(
            result.current.page?.history.entries.map(
                ({ sequence }) => sequence,
            ),
        ).toEqual([2]);
        await act(async () => {
            await result.current.load(true);
        });
        expect(
            result.current.page?.history.entries.map(
                ({ sequence }) => sequence,
            ),
        ).toEqual([2, 1]);
        expect(result.current.page?.history.has_more).toBe(false);
        expect(vi.mocked(axios.get).mock.calls[1][1]?.params).toMatchObject({
            actor_user_id: 7,
            before_sequence: 2,
        });
        expect(
            vi.mocked(axios.get).mock.calls[1][1]?.params.review_nonce,
        ).not.toBe(vi.mocked(axios.get).mock.calls[0][1]?.params.review_nonce);
    });
    it.each([
        'viewer_user_id',
        'ticket_id',
        'task_id',
        'review_nonce',
    ] as const)('never exposes a response with mismatched %s', async (key) => {
        vi.mocked(axios.get).mockImplementationOnce(async (_url, config) => ({
            status: 200,
            data: page(config?.params.review_nonce, [1], {
                [key]: key === 'review_nonce' ? crypto.randomUUID() : 999,
            }),
        }));
        const { result } = renderHook(() => useItWorkTaskHistory(props));
        await act(async () => {
            await result.current.load();
        });
        expect(result.current.page).toBeNull();
        expect(result.current.error).not.toBe('');
        if (key === 'viewer_user_id')
            expect(props.onAccessLost).toHaveBeenCalledTimes(1);
    });
    it('rejects mixed-version pagination and permits an explicit fresh first-page retry', async () => {
        const { result } = renderHook(() => useItWorkTaskHistory(props));
        await act(async () => {
            await result.current.load();
        });
        vi.mocked(axios.get).mockImplementationOnce(async (_url, config) => ({
            status: 200,
            data: page(config?.params.review_nonce, [1], { lock_version: 5 }),
        }));
        await act(async () => {
            await result.current.load(true);
        });
        expect(result.current.error).toMatch(/changed/);
        expect(
            result.current.page?.history.entries.map(
                ({ sequence }) => sequence,
            ),
        ).toEqual([2]);
        await act(async () => {
            await result.current.load();
        });
        expect(result.current.error).toBe('');
    });
    it.each([401, 403, 404, 419])(
        'conceals an earlier response on current HTTP %s',
        async (status) => {
            const { result } = renderHook(() => useItWorkTaskHistory(props));
            await act(async () => {
                await result.current.load();
            });
            vi.mocked(axios.get).mockRejectedValueOnce(rejected(status));
            await act(async () => {
                await result.current.load();
            });
            expect(result.current.page).toBeNull();
            expect(result.current.concealed).toBe(true);
            expect(
                status === 401 || status === 419
                    ? props.onSessionExpired
                    : props.onAccessLost,
            ).toHaveBeenCalledTimes(1);
        },
    );
    it('cancels a pending request and ignores late completion after same-scope reopen', async () => {
        let deliver: ((value: unknown) => void) | undefined;
        vi.mocked(axios.get).mockImplementationOnce(
            (_url, config) =>
                new Promise((resolve) => {
                    deliver = () =>
                        resolve({
                            status: 200,
                            data: page(config?.params.review_nonce),
                        });
                }),
        );
        const { result, rerender } = renderHook(
            (enabled) => useItWorkTaskHistory({ ...props, enabled }),
            { initialProps: true },
        );
        let pending: Promise<unknown>;
        act(() => {
            pending = result.current.load();
        });
        rerender(false);
        rerender(true);
        await act(async () => {
            deliver?.(null);
            await pending;
        });
        expect(result.current.page).toBeNull();
        expect(result.current.busy).toBe(false);
    });
    it('bounds a slow request and keeps an explicit retry available', async () => {
        vi.mocked(axios.get).mockImplementationOnce(
            (_url, config) =>
                new Promise((_resolve, reject) =>
                    config?.signal?.addEventListener?.('abort', () =>
                        reject(new Error('cancelled')),
                    ),
                ),
        );
        const { result } = renderHook(() =>
            useItWorkTaskHistory({ ...props, timeoutMs: 10 }),
        );
        act(() => {
            void result.current.load();
        });
        await waitFor(() => expect(result.current.busy).toBe(false));
        expect(result.current.error).toMatch(/timed out/);
        await act(async () => {
            await result.current.load();
        });
        expect(result.current.page).not.toBeNull();
    });
    it('keeps legacy unknown provenance distinct from known-empty bindings', () => {
        const nonce = crypto.randomUUID();
        const entry = {
            ...completionEntry(1),
            source: 'legacy_snapshot',
            completed_at: null,
            completed_by_user_id: null,
            completed_by: null,
            prerequisite_completions: null,
        };
        const response = page(nonce, [1], {
            history: {
                entries: [entry],
                has_more: false,
                next_before_sequence: null,
                total_count: 1,
            },
        });
        expect(
            readItWorkTaskHistoryPage(response, {
                actorId: 7,
                ticketId: 42,
                taskId: 10,
                nonce,
            })?.history.entries[0],
        ).toMatchObject({ completed_at: null, prerequisite_completions: null });
    });
});
