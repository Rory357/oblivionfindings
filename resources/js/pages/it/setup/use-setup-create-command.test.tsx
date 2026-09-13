import { act, renderHook, waitFor } from '@testing-library/react';
import axios from 'axios';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { useSetupCreateCommand } from './use-setup-create-command';

const options = { active: true, actorId: 8, resource: 'teams' as const };
const marker = 'it.setup.pending-command.v1.actor.8.teams';
function committed(requestUuid: string, viewer = 8) {
    return {
        status: 200,
        data: {
            status: 'committed',
            data: {
                viewer_user_id: viewer,
                resource: 'teams',
                request_uuid: requestUuid,
                id: 45,
                configuration_version: 'a'.repeat(64),
                committed_configuration_version: 'a'.repeat(64),
                replayed: true,
            },
        },
    };
}
describe('Setup create command recovery', () => {
    beforeEach(() => sessionStorage.clear());
    afterEach(() => {
        vi.restoreAllMocks();
        vi.useRealTimers();
        sessionStorage.clear();
    });
    it('bounds an unresponsive transport without claiming server cancellation and ignores its late result', async () => {
        vi.useFakeTimers();
        let finish!: (value: ReturnType<typeof committed>) => void;
        vi.spyOn(axios, 'post').mockImplementation(
            () =>
                new Promise((resolve) => {
                    finish = resolve;
                }),
        );
        const { result } = renderHook(() =>
            useSetupCreateCommand({ ...options, timeoutMs: 50 }),
        );
        let request!: Promise<unknown>;
        act(() => {
            request = result.current.submit({ name: 'Retained original' });
        });
        const uuid = result.current.requestUuid;
        await act(async () => {
            await vi.advanceTimersByTimeAsync(51);
        });
        expect(result.current.busy).toBe(false);
        expect(result.current.state).toBe('unknown');
        expect(result.current.canEdit).toBe(false);
        await act(async () => {
            finish(committed(uuid));
            await request;
        });
        expect(result.current.result).toBeNull();
        expect(sessionStorage.getItem(marker)).toBe(uuid);
    });
    it('retains a competing valid marker and requires its identity before restoring any different form', async () => {
        const post = vi.spyOn(axios, 'post');
        const { result } = renderHook(() => useSetupCreateCommand(options));
        const prior = crypto.randomUUID();
        sessionStorage.setItem(marker, prior);
        await act(async () => {
            await result.current.submit({
                name: 'Do not send this newer form',
            });
        });
        expect(post).not.toHaveBeenCalled();
        expect(result.current.requestUuid).toBe(prior);
        expect(result.current.accepts(crypto.randomUUID())).toBe(false);
        expect(result.current.canRetry).toBe(false);
        expect(result.current.canEdit).toBe(false);
    });
    it('retains only opaque command identity before send and replays the exact frozen body after a lost acknowledgement', async () => {
        const post = vi
            .spyOn(axios, 'post')
            .mockRejectedValueOnce(new Error('lost response'));
        const { result } = renderHook(() => useSetupCreateCommand(options));
        const uuid = result.current.requestUuid;
        const fields = { name: 'Private original', is_active: false };
        await act(async () => {
            await result.current.submit(fields);
        });
        fields.name = 'Changed outside the frozen request';
        expect(result.current.state).toBe('unknown');
        expect(sessionStorage.getItem(marker)).toBe(uuid);
        expect(JSON.stringify(sessionStorage)).not.toContain(
            'Private original',
        );
        expect(result.current.canEdit).toBe(false);
        post.mockResolvedValueOnce(committed(uuid));
        await act(async () => {
            await result.current.retry();
        });
        expect(post.mock.calls[1][1]).toEqual(post.mock.calls[0][1]);
        expect(post.mock.calls[1][1]).toMatchObject({
            name: 'Private original',
            request_uuid: uuid,
            actor_user_id: 8,
        });
        expect(result.current.result?.id).toBe(45);
        expect(sessionStorage.getItem(marker)).toBeNull();
    });
    it('releases a first definitive validation rejection and signals RAM settlement without pretending a save occurred', async () => {
        const post = vi
            .spyOn(axios, 'post')
            .mockResolvedValueOnce({
                status: 422,
                data: { errors: { name: ['Already used.'] } },
            });
        const { result } = renderHook(() => useSetupCreateCommand(options));
        await act(async () => {
            await result.current.submit({ name: 'Invalid original' });
        });
        expect(result.current.state).toBe('validation');
        expect(result.current.canEdit).toBe(true);
        expect(result.current.settledToken).toBe(1);
        expect(sessionStorage.getItem(marker)).toBeNull();
        expect(result.current.errors.name).toBe('Already used.');
        expect(result.current.result).toBeNull();
        post.mockResolvedValueOnce(committed(result.current.requestUuid));
        await act(async () => {
            await result.current.submit({ name: 'Corrected proposal' });
        });
        expect(post.mock.calls[1][1]).toMatchObject({
            name: 'Corrected proposal',
        });
    });
    it('does not treat validation on a retry as proof the earlier unknown command failed', async () => {
        vi.spyOn(axios, 'post')
            .mockRejectedValueOnce(new Error('lost'))
            .mockResolvedValueOnce({
                status: 422,
                data: { errors: { name: 'Not allowed now' } },
            });
        const { result } = renderHook(() => useSetupCreateCommand(options));
        await act(async () => {
            await result.current.submit({ name: 'Original' });
        });
        await act(async () => {
            await result.current.retry();
        });
        expect(result.current.state).toBe('unknown');
        expect(result.current.canEdit).toBe(false);
        expect(result.current.settledToken).toBe(0);
        expect(sessionStorage.getItem(marker)).toBe(result.current.requestUuid);
    });
    it('recovers a reloaded opaque marker without fabricating the missing original fields', async () => {
        const uuid = crypto.randomUUID();
        sessionStorage.setItem(marker, uuid);
        const post = vi.spyOn(axios, 'post').mockResolvedValueOnce({
            status: 200,
            data: {
                status: 'not_found',
                data: {
                    viewer_user_id: 8,
                    resource: 'teams',
                    request_uuid: uuid,
                    retry_same_command: true,
                },
            },
        });
        const { result } = renderHook(() => useSetupCreateCommand(options));
        expect(result.current.canRetry).toBe(false);
        expect(result.current.canEdit).toBe(false);
        await act(async () => {
            await result.current.recover();
        });
        expect(result.current.state).toBe('not_found');
        expect(result.current.canEdit).toBe(false);
        expect(result.current.result).toBeNull();
        post.mockResolvedValueOnce({
            status: 200,
            data: {
                status: 'cancelled',
                data: {
                    viewer_user_id: 8,
                    resource: 'teams',
                    request_uuid: uuid,
                    cancelled: true,
                },
            },
        });
        await act(async () => {
            expect(await result.current.cancelCommand()).toEqual({
                cancelled: true,
                request_uuid: uuid,
            });
        });
        expect(result.current.requestUuid).not.toBe(uuid);
        expect(result.current.canEdit).toBe(true);
        expect(result.current.settledToken).toBe(1);
        expect(sessionStorage.getItem(marker)).toBeNull();
    });
    it('shows a committed winner when explicit cancellation races the original create', async () => {
        const uuid = crypto.randomUUID();
        sessionStorage.setItem(marker, uuid);
        vi.spyOn(axios, 'post').mockResolvedValueOnce(committed(uuid));
        const { result } = renderHook(() => useSetupCreateCommand(options));
        await act(async () => {
            await result.current.cancelCommand();
        });
        expect(result.current.state).toBe('committed');
        expect(result.current.result?.id).toBe(45);
        expect(result.current.canEdit).toBe(false);
    });
    it('cancels only the wait and rejects its late response while retaining the command', async () => {
        let finish!: (value: ReturnType<typeof committed>) => void;
        vi.spyOn(axios, 'post').mockImplementation(
            () =>
                new Promise((resolve) => {
                    finish = resolve;
                }),
        );
        const { result } = renderHook(() => useSetupCreateCommand(options));
        let request!: Promise<unknown>;
        act(() => {
            request = result.current.submit({ name: 'Exact original' });
        });
        const uuid = result.current.requestUuid;
        act(() => result.current.cancelWait());
        await act(async () => {
            finish(committed(uuid));
            await request;
        });
        expect(result.current.state).toBe('unknown');
        expect(result.current.result).toBeNull();
        expect(result.current.canRetry).toBe(true);
        expect(sessionStorage.getItem(marker)).toBe(uuid);
    });
    it('does not accept a wrong-viewer acknowledgement or erase a newer independent marker', async () => {
        vi.spyOn(axios, 'post').mockImplementation(async (_url, data) =>
            committed((data as { request_uuid: string }).request_uuid, 9),
        );
        const { result } = renderHook(() => useSetupCreateCommand(options));
        await act(async () => {
            await result.current.submit({ name: 'Private original' });
        });
        expect(result.current.state).toBe('unknown');
        expect(result.current.result).toBeNull();
        const newer = crypto.randomUUID();
        sessionStorage.setItem(marker, newer);
        vi.mocked(axios.post).mockResolvedValueOnce(
            committed(result.current.requestUuid),
        );
        await act(async () => {
            await result.current.recover();
        });
        expect(sessionStorage.getItem(marker)).toBe(newer);
    });
    it('ignores a late original actor response after account change and cannot restore a conflicting intake command', async () => {
        let finish!: (value: ReturnType<typeof committed>) => void;
        vi.spyOn(axios, 'post').mockImplementation(
            () =>
                new Promise((resolve) => {
                    finish = resolve;
                }),
        );
        const { result, rerender } = renderHook(
            (actorId) => useSetupCreateCommand({ ...options, actorId }),
            { initialProps: 8 },
        );
        let request!: Promise<unknown>;
        act(() => {
            request = result.current.submit({ name: 'Private original' });
        });
        const originalUuid = result.current.requestUuid;
        rerender(9);
        await act(async () => {
            finish(committed(originalUuid));
            await request;
        });
        expect(result.current.result).toBeNull();
        expect(sessionStorage.getItem(marker)).toBe(originalUuid);
        rerender(8);
        await waitFor(() => expect(result.current.state).toBe('unknown'));
        expect(
            result.current.restore(
                crypto.randomUUID(),
                { name: 'Another form' },
                false,
            ),
        ).toBe(false);
    });
});
