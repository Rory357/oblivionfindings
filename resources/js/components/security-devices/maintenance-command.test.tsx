import { act, cleanup, renderHook, waitFor } from '@testing-library/react';
import axios from 'axios';
import { webcrypto } from 'node:crypto';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    canonicalMaintenanceInput,
    maintenanceCommandReply,
    maintenanceMarkerKey,
    type MaintenancePending,
} from './maintenance-command';
import { maintenanceCommandFixture } from './maintenance-command-test-fixtures';
import { useMaintenanceCommand } from './use-maintenance-command';
vi.mock('axios');
const scope = {
    site_id: 14,
    device_id: null,
    monitor_id: null,
    monitor_device_id: null,
};
const body = {
    site_id: 14,
    device_id: null,
    monitor_id: null,
    name: 'Private maintenance draft',
    reason: 'A private reason that must stay out of browser storage.',
    starts_at: '2026-09-26T09:00:00+12:00',
    ends_at: '2026-09-26T10:00:00+12:00',
    timezone: 'Pacific/Auckland',
    dst_overlap: 'reject',
    dst_gap: 'reject',
    recurrence: null,
    recurrence_until: null,
};
const options = { actorId: 7, active: true, context: 'current-scope' };
beforeEach(() => {
    sessionStorage.clear();
    vi.stubGlobal('crypto', webcrypto);
    vi.mocked(axios.post).mockReset();
    vi.mocked(axios.patch).mockReset();
    vi.mocked(axios.get).mockReset();
    vi.mocked(axios.isAxiosError).mockReset();
});
afterEach(() => cleanup());
describe('maintenance immutable request recovery', () => {
    it('retains only an opaque identity and fingerprint, then retries the exact original body and key', async () => {
        vi.mocked(axios.post)
            .mockRejectedValueOnce(Error('synthetic reply loss'))
            .mockImplementationOnce(async (_url, input) => ({
                data: await maintenanceCommandFixture(
                    input as Record<string, unknown>,
                ),
            }));
        const { result } = renderHook(() => useMaintenanceCommand(options));
        await act(async () => {
            await result.current.submit('create', null, body, scope);
        });
        expect(result.current.phase).toBe('unconfirmed');
        expect(result.current.canRetry).toBe(true);
        const raw = sessionStorage.getItem(maintenanceMarkerKey)!;
        expect(raw).not.toContain(body.name);
        expect(raw).not.toContain(body.reason);
        expect(raw).not.toContain(body.starts_at);
        const first = vi.mocked(axios.post).mock.calls[0][1];
        await act(async () => {
            await result.current.retry();
        });
        expect(vi.mocked(axios.post).mock.calls[1][1]).toEqual(first);
        expect(result.current.phase).toBe('idle');
        expect(sessionStorage.getItem(maintenanceMarkerKey)).toBeNull();
    });
    it('reopens only recovery after a reload, preserves a later cancellation as current state and never posts a second create', async () => {
        vi.mocked(axios.post).mockRejectedValueOnce(
            Error('synthetic reply loss'),
        );
        const first = renderHook(() => useMaintenanceCommand(options));
        await act(async () => {
            await first.result.current.submit('create', null, body, scope);
        });
        const sent = vi.mocked(axios.post).mock.calls[0][1],
            reply = await maintenanceCommandFixture(
                sent as Record<string, unknown>,
            );
        reply.current_window = { id: 21, version: 3, state: 'cancelled' };
        first.unmount();
        vi.mocked(axios.get).mockResolvedValue({ data: reply });
        const second = renderHook(() => useMaintenanceCommand(options));
        expect(second.result.current.blocked).toBe(true);
        expect(second.result.current.canRetry).toBe(false);
        await act(async () => {
            await second.result.current.recover();
        });
        expect(axios.post).toHaveBeenCalledTimes(1);
        expect(axios.get).toHaveBeenCalledTimes(1);
        expect(second.result.current.message).toContain(
            'has since been cancelled',
        );
        expect(sessionStorage.getItem(maintenanceMarkerKey)).toBeNull();
    });
    it('ignores an old save after an account change and cannot clear a newer actor marker', async () => {
        let finish!: (value: unknown) => void;
        vi.mocked(axios.post).mockImplementationOnce(
            async () =>
                new Promise((resolve) => {
                    finish = resolve;
                }),
        );
        const { result, rerender } = renderHook(
            (props) => useMaintenanceCommand(props),
            { initialProps: options },
        );
        let waiting!: Promise<unknown>;
        act(() => {
            waiting = result.current.submit('create', null, body, scope);
        });
        await waitFor(() => expect(axios.post).toHaveBeenCalledTimes(1));
        const old = vi.mocked(axios.post).mock.calls[0][1];
        rerender({ ...options, actorId: 8 });
        const newer: MaintenancePending = {
            schema_version: 1,
            actor_id: 8,
            request_uuid: webcrypto.randomUUID(),
            operation: 'create',
            target_window_id: null,
            expected_version: null,
            request_hash: 'a'.repeat(64),
            scope,
        };
        sessionStorage.setItem(maintenanceMarkerKey, JSON.stringify(newer));
        await act(async () => {
            finish({
                data: await maintenanceCommandFixture(
                    old as Record<string, unknown>,
                ),
            });
            await waiting;
        });
        expect(result.current.message).toBeNull();
        expect(
            JSON.parse(sessionStorage.getItem(maintenanceMarkerKey)!),
        ).toEqual(newer);
    });
    it('keeps an unconfirmed identity after a wrong-actor recovery or a malformed success reply', async () => {
        vi.mocked(axios.post).mockResolvedValueOnce({
            data: '<html>not a receipt</html>',
        });
        const { result } = renderHook(() => useMaintenanceCommand(options));
        await act(async () => {
            await result.current.submit('create', null, body, scope);
        });
        const sent = vi.mocked(axios.post).mock.calls[0][1],
            reply = await maintenanceCommandFixture(
                sent as Record<string, unknown>,
            );
        reply.receipt.actor_id = 8;
        vi.mocked(axios.get).mockResolvedValue({ data: reply });
        await act(async () => {
            await result.current.recover();
        });
        expect(result.current.blocked).toBe(true);
        expect(result.current.phase).toBe('unconfirmed');
        expect(sessionStorage.getItem(maintenanceMarkerKey)).not.toBeNull();
        expect(axios.post).toHaveBeenCalledTimes(1);
    });
    it('does not send when a recovery marker cannot be stored', async () => {
        const setter = vi
            .spyOn(Storage.prototype, 'setItem')
            .mockImplementation(() => {
                throw Error('synthetic blocked storage');
            });
        const { result } = renderHook(() => useMaintenanceCommand(options));
        await act(async () => {
            await result.current.submit('create', null, body, scope);
        });
        expect(axios.post).not.toHaveBeenCalled();
        expect(result.current.message).toContain('No new request was sent');
        setter.mockRestore();
    });
    it('denies broadened receipt metadata, a changed fingerprint and inconsistent current version', async () => {
        const sent = {
                ...body,
                expected_actor_id: 7,
                request_uuid: webcrypto.randomUUID(),
            },
            reply = await maintenanceCommandFixture(
                sent as Record<string, unknown>,
            );
        const p: MaintenancePending = {
            schema_version: 1,
            actor_id: 7,
            request_uuid: sent.request_uuid,
            operation: 'create',
            target_window_id: null,
            expected_version: null,
            request_hash: reply.receipt.request_hash,
            scope,
        };
        expect(maintenanceCommandReply(reply, p)).toEqual(reply);
        expect(() =>
            maintenanceCommandReply(
                { ...reply, transport_url: 'https://unapproved.invalid' },
                p,
            ),
        ).toThrow();
        expect(() =>
            maintenanceCommandReply(
                {
                    ...reply,
                    receipt: { ...reply.receipt, request_hash: 'b'.repeat(64) },
                },
                p,
            ),
        ).toThrow();
        expect(() =>
            maintenanceCommandReply(
                {
                    ...reply,
                    current_window: { ...reply.current_window, version: 0 },
                },
                p,
            ),
        ).toThrow();
    });
    it.each(['create', 'update', 'cancel'] as const)(
        'canonicalizes padded %s input before sending and recovers the same marker after reload',
        async (operation) => {
            const paddedReason =
                '\u200B \t Review the padded technical action. \u00A0';
            const draft: Record<string, unknown> =
                operation === 'cancel'
                    ? { version: 3, reason: paddedReason }
                    : {
                          ...body,
                          ...(operation === 'update' ? { version: 3 } : {}),
                          name: '\u200B Private maintenance draft \uFEFF',
                          reason: paddedReason,
                          device_id: ' \t',
                          recurrence: ' ',
                          recurrence_until: '\r\n',
                      };
            const target = operation === 'create' ? null : 21;
            const writer =
                operation === 'update'
                    ? vi.mocked(axios.patch)
                    : vi.mocked(axios.post);
            writer.mockRejectedValueOnce(Error('synthetic lost reply'));
            const first = renderHook(() => useMaintenanceCommand(options));
            await act(async () => {
                await first.result.current.submit(
                    operation,
                    target,
                    draft,
                    scope,
                );
            });
            const sent = writer.mock.calls[0][1] as Record<string, unknown>;
            expect(sent.reason).toBe('Review the padded technical action.');
            if (operation !== 'cancel') {
                expect(sent.name).toBe('Private maintenance draft');
                expect(sent.device_id).toBeNull();
                expect(sent.recurrence).toBeNull();
                expect(sent.recurrence_until).toBeNull();
            }
            const marker = JSON.parse(
                sessionStorage.getItem(maintenanceMarkerKey)!,
            );
            expect(marker.request_uuid).toBe(sent.request_uuid);
            const reply = await maintenanceCommandFixture(
                sent,
                operation,
                target,
                scope,
            );
            writer.mockResolvedValueOnce({ data: reply });
            await act(async () => {
                await first.result.current.retry();
            });
            expect(writer.mock.calls[1][1]).toEqual(sent);
            expect(sessionStorage.getItem(maintenanceMarkerKey)).toBeNull();
            first.unmount();
            sessionStorage.setItem(
                maintenanceMarkerKey,
                JSON.stringify(marker),
            );
            vi.mocked(axios.get).mockResolvedValue({ data: reply });
            const reopened = renderHook(() => useMaintenanceCommand(options));
            await act(async () => {
                await reopened.result.current.recover();
            });
            expect(sessionStorage.getItem(maintenanceMarkerKey)).toBeNull();
            expect(writer).toHaveBeenCalledTimes(2);
        },
    );
    it('matches middleware trimming without removing meaningful internal whitespace', () => {
        const input = canonicalMaintenanceInput('cancel', {
            expected_actor_id: ' 7 ',
            request_uuid: ' 123bf440-5978-4afc-b050-299825dc9eeb ',
            version: ' 2 ',
            reason: '\u200B First  line\nsecond line \uFEFF',
        });
        expect(input).toEqual({
            expected_actor_id: 7,
            request_uuid: '123bf440-5978-4afc-b050-299825dc9eeb',
            version: 2,
            reason: 'First  line\nsecond line',
        });
    });
});
