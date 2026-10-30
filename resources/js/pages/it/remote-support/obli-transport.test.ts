import { afterEach, describe, expect, it, vi } from 'vitest';
import {
    TechnicianObliError,
    technicianObliJSON,
    type ChatCommand,
} from './obli-transport';
const scope = { actorId: 9001, deviceId: 9100, ticketId: 7 };
const uuid = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
const command: ChatCommand = {
    request_uuid: uuid,
    expected_actor_id: 9001,
    ticket_id: 7,
    configuration_version: 2,
    messages: [{ role: 'user', content: 'Check printing' }],
};
function response(value: unknown, status = 200) {
    return new Response(JSON.stringify(value), {
        status,
        headers: { 'Content-Type': 'application/json' },
    });
}
afterEach(() => {
    vi.unstubAllGlobals();
    document.querySelector('meta[name="csrf-token"]')?.remove();
});
describe('technician Obli transport', () => {
    it('binds GET recovery to actor/device/ticket without sending a POST or prompt', async () => {
        const fetch = vi
            .fn()
            .mockResolvedValue(
                response({ request_uuid: uuid, status: 'pending', text: null }),
            );
        vi.stubGlobal('fetch', fetch);
        await technicianObliJSON(
            scope,
            ('chat/' + uuid) as Parameters<typeof technicianObliJSON>[1],
            new AbortController().signal,
        );
        const [path, init] = fetch.mock.calls[0];
        expect(path).toBe(
            '/it/remote-support/devices/9100/obli/chat/' +
                uuid +
                '?expected_actor_id=9001&ticket_id=7',
        );
        expect(init).toMatchObject({
            method: 'GET',
            cache: 'no-store',
            credentials: 'same-origin',
            redirect: 'error',
        });
        expect(init.body).toBeUndefined();
    });
    it('uses web CSRF for an exact immutable POST and rejects mixed scopes locally', async () => {
        const meta = document.createElement('meta');
        meta.name = 'csrf-token';
        meta.content = 'fictional-web-token';
        document.head.append(meta);
        const fetch = vi
            .fn()
            .mockResolvedValue(
                response({ request_uuid: uuid, status: 'pending', text: null }),
            );
        vi.stubGlobal('fetch', fetch);
        await technicianObliJSON(
            scope,
            'chat',
            new AbortController().signal,
            command,
        );
        expect(fetch.mock.calls[0][1]).toMatchObject({
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': 'fictional-web-token' },
            body: JSON.stringify(command),
        });
        await expect(
            technicianObliJSON(
                { ...scope, deviceId: 0 },
                'chat',
                new AbortController().signal,
                command,
            ),
        ).rejects.toThrow();
        await expect(
            technicianObliJSON(scope, 'chat', new AbortController().signal, {
                ...command,
                expected_actor_id: 3,
            }),
        ).rejects.toThrow();
        await expect(
            technicianObliJSON(
                scope,
                'options',
                new AbortController().signal,
                command,
            ),
        ).rejects.toThrow();
        expect(fetch).toHaveBeenCalledTimes(1);
    });
    it('bounds replies and does not expose server error text', async () => {
        const fetch = vi
            .fn()
            .mockResolvedValueOnce(
                new Response('x'.repeat(131073), {
                    headers: { 'Content-Type': 'application/json' },
                }),
            )
            .mockResolvedValueOnce(
                response(
                    { code: 'context_busy', message: 'private server detail' },
                    503,
                ),
            )
            .mockResolvedValueOnce(
                new Response('<html>sign in</html>', {
                    headers: { 'Content-Type': 'text/html' },
                }),
            );
        vi.stubGlobal('fetch', fetch);
        await expect(
            technicianObliJSON(scope, 'options', new AbortController().signal),
        ).rejects.toThrow('too large');
        const error = await technicianObliJSON(
            scope,
            'options',
            new AbortController().signal,
        ).catch((e) => e);
        expect(error).toBeInstanceOf(TechnicianObliError);
        if (!(error instanceof TechnicianObliError))
            throw Error('Unexpected error type');
        expect(error.code).toBe('context_busy');
        expect(error.message).not.toContain('private');
        await expect(
            technicianObliJSON(scope, 'options', new AbortController().signal),
        ).rejects.toThrow();
    });
});
