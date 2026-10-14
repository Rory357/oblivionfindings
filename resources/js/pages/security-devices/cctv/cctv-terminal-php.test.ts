import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import { cctvSessionReply, cctvTime } from './live-contract';

const exported = process.env.OBLI_CCTV_TERMINAL_EXPORT;

describe('actual PHP terminal CCTV status', () => {
    for (const provider of ['unifi', 'hikvision']) {
        it.skipIf(!exported)(
            `accepts the original late absolute-expiry reply for ${provider}`,
            () => {
                const reply = JSON.parse(
                    readFileSync(`${exported}.${provider}`, 'utf8'),
                );
                const session = cctvSessionReply(
                    reply,
                    reply.actor_id,
                    reply.camera_id,
                );
                expect(session.status).toBe('expired');
                expect(session.reason_code).toBe('session_expired');
                expect(session.answer).toBeNull();
                expect(session.ice_servers).toEqual([]);
                expect(cctvTime(session.server_now)).toBeGreaterThan(
                    cctvTime(session.session_expires_at),
                );
                expect(cctvTime(session.authority_until)).toBeLessThanOrEqual(
                    cctvTime(session.session_expires_at),
                );
                expect(() =>
                    cctvSessionReply(
                        {
                            ...reply,
                            session: {
                                ...reply.session,
                                authority_until: new Date(
                                    cctvTime(session.session_expires_at) + 1000,
                                )
                                    .toISOString()
                                    .replace('.000Z', 'Z'),
                            },
                        },
                        reply.actor_id,
                        reply.camera_id,
                    ),
                ).toThrow('Camera viewing permission expired.');
            },
        );
    }
});
