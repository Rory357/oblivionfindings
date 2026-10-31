import { webcrypto } from 'node:crypto';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { HandoverCommandSession, type HandoverCommandTransport } from './handover-command-session';
import type { HandoverCommandIdentity, HandoverCommandSource } from './handover-command-result';

const uuid = '7d886442-95d4-4bb6-a8b5-364489f0d22d' as const;
const source: HandoverCommandSource = {
    version: 1, scope: 'operations_handover_command_source', actor_id: 4, shift_id: 20,
    handover_id: 12, expected_version: 3, status: 'draft', expected_source: 'a'.repeat(64),
    can: { create: false, update: true, submit: true },
};
const fields = { handover_notes: 'Support after dinner.', submit: false, follow_up_items_text: '' };
const context = { recordId: 12, version: 3, shiftId: 20, canRead: true, canWrite: true };
type Response = Awaited<ReturnType<HandoverCommandTransport['request']>>;
const checked = (): Response => ({ status: 200, data: source });
const unknown = (): Response => ({ status: 202, data: { outcome: 'unconfirmed', saved: null } });
function deferred<T>() {
    let resolve!: (value: T) => void;
    const promise = new Promise<T>((finish) => { resolve = finish; });
    return { promise, resolve };
}
function identity(body: unknown): HandoverCommandIdentity {
    const wire = body as { request_id: string; expected_source: string; expected_version: number; intent_hash: string };
    return { version: 1, actor_id: 4, request_id: wire.request_id, action: 'update', shift_id: 20,
        requested_handover_id: 12, expected_version: wire.expected_version,
        expected_source: wire.expected_source, intent_hash: wire.intent_hash };
}
function confirmed(body: unknown): Response {
    return { status: 200, data: { version: 1, scope: 'operations_handover_command',
        identity: identity(body), outcome: 'confirmed',
        saved: { handover_id: 12, shift_id: 20, version: 4, status: 'draft', saved_at: '2026-10-12T04:05:06Z' } } };
}
function refused(body: unknown, message = 'Review the narrative.', status = 422): Response {
    return { status, data: { version: 1, scope: 'operations_handover_command',
        identity: identity(body), outcome: 'refused', saved: null,
        errors: { handover_notes: message } } };
}
function mockRequest() { return vi.fn<HandoverCommandTransport['request']>(); }

beforeEach(() => { vi.stubGlobal('crypto', { randomUUID: () => uuid, subtle: webcrypto.subtle }); });
afterEach(() => { vi.unstubAllGlobals(); });

describe('operations handover original command session', () => {
    it('proves no-send and retains editing when a preparation notification fails', async () => {
        const request = mockRequest();
        const work = new HandoverCommandSession(4, { request });
        work.subscribe(() => { if (work.snapshot().phase === 'preparing') throw new Error('View failed'); });
        expect(await work.save(20, 12, 3, fields)).toBeNull();
        expect(work.snapshot().phase).toBe('editing');
        expect(work.snapshot().message).toContain('Nothing was sent');
        expect(request).not.toHaveBeenCalled();
    });
    it('stops a prepared save before writer invocation when its saving notification fails', async () => {
        const request = mockRequest().mockResolvedValueOnce(checked());
        const work = new HandoverCommandSession(4, { request });
        work.subscribe(() => { if (work.snapshot().phase === 'saving') throw new Error('View failed'); });
        expect(await work.save(20, 12, 3, fields)).toBeNull();
        expect(work.canEdit()).toBe(true);
        expect(request.mock.calls.map(([method]) => method)).toEqual(['get']);
    });
    it('does not relabel a matched refusal as empty-reference uncertainty when its notification fails', async () => {
        const request = mockRequest().mockImplementation(async (method, _url, body) => method === 'get' ? checked() : refused(body));
        const work = new HandoverCommandSession(4, { request });
        work.subscribe(() => { if (work.snapshot().message === 'Review the narrative.') throw new Error('View failed'); });
        expect(await work.save(20, 12, 3, fields)).toBeNull();
        expect(work.snapshot()).toMatchObject({ phase: 'editing', message: 'Review the narrative.' });
        expect(work.canEdit()).toBe(true);
        expect(await work.recover()).toBeNull();
        expect(request.mock.calls.map(([method]) => method)).toEqual(['get', 'put']);
    });
    it('retains exact confirmed evidence when a confirmed notification fails', async () => {
        const request = mockRequest().mockImplementation(async (method, _url, body) => method === 'get' ? checked() : confirmed(body));
        const work = new HandoverCommandSession(4, { request });
        work.subscribe(() => { if (work.snapshot().phase === 'confirmed') throw new Error('View failed'); });
        const result = await work.save(20, 12, 3, fields);
        expect(result).not.toBeNull();
        expect(work.snapshot()).toMatchObject({ phase: 'confirmed', saved: result });
        expect(work.confirmedFor(result!, 4)).toBe(true);
        expect(request.mock.calls.map(([method]) => method)).toEqual(['get', 'put']);
    });
    it('notifies a finite subscription snapshot when listeners re-subscribe or add another listener', () => {
        const work = new HandoverCommandSession(4, { request: mockRequest() });
        const added = vi.fn();
        let unsubscribe = () => {};
        const first = vi.fn(() => {
            unsubscribe();
            unsubscribe = work.subscribe(first);
            work.subscribe(added);
        });
        unsubscribe = work.subscribe(first);
        work.notify();
        expect(first).toHaveBeenCalledOnce();
        expect(added).not.toHaveBeenCalled();
        work.notify();
        expect(first).toHaveBeenCalledTimes(2);
        expect(added).toHaveBeenCalledOnce();
    });
    it('reserves preparation synchronously and never sends a duplicate while preparing or uncertain', async () => {
        const read = deferred<Response>();
        const request = mockRequest().mockImplementation((method) => method === 'get' ? read.promise : Promise.resolve(unknown()));
        const work = new HandoverCommandSession(4, { request });
        const first = work.save(20, 12, 3, fields);
        expect(work.snapshot().phase).toBe('preparing');
        expect(work.canClose()).toBe(false);
        expect(await work.save(20, 12, 3, fields)).toBeNull();
        expect(request).toHaveBeenCalledTimes(1);
        read.resolve(checked());
        expect(await first).toBeNull();
        expect(work.snapshot().phase).toBe('uncertain');
        expect(await work.save(20, 12, 3, fields)).toBeNull();
        expect(request.mock.calls.map(([method]) => method)).toEqual(['get', 'put']);
    });

    it('returns to editing after a failed source GET without sending any write', async () => {
        const request = mockRequest().mockRejectedValue(new Error('Source read unavailable'));
        const work = new HandoverCommandSession(4, { request });
        expect(await work.save(20, 12, 3, fields)).toBeNull();
        expect(work.snapshot().phase).toBe('editing');
        expect(work.canEdit()).toBe(true);
        expect(work.canClose()).toBe(true);
        expect(request.mock.calls.map(([method]) => method)).toEqual(['get']);
        request.mockResolvedValueOnce(checked()).mockResolvedValueOnce(unknown());
        expect(await work.save(20, 12, 3, fields)).toBeNull();
        expect(request.mock.calls.map(([method]) => method)).toEqual(['get', 'get', 'put']);
    });

    it('proves no send for a missing, malformed, changed-parent or denied current source', async () => {
        for (const response of [
            { status: 503, data: null }, { status: 200, data: { ...source, expected_source: 'bad' } },
            { status: 200, data: { ...source, handover_id: 13 } },
            { status: 200, data: { ...source, expected_version: 4 } },
            { status: 200, data: { ...source, can: { ...source.can, update: false } } },
        ]) {
            const request = mockRequest().mockResolvedValue(response);
            const work = new HandoverCommandSession(4, { request });
            expect(await work.save(20, 12, 3, fields)).toBeNull();
            expect(work.snapshot().phase).toBe('editing');
            expect(work.snapshot().saved).toBeNull();
            expect(request.mock.calls.map(([method]) => method)).toEqual(['get']);
        }
    });

    it('permanently withholds a deferred source response after actor A to B to A', async () => {
        const read = deferred<Response>();
        const request = mockRequest().mockReturnValue(read.promise);
        const work = new HandoverCommandSession(4, { request });
        const pending = work.save(20, 12, 3, fields);
        work.publishActor(5); work.publishActor(4); work.notify();
        read.resolve(checked());
        expect(await pending).toBeNull();
        expect(work.snapshot()).toMatchObject({ phase: 'unavailable', saved: null });
        expect(await work.save(20, 12, 3, fields)).toBeNull();
        expect(request).toHaveBeenCalledTimes(1);
    });

    it('permanently withholds a deferred digest after actor A to B to A', async () => {
        const digest = deferred<ArrayBuffer>(); const hashing = deferred<void>();
        vi.stubGlobal('crypto', { randomUUID: () => uuid, subtle: {
            digest: () => { hashing.resolve(); return digest.promise; },
        } });
        const request = mockRequest().mockResolvedValue(checked());
        const work = new HandoverCommandSession(4, { request });
        const pending = work.save(20, 12, 3, fields);
        await hashing.promise;
        work.publishActor(5); work.publishActor(4); work.notify();
        digest.resolve(new ArrayBuffer(32));
        expect(await pending).toBeNull();
        expect(work.snapshot()).toMatchObject({ phase: 'unavailable', saved: null });
        expect(request.mock.calls.map(([method]) => method)).toEqual(['get']);
    });

    it('withholds a reached write response and recovery after actor A to B to A', async () => {
        const response = deferred<Response>(); const sending = deferred<unknown>();
        const request = mockRequest().mockImplementation((method, _url, body) => {
            if (method === 'get') return Promise.resolve(checked());
            sending.resolve(body); return response.promise;
        });
        const work = new HandoverCommandSession(4, { request });
        const pending = work.save(20, 12, 3, fields);
        const wire = await sending.promise;
        expect(work.snapshot().phase).toBe('saving');
        work.publishActor(5); work.publishActor(4); work.notify();
        response.resolve(confirmed(wire));
        expect(await pending).toBeNull();
        expect(await work.recover()).toBeNull();
        expect(work.snapshot()).toMatchObject({ phase: 'unavailable', saved: null });
        expect(request.mock.calls.map(([method]) => method)).toEqual(['get', 'put']);
    });

    it('does not invoke a source read or write after a synchronous preparation subscriber revokes the actor', async () => {
        const request = mockRequest(); const work = new HandoverCommandSession(4, { request });
        work.subscribe(() => { if (work.snapshot().phase === 'preparing') work.publishActor(5); });
        expect(await work.save(20, 12, 3, fields)).toBeNull();
        expect(work.snapshot().phase).toBe('unavailable');
        expect(request).not.toHaveBeenCalled();
    });

    it('does not invoke a write after a synchronous saving subscriber revokes the actor', async () => {
        const request = mockRequest().mockResolvedValue(checked()); const work = new HandoverCommandSession(4, { request });
        work.subscribe(() => { if (work.snapshot().phase === 'saving') work.publishActor(5); });
        expect(await work.save(20, 12, 3, fields)).toBeNull();
        work.publishActor(4); work.notify();
        expect(work.snapshot().phase).toBe('unavailable');
        expect(await work.recover()).toBeNull();
        expect(request.mock.calls.map(([method]) => method)).toEqual(['get']);
    });

    it('treats local invalid fields and failed UUID or digest as no-send while preserving editing', async () => {
        const request = mockRequest().mockResolvedValue(checked());
        let work = new HandoverCommandSession(4, { request });
        expect(await work.save(20, 12, 3, { ...fields, actor_id: 4 })).toBeNull();
        expect(work.canEdit()).toBe(true);
        vi.stubGlobal('crypto', { randomUUID: () => { throw new Error('UUID unavailable'); }, subtle: webcrypto.subtle });
        work = new HandoverCommandSession(4, { request });
        await expect(work.save(20, 12, 3, fields)).resolves.toBeNull();
        expect(work.canEdit()).toBe(true);
        vi.stubGlobal('crypto', { randomUUID: () => uuid, subtle: { digest: () => Promise.reject(new Error('Digest unavailable')) } });
        work = new HandoverCommandSession(4, { request });
        expect(await work.save(20, 12, 3, fields)).toBeNull();
        expect(work.canEdit()).toBe(true);
        expect(request.mock.calls.map(([method]) => method)).toEqual(['get', 'get', 'get']);
    });

    it('does not turn an invoked server validation refusal into no-write or permit an automatic retry', async () => {
        const request = mockRequest().mockResolvedValueOnce(checked())
            .mockResolvedValueOnce({ status: 422, data: { errors: { handover_notes: 'Review the narrative.' } } });
        const work = new HandoverCommandSession(4, { request });
        expect(await work.save(20, 12, 3, fields)).toBeNull();
        expect(work.snapshot()).toMatchObject({ phase: 'uncertain', saved: null });
        expect(work.canEdit()).toBe(false); expect(work.canClose()).toBe(false);
        expect(await work.save(20, 12, 3, fields)).toBeNull();
        expect(request.mock.calls.map(([method]) => method)).toEqual(['get', 'put']);
    });

    it('withholds malformed, wrong-identity and redirected save responses as uncertainty', async () => {
        for (const responseKind of ['malformed', 'wrong_actor', 'wrong_hash', 'redirect'] as const) {
            const request = mockRequest().mockImplementation((method, _url, body) => {
                if (method === 'get') return Promise.resolve(checked());
                const receipt = confirmed(body); const data = receipt.data as { identity: HandoverCommandIdentity };
                if (responseKind === 'malformed') return Promise.resolve({ status: 200, data: { status: 'draft', version: 4 } });
                if (responseKind === 'redirect') return Promise.resolve({ ...receipt, status: 302 });
                return Promise.resolve({ ...receipt, data: { ...data, identity: { ...data.identity,
                    ...(responseKind === 'wrong_actor' ? { actor_id: 5 } : { intent_hash: 'b'.repeat(64) }) } } });
            });
            const work = new HandoverCommandSession(4, { request });
            expect(await work.save(20, 12, 3, fields)).toBeNull();
            expect(work.snapshot()).toMatchObject({ phase: 'uncertain', saved: null });
            expect(work.canEdit()).toBe(false); expect(work.canClose()).toBe(false);
        }
    });

    it('recovers the original witnessed version separately from a later current record without resends or credentials', async () => {
        let wire: unknown;
        const request = mockRequest().mockImplementation((method, url, body) => {
            if (method !== 'get') { wire = body; return Promise.resolve(unknown()); }
            if (url.endsWith('/handover-command-source')) return Promise.resolve(checked());
            return Promise.resolve({ status: 200, data: { version: 1, scope: 'operations_handover_attempt',
                identity: identity(wire), result: confirmed(wire).data,
                current: { ...source, expected_version: 8, status: 'submitted', expected_source: 'c'.repeat(64),
                    can: { create: false, update: false, submit: false } } } });
        });
        const work = new HandoverCommandSession(4, { request });
        expect(await work.save(20, 12, 3, fields, 'ONE-SHOT-SECRET')).toBeNull();
        const saved = await work.recover();
        expect(saved).toMatchObject({ handover_id: 12, version: 4, status: 'draft' });
        expect(work.snapshot()).toMatchObject({ phase: 'confirmed', saved });
        expect(request.mock.calls.map(([method]) => method)).toEqual(['get', 'put', 'get']);
        expect(request.mock.calls[1]?.[2]).toHaveProperty('cd_witness_credential', 'ONE-SHOT-SECRET');
        const [lookupMethod, lookupUrl, lookupBody] = request.mock.calls[2]!;
        expect(lookupMethod).toBe('get'); expect(lookupBody).toBeUndefined();
        const lookup = new URL(lookupUrl, 'https://example.invalid');
        expect(lookup.pathname).toBe(`/operations/shifts/20/handover-attempts/${uuid}`);
        expect(Object.fromEntries(lookup.searchParams)).toEqual({ action: 'update', requested_handover_id: '12',
            expected_version: '3', expected_source: source.expected_source, intent_hash: identity(wire).intent_hash });
        expect(lookupUrl).not.toContain('SECRET'); expect(lookupUrl).not.toContain(fields.handover_notes);
        expect(await work.recover()).toBeNull();
        expect(request).toHaveBeenCalledTimes(3);
    });

    it('holds unknown recovery and deduplicates concurrent original-attempt checks', async () => {
        let wire: unknown; const lookup = deferred<Response>();
        const request = mockRequest().mockImplementation((method, url, body) => {
            if (method !== 'get') { wire = body; return Promise.resolve(unknown()); }
            return url.endsWith('/handover-command-source') ? Promise.resolve(checked()) : lookup.promise;
        });
        const work = new HandoverCommandSession(4, { request });
        await work.save(20, 12, 3, fields);
        const checking = work.recover();
        expect(await work.recover()).toBeNull();
        expect(request).toHaveBeenCalledTimes(3);
        lookup.resolve({ status: 200, data: { version: 1, scope: 'operations_handover_attempt',
            identity: identity(wire), result: null, current: { ...source, expected_version: 5 } } });
        expect(await checking).toBeNull();
        expect(work.snapshot().phase).toBe('uncertain');
        expect(work.canEdit()).toBe(false);
        expect(request.mock.calls.filter(([method]) => method !== 'get')).toHaveLength(1);
    });

    it('withholds a deferred recovery after retirement or actor restoration', async () => {
        for (const invalidation of ['retire', 'actor'] as const) {
            let wire: unknown; const lookup = deferred<Response>();
            const request = mockRequest().mockImplementation((method, url, body) => {
                if (method !== 'get') { wire = body; return Promise.resolve(unknown()); }
                return url.endsWith('/handover-command-source') ? Promise.resolve(checked()) : lookup.promise;
            });
            const work = new HandoverCommandSession(4, { request }); await work.save(20, 12, 3, fields);
            const checking = work.recover();
            if (invalidation === 'retire') work.retire(); else { work.publishActor(5); work.publishActor(4); work.notify(); }
            lookup.resolve({ status: 200, data: { version: 1, scope: 'operations_handover_attempt',
                identity: identity(wire), result: confirmed(wire).data, current: source } });
            expect(await checking).toBeNull(); expect(work.snapshot().saved).toBeNull();
            expect(work.canClose()).toBe(false); expect(await work.recover()).toBeNull();
            expect(request.mock.calls.map(([method]) => method)).toEqual(['get', 'put', 'get']);
        }
    });

    it('rechecks the actor after a confirmed notification before returning a dispatch result', async () => {
        const request = mockRequest().mockImplementation((method, _url, body) =>
            Promise.resolve(method === 'get' ? checked() : confirmed(body)));
        const work = new HandoverCommandSession(4, { request });
        work.subscribe(() => { if (work.snapshot().phase === 'confirmed') work.publishActor(5); });
        expect(await work.save(20, 12, 3, fields)).toBeNull();
        expect(work.snapshot()).toMatchObject({ phase: 'unavailable', saved: null });
        expect(work.canClose()).toBe(false);
    });

    it('rechecks the actor after a recovered confirmation notification before returning saved evidence', async () => {
        let wire: unknown;
        const request = mockRequest().mockImplementation((method, url, body) => {
            if (method !== 'get') { wire = body; return Promise.resolve(unknown()); }
            return Promise.resolve(url.endsWith('/handover-command-source') ? checked() : { status: 200,
                data: { version: 1, scope: 'operations_handover_attempt', identity: identity(wire),
                    result: confirmed(wire).data, current: source } });
        });
        const work = new HandoverCommandSession(4, { request }); await work.save(20, 12, 3, fields);
        work.subscribe(() => { if (work.snapshot().phase === 'confirmed') work.publishActor(5); });
        expect(await work.recover()).toBeNull();
        expect(work.snapshot()).toMatchObject({ phase: 'unavailable', saved: null });
        expect(request.mock.calls.map(([method]) => method)).toEqual(['get', 'put', 'get']);
    });

    it('allows deliberate corrected editing only for an exact identity-bound pre-writer refusal', async () => {
        for (const httpStatus of [409, 422]) {
            const secondUuid = 'd7b1e824-942a-4ac9-8ef2-fbd4d895bd33';
            const randomUUID = vi.fn().mockReturnValueOnce(uuid).mockReturnValueOnce(secondUuid);
            vi.stubGlobal('crypto', { randomUUID, subtle: webcrypto.subtle });
            let writes = 0;
            const request = mockRequest().mockImplementation((method, _url, body) => {
                if (method === 'get') return Promise.resolve(checked());
                ++writes;
                return Promise.resolve(writes === 1 ? refused(body, 'R'.repeat(300), httpStatus) : unknown());
            });
            const work = new HandoverCommandSession(4, { request });
            expect(await work.save(20, 12, 3, fields)).toBeNull();
            expect(work.snapshot()).toMatchObject({ phase: 'editing', message: 'R'.repeat(300), saved: null });
            expect(work.canClose()).toBe(true); expect(await work.recover()).toBeNull();
            expect(request.mock.calls.map(([method]) => method)).toEqual(['get', 'put']);
            // This is a new explicit action after proved refusal, not an automatic replay.
            expect(await work.save(20, 12, 3, { ...fields, handover_notes: 'Corrected narrative.' })).toBeNull();
            expect(request.mock.calls.map(([method]) => method)).toEqual(['get', 'put', 'get', 'put']);
            expect(request.mock.calls[1]?.[2]).toHaveProperty('request_id', uuid);
            expect(request.mock.calls[3]?.[2]).toHaveProperty('request_id', secondUuid);
            expect(work.snapshot().phase).toBe('uncertain');
        }
    });

    it('keeps malformed or unmatched refusal evidence uncertain without discarding the original attempt', async () => {
        for (const variant of ['wrong_actor', 'wrong_hash', 'saved', 'scope', 'status', 'unknown_field', 'empty', 'long'] as const) {
            const request = mockRequest().mockImplementation((method, _url, body) => {
                if (method === 'get') return Promise.resolve(checked());
                const response = refused(body);
                const data = response.data as { identity: HandoverCommandIdentity; errors: Record<string, string> };
                if (variant === 'status') return Promise.resolve({ ...response, status: 200 });
                if (variant === 'saved') return Promise.resolve({ ...response, data: { ...data, saved: { handover_id: 12 } } });
                if (variant === 'scope') return Promise.resolve({ ...response, data: { ...data, scope: 'other_command' } });
                if (variant === 'unknown_field') return Promise.resolve({ ...response, data: { ...data, errors: { private_record: 'Not a public field' } } });
                if (variant === 'empty') return Promise.resolve({ ...response, data: { ...data, errors: { handover_notes: '' } } });
                if (variant === 'long') return Promise.resolve({ ...response, data: { ...data, errors: { handover_notes: 'R'.repeat(301) } } });
                return Promise.resolve({ ...response, data: { ...data, identity: { ...data.identity,
                    ...(variant === 'wrong_actor' ? { actor_id: 5 } : { intent_hash: 'b'.repeat(64) }) } } });
            });
            const work = new HandoverCommandSession(4, { request });
            expect(await work.save(20, 12, 3, fields)).toBeNull();
            expect(work.snapshot()).toMatchObject({ phase: 'uncertain', saved: null });
            expect(work.canEdit()).toBe(false); expect(work.canClose()).toBe(false);
            expect(await work.save(20, 12, 3, fields)).toBeNull();
            expect(request.mock.calls.map(([method]) => method)).toEqual(['get', 'put']);
        }
    });

    it('does not adopt a refusal response as historical confirmed recovery', async () => {
        let wire: unknown;
        const request = mockRequest().mockImplementation((method, url, body) => {
            if (method !== 'get') { wire = body; return Promise.resolve(unknown()); }
            return Promise.resolve(url.endsWith('/handover-command-source') ? checked() : { status: 200,
                data: { version: 1, scope: 'operations_handover_attempt', identity: identity(wire),
                    result: refused(wire).data, current: source } });
        });
        const work = new HandoverCommandSession(4, { request }); await work.save(20, 12, 3, fields);
        expect(await work.recover()).toBeNull();
        expect(work.snapshot()).toMatchObject({ phase: 'uncertain', saved: null });
        expect(work.canEdit()).toBe(false);
        expect(request.mock.calls.map(([method]) => method)).toEqual(['get', 'put', 'get']);
    });

    it('permits a deliberate reset after confirmation but never erases an uncertain original attempt', async () => {
        vi.stubGlobal('crypto', { randomUUID: vi.fn().mockReturnValueOnce(uuid)
            .mockReturnValueOnce('d7b1e824-942a-4ac9-8ef2-fbd4d895bd33'), subtle: webcrypto.subtle });
        const request = mockRequest().mockImplementation((method, _url, body) =>
            Promise.resolve(method === 'get' ? checked() : confirmed(body)));
        const work = new HandoverCommandSession(4, { request });
        expect(await work.save(20, 12, 3, fields)).not.toBeNull();
        expect(work.reset()).toBe(true); expect(work.canEdit()).toBe(true);
        request.mockResolvedValueOnce(checked()).mockResolvedValueOnce(unknown());
        await work.save(20, 12, 3, fields);
        expect(work.reset()).toBe(false); expect(work.snapshot().phase).toBe('uncertain');
        expect(request.mock.calls.filter(([method]) => method !== 'get')).toHaveLength(2);
    });

    it('withdraws known source read denial rather than keeping a private record editable', async () => {
        for (const status of [401, 403, 404]) {
            const request = mockRequest().mockResolvedValue({ status, data: null });
            const work = new HandoverCommandSession(4, { request }); work.publishContext(context);
            expect(await work.save(20, 12, 3, fields)).toBeNull();
            expect(work.snapshot()).toMatchObject({ phase: 'unavailable', saved: null });
            expect(work.belongsTo(4)).toBe(false); expect(work.canEdit()).toBe(false);
            work.publishContext(context); work.notify();
            expect(await work.save(20, 12, 3, fields)).toBeNull();
            expect(request.mock.calls.map(([method]) => method)).toEqual(['get']);
        }
    });

    it('permanently rejects a deferred source response after target, version, Shift or access restoration', async () => {
        for (const changed of [
            { ...context, recordId: 13 }, { ...context, version: 4 }, { ...context, shiftId: 21 },
            { ...context, canRead: false }, { ...context, canWrite: false },
        ]) {
            const read = deferred<Response>(); const request = mockRequest().mockReturnValue(read.promise);
            const work = new HandoverCommandSession(4, { request }); work.publishContext(context);
            const pending = work.save(20, 12, 3, fields);
            work.publishContext(changed); work.publishContext(context); work.notify();
            read.resolve(checked()); expect(await pending).toBeNull();
            expect(work.snapshot()).toMatchObject({ phase: 'unavailable', saved: null });
            expect(await work.recover()).toBeNull();
            expect(request.mock.calls.map(([method]) => method)).toEqual(['get']);
        }
    });

    it('withholds a reached write receipt when the record frame changed and then restored', async () => {
        const response = deferred<Response>(); const sending = deferred<unknown>();
        let originalWire: unknown;
        const request = mockRequest().mockImplementation((method, _url, body) => {
            if (method === 'get') return Promise.resolve(_url.endsWith('/handover-command-source') ? checked() : {
                status: 200, data: { version: 1, scope: 'operations_handover_attempt', identity: identity(originalWire),
                    result: confirmed(originalWire).data, current: { ...source, expected_version: 8, status: 'submitted',
                        expected_source: 'c'.repeat(64), can: { create: false, update: false, submit: false } } },
            });
            originalWire = body; sending.resolve(body); return response.promise;
        });
        const work = new HandoverCommandSession(4, { request }); work.publishContext(context);
        const pending = work.save(20, 12, 3, fields); const wire = await sending.promise;
        work.publishContext({ ...context, version: 4 }); work.publishContext(context); work.notify();
        response.resolve(confirmed(wire)); expect(await pending).toBeNull();
        expect(work.snapshot()).toMatchObject({ phase: 'uncertain', saved: null });
        work.publishContext({ ...context, version: 8, canWrite: false }); work.notify();
        expect(await work.recover()).toMatchObject({ handover_id: 12, version: 4, status: 'draft' });
        expect(request.mock.calls.map(([method]) => method)).toEqual(['get', 'put', 'get']);
        const lookup = new URL(request.mock.calls[2]![1], 'https://example.invalid').searchParams;
        expect(lookup.get('expected_version')).toBe('3');
        expect(lookup.get('expected_source')).toBe(source.expected_source);
    });

    it('keeps a currently readable record closable without sending after only write capability is removed', async () => {
        const request = mockRequest(); const work = new HandoverCommandSession(4, { request });
        work.publishContext({ ...context, canWrite: false }); work.notify();
        expect(work.belongsTo(4)).toBe(true); expect(work.canClose()).toBe(true);
        expect(work.canEdit()).toBe(false); expect(await work.save(20, 12, 3, fields)).toBeNull();
        expect(request).not.toHaveBeenCalled();
    });

    it('uses the dedicated submit capability and empty intent without requiring edit permission', async () => {
        const submitSource = { ...source, can: { create: false, update: false, submit: true } };
        const request = mockRequest().mockImplementation((method, _url, body) => {
            if (method === 'get') return Promise.resolve({ status: 200, data: submitSource });
            const result = confirmed(body).data as { saved: Record<string, unknown> };
            return Promise.resolve({ status: 200, data: { ...result,
                identity: { ...identity(body), action: 'submit' }, saved: { ...result.saved, status: 'submitted' } } });
        });
        const work = new HandoverCommandSession(4, { request }); work.publishContext(context);
        const saved = await work.save(20, 12, 3, {}, null, true);
        expect(saved).toMatchObject({ handover_id: 12, version: 4, status: 'submitted' });
        expect(request.mock.calls.map(([method]) => method)).toEqual(['get', 'patch']);
        expect(request.mock.calls[1]?.[1]).toBe('/operations/shifts/20/handovers/12/command/submit');
        expect(request.mock.calls[1]?.[2]).toMatchObject({ expected_version: 3, fields: {} });
        expect(Object.keys((request.mock.calls[1]?.[2] as { fields: object }).fields)).toEqual([]);
        expect(work.confirmedFor(saved!, 4)).toBe(true);
    });

    it('never falls back to an update when submit is denied or dedicated submit carries narrative fields', async () => {
        for (const [currentSource, attemptedFields] of [
            [{ ...source, can: { ...source.can, submit: false } }, {}],
            [source, fields],
        ] as const) {
            const request = mockRequest().mockResolvedValue({ status: 200, data: currentSource });
            const work = new HandoverCommandSession(4, { request }); work.publishContext(context);
            expect(await work.save(20, 12, 3, attemptedFields, null, true)).toBeNull();
            expect(work.snapshot().phase).toBe('editing');
            expect(request.mock.calls.map(([method]) => method)).toEqual(['get']);
        }
    });

    it('invalidates the callback-entry confirmation after a completed response and a newer context frame', async () => {
        const request = mockRequest().mockImplementation((method, _url, body) =>
            Promise.resolve(method === 'get' ? checked() : confirmed(body)));
        const work = new HandoverCommandSession(4, { request }); work.publishContext(context);
        const saved = await work.save(20, 12, 3, fields); expect(saved).not.toBeNull();
        expect(work.confirmedFor(saved!, 4)).toBe(true);
        work.publishContext({ ...context, version: 4 }); work.publishContext(context); work.notify();
        expect(work.confirmedFor(saved!, 4)).toBe(false);
        expect(work.confirmedFor(saved!, 5)).toBe(false);
        expect(work.confirmedFor({ ...saved! }, 4)).toBe(false);
    });

    it('withdraws recovery read denial without replaying or claiming rollback of the original write', async () => {
        for (const status of [401, 403, 404]) {
            const request = mockRequest().mockResolvedValueOnce(checked()).mockResolvedValueOnce(unknown())
                .mockResolvedValueOnce({ status, data: null });
            const work = new HandoverCommandSession(4, { request }); work.publishContext(context);
            await work.save(20, 12, 3, fields);
            expect(work.snapshot().phase).toBe('uncertain');
            expect(await work.recover()).toBeNull();
            expect(work.snapshot()).toMatchObject({ phase: 'unavailable', saved: null });
            expect(work.belongsTo(4)).toBe(false); expect(await work.recover()).toBeNull();
            expect(await work.save(20, 12, 3, fields)).toBeNull();
            expect(request.mock.calls.map(([method]) => method)).toEqual(['get', 'put', 'get']);
        }
    });

    it('binds save arguments to the detached committed Shift, row and version before even reading source', async () => {
        const currentContext = { ...context };
        const request = mockRequest(); const work = new HandoverCommandSession(4, { request });
        work.publishContext(currentContext);
        currentContext.shiftId = 21;
        for (const [shiftId, rowId, version] of [[21, 12, 3], [20, 13, 3], [20, 12, 4]] as const) {
            expect(await work.save(shiftId, rowId, version, fields)).toBeNull();
            expect(work.snapshot().phase).toBe('editing');
        }
        expect(request).not.toHaveBeenCalled();
    });

    it('withdraws idle old-version typing when the same canonical row advances and does not revive it after restoration', async () => {
        const request = mockRequest(); const work = new HandoverCommandSession(4, { request });
        work.publishContext(context);
        work.publishContext({ ...context, version: 4 }); work.notify();
        expect(work.snapshot()).toMatchObject({ phase: 'unavailable', saved: null });
        work.publishContext(context); work.notify();
        expect(await work.save(20, 12, 3, fields)).toBeNull();
        expect(work.reset()).toBe(false);
        expect(request).not.toHaveBeenCalled();
    });

    it('withdraws known write-response read denial without retrying the invoked command', async () => {
        for (const status of [401, 403, 404]) {
            const request = mockRequest().mockResolvedValueOnce(checked()).mockResolvedValueOnce({ status, data: null });
            const work = new HandoverCommandSession(4, { request }); work.publishContext(context);
            expect(await work.save(20, 12, 3, fields)).toBeNull();
            expect(work.snapshot()).toMatchObject({ phase: 'unavailable', saved: null });
            expect(await work.recover()).toBeNull(); expect(await work.save(20, 12, 3, fields)).toBeNull();
            expect(request.mock.calls.map(([method]) => method)).toEqual(['get', 'put']);
        }
    });

    it('allows a new explicit read-only original lookup after write permission is removed without resending', async () => {
        let wire: unknown;
        const request = mockRequest().mockImplementation((method, url, body) => {
            if (method !== 'get') { wire = body; return Promise.resolve(unknown()); }
            return Promise.resolve(url.endsWith('/handover-command-source') ? checked() : { status: 200,
                data: { version: 1, scope: 'operations_handover_attempt', identity: identity(wire),
                    result: confirmed(wire).data, current: { ...source,
                        can: { create: false, update: false, submit: false } } } });
        });
        const work = new HandoverCommandSession(4, { request }); work.publishContext(context);
        await work.save(20, 12, 3, fields);
        work.publishContext({ ...context, canWrite: false }); work.notify();
        const saved = await work.recover();
        expect(saved).toMatchObject({ handover_id: 12, version: 4, status: 'draft' });
        expect(work.canEdit()).toBe(false);
        expect(request.mock.calls.map(([method]) => method)).toEqual(['get', 'put', 'get']);
    });

    it('allows original historical recovery after a later version of the same currently readable row arrives', async () => {
        let wire: unknown;
        const request = mockRequest().mockImplementation((method, url, body) => {
            if (method !== 'get') { wire = body; return Promise.resolve(unknown()); }
            return Promise.resolve(url.endsWith('/handover-command-source') ? checked() : { status: 200,
                data: { version: 1, scope: 'operations_handover_attempt', identity: identity(wire),
                    result: confirmed(wire).data, current: { ...source, expected_version: 8,
                        status: 'submitted', expected_source: 'c'.repeat(64),
                        can: { create: false, update: false, submit: false } } } });
        });
        const work = new HandoverCommandSession(4, { request }); work.publishContext(context);
        await work.save(20, 12, 3, fields);
        work.publishContext({ ...context, version: 8, canWrite: false }); work.notify();
        const saved = await work.recover();
        expect(saved).toMatchObject({ handover_id: 12, version: 4, status: 'draft' });
        const query = new URL(request.mock.calls[2]![1], 'https://example.invalid').searchParams;
        expect(query.get('expected_version')).toBe('3');
        expect(query.get('expected_source')).toBe(source.expected_source);
        expect(request.mock.calls.map(([method]) => method)).toEqual(['get', 'put', 'get']);
    });
});
